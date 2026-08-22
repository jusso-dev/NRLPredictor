<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilestoneEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'current_count' => 'integer',
        'target_count' => 'integer',
        'distance' => 'integer',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }
}
