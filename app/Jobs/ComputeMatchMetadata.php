<?php

namespace App\Jobs;

use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\Round;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Compute match-level metadata: turnaround days, interstate travel,
 * and tactical shift detection. Run before prediction scoring.
 */
class ComputeMatchMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function handle(): void
    {
        $round = Round::current();
        if (! $round) {
            return;
        }

        $matches = Matchup::with(['homeTeam', 'awayTeam'])
            ->where('round_id', $round->id)
            ->where('status', 'upcoming')
            ->get();

        $venueStates = config('nrl-weights.venue_states', []);
        $teamStates = config('nrl-weights.team_states', []);

        foreach ($matches as $match) {
            $updates = [];

            // Turnaround days
            foreach (['home' => $match->home_team_id, 'away' => $match->away_team_id] as $side => $teamId) {
                $lastMatch = Matchup::where('status', 'completed')
                    ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
                    ->orderByDesc('kickoff_at')
                    ->first();

                $days = $lastMatch && $match->kickoff_at && $lastMatch->kickoff_at
                    ? $lastMatch->kickoff_at->diffInDays($match->kickoff_at)
                    : null;

                $updates["days_since_last_{$side}"] = $days;

                // Interstate travel
                $team = $side === 'home' ? $match->homeTeam : $match->awayTeam;
                $teamState = $teamStates[$team?->nrl_slug ?? ''] ?? null;
                $venueState = $venueStates[$match->venue ?? ''] ?? null;
                $updates["interstate_travel_{$side}"] = $teamState && $venueState && $teamState !== $venueState;

                // Tactical shift: check if spine players changed
                $updates["tactical_shift_{$side}"] = $this->detectTacticalShift($match, $teamId);
            }

            $match->update($updates);
        }
    }

    protected function detectTacticalShift(Matchup $match, int $teamId): bool
    {
        // Spine = positions 1 (fullback), 6 (five-eighth), 7 (halfback), 9 (hooker)
        $spinePositions = [1, 6, 7, 9];

        $currentSpine = MatchTeamList::where('match_id', $match->id)
            ->where('team_id', $teamId)
            ->whereIn('position_number', $spinePositions)
            ->pluck('player_id')
            ->sort()
            ->values();

        if ($currentSpine->isEmpty()) {
            return false;
        }

        $priorMatch = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('kickoff_at')
            ->first();

        if (! $priorMatch) {
            return false;
        }

        $priorSpine = MatchTeamList::where('match_id', $priorMatch->id)
            ->where('team_id', $teamId)
            ->whereIn('position_number', $spinePositions)
            ->pluck('player_id')
            ->sort()
            ->values();

        if ($priorSpine->isEmpty()) {
            return false;
        }

        // If 2+ spine positions changed, flag as tactical shift
        $changed = $currentSpine->diff($priorSpine)->count();
        return $changed >= 2;
    }
}
