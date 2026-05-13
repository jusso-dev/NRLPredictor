<?php

namespace App\Livewire;

use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\WeightAdjustment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Value picks — Benter-style "where does the model disagree with the market
 * and have measurable edge". Lists upcoming-match predictions whose model_prob
 * meaningfully exceeds the bookmaker's market_prob.
 */
class ValuePicks extends Component
{
    /**
     * Minimum (model_prob - market_prob) gap to display. URL-bindable so
     * picks can be shared at a specific filter level.
     */
    #[Url(as: 'edge')]
    public float $threshold = 0.05;

    public function setThreshold(float $value): void
    {
        // Clamp to a sane range — anything outside this is either useless
        // (too low → market noise) or fantasy (too high → nothing will qualify).
        $this->threshold = max(0.01, min(0.30, $value));
    }

    #[Title('Value Picks — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $now = now();

        $matches = Matchup::with(['homeTeam', 'awayTeam', 'round'])
            ->whereIn('status', ['upcoming', 'live'])
            ->where(function ($q) use ($now) {
                $q->whereNull('kickoff_at')->orWhere('kickoff_at', '>=', $now->copy()->subHours(3));
            })
            ->orderBy('kickoff_at')
            ->get();

        if ($matches->isEmpty()) {
            return view('livewire.value-picks', [
                'picks' => collect(),
                'confidence' => $this->latestConfidence(),
                'threshold' => $this->threshold,
            ]);
        }

        $matchIds = $matches->pluck('id');

        // Pull every prediction in these matches that has both model and
        // market probabilities, with a meaningful edge over the market.
        $predictions = Prediction::with('player.team')
            ->whereIn('match_id', $matchIds)
            ->whereNotNull('model_prob')
            ->whereNotNull('market_prob')
            ->get()
            ->filter(fn (Prediction $p) => ($p->model_prob - $p->market_prob) >= $this->threshold)
            ->sortByDesc(fn (Prediction $p) => $p->model_prob - $p->market_prob)
            ->take(40);

        $bestOdds = $this->bestOddsFor($matchIds, $predictions->pluck('player_id'));
        $matchesById = $matches->keyBy('id');

        $picks = $predictions->map(function (Prediction $p) use ($bestOdds, $matchesById) {
            $edge = $p->model_prob - $p->market_prob;
            return [
                'prediction' => $p,
                'match' => $matchesById[$p->match_id] ?? null,
                'edge' => $edge,
                'model_pct' => $p->model_prob * 100,
                'market_pct' => $p->market_prob * 100,
                'fair_decimal' => $p->model_prob > 0 ? 1 / $p->model_prob : null,
                'best_decimal' => $bestOdds[$p->match_id][$p->player_id] ?? null,
            ];
        })->values();

        return view('livewire.value-picks', [
            'picks' => $picks,
            'confidence' => $this->latestConfidence(),
            'threshold' => $this->threshold,
        ]);
    }

    /**
     * Best (highest) decimal odds across AU books for each match/player so the
     * UI can show a punter the headline price. Keyed [matchId][playerId].
     */
    protected function bestOddsFor($matchIds, $playerIds): array
    {
        $rows = OddsSnapshot::where('market', 'ats')
            ->whereIn('match_id', $matchIds)
            ->whereIn('player_id', $playerIds)
            ->where('decimal_odds', '>', 1.0)
            ->get(['match_id', 'player_id', 'decimal_odds']);

        $best = [];
        foreach ($rows as $row) {
            $matchId = (int) $row->match_id;
            $playerId = (int) $row->player_id;
            $current = $best[$matchId][$playerId] ?? 0;
            if ($row->decimal_odds > $current) {
                $best[$matchId][$playerId] = (float) $row->decimal_odds;
            }
        }

        return $best;
    }

    /**
     * Confidence badge data based on the most recent WeightAdjustment row.
     * Tells the user whether to trust the picks: if the model hasn't been
     * beating the market lately, value picks are speculative at best.
     */
    protected function latestConfidence(): array
    {
        $latest = WeightAdjustment::orderByDesc('after_round')->orderByDesc('id')->first();

        if (! $latest || $latest->market_brier === null || $latest->brier_score === null) {
            return ['tier' => 'unknown', 'label' => 'Calibration pending', 'note' => 'Not enough graded rounds yet to score model vs market.'];
        }

        $beatsMarket = $latest->brier_score < $latest->market_brier;
        $value = $latest->value_score ?? 0;

        if ($beatsMarket && $value > 0.02) {
            return [
                'tier' => 'high',
                'label' => 'High confidence',
                'note' => sprintf('Model beats market Brier (%.3f vs %.3f), value +%.3f', $latest->brier_score, $latest->market_brier, $value),
            ];
        }

        if ($beatsMarket || $value > 0) {
            return [
                'tier' => 'moderate',
                'label' => 'Moderate confidence',
                'note' => sprintf('Mixed signal: brier %.3f vs market %.3f, value %s%.3f', $latest->brier_score, $latest->market_brier, $value >= 0 ? '+' : '', $value),
            ];
        }

        return [
            'tier' => 'low',
            'label' => 'Speculative',
            'note' => sprintf('Model currently trails market (brier %.3f vs %.3f). Treat picks as experimental.', $latest->brier_score, $latest->market_brier),
        ];
    }
}
