<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TryEvent extends Model
{
    protected $guarded = [];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
