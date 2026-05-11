<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerOpponentStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'try_rate' => 'float',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'opponent_team_id');
    }
}
