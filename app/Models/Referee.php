<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referee extends Model
{
    protected $guarded = [];

    protected $casts = [
        'paa' => 'float',
        'sraa' => 'float',
        'avg_penalties_per_game' => 'float',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(RefereeAssignment::class);
    }
}
