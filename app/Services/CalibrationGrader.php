<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\WeightAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calibration + market-divergence grader. Runs after a round completes and
 * answers three questions:
 *
 *   1. Are our probabilities honest? (Brier / log loss)
 *   2. Do we have edge beyond the bookmaker market? (value_score)
 *   3. Does the model beat the market head-to-head? (market_brier comparison)
 *
 * Per-prediction grades land on the predictions table (model_prob, market_prob,
 * was_hit). Round-level aggregates feed into SignalTuner so weight changes can
 * be evaluated against true-probability metrics, not just hit-rate.
 *
 * Probability mapping: model_prob is rank-conditioned — we use the empirical
 * hit rate at each rank across prior completed rounds. This is honest (gets
 * better with more data) and avoids hand-tuning a score→prob function.
 */
class CalibrationGrader
{
    // Number of top predictions per match included in aggregate metrics.
    // Matches the existing accuracy computation so the two are comparable.
    protected const TOP_N = 5;

    // Need this many prior observations at a rank before we trust the empirical
    // hit rate; otherwise fall back to the linear prior.
    protected const MIN_RANK_SAMPLES = 20;

    // Need this many total prior (score, was_hit) pairs before we trust a
    // fitted logistic regression. Below this we use rank-conditioned only.
    protected const MIN_LOGISTIC_SAMPLES = 100;

    // Newton-Raphson iterations for the logistic fit. Two features (intercept +
    // score) is convex and converges in well under 50 iterations in practice.
    protected const LOGISTIC_MAX_ITER = 50;
    protected const LOGISTIC_TOL = 1e-6;

    // Clip probabilities before log() to keep log loss finite on a single bad call.
    protected const LOG_EPS = 0.001;

    // Cache the latest persisted logistic per service instance so PredictionScorer
    // doesn't re-query weight_adjustments for every match in a round.
    protected ?array $cachedLatestLogistic = null;
    protected bool $latestLogisticLoaded = false;

    /**
     * Grade every prediction in a completed round and return aggregate metrics.
     */
    public function gradeRound(int $season, int $roundNumber): array
    {
        $round = Round::where('season', $season)
            ->where('round_number', $roundNumber)
            ->first();

        if (! $round) {
            return $this->emptyResult('Round not found');
        }

        $rankTable = $this->buildRankCalibrationTable($season, $roundNumber);
        $logisticFit = $this->fitLogisticModel($season, $roundNumber);
        $logistic = $logisticFit === null ? null : [$logisticFit['b0'], $logisticFit['b1']];

        $matches = Matchup::with(['predictions', 'tryEvents'])
            ->where('round_id', $round->id)
            ->where('status', 'completed')
            ->get();

        $perPredictionGrades = [];
        $topNRows = []; // only top-N per match feed the aggregate metrics

        foreach ($matches as $match) {
            $scorerIds = $match->tryEvents->pluck('player_id')->unique();
            $marketProbs = $this->marketProbsForMatch($match->id);

            $sortedPredictions = $match->predictions->sortBy('rank_in_match')->values();

            foreach ($sortedPredictions as $idx => $pred) {
                $rank = $pred->rank_in_match;
                $wasHit = $scorerIds->contains($pred->player_id) ? 1 : 0;
                $modelProb = $this->modelProbForPrediction((int) $pred->score, $rank, $logistic, $rankTable);
                $marketProb = $marketProbs[$pred->player_id] ?? null;

                $perPredictionGrades[$pred->id] = [
                    'model_prob' => round($modelProb, 4),
                    'market_prob' => $marketProb !== null ? round($marketProb, 4) : null,
                    'was_hit' => $wasHit,
                ];

                if ($idx < self::TOP_N) {
                    $topNRows[] = [
                        'model_prob' => $modelProb,
                        'market_prob' => $marketProb,
                        'was_hit' => $wasHit,
                    ];
                }
            }
        }

        $this->persistPerPredictionGrades($perPredictionGrades);

        $metrics = $this->aggregateMetrics($topNRows);
        $metrics['summary'] = $this->buildSummary($metrics);
        $metrics['logistic'] = $logisticFit; // [b0, b1, samples] or null — SignalTuner persists this

        Log::info(sprintf(
            'CalibrationGrader: R%d graded %d predictions. %s',
            $roundNumber,
            $metrics['graded'],
            $metrics['summary'],
        ));

        return $metrics;
    }

