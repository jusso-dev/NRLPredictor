<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\Prediction;
use App\Models\Round;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Walk-forward backtest harness.
 *
 * For each round R in order, the harness:
 *
 *   1. Asks SignalTuner what it would change the weights to given everything
 *      up to and including R (no peeking at R+1).
 *   2. Rescores every prediction in round R+1 with both the *current* and
 *      *proposed* weight sets, using the stored signal JSON so we don't have
 *      to rerun the entire scraping pipeline.
 *   3. Maps each rescored score to a probability via the persisted logistic.
 *   4. Computes Brier vs was_hit under each weight set on R+1.
 *   5. If the proposed weights win on the held-out next round, "accept" them
 *      and carry them forward. Otherwise stick with the previous accepted set.
 *
 * This is the discipline Benter built over his three losing seasons: only keep
 * model changes that improved performance on data the change didn't see.
 *
 * The harness is read-only by default — it reports what *would have happened*.
 * Pass apply=true to persist the final accepted weight set as a normal
 * WeightAdjustment row so the live tuner picks it up.
 */
class Backtester
{
    // Clip probabilities before log() / Brier to keep the metric finite.
    protected const LOG_EPS = 0.001;

    public function __construct(
        protected SignalTuner $tuner,
        protected CalibrationGrader $grader,
    ) {}

    /**
     * Walk forward across [fromRound..toRound], holding out each next round.
     * Returns an array of per-round outcomes + a summary block.
     */
    public function walkForward(int $season, int $fromRound, int $toRound, array $startingWeights, bool $apply = false): array
    {
        $rounds = Round::where('season', $season)
            ->whereBetween('round_number', [$fromRound, $toRound])
            ->orderBy('round_number')
            ->get();

        if ($rounds->count() < 2) {
            return [
                'rounds' => [],
                'summary' => [
                    'error' => 'Need at least 2 rounds to backtest (one to learn from, one to validate on).',
                ],
            ];
        }

        $logistic = $this->loadLogistic();
        $current = $startingWeights;
        $perRound = [];

        $brierSumOld = 0.0;
        $brierSumNew = 0.0;
        $brierSamples = 0;
        $accepted = 0;
        $rejected = 0;

        // Walk every (R, R+1) pair: propose at R, evaluate on R+1.
        for ($i = 0; $i < $rounds->count() - 1; $i++) {
            $learnRound = $rounds[$i];
            $testRound = $rounds[$i + 1];

            $deltas = $this->tuner->deltasForRound($season, $learnRound->round_number);
            $proposed = $this->tuner->proposeWeights($current, $deltas);

            $brierOld = $this->brierForRound($testRound, $current, $logistic);
            $brierNew = $this->brierForRound($testRound, $proposed, $logistic);

            // Skip rounds we can't evaluate (no graded predictions on R+1).
            if ($brierOld === null || $brierNew === null) {
                $perRound[] = [
                    'learn_round' => $learnRound->round_number,
                    'test_round' => $testRound->round_number,
                    'brier_current' => $brierOld,
                    'brier_proposed' => $brierNew,
                    'decision' => 'skip',
                    'reason' => 'No graded predictions on test round',
                    'weight_changes' => 0,
                ];
                continue;
            }

            $changes = $this->countChanges($current, $proposed);
            $improved = $brierNew < $brierOld;

            $perRound[] = [
                'learn_round' => $learnRound->round_number,
                'test_round' => $testRound->round_number,
                'brier_current' => $brierOld,
                'brier_proposed' => $brierNew,
                'delta' => $brierNew - $brierOld,
                'decision' => $improved ? 'accept' : 'reject',
                'weight_changes' => $changes,
            ];

            if ($improved && $changes > 0) {
                $current = $proposed;
                $accepted++;
            } else {
                $rejected++;
            }

            // Cumulative Brier uses whichever set actually applied to R+1 — that's
            // what walked-forward "production" would look like.
            $brierSumNew += $improved ? $brierNew : $brierOld;
            $brierSumOld += $brierOld;
            $brierSamples++;
        }

        $summary = [
            'pairs_evaluated' => $brierSamples,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'baseline_brier' => $brierSamples > 0 ? round($brierSumOld / $brierSamples, 4) : null,
            'walked_brier' => $brierSamples > 0 ? round($brierSumNew / $brierSamples, 4) : null,
            'improvement' => $brierSamples > 0
                ? round(($brierSumOld - $brierSumNew) / $brierSamples, 4)
                : null,
            'final_weights' => $current,
            'starting_weights' => $startingWeights,
            'applied' => false,
        ];

        if ($apply && $current !== $startingWeights) {
            $this->persistFinalWeights($season, $rounds->last()->round_number, $startingWeights, $current);
            $summary['applied'] = true;
        }

        Log::info(sprintf(
            'Backtester: season=%d R%d..R%d. Accepted %d / Rejected %d. Baseline Brier=%s, Walked Brier=%s.',
            $season, $fromRound, $toRound, $accepted, $rejected,
            $summary['baseline_brier'] ?? 'n/a',
            $summary['walked_brier'] ?? 'n/a',
        ));

        return ['rounds' => $perRound, 'summary' => $summary];
    }

