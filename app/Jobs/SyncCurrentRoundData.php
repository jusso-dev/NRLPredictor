<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Round;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\ComputeMatchMetadata;
use App\Jobs\FetchMatchResults;
use App\Jobs\FetchPlayerStats;
use App\Jobs\FetchWeatherForecasts;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-shot bootstrap: pulls everything needed to render the current round
 * end-to-end. Chains the fetchers so each waits for its predecessor to
 * finish (team lists depend on the draw being there, scoring depends on
 * team lists, AI depends on predictions).
 *
 * ShouldBeUnique means repeatedly clicking "Sync" only queues one copy —
 * subsequent clicks are dropped until this one finishes.
 */
class SyncCurrentRoundData implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 60;
    public int $tries = 1;
    public int $uniqueFor = 1800; // 30 min

    public function uniqueId(): string
    {
        return 'sync:current-round';
    }

    public function handle(): void
    {
        $round = Round::current();
        if (! $round) {
            Log::warning('SyncCurrentRoundData: no current round; running a draw fetch only');
            FetchDraw::dispatch();
            return;
        }

        $this->startLog('internal.sync');

        try {
            Bus::chain([
                new FetchDraw($round->season, $round->round_number),
                new FetchTeamLists,
                new FetchMatchResults,
                new FetchPlayerStats,
                new FetchInjuryUpdates,
                new FetchNrlArticles,
                new FetchWeatherForecasts,
                new ComputeMatchMetadata,
                new ComputeTeamTryDistributions,
                new RunPredictionAnalysis,
                new DispatchAiAnalysisFanout,
            ])->onQueue('default')->dispatch();

            Log::info("SyncCurrentRoundData: queued chain for round {$round->round_number}/{$round->season}");
            $this->completeLog(6);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }
}