    /**
     * Build a rank -> hit-rate map from all previously graded predictions in this
     * season (and prior seasons as a fallback). Returns ranks 1..15.
     */
    protected function buildRankCalibrationTable(int $season, int $excludeRound): array
    {
        // Pull all graded predictions before this round, this season first.
        $rows = DB::table('predictions')
            ->join('matches', 'matches.id', '=', 'predictions.match_id')
            ->join('rounds', 'rounds.id', '=', 'matches.round_id')
            ->whereNotNull('predictions.was_hit')
            ->where(function ($q) use ($season, $excludeRound) {
                $q->where('rounds.season', '<', $season)
                  ->orWhere(function ($q2) use ($season, $excludeRound) {
                      $q2->where('rounds.season', $season)
                         ->where('rounds.round_number', '<', $excludeRound);
                  });
            })
            ->select('predictions.rank_in_match', 'predictions.was_hit')
            ->get();

        $byRank = [];
        foreach ($rows as $row) {
            $r = (int) $row->rank_in_match;
            $byRank[$r] ??= ['hits' => 0, 'total' => 0];
            $byRank[$r]['total']++;
            $byRank[$r]['hits'] += (int) $row->was_hit;
        }

        $table = [];
        for ($r = 1; $r <= 15; $r++) {
            $table[$r] = isset($byRank[$r]) && $byRank[$r]['total'] >= self::MIN_RANK_SAMPLES
                ? $byRank[$r]['hits'] / $byRank[$r]['total']
                : null; // fallback to prior below
        }

        return $table;
    }

    /**
     * Probability cascade — tries the richest data source first:
     *   1. Fitted logistic regression sigmoid(b0 + b1 * score/100)
     *   2. Rank-conditioned empirical hit rate from prior rounds
     *   3. Linear cold-start prior
     *
     * Score carries more information than rank alone — it captures the gap
     * between top picks, not just their ordering — so the logistic fit
     * generalises better as soon as we have ~100+ graded predictions.
     */
    protected function modelProbForPrediction(int $score, int $rank, ?array $logistic, array $rankTable): float
    {
        if ($logistic !== null) {
            $x = max(0, min(100, $score)) / 100;
            $z = $logistic[0] + $logistic[1] * $x;
            return max(0.01, min(0.95, 1.0 / (1.0 + exp(-$z))));
        }

        $rank = max(1, min(15, $rank));
        if (isset($rankTable[$rank])) {
            return max(0.01, min(0.95, $rankTable[$rank]));
        }

        // Cold-start prior: rank 1 ≈ 40% try chance, declining ~2pp per rank.
        // Anchored to top-5 hit rate of ~35-40% seen empirically for NRL try
        // scorers when the model has any edge.
        return max(0.05, 0.40 - ($rank - 1) * 0.02);
    }

