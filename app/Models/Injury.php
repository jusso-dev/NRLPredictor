<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Injury extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fetched_at' => 'datetime',
        'resolved' => 'boolean',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