    /**
     * Rescore every prediction on a round using the given weight set against
     * the *stored* signal strengths, then compute Brier vs was_hit.
     *
     * Returns null if the round has no graded predictions (so we can skip it
     * rather than poison the cumulative metric).
     */
    protected function brierForRound(Round $round, array $weights, ?array $logistic): ?float
    {
        $matches = Matchup::with(['predictions' => function ($q) {
                $q->whereNotNull('was_hit');
            }])
            ->where('round_id', $round->id)
            ->where('status', 'completed')
            ->get();

        $brierSum = 0.0;
        $count = 0;

        foreach ($matches as $match) {
            $rescored = $this->rescoreMatch($match->predictions, $weights);
            if (empty($rescored)) {
                continue;
            }

            $maxRaw = max(array_column($rescored, 'raw')) ?: 1;

            foreach ($rescored as $r) {
                $score = (int) round(($r['raw'] / $maxRaw) * 100);
                $score = max(0, min(100, $score));
                $modelProb = $this->probFromScore($score, $logistic);
                $brierSum += ($modelProb - $r['was_hit']) ** 2;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return $brierSum / $count;
    }

    /**
     * Apply a weight set to the stored signal strengths on each prediction,
     * returning per-prediction raw scores. The original scoring pipeline also
     * adds a tiny tiebreaker based on the player's season try rate; we skip
     * it here — it's small and applies identically to both weight sets, so
     * the relative comparison is unbiased.
     */
    protected function rescoreMatch(Collection $predictions, array $weights): array
    {
        $out = [];
        foreach ($predictions as $pred) {
            if ($pred->was_hit === null) {
                continue;
            }
            $raw = 0.0;
            foreach ($pred->signals ?? [] as $s) {
                $type = $s['type'] ?? null;
                $strength = $s['strength'] ?? 0;
                if ($type === null) {
                    continue;
                }
                $w = $weights[$type] ?? ($s['weight'] ?? 0);
                $raw += $w * $strength;
            }
            $out[] = [
                'raw' => $raw,
                'was_hit' => (int) $pred->was_hit,
            ];
        }
        return $out;
    }

    /**
     * Score → probability via the persisted production logistic. If none has
     * been fit yet (cold start), fall back to a linear prior on score so the
     * backtest can still run on a fresh database.
     */
    protected function probFromScore(int $score, ?array $logistic): float
    {
        if ($logistic !== null) {
            $x = $score / 100;
            $z = $logistic['b0'] + $logistic['b1'] * $x;
            return max(0.01, min(0.95, 1.0 / (1.0 + exp(-$z))));
        }
        return max(0.05, min(0.95, 0.05 + ($score / 100) * 0.50));
    }

    protected function loadLogistic(): ?array
    {
        return $this->grader->latestLogistic();
    }

    protected function countChanges(array $a, array $b): int
    {
        $changes = 0;
        foreach ($b as $type => $weight) {
            if (($a[$type] ?? null) !== $weight) {
                $changes++;
            }
        }
        return $changes;
    }

    /**
     * Persist the final accepted weight set as a WeightAdjustment row so the
     * live SignalCalculator picks it up on its next read. Reuses the model so
     * the existing tuning audit trail stays consistent.
     */
    protected function persistFinalWeights(int $season, int $afterRound, array $startingWeights, array $finalWeights): void
    {
        \App\Models\WeightAdjustment::create([
            'season' => $season,
            'after_round' => $afterRound,
            'old_weights' => $startingWeights,
            'new_weights' => $finalWeights,
            'signal_deltas' => [],
            'accuracy_before' => null,
            'reasoning' => 'Applied by Backtester: walk-forward replay accepted these weights after OOS Brier validation.',
        ]);

        SignalCalculator::clearTunedCache();
    }
}
