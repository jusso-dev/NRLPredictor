<?php

namespace App\Console\Commands;

use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\PlayerOpponentStat;
use App\Models\PlayerVenueStat;
use App\Models\TryEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalculate all player stats from try events and team lists.
 * This replaces the broken HTML scraper with data we already have.
 */
class RecalculatePlayerStats extends Command
{
    protected $signature = 'nrl:recalculate-stats {--season=}';
    protected $description = 'Recalculate player career/season stats, venue stats, and opponent stats from match data.';

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: now()->year);
        $this->info("Recalculating stats for season {$season}...");

        $this->recalculateSeasonStats($season);
        $this->recalculateCareerStats();
        $this->recalculateVenueStats();
        $this->recalculateOpponentStats();
        $this->fixMissingPositions();

        $this->info('Done.');
        return self::SUCCESS;
    }

    protected function recalculateSeasonStats(int $season): void
    {
        $this->info('  Season stats...');

        // Get all match IDs for this season
        $seasonMatchIds = Matchup::where('status', 'completed')
            ->whereHas('round', fn ($q) => $q->where('season', $season))
            ->pluck('id');

        // Count games per player from team lists
        $gamesPlayed = MatchTeamList::whereIn('match_id', $seasonMatchIds)
            ->select('player_id', DB::raw('COUNT(DISTINCT match_id) as games'))
            ->groupBy('player_id')
            ->pluck('games', 'player_id');

        // Count tries per player from try events
        $triesScored = TryEvent::whereIn('match_id', $seasonMatchIds)
            ->select('player_id', DB::raw('COUNT(*) as tries'))
            ->groupBy('player_id')
            ->pluck('tries', 'player_id');

        // For players with tries but no team list entries, count distinct matches they scored in
        $matchesScored = TryEvent::whereIn('match_id', $seasonMatchIds)
            ->select('player_id', DB::raw('COUNT(DISTINCT match_id) as matches'))
            ->groupBy('player_id')
            ->pluck('matches', 'player_id');

        // Get all player IDs who appeared in any match or scored any try
        $allPlayerIds = $gamesPlayed->keys()->merge($triesScored->keys())->unique();

        $updated = 0;
        foreach ($allPlayerIds as $playerId) {
            $games = $gamesPlayed[$playerId] ?? 0;
            $tries = $triesScored[$playerId] ?? 0;

            // If they scored tries but have no team list entries, use matches-scored as minimum games
            if ($games === 0 && $tries > 0) {
                $games = $matchesScored[$playerId] ?? 1;
            }

            $rate = $games > 0 ? round($tries / $games, 3) : 0;

            Player::where('id', $playerId)->update([
                'current_season_games' => $games,
                'current_season_tries' => $tries,
                'current_season_try_rate' => $rate,
            ]);
            $updated++;
        }

        // Zero out players who didn't appear
        Player::whereNotIn('id', $allPlayerIds)
            ->where(fn ($q) => $q->where('current_season_games', '>', 0)
                ->orWhere('current_season_tries', '>', 0))
            ->update([
                'current_season_games' => 0,
                'current_season_tries' => 0,
                'current_season_try_rate' => 0,
            ]);

        $this->line("    Updated {$updated} players");
    }

    protected function recalculateCareerStats(): void
    {
        $this->info('  Career stats (all seasons)...');

        // Career games = total distinct matches played across all seasons
        $careerGames = MatchTeamList::whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->select('player_id', DB::raw('COUNT(DISTINCT match_id) as games'))
            ->groupBy('player_id')
            ->pluck('games', 'player_id');

        // Career tries = total try events
        $careerTries = TryEvent::select('player_id', DB::raw('COUNT(*) as tries'))
            ->groupBy('player_id')
            ->pluck('tries', 'player_id');

        // Fallback: count matches scored in for players without team list entries
        $careerMatchesScored = TryEvent::select('player_id', DB::raw('COUNT(DISTINCT match_id) as matches'))
            ->groupBy('player_id')
            ->pluck('matches', 'player_id');

        $allIds = $careerGames->keys()->merge($careerTries->keys())->unique();
        $updated = 0;

        foreach ($allIds as $playerId) {
            $games = $careerGames[$playerId] ?? 0;
            $tries = $careerTries[$playerId] ?? 0;

            if ($games === 0 && $tries > 0) {
                $games = $careerMatchesScored[$playerId] ?? 1;
            }

            Player::where('id', $playerId)->update([
                'career_games' => $games,
                'career_tries' => $tries,
            ]);
            $updated++;
        }

        $this->line("    Updated {$updated} players");
    }

    protected function recalculateVenueStats(): void
    {
        $this->info('  Venue stats...');

        // Clear existing
        PlayerVenueStat::truncate();

        // For each player, count games and tries at each venue
        $rows = DB::select("
            SELECT
                mtl.player_id,
                m.venue,
                COUNT(DISTINCT m.id) as games,
                COUNT(te.id) as tries
            FROM match_team_lists mtl
            JOIN matches m ON m.id = mtl.match_id AND m.status = 'completed' AND m.venue IS NOT NULL AND m.venue != ''
            LEFT JOIN try_events te ON te.match_id = m.id AND te.player_id = mtl.player_id
            GROUP BY mtl.player_id, m.venue
            HAVING games > 0
        ");

        $inserted = 0;
        foreach ($rows as $row) {
            PlayerVenueStat::create([
                'player_id' => $row->player_id,
                'venue' => $row->venue,
                'games' => $row->games,
                'tries' => $row->tries,
                'try_rate' => $row->games > 0 ? round($row->tries / $row->games, 3) : 0,
            ]);
            $inserted++;
        }

        $this->line("    Inserted {$inserted} venue stat rows");
    }

    protected function recalculateOpponentStats(): void
    {
        $this->info('  Opponent stats...');

        // Clear existing
        PlayerOpponentStat::truncate();

        // For each player, determine opponent per match and count tries
        $players = Player::whereHas('matchTeamLists', fn ($q) => $q->whereHas('match', fn ($m) => $m->where('status', 'completed')))
            ->with(['matchTeamLists.match'])
            ->get();

        $stats = [];
        foreach ($players as $player) {
            foreach ($player->matchTeamLists as $mtl) {
                $match = $mtl->match;
                if (! $match || $match->status !== 'completed') {
                    continue;
                }

                $opponentId = $match->home_team_id === $mtl->team_id
                    ? $match->away_team_id
                    : $match->home_team_id;

                $key = "{$player->id}:{$opponentId}";
                if (! isset($stats[$key])) {
                    $stats[$key] = ['player_id' => $player->id, 'opponent_team_id' => $opponentId, 'games' => 0, 'tries' => 0];
                }
                $stats[$key]['games']++;
            }
        }

        // Count tries per opponent
        $triesByMatchPlayer = TryEvent::with('match')->get()->groupBy(fn ($te) => "{$te->player_id}:{$te->match_id}");

        foreach ($triesByMatchPlayer as $key => $events) {
            [$playerId, $matchId] = explode(':', $key);
            $match = $events->first()->match;
            if (! $match) continue;

            $player = Player::find($playerId);
            if (! $player) continue;

            $opponentId = $match->home_team_id === $player->team_id
                ? $match->away_team_id
                : $match->home_team_id;

            $statKey = "{$playerId}:{$opponentId}";
            if (isset($stats[$statKey])) {
                $stats[$statKey]['tries'] += $events->count();
            }
        }

        $inserted = 0;
        foreach ($stats as $stat) {
            PlayerOpponentStat::create([
                'player_id' => $stat['player_id'],
                'opponent_team_id' => $stat['opponent_team_id'],
                'games' => $stat['games'],
                'tries' => $stat['tries'],
                'try_rate' => $stat['games'] > 0 ? round($stat['tries'] / $stat['games'], 3) : 0,
            ]);
            $inserted++;
        }

        $this->line("    Inserted {$inserted} opponent stat rows");
    }

    protected function fixMissingPositions(): void
    {
        $this->info('  Fixing missing positions...');

        // Get position from team lists (most recent appearance)
        $playersWithoutPosition = Player::where(fn ($q) => $q->whereNull('position')->orWhere('position', ''))
            ->whereHas('matchTeamLists')
            ->pluck('id');

        $fixed = 0;
        foreach ($playersWithoutPosition as $playerId) {
            $latestList = MatchTeamList::where('player_id', $playerId)
                ->whereNotNull('position_number')
                ->orderByDesc('match_id')
                ->first();

            if (! $latestList || ! $latestList->position_number) {
                continue;
            }

            $position = $this->positionFromNumber($latestList->position_number);
            if ($position) {
                Player::where('id', $playerId)->update(['position' => $position]);
                $fixed++;
            }
        }

        $this->line("    Fixed {$fixed} player positions");
    }

    protected function positionFromNumber(int $number): ?string
    {
        return match ($number) {
            1 => 'fullback',
            2, 5 => 'winger',
            3, 4 => 'centre',
            6 => 'five-eighth',
            7 => 'halfback',
            8, 10 => 'prop',
            9 => 'hooker',
            11, 12 => 'second-row',
            13 => 'lock',
            default => null, // bench/interchange — can't determine position from number alone
        };
    }
}
