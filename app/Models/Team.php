<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $guarded = [];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function homeMatches(): HasMany
    {
        return $this->hasMany(Matchup::class, 'home_team_id');
    }

    public function awayMatches(): HasMany
    {
        return $this->hasMany(Matchup::class, 'away_team_id');
    }
}
