<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Round extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function matches(): HasMany
    {
        return $this->hasMany(Matchup::class);
    }

    /**
     * Pick the round we should show on the dashboard. Priority:
     *   1. A round whose window contains today (start_date ≤ today ≤ end_date + 2 days),
     *      BUT only if it still has incomplete matches. If all matches are completed,
     *      fall through to the next upcoming round.
     *   2. The next upcoming round (start_date in the future).
     *   3. The is_current flag, if set.
     *   4. The most recent round on record.
     *
     * This makes sure a stale hardcoded Round 1 from seeding doesn't
     * keep showing after the real season has moved on.
     */
    public static function current(): ?self
    {
        $today = Carbon::today();

        $inWindow = self::whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today->copy()->subDays(2))
            ->orderByDesc('start_date')
            ->first();
        if ($inWindow) {
            // If all matches in this round are completed, prefer the next upcoming round
            $totalMatches = $inWindow->matches()->count();
            $completedMatches = $inWindow->matches()->where('status', 'completed')->count();
            $allDone = $totalMatches > 0 && $totalMatches === $completedMatches;

            if (! $allDone) {
                return $inWindow;
            }

            // Round is done — check if there's an upcoming round to move to.
            // >= today, not > today: the next round can start the same day
            // the previous one wraps up (e.g. a Thursday opener).
            $upcoming = self::whereDate('start_date', '>=', $today)
                ->whereKeyNot($inWindow->id)
                ->orderBy('start_date')
                ->orderBy('round_number')
                ->first();
            if ($upcoming) {
                return $upcoming;
            }

            // No upcoming round, stick with the completed one (end of season)
            return $inWindow;
        }

        $upcoming = self::whereDate('start_date', '>=', $today)
            ->orderBy('start_date')
            ->orderBy('round_number')
            ->first();
        if ($upcoming) {
            return $upcoming;
        }

        return self::where('is_current', true)->first()
            ?? self::orderByDesc('season')->orderByDesc('round_number')->first();
    }
}
