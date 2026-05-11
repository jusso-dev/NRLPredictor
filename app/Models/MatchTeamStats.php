<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchTeamStats extends Model
{
    protected $table = 'match_team_stats';
    protected $guarded = [];

    protected $casts = [
        'effective_tackle_pct' => 'float',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function completionRate(): ?float
    {
        if (! $this->completion_denominator) {
            return null;
        }
        return $this->completion_numerator / $this->completion_denominator;
    }
}