    /**
     * Fit a 2-parameter logistic model: P(hit) = sigmoid(b0 + b1 * score/100)
     * over all graded predictions before this round. Uses Newton-Raphson (IRLS)
     * with a small ridge term so the Hessian stays invertible on small samples.
     *
     * Returns ['b0' => x, 'b1' => y, 'samples' => n] or null if there isn't
     * enough data or the fit fails to converge.
     */
    protected function fitLogisticModel(int $season, int $excludeRound): ?array
    {
        $rows = DB::table('predictions')
            ->join('matches', 'matches.id', '=', 'predictions.match_id')
            ->join('rounds', 'rounds.id', '=', 'matches.round_id')
            ->whereNotNull('predictions.was_hit')
            ->where(function ($q) use ($season, $excludeRound) {
                $q->where('rounds.season', '<', $season)
                  ->orWhere(function ($q2) use ($season, $excludeRound) {
                      $q2->where('rounds.season', $season)
                         ->where('rounds.round_number', '<', $excludeRound);
                  });
            })
            ->select('predictions.score', 'predictions.was_hit')
            ->get();

        if ($rows->count() < self::MIN_LOGISTIC_SAMPLES) {
            return null;
        }

        // Build feature matrix and label vector.
        $xs = [];
        $ys = [];
        foreach ($rows as $row) {
            $xs[] = max(0, min(100, (int) $row->score)) / 100;
            $ys[] = (int) $row->was_hit;
        }
        $n = count($xs);

        $b0 = 0.0;
        $b1 = 0.0;

        // Newton-Raphson on 2x2 system with ridge 1e-4 for numerical stability.
        // For each iter: gradient g = X^T (p - y); hessian H = X^T W X (W diag p(1-p));
        // beta_new = beta - H^{-1} g.
        for ($iter = 0; $iter < self::LOGISTIC_MAX_ITER; $iter++) {
            $g0 = 0.0; $g1 = 0.0;
            $h00 = 0.0; $h01 = 0.0; $h11 = 0.0;

            for ($i = 0; $i < $n; $i++) {
                $z = $b0 + $b1 * $xs[$i];
                $p = 1.0 / (1.0 + exp(-$z));
                $r = $p - $ys[$i];
                $w = $p * (1 - $p);

                $g0 += $r;
                $g1 += $r * $xs[$i];

                $h00 += $w;
                $h01 += $w * $xs[$i];
                $h11 += $w * $xs[$i] * $xs[$i];
            }

            // Ridge
            $h00 += 1e-4;
            $h11 += 1e-4;

            $det = $h00 * $h11 - $h01 * $h01;
            if (abs($det) < 1e-12) {
                return null; // singular — bail out, caller falls back
            }

            $invH00 =  $h11 / $det;
            $invH01 = -$h01 / $det;
            $invH11 =  $h00 / $det;

            $step0 = $invH00 * $g0 + $invH01 * $g1;
            $step1 = $invH01 * $g0 + $invH11 * $g1;

            $b0 -= $step0;
            $b1 -= $step1;

            if (abs($step0) < self::LOGISTIC_TOL && abs($step1) < self::LOGISTIC_TOL) {
                return ['b0' => $b0, 'b1' => $b1, 'samples' => $n];
            }
        }

        // Did not converge — return the last estimate anyway if the slope is
        // sensible (positive: higher score should mean higher hit rate).
        return $b1 > 0 ? ['b0' => $b0, 'b1' => $b1, 'samples' => $n] : null;
    }

    /**
     * Calibrated try probability for a single prediction, using the most
     * recently persisted logistic from weight_adjustments (cached per
     * instance). Falls through to a linear prior on rank when no logistic
     * has been fit yet.
     *
     * Public so PredictionScorer can fill model_prob at write time without
     * waiting for the round-after grading pass.
     */
    public function probForPrediction(int $score, int $rank): float
    {
        $logistic = $this->latestLogistic();
        $logisticPair = $logistic === null ? null : [$logistic['b0'], $logistic['b1']];

        // Empty rank table is fine — modelProbForPrediction falls through to
        // the linear prior. We could load the rank table here too, but the
        // logistic kicks in well before rank-conditioning becomes informative.
        return $this->modelProbForPrediction($score, $rank, $logisticPair, []);
    }

    /**
     * Latest persisted logistic coefficients, cached for this service instance.
     * Returns ['b0' => x, 'b1' => y, 'samples' => n] or null if none on file.
     */
    public function latestLogistic(): ?array
    {
        if ($this->latestLogisticLoaded) {
            return $this->cachedLatestLogistic;
        }

        $row = WeightAdjustment::whereNotNull('logistic_b0')
            ->whereNotNull('logistic_b1')
            ->orderByDesc('after_round')
            ->orderByDesc('id')
            ->first();

        $this->cachedLatestLogistic = $row
            ? ['b0' => (float) $row->logistic_b0, 'b1' => (float) $row->logistic_b1, 'samples' => (int) ($row->logistic_samples ?? 0)]
            : null;
        $this->latestLogisticLoaded = true;

        return $this->cachedLatestLogistic;
    }

    /**
     * Public alias for marketProbsForMatch so PredictionScorer can fill
     * market_prob at write time using the same median-of-books logic.
     */
    public function marketProbsFor(int $matchId): array
    {
        return $this->marketProbsForMatch($matchId);
    }

    /**
     * Median bookmaker-implied try-scorer probability per player in this match.
     * Mirrors the median-of-books logic SignalCalculator uses so model_prob and
     * market_prob live on the same scale.
     */
    protected function marketProbsForMatch(int $matchId): array
    {
        $oddsByPlayer = OddsSnapshot::where('match_id', $matchId)
            ->where('market', 'ats')
            ->where('decimal_odds', '>', 1.0)
            ->whereNotNull('player_id')
            ->get(['player_id', 'decimal_odds'])
            ->groupBy('player_id');

        $result = [];
        foreach ($oddsByPlayer as $playerId => $rows) {
            $probs = $rows->map(fn ($r) => 1.0 / $r->decimal_odds)->sort()->values()->all();
            if (count($probs) < 2) {
                continue; // thin market — treat as unknown rather than noisy
            }
            $mid = intdiv(count($probs), 2);
            $median = count($probs) % 2 === 0
                ? ($probs[$mid - 1] + $probs[$mid]) / 2
                : $probs[$mid];
            $result[(int) $playerId] = $median;
        }

        return $result;
    }

