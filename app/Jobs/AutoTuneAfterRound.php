<?php

namespace App\Jobs;

use App\Models\Matchup;
use App\Models\Round;
use App\Models\WeightAdjustment;
use App\Services\SignalTuner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Automatically runs after all matches in a round complete.
 * Grades predictions, adjusts signal weights, and logs learnings.
 *
 * Triggered by the scheduler — finds the oldest eligible completed round
 * that has not been tuned yet, then runs the tuner.
 */
class AutoTuneAfterRound implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $uniqueFor = 300; // > worst case: 120s timeout; short so a crashed run cannot block the next hourly one

    public function uniqueId(): string
    {
        return 'auto-tune';
    }

    public function handle(SignalTuner $tuner): void
    {
        $season = now()->year;

        // Tune the oldest eligible gap first. Selecting only the latest round
        // permanently skipped earlier rounds after outages or restored backups.
        $tunedRounds = WeightAdjustment::where('season', $season)->pluck('after_round');
        $completedRound = Round::where('season', $season)
            ->whereNotIn('round_number', $tunedRounds)
            ->whereHas('matches', fn ($q) => $q->where('status', 'completed'))
            ->whereHas('matches', fn ($q) => $q
                ->where('status', 'completed')
                ->whereHas('predictions')
                ->whereHas('tryEvents'))
            ->whereDoesntHave('matches', fn ($q) => $q->whereIn('status', ['upcoming', 'live']))
            ->orderBy('round_number')
            ->first();

        if (! $completedRound) {
            return;
        }

        Log::info("AutoTune: running tuner for R{$completedRound->round_number}");
        $result = $tuner->tuneAfterRound($season, $completedRound->round_number);
        Log::info("AutoTune: completed. Accuracy={$result['accuracy']}%, {$result['weights_changed']} weights changed");

        // Apply statistical and AI learning to upcoming suggestions immediately.
        // Per-match IDs avoid colliding with the scheduled current-round scorer.
        Matchup::upcomingInCurrentRound()->pluck('id')->each(function (int $matchId) {
            Bus::chain([
                new RunPredictionAnalysis($matchId),
                new AnalyseMatchWithAi($matchId),
            ])->dispatch();
        });
    }
}
