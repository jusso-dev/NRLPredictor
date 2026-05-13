<?php

namespace App\Jobs;

use App\Models\BacktestRun;
use App\Services\Backtester;
use App\Services\SignalCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued runner for Backtester::walkForward. Wraps the call so the UI can
 * dispatch a long backtest and poll a row instead of holding a request open.
 */
class RunBacktest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $backtestRunId) {}

    public function handle(Backtester $backtester): void
    {
        $run = BacktestRun::find($this->backtestRunId);
        if (! $run) {
            Log::warning("RunBacktest: row {$this->backtestRunId} not found");
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $starting = (new SignalCalculator())->weights();
            $result = $backtester->walkForward(
                season: $run->season,
                fromRound: $run->from_round,
                toRound: $run->to_round,
                startingWeights: $starting,
                apply: $run->apply,
            );

            $run->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('RunBacktest failed: ' . $e->getMessage(), ['run_id' => $run->id]);
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        // Catches infrastructure-level failures (timeout, OOM) that don't reach
        // the try/catch above. Mirror the handle() failure path so the UI can
        // surface the error instead of leaving the row stuck in 'running'.
        $run = BacktestRun::find($this->backtestRunId);
        if ($run && $run->status !== 'failed') {
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
