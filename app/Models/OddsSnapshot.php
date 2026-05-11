<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OddsSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decimal_odds' => 'float',
        'captured_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function impliedProbability(): float
    {
        return $this->decimal_odds > 0 ? round(1 / $this->decimal_odds, 4) : 0;
    }
}
