<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Services\TryPredictionAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One AI-agent pass for one match. Skips early (logged, not failed)
 * if the match has no statistical predictions — there'd be nothing for
 * the agent's submit_adjusted_prediction endpoint to update.
 */
class AnalyseMatchWithAi implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsDataFetch, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 2;

    public int $uniqueFor = 900; // > worst case: 2 tries x 420s timeout + backoff

    public function __construct(public int $matchId) {}

    /** Single retry delay (seconds). Covers transient agent/network blips. */
    public function backoff(): array
    {
        return [30];
    }

    public function uniqueId(): string
    {
        return 'ai-analysis:match:'.$this->matchId;
    }

    public function handle(TryPredictionAgent $agent): void
    {
        $this->startLog('ai.agent:match:'.$this->matchId);

        try {
            $match = Matchup::with(['homeTeam', 'awayTeam'])
                ->withCount('predictions')
                ->find($this->matchId);

            if (! $match) {
                Log::warning("AnalyseMatchWithAi: match {$this->matchId} not found — aborting");
                $this->log?->update([
                    'status' => 'failed',
                    'error' => "Match {$this->matchId} not found.",
                    'completed_at' => now(),
                ]);

                return;
            }

            if ($match->isPast()) {
                $msg = sprintf(
                    'Match %d (%s v %s) is already %s — no point re-analysing.',
                    $match->id,
                    $match->homeTeam?->short_name ?? '?',
                    $match->awayTeam?->short_name ?? '?',
                    $match->status,
                );
                Log::info('AnalyseMatchWithAi skip: '.$msg);
                $this->log?->update([
                    'status' => 'failed',
                    'error' => $msg,
                    'completed_at' => now(),
                ]);

                return;
            }

            if ($match->predictions_count === 0) {
                $msg = sprintf(
                    'No statistical predictions for match %d (%s v %s). Run scoring first (requires team lists).',
                    $match->id,
                    $match->homeTeam?->short_name ?? '?',
                    $match->awayTeam?->short_name ?? '?',
                );
                Log::info('AnalyseMatchWithAi skip: '.$msg);
                $this->log?->update([
                    'status' => 'failed',
                    'error' => $msg,
                    'completed_at' => now(),
                ]);

                return;
            }

            $applied = $agent->analyse($this->matchId);
            $this->completeLog($applied);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }
}
