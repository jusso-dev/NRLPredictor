<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\SignalPerformanceLog;
use App\Models\TryEvent;
use App\Models\WeightAdjustment;
use Illuminate\Support\Facades\Log;

/**
 * Self-tuning feedback loop for the prediction model.
 *
 * After each completed round:
 * 1. Grade every prediction against actual try scorers
 * 2. Measure which signals were stronger in hits vs misses (delta)
 * 3. Adjust weights: boost signals with positive delta, dampen negative
 * 4. Persist the new weights to config and log the reasoning
 * 5. Generate an updated prompt insight for the Claude agent
 */
class SignalTuner
{
    // Never let a weight go below this or above this
    protected const MIN_WEIGHT = 1;
    protected const MAX_WEIGHT = 30;

    // Maximum adjustment per round (prevents wild swings from small samples)
    protected const MAX_ADJUSTMENT_PCT = 0.15; // 15% max change per signal per round

    // Minimum sample size before we trust a signal's delta
    protected const MIN_SAMPLE = 10;

    public function __construct(protected CalibrationGrader $grader) {}

    /**
     * Run the full tuning loop for a completed round.
     */
    public function tuneAfterRound(int $season, int $roundNumber): array
    {
        $round = Round::where('season', $season)->where('round_number', $roundNumber)->first();
        if (! $round) {
            return ['error' => 'Round not found'];
        }

        // Step 1: Grade predictions
        $signalStats = $this->gradeRound($round);

        // Step 2: Log signal performance
        $this->logSignalPerformance($season, $roundNumber, $signalStats);

        // Step 3: Compute cumulative deltas across recent rounds (last 4)
        $cumulativeDeltas = $this->cumulativeDeltas($season, $roundNumber);

        // Step 4: Compute weight adjustments. Start from the current live weights
        // (which may already include prior tuned adjustments from the DB), not just
        // the baked-in config — otherwise each tune resets to the defaults.
        $currentWeights = (new SignalCalculator())->weights();
        $newWeights = $this->adjustWeights($currentWeights, $cumulativeDeltas);

        // Step 5: Compute accuracy metrics
        $accuracyBefore = $this->computeAccuracy($round);

        // Step 5b: Calibration + market-divergence grading. Writes per-prediction
        // probabilities and was_hit flags; returns round-level Brier/log-loss/value.
        $calibration = $this->grader->gradeRound($season, $roundNumber);

        // Step 6: Persist (DB row is the source of truth; SignalCalculator reads this back)
        $adjustment = $this->persistAdjustment($season, $roundNumber, $currentWeights, $newWeights, $cumulativeDeltas, $accuracyBefore, $calibration);

        // Step 7: Best-effort write to config file. The DB row above is what actually
        // feeds the live model — this is a convenience snapshot for humans.
        $this->writeWeightsToConfig($newWeights);

        // Invalidate the in-process cache so the new weights take effect immediately.
        SignalCalculator::clearTunedCache();

        // Step 8: Generate agent prompt insights
        $insights = $this->generateInsights($cumulativeDeltas, $signalStats, $accuracyBefore, $calibration);

        Log::info("SignalTuner: tuned after R{$roundNumber}. Accuracy={$accuracyBefore}%. " . count($newWeights) . ' weights adjusted. ' . ($calibration['summary'] ?? ''));

        return [
            'round' => $roundNumber,
            'accuracy' => $accuracyBefore,
            'signals_graded' => count($signalStats),
            'weights_changed' => collect($currentWeights)->filter(fn ($v, $k) => ($newWeights[$k] ?? $v) !== $v)->count(),
            'insights' => $insights,
            'adjustment_id' => $adjustment->id,
            'calibration' => $calibration,
        ];
    }

    /**
     * Grade every prediction in a round: for each signal, track strength in hits vs misses.
     */
    protected function gradeRound(Round $round): array
    {
        $matches = Matchup::with(['predictions', 'tryEvents'])
            ->where('round_id', $round->id)
            ->where('status', 'completed')
            ->get();

        $signalStats = []; // type => [hit_sum, miss_sum, hit_count, miss_count]

        foreach ($matches as $match) {
            $scorerIds = $match->tryEvents->pluck('player_id')->unique();

            foreach ($match->predictions as $pred) {
                $isHit = $scorerIds->contains($pred->player_id);

                foreach ($pred->signals ?? [] as $signal) {
                    $type = $signal['type'];
                    $strength = $signal['strength'] ?? 0;

                    if (! isset($signalStats[$type])) {
                        $signalStats[$type] = ['hit_sum' => 0, 'miss_sum' => 0, 'hit_count' => 0, 'miss_count' => 0];
                    }

                    if ($isHit) {
                        $signalStats[$type]['hit_sum'] += $strength;
                        $signalStats[$type]['hit_count']++;
                    } else {
                        $signalStats[$type]['miss_sum'] += $strength;
                        $signalStats[$type]['miss_count']++;
                    }
                }
            }
        }

        return $signalStats;
    }

