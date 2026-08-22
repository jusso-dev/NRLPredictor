<?php

namespace App\Services;

use App\Models\Matchup;
use Illuminate\Support\Str;

class SignalPatternAnalyzer
{
    protected const ACTIVE_STRENGTH = 0.6;

    protected const MIN_SAMPLE = 8;

    protected const ROLLING_ROUNDS = 8;

    /**
     * Find signal combinations that occur disproportionately in scorers or misses.
     */
    public function analyze(int $season, int $throughRound): array
    {
        $matches = Matchup::with(['round', 'predictions', 'tryEvents'])
            ->whereHas('round', fn ($query) => $query
                ->where('season', $season)
                ->whereBetween('round_number', [max(1, $throughRound - self::ROLLING_ROUNDS + 1), $throughRound]))
            ->where('status', 'completed')
            ->whereHas('predictions')
            ->whereHas('tryEvents')
            ->get();

        $patterns = [];
        $roundTotals = [];
        $predictionCount = 0;
        $hitCount = 0;

        foreach ($matches as $match) {
            $scorerIds = $match->tryEvents->pluck('player_id')->unique();
            $roundNumber = $match->round->round_number;

            foreach ($match->predictions as $prediction) {
                $predictionCount++;
                $isHit = $scorerIds->contains($prediction->player_id);
                $hitCount += $isHit ? 1 : 0;
                $roundTotals[$roundNumber] ??= ['sample_size' => 0, 'hit_count' => 0];
                $roundTotals[$roundNumber]['sample_size']++;
                $roundTotals[$roundNumber]['hit_count'] += $isHit ? 1 : 0;

                $activeSignals = collect($prediction->signals ?? [])
                    ->filter(fn (array $signal) => ($signal['strength'] ?? 0) >= self::ACTIVE_STRENGTH)
                    ->pluck('type')
                    ->filter(fn (?string $type) => $type && $type !== 'learned_pattern')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                foreach ($this->pairs($activeSignals) as $signals) {
                    $key = implode('+', $signals);
                    $patterns[$key] ??= [
                        'key' => $key,
                        'signals' => $signals,
                        'sample_size' => 0,
                        'hit_count' => 0,
                        'rounds' => [],
                    ];
                    $patterns[$key]['sample_size']++;
                    $patterns[$key]['hit_count'] += $isHit ? 1 : 0;
                    $patterns[$key]['rounds'][$roundNumber] ??= ['sample_size' => 0, 'hit_count' => 0];
                    $patterns[$key]['rounds'][$roundNumber]['sample_size']++;
                    $patterns[$key]['rounds'][$roundNumber]['hit_count'] += $isHit ? 1 : 0;
                }
            }
        }

        $baseline = $predictionCount > 0 ? $hitCount / $predictionCount * 100 : 0.0;
        $ranked = collect($patterns)
            ->filter(function (array $pattern) {
                return $pattern['sample_size'] >= self::MIN_SAMPLE
                    || (in_array('milestone_game', $pattern['signals'], true) && $pattern['sample_size'] > 0);
            })
            ->map(function (array $pattern) use ($baseline, $roundTotals) {
                $hitRate = $pattern['hit_count'] / $pattern['sample_size'] * 100;
                $direction = $hitRate <=> $baseline;
                $roundDeltas = collect($pattern['rounds'])->map(function (array $stats, int $round) use ($roundTotals) {
                    $patternRate = $stats['hit_count'] / $stats['sample_size'] * 100;
                    $roundTotal = $roundTotals[$round];
                    $roundBaseline = $roundTotal['hit_count'] / $roundTotal['sample_size'] * 100;

                    return $patternRate - $roundBaseline;
                });
                $directionalRounds = $roundDeltas->reject(fn (float $delta) => abs($delta) < 0.0001);
                $consistentRounds = $directionalRounds
                    ->filter(fn (float $delta) => ($delta <=> 0) === $direction)
                    ->count();
                $directionConsistency = $directionalRounds->isNotEmpty()
                    ? $consistentRounds / $directionalRounds->count()
                    : 0.0;
                $pattern['label'] = collect($pattern['signals'])
                    ->map(fn (string $signal) => Str::headline($signal))
                    ->implode(' + ');
                $pattern['rounds'] = count($pattern['rounds']);
                $pattern['consistent_rounds'] = $consistentRounds;
                $pattern['direction_consistency'] = round($directionConsistency, 2);
                $pattern['hit_rate'] = round($hitRate, 1);
                $pattern['baseline_hit_rate'] = round($baseline, 1);
                $pattern['lift'] = round($hitRate - $baseline, 1);
                $pattern['confidence'] = match (true) {
                    $pattern['sample_size'] >= 30 => 'established',
                    $pattern['sample_size'] >= 12 => 'developing',
                    default => 'early',
                };
                $pattern['is_actionable'] = $pattern['sample_size'] >= 12
                    && $pattern['rounds'] >= 3
                    && abs($pattern['lift']) >= 5
                    && $pattern['direction_consistency'] >= 0.67;

                return $pattern;
            });

        $positive = $ranked->filter(fn (array $pattern) => $pattern['lift'] > 0)
            ->sortBy([
                ['is_actionable', 'desc'],
                ['sample_size', 'desc'],
                ['lift', 'desc'],
            ])
            ->take(6);
        $negative = $ranked->filter(fn (array $pattern) => $pattern['lift'] < 0)
            ->sortBy([
                ['is_actionable', 'desc'],
                ['sample_size', 'desc'],
                ['lift', 'asc'],
            ])
            ->take(4);
        $milestones = $ranked->filter(fn (array $pattern) => in_array('milestone_game', $pattern['signals'], true));

        return [
            'rounds_analyzed' => $matches->pluck('round_id')->unique()->count(),
            'matches_analyzed' => $matches->count(),
            'predictions_graded' => $predictionCount,
            'baseline_hit_rate' => round($baseline, 1),
            'patterns' => $positive
                ->concat($negative)
                ->concat($milestones)
                ->unique('key')
                ->values()
                ->all(),
        ];
    }

    /** @return array<int, array{0: string, 1: string}> */
    protected function pairs(array $signals): array
    {
        $pairs = [];
        $count = count($signals);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                $pairs[] = [$signals[$left], $signals[$right]];
            }
        }

        return $pairs;
    }
}
