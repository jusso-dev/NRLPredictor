<?php

namespace App\Console\Commands;

use App\Jobs\FetchDraw;
use Illuminate\Console\Command;

class FetchDrawCommand extends Command
{
    protected $signature = 'nrl:fetch-draw {--season=} {--round=} {--all : Fetch all 27 rounds instead of just the current}';
    protected $description = 'Pull NRL fixture data from ESPN\'s public scoreboard API. Defaults to the current round (+1).';

    public function handle(): int
    {
        $season = $this->option('season') ? (int) $this->option('season') : null;
        $round = $this->option('round') ? (int) $this->option('round') : null;

        if ($this->option('all') && ! $round) {
            // Fetch every round by dispatching with round=null and overriding the detect logic
            foreach (range(1, 27) as $r) {
                $this->info("Fetching round {$r}…");
                dispatch_sync(new FetchDraw(season: $season, round: $r));
            }
            $this->info('Done — all 27 rounds fetched.');
            return self::SUCCESS;
        }

        $this->info('Fetching draw (season='.($season ?? 'current').', round='.($round ?? 'current').')…');
        dispatch_sync(new FetchDraw(season: $season, round: $round));
        $this->info('Done.');

        return self::SUCCESS;
    }
}
