<?php

namespace App\Console\Commands;

use App\Models\Matchup;
use App\Models\Prediction;
use App\Models\Round;
use Illuminate\Console\Command;

class AccuracyReportCommand extends Command
{
    protected $signature = 'accuracy:report
        {--rounds= : Limit to last N completed rounds}
        {--season= : Restrict to a single season}';

    protected $aliases = ['nrl:accuracy-report'];
    protected $description = 'Print try-scorer and match-winner accuracy across completed matches.';

    public function handle(): int
    {
        $query = Matchup::query()->where('status', 'completed')->whereHas('tryEvents');

        if ($season = $this->option('season')) {
            $query->whereHas('round', fn ($q) => $q->where('season', $season));
        }
        if ($limit = $this->option('rounds')) {
            // Last N rounds that actually have completed matches with try events.
            // Using round_number alone picks rounds that haven't been played yet.
            $roundIds = Round::whereHas('matches', fn ($q) => $q->where('status', 'completed')->whereHas('tryEvents'))
                ->orderByDesc('round_number')
                ->limit((int) $limit)
                ->pluck('id');
            $query->whereIn('round_id', $roundIds);
        }

        $matches = $query->with(['tryEvents', 'predictions', 'homeTeam', 'awayTeam'])->get();

        if ($matches->isEmpty()) {
            $this->warn('No completed matches with try events available.');
            return self::SUCCESS;
        }

        $totalActual = 0;
        $hitsTop3 = 0;
        $hitsTop5 = 0;
        $hitsTop8 = 0;
        $hitsTop15 = 0;
        $top3Tot = 0;
        $top3Hits = 0;
        $winTot = 0;
        $winOk = 0;

        $matchesScored = 0;
        foreach ($matches as $m) {
            $actual = $m->tryEvents->pluck('player_id')->unique()->all();
            if (empty($actual)) {
                continue;
            }
            $preds = $m->predictions->sortByDesc('score')->pluck('player_id')->values()->all();
            if (empty($preds)) {
                continue;
            }
            $totalActual += count($actual);
            $matchesScored++;
            foreach (array_slice($preds, 0, 3) as $pid) {
                $top3Tot++;
                if (in_array($pid, $actual)) {
                    $hitsTop3++;
                    $top3Hits++;
                }
            }
            // Recall buckets — each bucket counts the *extra* hits beyond the
            // tighter bucket so $hits5/$hits8/$hits15 below sum cleanly.
            $top5Set = array_slice($preds, 0, 5);
            $top8Set = array_slice($preds, 0, 8);
            $top15Set = array_slice($preds, 0, 15);
            $hitsTop5 += count(array_intersect($top5Set, $actual)) - count(array_intersect(array_slice($preds, 0, 3), $actual));
            $hitsTop8 += count(array_intersect($top8Set, $actual)) - count(array_intersect($top5Set, $actual));
            $hitsTop15 += count(array_intersect($top15Set, $actual)) - count(array_intersect($top8Set, $actual));

            // Win-prediction accuracy
            if ($m->home_win_pct !== null && $m->home_score !== null && $m->away_score !== null) {
                $winTot++;
                $predHome = $m->home_win_pct >= 50;
                $actHome = $m->home_score > $m->away_score;
                if ($predHome === $actHome) {
                    $winOk++;
                }
            }
        }

        $hits5 = $hitsTop3 + $hitsTop5;
        $hits8 = $hits5 + $hitsTop8;
        $hits15 = $hits8 + $hitsTop15;

        $this->info(sprintf('Try-scorer accuracy across %d matches with predictions (%d actual scorers):', $matchesScored, $totalActual));
        $this->table(
            ['bucket', 'hits', 'recall'],
            [
                ['top-3', $hitsTop3, sprintf('%.1f%%', $hitsTop3 / max(1, $totalActual) * 100)],
                ['top-5', $hits5, sprintf('%.1f%%', $hits5 / max(1, $totalActual) * 100)],
                ['top-8', $hits8, sprintf('%.1f%%', $hits8 / max(1, $totalActual) * 100)],
                ['top-15', $hits15, sprintf('%.1f%%', $hits15 / max(1, $totalActual) * 100)],
            ],
        );
        $this->line(sprintf(
            'Top-3 precision: %d/%d = %.1f%% (each top-3 pick scoring rate)',
            $top3Hits,
            $top3Tot,
            $top3Hits / max(1, $top3Tot) * 100,
        ));

        if ($winTot > 0) {
            $this->info(sprintf('Match-winner accuracy: %d/%d = %.1f%%', $winOk, $winTot, $winOk / $winTot * 100));
        } else {
            $this->warn('No match-winner predictions to evaluate.');
        }

        return self::SUCCESS;
    }
}
