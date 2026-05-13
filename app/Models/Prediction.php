<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'signals' => 'array',
        'score' => 'integer',
        'rank_in_match' => 'integer',
        'version' => 'integer',
        'model_prob' => 'float',
        'market_prob' => 'float',
        'was_hit' => 'boolean',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matchup::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function tierClass(): string
    {
        return match (true) {
            $this->score >= 80 => 'bg-signal-red',
            $this->score >= 65 => 'bg-signal-orange',
            $this->score >= 50 => 'bg-signal-yellow',
            default => 'bg-white/15',
        };
    }

    public function advantageTags(): array
    {
        $tags = [];
        foreach ($this->signals ?? [] as $signal) {
            $strength = $signal['strength'] ?? 0;
            if ($strength < 0.6) {
                continue;
            }
            $tags[] = match ($signal['type']) {
                'recent_form' => ['label' => 'HOT FORM', 'class' => 'chip-red'],
                'milestone_game' => ['label' => 'MILESTONE', 'class' => 'chip-gold'],
                'venue_record' => ['label' => 'VENUE', 'class' => 'chip-green'],
                'head_to_head' => ['label' => 'MATCHUP', 'class' => 'chip-blue'],
                'returning_player' => ['label' => 'RETURNING', 'class' => 'chip-orange'],
                'opponent_edge_weakness', 'opponent_missing_defenders' => ['label' => 'OPP. WEAK', 'class' => 'chip-purple'],
                default => null,
            };
        }

        return array_values(array_unique(array_filter($tags), SORT_REGULAR));
    }
}
