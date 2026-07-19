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
 * ShouldBeUnique de-dupes rapid "Sync" clicks while this dispatcher is
 * queued. It does NOT cover the chain itself — each chained job carries its
 * own ShouldBeUnique lock, so a second sync while the chain runs just
 * no-ops job by job.
 */
class SyncCurrentRoundData implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 60;
    public int $tries = 1;
    public int $uniqueFor = 120; // dispatcher only runs for seconds

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
            // Keep the dependency chain limited to work that can produce the
            // current-round experience from the data already in our database.
            // External enrichers must report their own failures, but must not
            // prevent fixture refreshes and predictions when a provider
            // changes or becomes unavailable.
            $jobs = [
                new FetchDraw($round->season, $round->round_number),
                new FetchPlayerStats,
                new ComputeMatchMetadata,
                new ComputeTeamTryDistributions,
                new RunPredictionAnalysis,
                new DispatchAiAnalysisFanout,
            ];

            $enrichers = [
                new FetchTeamLists,
                new FetchMatchResults,
                new FetchInjuryUpdates,
                new FetchNrlArticles,
                new FetchWeatherForecasts,
            ];

            Bus::chain($jobs)
                ->onQueue('default')
                // Without this, a failed link kills the rest of the chain
                // silently — the jobs page would show a successful "sync"
                // with no predictions behind it.
                ->catch(function (Throwable $e) {
                    Log::error('SyncCurrentRoundData: chain aborted: '.$e->getMessage());
                    \App\Models\DataFetchLog::create([
                        'source' => 'internal.sync.chain',
                        'job_class' => self::class,
                        'status' => 'failed',
                        'error' => 'Chain aborted: '.$e->getMessage(),
                        'started_at' => now(),
                        'completed_at' => now(),
                    ]);
                })
                ->dispatch();

            // These jobs remain fail-closed: their DataFetchLog entry will be
            // marked failed if an upstream response is invalid. Dispatching
            // them separately prevents one unavailable optional data source
            // from aborting the core sync chain.
            foreach ($enrichers as $enricher) {
                dispatch($enricher);
            }

            Log::info("SyncCurrentRoundData: queued chain for round {$round->round_number}/{$round->season}");
            // "Success" here means the chain was queued, not that it finished —
            // each chained job writes its own log row as it runs.
            $this->completeLog(count($jobs) + count($enrichers));
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }
}
