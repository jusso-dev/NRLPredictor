<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'old_weights' => 'array',
        'new_weights' => 'array',
        'signal_deltas' => 'array',
        'accuracy_before' => 'float',
        'accuracy_after' => 'float',
        'brier_score' => 'float',
    ];
}
