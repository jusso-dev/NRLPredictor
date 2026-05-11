<?php

namespace App\Jobs;

use App\Models\Matchup;
use App\Models\Round;
use App\Services\SignalTuner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Automatically runs after all matches in a round complete.
 * Grades predictions, adjusts signal weights, and logs learnings.
 *
 * Triggered by the scheduler — checks if the most recent completed round
 * has been tuned yet. If not, runs the tuner.
 */
class AutoTuneAfterRound implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'auto-tune';
    }

    public function handle(SignalTuner $tuner): void
    {
        $season = now()->year;

        // Find the most recently completed round (all matches finished)
        $completedRound = Round::where('season', $season)
            ->whereHas('matches', fn ($q) => $q->where('status', 'completed'))
            ->whereDoesntHave('matches', fn ($q) => $q->whereIn('status', ['upcoming', 'live']))
            ->orderByDesc('round_number')
            ->first();

        if (! $completedRound) {
            return;
        }

        // Check if we already tuned this round
        $alreadyTuned = \App\Models\WeightAdjustment::where('season', $season)
            ->where('after_round', $completedRound->round_number)
            ->exists();

        if ($alreadyTuned) {
            return;
        }

        // Check round has predictions to grade
        $hasPredictions = Matchup::where('round_id', $completedRound->id)
            ->whereHas('predictions')
            ->whereHas('tryEvents')
            ->exists();

        if (! $hasPredictions) {
            Log::info("AutoTune: R{$completedRound->round_number} has no predictions/try events to grade");
            return;
        }

        Log::info("AutoTune: running tuner for R{$completedRound->round_number}");
        $result = $tuner->tuneAfterRound($season, $completedRound->round_number);
        Log::info("AutoTune: completed. Accuracy={$result['accuracy']}%, {$result['weights_changed']} weights changed");
    }
}
