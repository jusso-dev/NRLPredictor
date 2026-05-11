<?php

namespace App\Console\Commands;

use App\Models\WeightAdjustment;
use App\Services\SignalTuner;
use Illuminate\Console\Command;

class TuneWeightsCommand extends Command
{
    protected $signature = 'nrl:tune {round? : Round number to tune after} {--season=} {--dry-run : Show changes without applying}';
    protected $description = 'Analyse signal performance and adjust weights based on actual results.';

    public function handle(SignalTuner $tuner): int
    {
        $season = (int) ($this->option('season') ?: now()->year);
        $round = $this->argument('round')
            ? (int) $this->argument('round')
            : $this->latestCompletedRound($season);

        if (! $round) {
            $this->error('No completed round found to tune.');
            return self::FAILURE;
        }

        $this->info("Tuning weights based on Round {$round} (season {$season})...");

        $result = $tuner->tuneAfterRound($season, $round);

        if (isset($result['error'])) {
            $this->error($result['error']);
            return self::FAILURE;
        }

        $this->info("Accuracy: {$result['accuracy']}%");
        $this->info("Signals graded: {$result['signals_graded']}");
        $this->info("Weights changed: {$result['weights_changed']}");
        $this->newLine();
        $this->info('Insights:');
        $this->line($result['insights']);

        // Show adjustment details
        $adj = WeightAdjustment::find($result['adjustment_id']);
        if ($adj && $adj->reasoning) {
            $this->newLine();
            $this->info('Weight changes:');
            $this->line($adj->reasoning);
        }

        return self::SUCCESS;
    }

    protected function latestCompletedRound(int $season): ?int
    {
        return \App\Models\Round::where('season', $season)
            ->whereHas('matches', fn ($q) => $q->where('status', 'completed'))
            ->whereDoesntHave('matches', fn ($q) => $q->whereIn('status', ['upcoming', 'live']))
            ->orderByDesc('round_number')
            ->value('round_number');
    }
}
