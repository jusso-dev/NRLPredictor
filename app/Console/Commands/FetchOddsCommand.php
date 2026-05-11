<?php

namespace App\Console\Commands;

use App\Jobs\FetchOdds;
use Illuminate\Console\Command;

class FetchOddsCommand extends Command
{
    protected $signature = 'nrl:fetch-odds {--no-player-props : Skip fetching player try scorer odds (saves API credits)}';
    protected $description = 'Fetch NRL match and player odds from The Odds API.';

    public function handle(): int
    {
        $apiKey = config('services.odds_api.key');
        if (! $apiKey) {
            $this->error('ODDS_API_KEY is not set. Add it to your .env file.');
            return self::FAILURE;
        }

        $includePlayerProps = ! $this->option('no-player-props');

        $this->info('Fetching NRL odds from The Odds API…');
        if (! $includePlayerProps) {
            $this->comment('  (player props skipped)');
        }

        try {
            dispatch_sync(new FetchOdds(includePlayerProps: $includePlayerProps));
            $this->line('  <fg=green>ok</>');
        } catch (\Throwable $e) {
            $this->error('  failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
