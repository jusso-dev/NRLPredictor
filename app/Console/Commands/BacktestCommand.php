<?php

namespace App\Console\Commands;

use App\Models\Round;
use App\Services\Backtester;
use App\Services\SignalCalculator;
use Illuminate\Console\Command;

class BacktestCommand extends Command
{
    protected $signature = 'nrl:backtest
        {--season= : Season to replay (defaults to current)}
        {--from= : First round to include (defaults to earliest completed)}
        {--to= : Last round to include (defaults to latest completed)}
        {--apply : Persist the final accepted weight set as a WeightAdjustment row}
        {--verbose : Print the per-round table even for very long runs}';

    protected $description = 'Replay past rounds and only accept tuner weight changes that improve the next round\'s Brier score.';

    public function handle(Backtester $backtester): int
    {
        $season = (int) ($this->option('season') ?: now()->year);

        $bounds = $this->resolveBounds($season);
        if ($bounds === null) {
            $this->error("No completed rounds with graded predictions found for season {$season}.");
            return self::FAILURE;
        }
        [$from, $to] = $bounds;

        $starting = (new SignalCalculator())->weights();

        $this->info("Backtesting season {$season} R{$from}..R{$to} ({$this->countWeights($starting)} signals)...");
        if ($this->option('apply')) {
            $this->warn('--apply set: accepted weight changes will be persisted to weight_adjustments.');
        }

        $result = $backtester->walkForward($season, $from, $to, $starting, (bool) $this->option('apply'));

        if (isset($result['summary']['error'])) {
            $this->error($result['summary']['error']);
            return self::FAILURE;
        }

        $this->renderRoundsTable($result['rounds']);
        $this->renderSummary($result['summary']);
        $this->renderWeightDiff($result['summary']['starting_weights'], $result['summary']['final_weights']);

        return self::SUCCESS;
    }

    protected function resolveBounds(int $season): ?array
    {
        $rounds = Round::where('season', $season)
            ->whereHas('matches', fn ($q) => $q
                ->where('status', 'completed')
                ->whereHas('predictions', fn ($q2) => $q2->whereNotNull('was_hit'))
                ->whereHas('tryEvents'))
            ->orderBy('round_number')
            ->pluck('round_number');

        if ($rounds->isEmpty()) {
            return null;
        }

        $from = (int) ($this->option('from') ?: $rounds->first());
        $to = (int) ($this->option('to') ?: $rounds->last());

        if ($to <= $from) {
            $this->error("Need --to > --from. Got from={$from}, to={$to}.");
            return null;
        }

        return [$from, $to];
    }

    protected function renderRoundsTable(array $rounds): void
    {
        if (empty($rounds)) {
            return;
        }

        $rows = [];
        foreach ($rounds as $r) {
            $rows[] = [
                'R' . $r['learn_round'] . ' → R' . $r['test_round'],
                $this->fmtBrier($r['brier_current'] ?? null),
                $this->fmtBrier($r['brier_proposed'] ?? null),
                $this->fmtSignedBrier($r['delta'] ?? null),
                strtoupper($r['decision']),
                $r['weight_changes'] ?? 0,
            ];
        }

        $this->newLine();
        $this->table(
            ['Learn → Test', 'Brier (current)', 'Brier (proposed)', 'Δ', 'Decision', 'Changes'],
            $rows,
        );
    }

    protected function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->line('<comment>Summary</comment>');
        $this->line("  Pairs evaluated : {$summary['pairs_evaluated']}");
        $this->line("  Accepted        : {$summary['accepted']}");
        $this->line("  Rejected        : {$summary['rejected']}");
        $this->line('  Baseline Brier  : ' . $this->fmtBrier($summary['baseline_brier']));
        $this->line('  Walked Brier    : ' . $this->fmtBrier($summary['walked_brier']));

        if ($summary['improvement'] !== null) {
            $tone = $summary['improvement'] > 0 ? 'info' : 'warn';
            $line = '  Improvement     : ' . $this->fmtSignedBrier(-$summary['improvement']) . ' (lower is better)';
            $this->$tone($line);
        }

        if (! empty($summary['applied'])) {
            $this->info('  Final weight set written to weight_adjustments.');
        } elseif ($summary['final_weights'] !== $summary['starting_weights']) {
            $this->comment('  Final weights differ from starting weights — rerun with --apply to persist them.');
        }
    }

    protected function renderWeightDiff(array $before, array $after): void
    {
        $changed = [];
        foreach ($after as $type => $value) {
            if (($before[$type] ?? null) !== $value) {
                $changed[$type] = ['from' => $before[$type] ?? 0, 'to' => $value];
            }
        }

        if (empty($changed)) {
            $this->newLine();
            $this->line('<comment>No weights changed across the run.</comment>');
            return;
        }

        $this->newLine();
        $this->line('<comment>Weight changes</comment>');
        $rows = [];
        foreach ($changed as $type => $info) {
            $rows[] = [str_replace('_', ' ', $type), $info['from'], $info['to'], $info['to'] - $info['from']];
        }
        $this->table(['Signal', 'From', 'To', 'Δ'], $rows);
    }

    protected function countWeights(array $weights): int
    {
        return count($weights);
    }

    protected function fmtBrier(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 4);
    }

    protected function fmtSignedBrier(?float $value): string
    {
        if ($value === null) return '—';
        $sign = $value >= 0 ? '+' : '';
        return $sign . number_format($value, 4);
    }
}
