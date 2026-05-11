<?php

namespace App\Livewire;

use App\Models\Matchup;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\TryEvent;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Calibration extends Component
{
    #[Title('Calibration — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $season = now()->year;
        $rounds = Round::where('season', $season)->orderBy('round_number')->get();
        $buckets = [];
        $perRound = [];
        $signalContributions = [];

        foreach ($rounds as $round) {
            $matches = Matchup::where('round_id', $round->id)
                ->where('status', 'completed')
                ->with(['predictions.player', 'tryEvents'])
                ->get();

            if ($matches->isEmpty()) {
                continue;
            }

            $roundHits = 0;
            $roundTotal = 0;

            foreach ($matches as $match) {
                $scorerIds = $match->tryEvents->pluck('player_id')->unique();

                foreach ($match->predictions as $pred) {
                    $score = $pred->score;
                    $bucket = (int) floor($score / 10) * 10;
                    $hit = $scorerIds->contains($pred->player_id) ? 1 : 0;

                    if (! isset($buckets[$bucket])) {
                        $buckets[$bucket] = ['predicted' => 0, 'actual' => 0, 'count' => 0];
                    }
                    $buckets[$bucket]['predicted'] += $score / 100;
                    $buckets[$bucket]['actual'] += $hit;
                    $buckets[$bucket]['count']++;

                    $roundTotal++;
                    $roundHits += $hit;

                    // Track signal contributions to hits/misses
                    foreach ($pred->signals ?? [] as $signal) {
                        $type = $signal['type'];
                        $strength = $signal['strength'] ?? 0;
                        if (! isset($signalContributions[$type])) {
                            $signalContributions[$type] = ['hit_strength' => 0, 'miss_strength' => 0, 'hits' => 0, 'misses' => 0];
                        }
                        if ($hit) {
                            $signalContributions[$type]['hit_strength'] += $strength;
                            $signalContributions[$type]['hits']++;
                        } else {
                            $signalContributions[$type]['miss_strength'] += $strength;
                            $signalContributions[$type]['misses']++;
                        }
                    }
                }
            }

            if ($roundTotal > 0) {
                $perRound[] = [
                    'round' => $round->round_number,
                    'hits' => $roundHits,
                    'total' => $roundTotal,
                    'pct' => round($roundHits / $roundTotal * 100, 1),
                ];
            }
        }

        // Format buckets for display
        ksort($buckets);
        $calibrationData = [];
        foreach ($buckets as $bucket => $data) {
            $calibrationData[] = [
                'bucket' => "{$bucket}-" . ($bucket + 9) . '%',
                'predicted_pct' => $data['count'] > 0 ? round($data['predicted'] / $data['count'] * 100, 1) : 0,
                'actual_pct' => $data['count'] > 0 ? round($data['actual'] / $data['count'] * 100, 1) : 0,
                'count' => $data['count'],
            ];
        }

        // Format signal effectiveness
        $signalEffectiveness = [];
        foreach ($signalContributions as $type => $data) {
            $totalCount = $data['hits'] + $data['misses'];
            if ($totalCount === 0) {
                continue;
            }
            $avgHitStr = $data['hits'] > 0 ? round($data['hit_strength'] / $data['hits'], 3) : 0;
            $avgMissStr = $data['misses'] > 0 ? round($data['miss_strength'] / $data['misses'], 3) : 0;
            $signalEffectiveness[] = [
                'type' => $type,
                'avg_hit_strength' => $avgHitStr,
                'avg_miss_strength' => $avgMissStr,
                'delta' => round($avgHitStr - $avgMissStr, 3),
                'hit_rate' => $totalCount > 0 ? round($data['hits'] / $totalCount * 100, 1) : 0,
            ];
        }
        usort($signalEffectiveness, fn ($a, $b) => $b['delta'] <=> $a['delta']);

        // Brier score
        $brierSum = 0;
        $brierCount = 0;
        foreach ($buckets as $data) {
            $brierSum += ($data['predicted'] / max(1, $data['count']) - $data['actual'] / max(1, $data['count'])) ** 2 * $data['count'];
            $brierCount += $data['count'];
        }
        $brierScore = $brierCount > 0 ? round($brierSum / $brierCount, 4) : null;

        return view('livewire.calibration', [
            'calibrationData' => $calibrationData,
            'perRound' => $perRound,
            'signalEffectiveness' => $signalEffectiveness,
            'brierScore' => $brierScore,
        ]);
    }
}
