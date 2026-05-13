<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Round;
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

    // Clip probabilities before log() to keep log loss finite on a single bad call.
    protected const LOG_EPS = 0.001;

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

        $calibration = $this->buildRankCalibrationTable($season, $roundNumber);

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
                $modelProb = $this->modelProbForRank($rank, $calibration);
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

    protected function modelProbForRank(int $rank, array $calibration): float
    {
        $rank = max(1, min(15, $rank));
        if (isset($calibration[$rank])) {
            return max(0.01, min(0.95, $calibration[$rank]));
        }

        // Cold-start prior: rank 1 ≈ 40% try chance, declining ~2pp per rank.
        // Anchored to top-5 hit rate of ~35-40% seen empirically for NRL try
        // scorers when the model has any edge.
        return max(0.05, 0.40 - ($rank - 1) * 0.02);
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
