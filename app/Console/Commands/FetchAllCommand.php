<?php

namespace App\Console\Commands;

use App\Jobs\FetchDraw;
use App\Jobs\FetchInjuryUpdates;
use App\Jobs\FetchLiveScores;
use App\Jobs\FetchMatchResults;
use App\Jobs\FetchNrlArticles;
use App\Jobs\FetchOdds;
use App\Jobs\FetchPlayerStats;
use App\Jobs\FetchTeamLists;
use Illuminate\Console\Command;

class FetchAllCommand extends Command
{
    protected $signature = 'nrl:fetch-all';
    protected $description = 'Run every scraping job synchronously to populate initial data.';

    public function handle(): int
    {
        $jobs = [
            'Draw / fixtures' => FetchDraw::class,
            'Team lists' => FetchTeamLists::class,
            'Player stats' => FetchPlayerStats::class,
            'Injury updates' => FetchInjuryUpdates::class,
            'Live scores' => FetchLiveScores::class,
            'Match results' => FetchMatchResults::class,
            'Articles' => FetchNrlArticles::class,
            'Betting odds' => FetchOdds::class,
        ];

        foreach ($jobs as $label => $class) {
            $this->info("Running {$label}…");
            try {
                dispatch_sync(new $class);
                $this->line("  <fg=green>ok</>");
            } catch (\Throwable $e) {
                $this->error("  failed: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