    protected function persistPerPredictionGrades(array $grades): void
    {
        if (empty($grades)) {
            return;
        }

        DB::transaction(function () use ($grades) {
            foreach ($grades as $predictionId => $fields) {
                Prediction::where('id', $predictionId)->update($fields);
            }
        });
    }

    /**
     * Compute Brier, log loss, and the value_score (model vs market) over the
     * top-N predictions per match.
     */
    protected function aggregateMetrics(array $rows): array
    {
        $graded = count($rows);
        if ($graded === 0) {
            return $this->emptyResult('No completed predictions to grade');
        }

        $brierSum = 0.0;
        $logLossSum = 0.0;
        $marketBrierSum = 0.0;
        $marketLogLossSum = 0.0;
        $marketGraded = 0;

        $hitDeltas = [];   // model_prob - market_prob on hits
        $missDeltas = [];  // model_prob - market_prob on misses

        foreach ($rows as $row) {
            $p = $row['model_prob'];
            $y = $row['was_hit'];

            $brierSum += ($p - $y) ** 2;
            $logLossSum += $this->logLossContribution($p, $y);

            if ($row['market_prob'] !== null) {
                $m = $row['market_prob'];
                $marketBrierSum += ($m - $y) ** 2;
                $marketLogLossSum += $this->logLossContribution($m, $y);
                $marketGraded++;

                if ($y === 1) {
                    $hitDeltas[] = $p - $m;
                } else {
                    $missDeltas[] = $p - $m;
                }
            }
        }

        $brier = $brierSum / $graded;
        $logLoss = $logLossSum / $graded;
        $marketBrier = $marketGraded > 0 ? $marketBrierSum / $marketGraded : null;
        $marketLogLoss = $marketGraded > 0 ? $marketLogLossSum / $marketGraded : null;

        $valueScore = null;
        if (! empty($hitDeltas) && ! empty($missDeltas)) {
            $valueScore = (array_sum($hitDeltas) / count($hitDeltas))
                        - (array_sum($missDeltas) / count($missDeltas));
        }

        return [
            'graded' => $graded,
            'brier' => round($brier, 4),
            'log_loss' => round($logLoss, 4),
            'market_brier' => $marketBrier !== null ? round($marketBrier, 4) : null,
            'market_log_loss' => $marketLogLoss !== null ? round($marketLogLoss, 4) : null,
            'value_score' => $valueScore !== null ? round($valueScore, 4) : null,
            'market_graded' => $marketGraded,
            'beats_market' => $marketBrier !== null ? $brier < $marketBrier : null,
            'summary' => null,
        ];
    }

    protected function logLossContribution(float $p, int $y): float
    {
        $p = max(self::LOG_EPS, min(1 - self::LOG_EPS, $p));
        return -($y * log($p) + (1 - $y) * log(1 - $p));
    }

    protected function buildSummary(array $m): string
    {
        if ($m['graded'] === 0) {
            return 'No predictions to grade';
        }

        $parts = [
            sprintf('Brier=%.3f', $m['brier']),
            sprintf('LogLoss=%.3f', $m['log_loss']),
        ];

        if ($m['market_brier'] !== null) {
            $parts[] = sprintf('MarketBrier=%.3f', $m['market_brier']);
            $verdict = $m['beats_market']
                ? 'model beats market'
                : 'market beats model';
            $parts[] = $verdict;
        }

        if ($m['value_score'] !== null) {
            $sign = $m['value_score'] >= 0 ? '+' : '';
            $parts[] = sprintf('value=%s%.3f', $sign, $m['value_score']);
        }

        return implode(' ', $parts);
    }

    protected function emptyResult(string $reason): array
    {
        return [
            'graded' => 0,
            'brier' => null,
            'log_loss' => null,
            'market_brier' => null,
            'market_log_loss' => null,
            'value_score' => null,
            'market_graded' => 0,
            'beats_market' => null,
            'summary' => $reason,
        ];
    }
}
