<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Services\PredictionScorer;
use App\Services\WinPredictor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fast, statistical-only scoring run for the CURRENT round's upcoming matches.
 *
 * Why only current round: matches two weeks out don't have team lists yet,
 * so scoring them produces zero predictions. We'd rather skip loudly than
 * log a misleading "success" with no output.
 */
class RunPredictionAnalysis implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 300;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(public ?int $matchId = null) {}

    public function uniqueId(): string
    {
        return 'prediction-scoring:'.($this->matchId ?? 'current-round');
    }

    public function handle(PredictionScorer $scorer, WinPredictor $winPredictor): void
    {
        $this->startLog('internal.prediction');
        $scored = 0;
        $skipped = 0;

        // The tuned-weights cache is static and this worker is a daemon —
        // without this, weights written by AutoTuneAfterRound after the
        // process started would never be picked up.
        \App\Services\SignalCalculator::clearTunedCache();

        try {
            $matches = $this->matchId
                ? Matchup::where('id', $this->matchId)->get()
                : Matchup::upcomingInCurrentRound()->get();

            if ($matches->isEmpty()) {
                Log::info('RunPredictionAnalysis: no upcoming matches in current round — nothing to score');
                $this->completeLog(0);
                return;
            }

            foreach ($matches as $match) {
                // Always run win prediction even without team lists
                $win = $winPredictor->predict($match);
                $match->update([
                    'home_win_pct' => $win['home_win_pct'],
                    'away_win_pct' => $win['away_win_pct'],
                    'predicted_winner_id' => $win['predicted_winner_id'],
                    'win_signals' => $win['win_signals'],
                ]);

                if ($match->teamLists()->count() === 0) {
                    Log::info(sprintf(
                        'RunPredictionAnalysis: skipping try-scorer scoring for match %d (%s v %s) — no team lists yet',
                        $match->id,
                        $match->homeTeam?->short_name ?? '?',
                        $match->awayTeam?->short_name ?? '?',
                    ));
                    $skipped++;
                    continue;
                }

                $predictions = $scorer->score($match->id);
                if (empty($predictions)) {
                    Log::warning(sprintf('RunPredictionAnalysis: match %d produced 0 predictions', $match->id));
                    $skipped++;
                    continue;
                }
                $scored++;
            }

            Log::info("RunPredictionAnalysis: scored {$scored}, skipped {$skipped}");
            $this->completeLog($scored);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }
}
