<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date',
        'current_season_try_rate' => 'float',
        'career_games' => 'integer',
        'career_tries' => 'integer',
        'career_try_assists' => 'integer',
        'career_line_breaks' => 'integer',
        'current_season_games' => 'integer',
        'current_season_tries' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function tryEvents(): HasMany
    {
        return $this->hasMany(TryEvent::class);
    }

    public function injuries(): HasMany
    {
        return $this->hasMany(Injury::class);
    }

    public function activeInjury()
    {
        return $this->hasOne(Injury::class)->where('resolved', false)->latestOfMany();
    }

    public function suspensions(): HasMany
    {
        return $this->hasMany(Suspension::class);
    }

    public function venueStats(): HasMany
    {
        return $this->hasMany(PlayerVenueStat::class);
    }

    public function opponentStats(): HasMany
    {
        return $this->hasMany(PlayerOpponentStat::class);
    }

    public function matchTeamLists(): HasMany
    {
        return $this->hasMany(MatchTeamList::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    public function careerTryRate(): float
    {
        if (! $this->career_games) {
            return 0.0;
        }
        return round($this->career_tries / $this->career_games, 3);
    }

    public function isBackFive(): bool
    {
        return in_array($this->position, ['fullback', 'winger', 'centre'], true);
    }

    public function isEdgeForward(): bool
    {
        return $this->position === 'second-row';
    }
}
