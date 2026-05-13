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
        'log_loss' => 'float',
        'value_score' => 'float',
        'market_brier' => 'float',
        'market_log_loss' => 'float',
        'graded_predictions' => 'integer',
        'logistic_b0' => 'float',
        'logistic_b1' => 'float',
        'logistic_samples' => 'integer',
    ];
}
