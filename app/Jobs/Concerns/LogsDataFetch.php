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
}
