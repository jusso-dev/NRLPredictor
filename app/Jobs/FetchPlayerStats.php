<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\PlayerOpponentStat;
use App\Models\PlayerVenueStat;
use App\Models\TryEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recalculate player stats from match data (try events + team lists).
 * Replaces the original HTML scraper which relied on CSS selectors
 * that no longer match nrl.com's markup.
 */
class FetchPlayerStats implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 300;
    public int $tries = 1;
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'fetch:player-stats';
    }

    public function handle(): void
    {
        $this->startLog('internal.player-stats');

        try {
            Artisan::call('nrl:recalculate-stats');
            $output = Artisan::output();

            // Count how many players were updated
            $records = Player::where('current_season_games', '>', 0)->count();
            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }
}
