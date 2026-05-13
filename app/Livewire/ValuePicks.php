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
                'emptyState' => $this->emptyState('no_matches', []),
            ]);
        }

        $matchIds = $matches->pluck('id');

        // One query, then bucket counts off the same collection so we can tell
        // the user *why* the list is empty (warming up vs no odds vs no edge).
        $allPredictions = Prediction::with('player.team')
            ->whereIn('match_id', $matchIds)
            ->get();

        $totals = [
            'matches' => $matches->count(),
            'predictions' => $allPredictions->count(),
            'with_model' => $allPredictions->whereNotNull('model_prob')->count(),
            'with_market' => $allPredictions->whereNotNull('market_prob')->count(),
        ];
        $totals['with_both'] = $allPredictions
            ->filter(fn ($p) => $p->model_prob !== null && $p->market_prob !== null)
            ->count();

        $predictions = $allPredictions
            ->filter(fn (Prediction $p) => $p->model_prob !== null
                && $p->market_prob !== null
                && ($p->model_prob - $p->market_prob) >= $this->threshold)
            ->sortByDesc(fn (Prediction $p) => $p->model_prob - $p->market_prob)
            ->take(40)
            ->values();

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
        });

        $emptyState = $picks->isEmpty() ? $this->emptyState($this->diagnose($totals), $totals) : null;

        return view('livewire.value-picks', [
            'picks' => $picks,
            'confidence' => $this->latestConfidence(),
            'threshold' => $this->threshold,
            'emptyState' => $emptyState,
        ]);
    }

    /**
     * Work out *why* there are no picks so the empty state can say something
     * useful. Order matters — earlier checks describe the most fundamental gap.
     */
    protected function diagnose(array $totals): string
    {
        if ($totals['predictions'] === 0) {
            return 'no_predictions';
        }
        if ($totals['with_model'] === 0) {
            return 'warming_up';
        }
        if ($totals['with_market'] === 0) {
            return 'no_market';
        }
        if ($totals['with_both'] === 0) {
            return 'no_overlap';
        }
        return 'no_edge';
    }

    protected function emptyState(string $reason, array $totals): array
    {
        $pending = max(0, ($totals['predictions'] ?? 0) - ($totals['with_model'] ?? 0));

        return match ($reason) {
            'no_matches' => [
                'reason' => $reason,
                'title' => 'No upcoming matches',
                'detail' => 'Value picks compare upcoming predictions to live bookmaker odds. There are no upcoming or live matches in scope right now — check back after the next round is loaded.',
                'hint' => null,
            ],
            'no_predictions' => [
                'reason' => $reason,
                'title' => 'Predictions not yet generated',
                'detail' => "There are {$totals['matches']} upcoming match(es) but no predictions have been written for them yet.",
                'hint' => 'The scheduler runs RunPredictionAnalysis every 30 minutes. To trigger immediately: docker compose exec app php artisan nrl:predict',
            ],
            'warming_up' => [
                'reason' => $reason,
                'title' => 'Calibration warming up',
                'detail' => "Found {$totals['predictions']} prediction(s) but {$pending} were written before the calibration layer was wired in, so they don't carry a model probability yet.",
                'hint' => 'New predictions are auto-calibrated. To recalibrate the existing rows now: docker compose exec app php artisan nrl:predict (or wait up to 30 minutes for the scheduler).',
            ],
            'no_market' => [
                'reason' => $reason,
                'title' => 'No bookmaker odds yet',
                'detail' => "{$totals['with_model']} prediction(s) have a model probability but no anytime-try-scorer market data is in the database for these matches yet.",
                'hint' => 'Odds are fetched every 4 hours. To pull now: docker compose exec app php artisan nrl:fetch-odds',
            ],
            'no_overlap' => [
                'reason' => $reason,
                'title' => 'Model and market don\'t overlap on the same players',
                'detail' => "We have {$totals['with_model']} predictions with model probabilities and {$totals['with_market']} with market probabilities, but no single player has both.",
                'hint' => 'This usually clears up once the next nrl:fetch-odds + nrl:predict cycle runs — both jobs need to see the same team lists.',
            ],
            'no_edge' => [
                'reason' => $reason,
                'title' => 'Model agrees with the market',
                'detail' => "Compared {$totals['with_both']} predictions head-to-head with the market and none exceed the current edge threshold.",
                'hint' => 'Lower the edge filter to see closer picks, or wait — markets can move significantly in the hours before kickoff.',
            ],
            default => [
                'reason' => $reason,
                'title' => 'No value picks at this threshold',
                'detail' => 'Lower the edge filter or check back closer to kickoff.',
                'hint' => null,
            ],
        };
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
