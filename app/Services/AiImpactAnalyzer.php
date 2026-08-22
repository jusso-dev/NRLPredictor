<?php

namespace App\Services;

use App\Models\Matchup;
use Illuminate\Support\Collection;

class AiImpactAnalyzer
{
    /**
     * Compare the saved pre-AI ranking with the final AI-reviewed ranking.
     * Existing historical rows stay excluded because their overwritten
     * statistical baseline cannot be recovered honestly.
     */
    public function analyze(int $season): array
    {
        $matches = Matchup::with(['round', 'predictions', 'tryEvents'])
            ->whereHas('round', fn ($query) => $query->where('season', $season))
            ->where('status', 'completed')
            ->whereNotNull('ai_reviewed_at')
            ->whereHas('predictions', fn ($query) => $query->whereNotNull('statistical_rank'))
            ->get();

        $matchResults = $matches->map(function (Matchup $match) {
            $scorerIds = $match->tryEvents->pluck('player_id')->unique();
            $statistical = $match->predictions
                ->whereNotNull('statistical_rank')
                ->sortBy('statistical_rank')
                ->take(5);
            $ai = $match->predictions
                ->sortBy('rank_in_match')
                ->take(5);

            return [
                'round' => $match->round?->round_number,
                'statistical_hits' => $statistical->whereIn('player_id', $scorerIds)->count(),
                'statistical_picks' => $statistical->count(),
                'ai_hits' => $ai->whereIn('player_id', $scorerIds)->count(),
                'ai_picks' => $ai->count(),
                'adjusted_predictions' => $match->predictions->whereNotNull('ai_reviewed_at')->count(),
            ];
        });

        $summary = $this->summarize($matchResults);
        $summary['rounds'] = $matchResults
            ->groupBy('round')
            ->map(function (Collection $results, $round) {
                return ['round' => (int) $round, ...$this->summarize($results)];
            })
            ->sortBy('round')
            ->values()
            ->all();

        return $summary;
    }

    protected function summarize(Collection $results): array
    {
        $statisticalHits = $results->sum('statistical_hits');
        $statisticalPicks = $results->sum('statistical_picks');
        $aiHits = $results->sum('ai_hits');
        $aiPicks = $results->sum('ai_picks');
        $statisticalAccuracy = $statisticalPicks > 0
            ? round($statisticalHits / $statisticalPicks * 100, 2)
            : 0.0;
        $aiAccuracy = $aiPicks > 0
            ? round($aiHits / $aiPicks * 100, 2)
            : 0.0;

        return [
            'reviewed_matches' => $results->count(),
            'adjusted_predictions' => $results->sum('adjusted_predictions'),
            'statistical_top5_accuracy' => $statisticalAccuracy,
            'ai_top5_accuracy' => $aiAccuracy,
            'lift' => round($aiAccuracy - $statisticalAccuracy, 2),
            'helped_matches' => $results->filter(fn (array $result) => $result['ai_hits'] > $result['statistical_hits'])->count(),
            'hurt_matches' => $results->filter(fn (array $result) => $result['ai_hits'] < $result['statistical_hits'])->count(),
            'held_matches' => $results->filter(fn (array $result) => $result['ai_hits'] === $result['statistical_hits'])->count(),
        ];
    }
}
