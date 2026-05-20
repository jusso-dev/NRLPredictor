<?php

namespace App\Console\Commands;

use App\Jobs\AnalyseMatchWithAi;
use App\Jobs\RunPredictionAnalysis;
use App\Models\Matchup;
use App\Models\Round;
use Illuminate\Console\Command;

class PredictRoundCommand extends Command
{
    protected $signature = 'nrl:predict {round? : Round number; defaults to the current round} {--ai : Also queue AI review per match}';

    protected $description = 'Score every match in a round (fast). Pass --ai to queue AI review too.';

    public function handle(): int
    {
        $roundArg = $this->argument('round');

        $round = $roundArg
            ? Round::where('round_number', (int) $roundArg)->latest('season')->first()
            : Round::current();

        if (! $round) {
            $this->error('Round not found.');

            return self::FAILURE;
        }

        $matches = Matchup::where('round_id', $round->id)->get();
        $this->info("Predicting round {$round->round_number} ({$matches->count()} matches)");

        foreach ($matches as $match) {
            $this->line("  → match #{$match->id} — scoring");
            dispatch_sync(new RunPredictionAnalysis($match->id));

            if ($this->option('ai')) {
                AnalyseMatchWithAi::dispatch($match->id);
                $this->line('     + AI review queued');
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
