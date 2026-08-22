<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\WeightAdjustment;

class LearnedPatternSignal
{
    /** @var array<string, WeightAdjustment|null> */
    protected array $priorAdjustments = [];

    public function forMatch(array $signals, Matchup $match, float $weight): ?array
    {
        $match->loadMissing('round');
        if (! $match->round) {
            return null;
        }

        $adjustment = $this->priorAdjustment($match);
        if (! $adjustment) {
            return null;
        }

        $active = collect($signals)
            ->filter(fn (array $signal) => ($signal['strength'] ?? 0) >= 0.6)
            ->pluck('type')
            ->filter()
            ->unique()
            ->all();

        $pattern = collect($adjustment->pattern_insights ?? [])
            ->filter(fn (array $candidate) => ($candidate['is_actionable'] ?? false) === true)
            ->filter(fn (array $candidate) => empty(array_diff($candidate['signals'] ?? [], $active)))
            ->sortByDesc(function (array $candidate): float {
                $sampleConfidence = min(1.0, ($candidate['sample_size'] ?? 0) / 30);
                $roundConfidence = min(1.0, ($candidate['rounds'] ?? 0) / 4);

                return abs((float) ($candidate['lift'] ?? 0)) * $sampleConfidence * $roundConfidence;
            })
            ->first();

        if (! $pattern || (float) ($pattern['lift'] ?? 0) === 0.0) {
            return null;
        }

        $lift = (float) $pattern['lift'];

        return [
            'type' => 'learned_pattern',
            'weight' => (int) round($weight),
            'strength' => round(max(-1.0, min(1.0, $lift / 25)), 4),
            'description' => sprintf(
                '%s: %+.1f point lift (n=%d across %d rounds, learned through R%d)',
                $pattern['label'],
                $lift,
                $pattern['sample_size'],
                $pattern['rounds'],
                $adjustment->after_round,
            ),
            'pattern_key' => $pattern['key'],
            'sample_size' => $pattern['sample_size'],
            'lift' => $lift,
            'confidence' => $pattern['confidence'],
            'trained_through_round' => $adjustment->after_round,
        ];
    }

    protected function priorAdjustment(Matchup $match): ?WeightAdjustment
    {
        $key = "{$match->round->season}:{$match->round->round_number}";
        if (! array_key_exists($key, $this->priorAdjustments)) {
            $this->priorAdjustments[$key] = WeightAdjustment::where('season', $match->round->season)
                ->where('after_round', '<', $match->round->round_number)
                ->orderByDesc('after_round')
                ->first();
        }

        return $this->priorAdjustments[$key];
    }
}
