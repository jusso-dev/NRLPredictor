<?php

namespace App\Jobs\Concerns;

use App\Models\DataFetchLog;
use Throwable;

trait LogsDataFetch
{
    protected ?DataFetchLog $log = null;

    protected function startLog(string $source): void
    {
        $this->log = DataFetchLog::start($source, static::class);
    }

    protected function completeLog(int $records): void
    {
        $this->log?->succeed($records);
    }

    protected function failLog(Throwable $e): void
    {
        $this->log?->fail($e);
        report($e);
    }

    /**
     * Called by the queue worker when the job finally fails — including
     * timeouts, where the in-handle catch/failLog never runs. $this->log is
     * not serialized, so find the open row for this job class instead of
     * leaving it stuck "running" until the sweeper gets to it.
     */
    public function failed(?Throwable $e = null): void
    {
        DataFetchLog::where('job_class', static::class)
            ->whereNull('completed_at')
            ->orderByDesc('id')
            ->first()
            ?->fail($e ?? new \RuntimeException('Job failed without an exception (timed out or worker died).'));
    }
}
