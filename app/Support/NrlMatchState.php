<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Single source of truth for mapping nrl.com's `matchState` tokens onto our
 * match statuses. Previously three jobs kept their own (drifting) copies.
 */
class NrlMatchState
{
    /**
     * @return string|null 'completed' | 'live' | 'upcoming', or null when the
     *                     token is unrecognised (callers decide the fallback)
     */
    public static function toStatus(?string $state): ?string
    {
        $s = Str::lower(trim((string) $state));

        return match (true) {
            in_array($s, ['fulltime', 'fullt', 'post', 'postmatch', 'final', 'ended']) => 'completed',
            in_array($s, ['live', 'inprogress', 'ongoing', 'current', 'playing']) => 'live',
            str_contains($s, 'half') => 'live', // FirstHalf / HalfTime / SecondHalf
            str_contains($s, 'live') => 'live',
            in_array($s, ['upcoming', 'pre', 'prematch', 'preview', 'notstarted', 'scheduled']) => 'upcoming',
            default => null,
        };
    }
}
