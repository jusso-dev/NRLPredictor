<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeatherForecast extends Model
{
    protected $guarded = [];

    protected $casts = [
        'temp_c' => 'float',
        'rainfall_mm_6h' => 'float',
        'humidity_pct' => 'integer',
        'wind_kph' => 'integer',
        'is_wet' => 'boolean',
        'is_hot' => 'boolean',
        'captured_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }
}
