<?php

namespace App\Jobs;

use App\Models\Matchup;
use App\Models\Round;
use App\Services\CalibrationGrader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Backfill calibration grades on any completed round that has predictions +
 * try-events but no model_prob populated yet. Complementary to AutoTuneAfterRound
 * (which grades only the latest completed round as part of weight tuning) —
 * this catches holes from missed runs, restored backups, or historical seasons
 * loaded after the fact.
 *
 * Scheduled daily. Safe to run as often as you like: it skips already-graded
 * rounds, and ShouldBeUnique prevents concurrent runs from racing.
 */
class BackfillCalibrationGrades implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $uniqueFor = 3600;

    /**
     * Optional season filter. Null = current season.
     */
    public function __construct(public ?int $season = null) {}

    public function uniqueId(): string
    {
        return 'backfill-calibration-' . ($this->season ?? 'current');
    }

    public function handle(CalibrationGrader $grader): void
    {
        $season = $this->season ?? now()->year;

        $candidates = Round::where('season', $season)
            ->whereHas('matches', fn ($q) => $q
                ->where('status', 'completed')
                ->whereHas('predictions')
                ->whereHas('tryEvents'))
            ->orderBy('round_number')
            ->get();

        $graded = 0;
        $skipped = 0;

        foreach ($candidates as $round) {
            if ($this->alreadyGraded($round->id)) {
                $skipped++;
                continue;
            }

            $result = $grader->gradeRound($season, $round->round_number);
            $graded++;

            Log::info(sprintf(
                'BackfillCalibrationGrades: R%d graded (%d predictions). %s',
                $round->round_number,
                $result['graded'] ?? 0,
                $result['summary'] ?? '',
            ));
        }

        if ($graded === 0 && $skipped > 0) {
            Log::info("BackfillCalibrationGrades: nothing to do ({$skipped} rounds already graded).");
        }
    }

    /**
     * A round counts as already-graded if every prediction whose match has a
     * recorded try-scorer also has model_prob populated. We check the inverse:
     * are there any predictions on completed matches in this round that still
     * lack model_prob? If not, we can skip.
     */
    protected function alreadyGraded(int $roundId): bool
    {
        $ungraded = Matchup::where('round_id', $roundId)
            ->where('status', 'completed')
            ->whereHas('tryEvents')
            ->whereHas('predictions', fn ($q) => $q->whereNull('model_prob'))
            ->exists();

        return ! $ungraded;
    }
}
