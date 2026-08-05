<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change detected close to kickoff — a late out, a reshuffle, or a
 * bookmaker price moving hard enough to imply news we have not read yet.
 */
class MatchLateChange extends Model
{
    public const TYPE_OUT = 'out';

    public const TYPE_IN = 'in';

    public const TYPE_POSITIONAL = 'positional';

    public const TYPE_ODDS_DRIFT = 'odds_drift';

    protected $fillable = [
        'match_id',
        'player_id',
        'team_id',
        'type',
        'summary',
        'detail',
        'source',
        'minutes_to_kickoff',
        'detected_at',
        'fingerprint',
    ];

    protected $casts = [
        'detail' => 'array',
        'detected_at' => 'datetime',
        'minutes_to_kickoff' => 'integer',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** Team-list movement matters more to a reader than a price twitch. */
    public function isTeamChange(): bool
    {
        return $this->type !== self::TYPE_ODDS_DRIFT;
    }
}