    protected function logSignalPerformance(int $season, int $roundNumber, array $signalStats): void
    {
        foreach ($signalStats as $type => $stats) {
            $hitAvg = $stats['hit_count'] > 0 ? $stats['hit_sum'] / $stats['hit_count'] : 0;
            $missAvg = $stats['miss_count'] > 0 ? $stats['miss_sum'] / $stats['miss_count'] : 0;

            SignalPerformanceLog::updateOrCreate(
                ['season' => $season, 'round_number' => $roundNumber, 'signal_type' => $type],
                [
                    'avg_strength_hits' => round($hitAvg, 4),
                    'avg_strength_misses' => round($missAvg, 4),
                    'delta' => round($hitAvg - $missAvg, 4),
                    'sample_size' => $stats['hit_count'] + $stats['miss_count'],
                ],
            );
        }
    }

    /**
     * Compute cumulative signal deltas across recent rounds (EMA-style, recent rounds weighted more).
     */
    protected function cumulativeDeltas(int $season, int $currentRound): array
    {
        $logs = SignalPerformanceLog::where('season', $season)
            ->where('round_number', '<=', $currentRound)
            ->where('round_number', '>=', max(1, $currentRound - 3))
            ->get();

        $deltas = [];
        foreach ($logs as $log) {
            if ($log->sample_size < self::MIN_SAMPLE) {
                continue;
            }

            // More recent rounds get higher weight
            $recency = 1 - (($currentRound - $log->round_number) * 0.2);
            $recency = max(0.4, $recency);

            if (! isset($deltas[$log->signal_type])) {
                $deltas[$log->signal_type] = ['weighted_sum' => 0, 'weight_sum' => 0];
            }
            $deltas[$log->signal_type]['weighted_sum'] += $log->delta * $recency;
            $deltas[$log->signal_type]['weight_sum'] += $recency;
        }

        $result = [];
        foreach ($deltas as $type => $data) {
            $result[$type] = $data['weight_sum'] > 0 ? round($data['weighted_sum'] / $data['weight_sum'], 4) : 0;
        }

        return $result;
    }

    /**
     * Adjust weights based on cumulative deltas.
     * Positive delta = signal is predictive → increase weight.
     * Negative delta = signal is misleading → decrease weight.
     */
    protected function adjustWeights(array $currentWeights, array $deltas): array
    {
        $newWeights = $currentWeights;

        foreach ($deltas as $type => $delta) {
            if (! isset($currentWeights[$type])) {
                continue;
            }

            $current = $currentWeights[$type];

            // Scale adjustment by delta magnitude
            // delta of +0.1 means signal was 10% stronger in hits than misses
            $adjustmentPct = min(self::MAX_ADJUSTMENT_PCT, max(-self::MAX_ADJUSTMENT_PCT, $delta));
            $newWeight = $current * (1 + $adjustmentPct);

            // Clamp
            $newWeight = max(self::MIN_WEIGHT, min(self::MAX_WEIGHT, round($newWeight)));

            $newWeights[$type] = (int) $newWeight;
        }

        return $newWeights;
    }

    protected function computeAccuracy(Round $round): float
    {
        $matches = Matchup::with(['predictions', 'tryEvents'])
            ->where('round_id', $round->id)
            ->where('status', 'completed')
            ->get();

        $hits = 0;
        $total = 0;

        foreach ($matches as $match) {
            $scorerIds = $match->tryEvents->pluck('player_id')->unique();
            foreach ($match->predictions->sortBy('rank_in_match')->take(5) as $pred) {
                $total++;
                if ($scorerIds->contains($pred->player_id)) {
                    $hits++;
                }
            }
        }

        return $total > 0 ? round($hits / $total * 100, 1) : 0;
    }

