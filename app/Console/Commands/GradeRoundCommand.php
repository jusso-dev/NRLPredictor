<?php

namespace App\Console\Commands;

use App\Models\Matchup;
use App\Models\Round;
use App\Services\CalibrationGrader;
use Illuminate\Console\Command;

class GradeRoundCommand extends Command
{
    protected $signature = 'nrl:grade-round
        {round? : Round number to grade}
        {--season= : Season year (defaults to current)}
        {--all : Grade every completed round with predictions in this season}
        {--backfill : Skip rounds where predictions already have model_prob set}';

    protected $description = 'Compute calibration metrics (Brier, log loss, value vs market) for a completed round without touching weights.';

    public function handle(CalibrationGrader $grader): int
    {
        $season = (int) ($this->option('season') ?: now()->year);

        $rounds = $this->resolveRounds($season);
        if (empty($rounds)) {
            $this->error('No rounds to grade.');
            return self::FAILURE;
        }

        $rows = [];
        foreach ($rounds as $roundNumber) {
            if ($this->option('backfill') && $this->alreadyGraded($season, $roundNumber)) {
                $this->line("R{$roundNumber}: already graded, skipping (use without --backfill to regrade).");
                continue;
            }

            $this->info("Grading season {$season} R{$roundNumber}...");
            $result = $grader->gradeRound($season, $roundNumber);

            $rows[] = [
                'R' . $roundNumber,
                $result['graded'],
                $this->fmt($result['brier']),
                $this->fmt($result['log_loss']),
                $this->fmt($result['market_brier']),
                $this->fmt($result['value_score'], true),
                $result['beats_market'] === null ? '—' : ($result['beats_market'] ? 'yes' : 'no'),
            ];
        }

        if (empty($rows)) {
            $this->warn('Nothing graded.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Round', 'Graded', 'Brier', 'LogLoss', 'MktBrier', 'Value', 'Beats mkt'],
            $rows,
        );

        return self::SUCCESS;
    }

    protected function resolveRounds(int $season): array
    {
        if ($this->option('all') || $this->option('backfill')) {
            return Round::where('season', $season)
                ->whereHas('matches', fn ($q) => $q
                    ->where('status', 'completed')
                    ->whereHas('predictions')
                    ->whereHas('tryEvents'))
                ->orderBy('round_number')
                ->pluck('round_number')
                ->all();
        }

        if ($this->argument('round')) {
            return [(int) $this->argument('round')];
        }

        $latest = Round::where('season', $season)
            ->whereHas('matches', fn ($q) => $q->where('status', 'completed'))
            ->whereDoesntHave('matches', fn ($q) => $q->whereIn('status', ['upcoming', 'live']))
            ->orderByDesc('round_number')
            ->value('round_number');

        return $latest ? [(int) $latest] : [];
    }

    protected function alreadyGraded(int $season, int $roundNumber): bool
    {
        return Matchup::whereHas('round', fn ($q) => $q
                ->where('season', $season)
                ->where('round_number', $roundNumber))
            ->whereHas('predictions', fn ($q) => $q->whereNotNull('model_prob'))
            ->exists();
    }

    protected function fmt(?float $value, bool $signed = false): string
    {
        if ($value === null) {
            return '—';
        }
        $formatted = number_format($value, 3);
        return $signed && $value >= 0 ? '+' . $formatted : $formatted;
    }
}
