<?php

namespace App\Services;

use App\Models\ModelAlert;
use App\Models\WeightAdjustment;
use Illuminate\Support\Facades\Log;

/**
 * Watches the rolling Brier-vs-market comparison and raises a persistent alert
 * when the model trails the bookmaker market for N consecutive rounds.
 *
 * Auto-resolves the alert the moment the model beats the market again, so the
 * banner on the Accuracy page reflects current state, not stale history.
 */
class ModelAlertChecker
{
    public const ALERT_MODEL_TRAILS_MARKET = 'model_trails_market';

    // Need this many consecutive trailing rounds before raising. Set high enough
    // that a single noisy round won't trip it, low enough to catch real drift.
    protected const CONSECUTIVE_THRESHOLD = 3;

    /**
     * Evaluate the trailing-market condition based on the last N tuning rows
     * for this season and raise or resolve the corresponding alert.
     */
    public function checkAfterTuning(int $season, int $afterRound): void
    {
        $recent = WeightAdjustment::where('season', $season)
            ->where('after_round', '<=', $afterRound)
            ->orderByDesc('after_round')
            ->limit(self::CONSECUTIVE_THRESHOLD)
            ->get();

        $existing = ModelAlert::ofType(self::ALERT_MODEL_TRAILS_MARKET)->unresolved()->first();

        if ($recent->count() < self::CONSECUTIVE_THRESHOLD) {
            // Not enough history to make a call either way. Leave existing
            // alert alone — it was raised on real evidence previously.
            return;
        }

        $allTrailing = $recent->every(fn (WeightAdjustment $a) =>
            $a->market_brier !== null
            && $a->brier_score !== null
            && $a->brier_score >= $a->market_brier
        );

        if ($allTrailing) {
            if ($existing) {
                return; // already flagged, nothing to do
            }
            $this->raiseTrailingMarketAlert($recent, $season, $afterRound);
            return;
        }

        // Condition no longer met — resolve any open alert.
        if ($existing) {
            $existing->resolve("Model beat market after R{$afterRound}");
            Log::info("ModelAlertChecker: resolved trailing-market alert after R{$afterRound}");
        }
    }

    protected function raiseTrailingMarketAlert($recent, int $season, int $afterRound): void
    {
        $rounds = $recent->pluck('after_round')->sort()->values()->all();
        $gaps = $recent->map(fn (WeightAdjustment $a) => round($a->brier_score - $a->market_brier, 4))
            ->values()
            ->all();

        $message = sprintf(
            'Model has trailed the bookmaker market for %d consecutive rounds (R%s). '
            . 'Brier gaps: %s. The model is adding noise on top of public odds — '
            . 'review signal weights or drop signals with negative cumulative deltas.',
            self::CONSECUTIVE_THRESHOLD,
            implode(', R', $rounds),
            implode(', ', array_map(fn ($g) => ($g >= 0 ? '+' : '') . number_format($g, 3), $gaps)),
        );

        ModelAlert::create([
            'type' => self::ALERT_MODEL_TRAILS_MARKET,
            'severity' => 'warning',
            'message' => $message,
            'context' => [
                'season' => $season,
                'rounds' => $rounds,
                'brier_gaps' => $gaps,
                'raised_after_round' => $afterRound,
            ],
        ]);

        Log::warning('ModelAlertChecker: ' . $message);
    }
}
