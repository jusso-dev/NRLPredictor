<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataFetchLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function start(string $source, string $jobClass): self
    {
        return self::create([
            'source' => $source,
            'job_class' => $jobClass,
            'status' => 'success',
            'started_at' => now(),
        ]);
    }

    public function succeed(int $records = 0): void
    {
        $this->update([
            'status' => 'success',
            'records_updated' => $records,
            'completed_at' => now(),
        ]);
    }

    public function fail(\Throwable $e): void
    {
        $this->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
