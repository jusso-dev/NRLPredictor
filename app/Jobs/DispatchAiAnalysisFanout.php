<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues one AnalyseMatchWithAi per upcoming match in the current round
 * that already has statistical predictions. Skips matches with no predictions —
 * nothing for the AI to adjust.
 */
class DispatchAiAnalysisFanout implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 60;
    public int $tries = 1;
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'ai-analysis:fanout';
    }

    public function handle(): void
    {
        $this->startLog('internal.ai-fanout');
        $queued = 0;
        $skipped = 0;

        try {
            $matches = Matchup::upcomingInCurrentRound()
                ->with(['homeTeam', 'awayTeam'])
                ->withCount('predictions')
                ->get();

            foreach ($matches as $match) {
                if ($match->predictions_count === 0) {
                    Log::info(sprintf(
                        'DispatchAiAnalysisFanout: skipping match %d (%s v %s) — no predictions to adjust',
                        $match->id,
                        $match->homeTeam?->short_name ?? '?',
                        $match->awayTeam?->short_name ?? '?',
                    ));
                    $skipped++;
                    continue;
                }
                AnalyseMatchWithAi::dispatch($match->id);
                $queued++;
            }

            Log::info("DispatchAiAnalysisFanout: queued {$queued}, skipped {$skipped}");
            $this->completeLog($queued);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }
}
