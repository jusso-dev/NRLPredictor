<?php

namespace App\Services;

use App\Models\Round;

class OutcomeTrendAnalyzer
{
    protected const ROLLING_ROUNDS = 4;

    /**
     * Direct observed performance. Only fully completed rounds enter the
     * trend, so a Thursday result cannot be compared with an eight-match week.
     */
    public function analyze(int $season): array
    {
        $rounds = Round::withCount([
            'matches as completed_matches_count' => fn ($query) => $query->where('status', 'completed'),
        ])
            ->with(['matches' => fn ($query) => $query
                ->where('status', 'completed')
                ->whereHas('predictions')
                ->whereHas('tryEvents')
                ->with(['predictions', 'tryEvents'])])
            ->where('season', $season)
            ->whereHas('matches')
            ->whereDoesntHave('matches', fn ($query) => $query->whereIn('status', ['upcoming', 'live']))
            ->orderBy('round_number')
            ->get();

        $rows = $rounds->map(function (Round $round) {
            $hits = 0;
            $picks = 0;

            foreach ($round->matches as $match) {
                $scorerIds = $match->tryEvents->pluck('player_id')->unique();
                foreach ($match->predictions->sortBy('rank_in_match')->take(5) as $prediction) {
                    $picks++;
                    $hits += $scorerIds->contains($prediction->player_id) ? 1 : 0;
                }
            }

            return [
                'round' => $round->round_number,
                'hits' => $hits,
                'picks' => $picks,
                'accuracy' => $picks > 0 ? round($hits / $picks * 100, 1) : 0.0,
                'graded_matches' => $round->matches->count(),
                'completed_matches' => $round->completed_matches_count,
            ];
        })
            ->filter(fn (array $row) => $row['picks'] > 0)
            ->values()
            ->all();

        foreach ($rows as $index => $row) {
            $window = array_slice($rows, max(0, $index - self::ROLLING_ROUNDS + 1), self::ROLLING_ROUNDS);
            $windowHits = array_sum(array_column($window, 'hits'));
            $windowPicks = array_sum(array_column($window, 'picks'));
            $rows[$index]['rolling_rounds'] = count($window);
            $rows[$index]['rolling_accuracy'] = $windowPicks > 0
                ? round($windowHits / $windowPicks * 100, 1)
                : 0.0;
        }

        $latest = $rows !== [] ? $rows[array_key_last($rows)] : null;
        $prior = count($rows) >= self::ROLLING_ROUNDS + 1 ? $rows[count($rows) - 2] : null;
        $rollingChange = $latest && $prior
            ? round($latest['rolling_accuracy'] - $prior['rolling_accuracy'], 1)
            : null;
        $gradedMatches = array_sum(array_column($rows, 'graded_matches'));
        $completedMatches = array_sum(array_column($rows, 'completed_matches'));

        return [
            'completed_rounds' => count($rows),
            'graded_matches' => $gradedMatches,
            'completed_matches' => $completedMatches,
            'coverage_pct' => $completedMatches > 0 ? round($gradedMatches / $completedMatches * 100, 1) : 0.0,
            'latest_accuracy' => $latest['accuracy'] ?? null,
            'rolling_accuracy' => $latest['rolling_accuracy'] ?? null,
            'prior_rolling_accuracy' => $prior['rolling_accuracy'] ?? null,
            'rolling_change' => $rollingChange,
            'state' => match (true) {
                $rollingChange === null => 'collecting',
                $rollingChange >= 1 => 'improving',
                $rollingChange <= -1 => 'declining',
                default => 'held',
            },
            'rounds' => $rows,
        ];
    }
}
