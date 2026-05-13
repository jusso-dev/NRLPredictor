<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BacktestRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'apply' => 'boolean',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }

    public function summary(): array
    {
        return $this->result['summary'] ?? [];
    }

    public function rounds(): array
    {
        return $this->result['rounds'] ?? [];
    }
}
