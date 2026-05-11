<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignalPerformanceLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'avg_strength_hits' => 'float',
        'avg_strength_misses' => 'float',
        'delta' => 'float',
    ];
}
