<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "match" is a reserved word in PHP 8+, so the Eloquent class is Matchup,
 * while the table stays `matches`.
 */
class Matchup extends Model
{
    protected $table = 'matches';

    protected $guarded = [];

    protected $casts = [
        'kickoff_at' => 'datetime',
        'win_signals' => 'array',
        'home_win_pct' => 'integer',
        'away_win_pct' => 'integer',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function predictedWinner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'predicted_winner_id');
    }

    public function teamLists(): HasMany
    {
        return $this->hasMany(MatchTeamList::class, 'match_id');
    }

    public function tryEvents(): HasMany
    {
        return $this->hasMany(TryEvent::class, 'match_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class, 'match_id');
    }

    public function oddsSnapshots(): HasMany
    {
        return $this->hasMany(OddsSnapshot::class, 'match_id');
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'live' => 'LIVE',
            'completed' => 'FINAL',
            default => 'UPCOMING',
        };
    }

    /**
     * Upcoming matches in the round the dashboard is currently displaying.
     * Used by the prediction pipeline so we don't burn time scoring matches
     * that are weeks away and don't have published team lists yet.
     */
    public function scopeForCurrentRound($query)
    {
        $round = Round::current();
        if (! $round) {
            return $query->whereRaw('1 = 0');
        }
        return $query->where('round_id', $round->id);
    }

    public function scopeUpcomingInCurrentRound($query)
    {
        return $query->forCurrentRound()
            ->where('status', 'upcoming')
            ->where(function ($q) {
                // Treat NULL kickoff_at as OK (fixture published without a time).
                // Otherwise require kickoff to still be in the future.
                $q->whereNull('kickoff_at')->orWhere('kickoff_at', '>', now());
            });
    }

    public function isPast(): bool
    {
        if (in_array($this->status, ['completed', 'live'], true)) {
            return true;
        }
        return $this->kickoff_at && $this->kickoff_at->isPast();
    }
}