    protected function persistAdjustment(int $season, int $round, array $old, array $new, array $deltas, float $accuracy, array $calibration = []): WeightAdjustment
    {
        $changed = [];
        foreach ($new as $type => $weight) {
            if (($old[$type] ?? 0) !== $weight) {
                $changed[$type] = ['from' => $old[$type] ?? 0, 'to' => $weight, 'delta' => $deltas[$type] ?? 0];
            }
        }

        $reasoning = $this->buildReasoning($changed, $accuracy, $calibration);

        return WeightAdjustment::create([
            'season' => $season,
            'after_round' => $round,
            'old_weights' => $old,
            'new_weights' => $new,
            'signal_deltas' => $deltas,
            'accuracy_before' => $accuracy,
            'brier_score' => $calibration['brier'] ?? null,
            'log_loss' => $calibration['log_loss'] ?? null,
            'value_score' => $calibration['value_score'] ?? null,
            'market_brier' => $calibration['market_brier'] ?? null,
            'market_log_loss' => $calibration['market_log_loss'] ?? null,
            'graded_predictions' => $calibration['graded'] ?? null,
            'reasoning' => $reasoning,
        ]);
    }

    protected function buildReasoning(array $changed, float $accuracy, array $calibration = []): string
    {
        $lines = ["Round accuracy: {$accuracy}%"];

        if (! empty($calibration['summary'])) {
            $lines[] = $calibration['summary'];
        }

        if (empty($changed)) {
            $lines[] = 'No weight changes — all signals performing within normal range.';
            return implode("\n", $lines);
        }

        foreach ($changed as $type => $info) {
            $direction = $info['to'] > $info['from'] ? 'increased' : 'decreased';
            $lines[] = sprintf(
                '%s: %d → %d (%s, delta=%.3f)',
                str_replace('_', ' ', $type),
                $info['from'], $info['to'], $direction, $info['delta']
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Write adjusted weights back to config/nrl-weights.php.
     */
    protected function writeWeightsToConfig(array $newWeights): void
    {
        $configPath = config_path('nrl-weights.php');
        if (! file_exists($configPath)) {
            return;
        }

        $config = require $configPath;
        $config['try_scorer'] = $newWeights;

        $export = var_export($config, true);
        // Clean up var_export formatting
        $export = preg_replace('/array \(/', '[', $export);
        $export = preg_replace('/\)/', ']', $export);
        $export = preg_replace("/=> \n\s+\[/", '=> [', $export);

        $content = "<?php\n\nreturn {$export};\n";
        file_put_contents($configPath, $content);
    }

    /**
     * Generate insights for the Claude agent prompt.
     */
    protected function generateInsights(array $deltas, array $roundStats, float $accuracy, array $calibration = []): string
    {
        $insights = ["Current model accuracy: {$accuracy}% (top-5 hit rate)"];

        // Calibration headline — answers "are our probabilities honest" and
        // "do we beat the market" in a single block the agent can quote.
        if (! empty($calibration) && ($calibration['graded'] ?? 0) > 0) {
            if ($calibration['brier'] !== null) {
                $insights[] = sprintf('Brier score: %.3f (lower is better; random=0.250)', $calibration['brier']);
            }
            if ($calibration['log_loss'] !== null) {
                $insights[] = sprintf('Log loss: %.3f', $calibration['log_loss']);
            }
            if ($calibration['market_brier'] !== null) {
                $verdict = $calibration['beats_market'] ? 'MODEL BEATS MARKET' : 'market beats model';
                $insights[] = sprintf(
                    'Market Brier: %.3f → %s by %.3f',
                    $calibration['market_brier'],
                    $verdict,
                    abs($calibration['brier'] - $calibration['market_brier']),
                );
            }
            if ($calibration['value_score'] !== null) {
                $sign = $calibration['value_score'] >= 0 ? '+' : '';
                $verdict = $calibration['value_score'] > 0.01
                    ? 'model has alpha beyond market'
                    : ($calibration['value_score'] < -0.01 ? 'model is contradicting market unprofitably' : 'flat');
                $insights[] = sprintf('Value vs market: %s%.3f (%s)', $sign, $calibration['value_score'], $verdict);
            }
        }

        // Best performing signals
        arsort($deltas);
        $best = array_slice($deltas, 0, 3, true);
        if ($best) {
            $insights[] = 'Strongest signals this period:';
            foreach ($best as $type => $delta) {
                if ($delta > 0) {
                    $insights[] = sprintf('  - %s (delta +%.3f)', str_replace('_', ' ', $type), $delta);
                }
            }
        }

        // Worst performing
        $worst = array_slice(array_reverse($deltas, true), 0, 3, true);
        $hasNegative = false;
        foreach ($worst as $type => $delta) {
            if ($delta < -0.02) {
                if (! $hasNegative) {
                    $insights[] = 'Underperforming signals (consider less weight):';
                    $hasNegative = true;
                }
                $insights[] = sprintf('  - %s (delta %.3f)', str_replace('_', ' ', $type), $delta);
            }
        }

        return implode("\n", $insights);
    }
}
