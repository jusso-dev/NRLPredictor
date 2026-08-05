<?php

namespace App\Services;

use App\Models\MatchLateChange;
use App\Models\Matchup;
use Illuminate\Support\Facades\Log;

/**
 * Records what changed about a match in the hours before kickoff.
 *
 * Late mail is the most valuable information the model ever sees and the most
 * perishable: a fullback withdrawn 60 minutes out rewrites a match. Two feeds
 * carry it — the team list itself, and the bookmaker prices, which move on news
 * long before anyone publishes an article about it.
 */
class LateChangeRecorder
{
    /** Only treat list movement as "late" inside this window before kickoff. */
    public const WINDOW_HOURS = 36;

    /** Prices drift constantly; only trust a move as news this close to kickoff. */
    public const ODDS_WINDOW_HOURS = 8;

    /** Minimum change in implied probability (0-1) before a price move is news. */
    public const ODDS_MIN_DELTA = 0.05;

    public function shouldTrack(Matchup $match, int $windowHours = self::WINDOW_HOURS): bool
    {
        if ($match->status !== 'upcoming' || ! $match->kickoff_at) {
            return false;
        }

        $minutes = $this->minutesToKickoff($match);

        // Inside the window, and not already past kickoff.
        return $minutes !== null && $minutes >= 0 && $minutes <= $windowHours * 60;
    }

    public function minutesToKickoff(Matchup $match): ?int
    {
        return $match->kickoff_at ? (int) round(now()->diffInMinutes($match->kickoff_at, false)) : null;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function record(
        Matchup $match,
        string $type,
        string $summary,
        array $detail = [],
        ?int $playerId = null,
        ?int $teamId = null,
        string $source = 'nrl.com',
    ): ?MatchLateChange {
        $fingerprint = sha1($type.'|'.($playerId ?? 0).'|'.$summary);

        $change = MatchLateChange::firstOrCreate(
            ['match_id' => $match->id, 'fingerprint' => $fingerprint],
            [
                'player_id' => $playerId,
                'team_id' => $teamId,
                'type' => $type,
                'summary' => $summary,
                'detail' => $detail === [] ? null : $detail,
                'source' => $source,
                'minutes_to_kickoff' => $this->minutesToKickoff($match),
                'detected_at' => now(),
            ],
        );

        // Only a first sighting is news; re-detecting it every poll is not.
        return $change->wasRecentlyCreated ? $change : null;
    }

    /**
     * Diff two snapshots of one side's team list.
     *
     * @param  array<int, array{name: string, number: int, role: string}>  $before  keyed by player id
     * @param  array<int, array{name: string, number: int, role: string}>  $after
     * @return int number of changes recorded
     */
    public function recordTeamListDiff(Matchup $match, int $teamId, array $before, array $after): int
    {
        // Nothing to compare against on the first sync of a match.
        if ($before === [] || ! $this->shouldTrack($match)) {
            return 0;
        }

        $recorded = 0;

        foreach ($before as $playerId => $was) {
            if (! isset($after[$playerId])) {
                $recorded += $this->record(
                    $match,
                    MatchLateChange::TYPE_OUT,
                    sprintf('%s (%d) out of the side', $was['name'], $was['number']),
                    ['was' => $was],
                    $playerId,
                    $teamId,
                ) ? 1 : 0;

                continue;
            }

            $now = $after[$playerId];

            if ($was['number'] !== $now['number']) {
                $recorded += $this->record(
                    $match,
                    MatchLateChange::TYPE_POSITIONAL,
                    sprintf('%s moves %d → %d', $now['name'], $was['number'], $now['number']),
                    ['was' => $was, 'now' => $now],
                    $playerId,
                    $teamId,
                ) ? 1 : 0;
            } elseif ($was['role'] !== $now['role']) {
                $recorded += $this->record(
                    $match,
                    MatchLateChange::TYPE_POSITIONAL,
                    sprintf('%s moves to %s', $now['name'], str_replace('_', ' ', $now['role'])),
                    ['was' => $was, 'now' => $now],
                    $playerId,
                    $teamId,
                ) ? 1 : 0;
            }
        }

        foreach ($after as $playerId => $now) {
            if (isset($before[$playerId])) {
                continue;
            }

            $recorded += $this->record(
                $match,
                MatchLateChange::TYPE_IN,
                sprintf('%s named at %d', $now['name'], $now['number']),
                ['now' => $now],
                $playerId,
                $teamId,
            ) ? 1 : 0;
        }

        if ($recorded > 0) {
            Log::info("LateChangeRecorder: {$recorded} team-list change(s) on match {$match->id}");
        }

        return $recorded;
    }

    /**
     * A bookmaker price that jumps inside the final hours is news we have not
     * read yet — a scratching, a fitness test failed, a rain forecast.
     */
    public function recordOddsDrift(
        Matchup $match,
        string $market,
        float $previous,
        float $current,
        string $bookmaker,
        ?int $playerId = null,
        ?string $playerName = null,
    ): ?MatchLateChange {
        if ($previous <= 1.0 || $current <= 1.0) {
            return null;
        }

        if (! $this->shouldTrack($match, self::ODDS_WINDOW_HOURS)) {
            return null;
        }

        $delta = (1 / $current) - (1 / $previous);
        if (abs($delta) < self::ODDS_MIN_DELTA) {
            return null;
        }

        $subject = $playerName ?? strtoupper(str_replace('_', ' ', $market));
        $direction = $delta > 0 ? 'shortened' : 'drifted';

        return $this->record(
            $match,
            MatchLateChange::TYPE_ODDS_DRIFT,
            sprintf(
                '%s %s %s → %s (%s%.1f pts implied)',
                $subject,
                $direction,
                number_format($previous, 2),
                number_format($current, 2),
                $delta > 0 ? '+' : '-',
                abs($delta) * 100,
            ),
            [
                'market' => $market,
                'bookmaker' => $bookmaker,
                'previous_odds' => $previous,
                'current_odds' => $current,
                'implied_delta' => round($delta, 4),
            ],
            $playerId,
            null,
            $bookmaker,
        );
    }
}
