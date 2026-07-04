<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Models\Round;
use App\Support\HttpScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Monitor NRL news in the 45 minutes before kickoff for late team changes,
 * injuries, and other updates that could affect predictions.
 *
 * Scheduled to run every 5 minutes, but only does work when there's a match
 * kicking off within the next 45 minutes.
 */
class FetchPreGameNews implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsDataFetch, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public int $uniqueFor = 700; // > worst case: 2 tries x 300s timeout + backoff

    public function uniqueId(): string
    {
        return 'fetch:pre-game-news';
    }

    public function backoff(): array
    {
        return [30];
    }

    public function handle(HttpScraper $http): void
    {
        $round = Round::current();
        if (! $round) {
            return;
        }

        // Find matches kicking off in the next 45 minutes
        $upcoming = Matchup::with(['homeTeam', 'awayTeam'])
            ->where('round_id', $round->id)
            ->where('status', 'upcoming')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '>', now())
            ->where('kickoff_at', '<=', now()->addMinutes(45))
            ->get();

        if ($upcoming->isEmpty()) {
            return;
        }

        $this->startLog('nrl.com/pre-game-news');
        $records = 0;

        try {
            foreach ($upcoming as $match) {
                $minutesToKickoff = now()->diffInMinutes($match->kickoff_at);
                Log::info(sprintf(
                    'PreGameNews: %s v %s — %d min to kickoff, refreshing data',
                    $match->homeTeam?->short_name,
                    $match->awayTeam?->short_name,
                    $minutesToKickoff,
                ));

                // Re-fetch team lists for this match's teams
                $records += $this->refreshTeamLists($http, $match);

                // Re-fetch injuries
                $records += $this->refreshInjuries($http);

                // Fetch latest articles for both teams
                $records += $this->fetchTeamNews($http, $match);
            }

            // Re-score predictions if we found updates
            if ($records > 0) {
                foreach ($upcoming as $match) {
                    dispatch(new RunPredictionAnalysis($match->id));
                }
                Log::info("PreGameNews: queued re-scoring for {$upcoming->count()} matches");
            }

            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    protected function refreshTeamLists(HttpScraper $http, Matchup $match): int
    {
        try {
            dispatch_sync(new FetchTeamLists);

            return 1;
        } catch (Throwable $e) {
            Log::warning("PreGameNews: team list refresh failed: {$e->getMessage()}");

            return 0;
        }
    }

    protected function refreshInjuries(HttpScraper $http): int
    {
        try {
            dispatch_sync(new FetchInjuryUpdates);

            return 1;
        } catch (Throwable $e) {
            Log::warning("PreGameNews: injury refresh failed: {$e->getMessage()}");

            return 0;
        }
    }

    protected function fetchTeamNews(HttpScraper $http, Matchup $match): int
    {
        // Article fetch is slow (crawls all teams with rate-limit throttle) and not on the
        // critical path for re-scoring — dispatch async so we don't blow the 5-min cadence.
        try {
            dispatch(new FetchNrlArticles);

            return 1;
        } catch (Throwable $e) {
            Log::warning("PreGameNews: article refresh dispatch failed: {$e->getMessage()}");

            return 0;
        }
    }
}
