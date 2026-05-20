<?php

namespace App\Console\Commands;

use App\Models\DataFetchLog;
use Illuminate\Console\Command;

/**
 * Mark data_fetch_logs rows that have been "running" longer than the threshold
 * as failed. Catches jobs killed by container restarts or OOM where the worker
 * never wrote a completion row.
 */
class SweepStuckLogs extends Command
{
    protected $signature = 'nrl:sweep-stuck-logs {--minutes=15 : Treat rows running longer than this as stuck}';

    protected $description = 'Mark stale data_fetch_logs (running too long) as failed so the UI and metrics reflect reality.';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $count = DataFetchLog::whereNull('completed_at')
            ->where('started_at', '<', $cutoff)
            ->update([
                'status' => 'failed',
                'error' => "Marked failed by sweeper — running > {$minutes}m with no completion event.",
                'completed_at' => now(),
            ]);

        $this->info($count > 0
            ? "Swept {$count} stuck log(s)."
            : 'No stuck logs.');

        return self::SUCCESS;
    }
}
