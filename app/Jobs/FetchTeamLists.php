<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\Player;
use App\Support\HttpScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Syncs named team lists for every upcoming match in the current round.
 *
 * Uses nrl.com's per-match JSON endpoint (/data sibling of each match URL),
 * which returns a structured homeTeam.players[] / awayTeam.players[] with
 * first/last name, position string, jersey number and a stable playerId.
 */
class FetchTeamLists implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 300;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'fetch:team-lists';
    }

    public function handle(HttpScraper $http): void
    {
        $this->startLog('nrl.com/match/data');
        $records = 0;
        $skipped = 0;

        try {
            $matches = Matchup::upcomingInCurrentRound()
                ->with(['round', 'homeTeam', 'awayTeam'])
                ->get();

            foreach ($matches as $match) {
                if (! $match->round || ! $match->homeTeam || ! $match->awayTeam) {
                    $skipped++;
                    continue;
                }

                $url = sprintf(
                    'https://www.nrl.com/draw/nrl-premiership/%d/round-%d/%s-v-%s/data',
                    $match->round->season,
                    $match->round->round_number,
                    $match->homeTeam->nrl_slug,
                    $match->awayTeam->nrl_slug,
                );

                $response = $http->get($url);
                if (! $response->successful()) {
                    Log::warning("FetchTeamLists: HTTP {$response->status()} for match {$match->id}");
                    $skipped++;
                    continue;
                }

                $data = $response->json() ?: [];
                $records += $this->syncSide($match, $match->home_team_id, data_get($data, 'homeTeam.players', []));
                $records += $this->syncSide($match, $match->away_team_id, data_get($data, 'awayTeam.players', []));
            }

            Log::info("FetchTeamLists: {$records} player rows upserted across " . ($matches->count() - $skipped) . ' matches');
            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    /** @param array<int, array<string, mixed>> $players */
    protected function syncSide(Matchup $match, int $teamId, array $players): int
    {
        $count = 0;
        $keptPlayerIds = [];
        foreach ($players as $raw) {
            $firstName = trim((string) data_get($raw, 'firstName'));
            $lastName = trim((string) data_get($raw, 'lastName'));
            $name = trim("{$firstName} {$lastName}");
            $number = (int) data_get($raw, 'number', 0);
            if ($name === '' || $number < 1) {
                continue;
            }

            $player = $this->resolvePlayer($name, $teamId, data_get($raw, 'playerId'), (string) data_get($raw, 'position'));

            if ($player->team_id !== $teamId) {
                $player->update(['team_id' => $teamId]);
            }

            // Positions only arrive on team-list day; backfill players created
            // before we could map one (bench-only strings map to null).
            if ($player->position === null) {
                $mapped = $this->mapPosition((string) data_get($raw, 'position'));
                if ($mapped !== null) {
                    $player->update(['position' => $mapped]);
                }
            }

            $role = match (true) {
                $number >= 14 && $number <= 17 => 'interchange',
                $number >= 18 => 'reserve',
                default => 'starting',
            };

            MatchTeamList::updateOrCreate(
                ['match_id' => $match->id, 'player_id' => $player->id],
                [
                    'team_id' => $teamId,
                    'position_number' => $number,
                    'role' => $role,
                    'is_confirmed' => (bool) data_get($raw, 'isOnField', false),
                ],
            );
            $keptPlayerIds[] = $player->id;
            $count++;
        }

        // Late outs and re-shuffles: anyone previously listed for this side of
        // the match who is no longer in the feed gets removed, otherwise
        // dropped players keep getting scored and picked for multis.
        if ($keptPlayerIds !== []) {
            MatchTeamList::where('match_id', $match->id)
                ->where('team_id', $teamId)
                ->whereNotIn('player_id', $keptPlayerIds)
                ->delete();
        }

        return $count;
    }

    /**
     * Prefer the stable NRL playerId over name slugs — two players can share
     * a name, and the same player can be spelt differently between feeds.
     */
    protected function resolvePlayer(string $name, int $teamId, mixed $nrlId, string $rawPosition): Player
    {
        $slug = Str::slug($name);
        $nrlId = is_numeric($nrlId) ? (int) $nrlId : null;

        $player = null;
        if ($nrlId) {
            $player = Player::where('nrl_player_id', $nrlId)->first();
        }
        if (! $player) {
            // A same-name row that carries a DIFFERENT stable id is a
            // different person — don't match it, fall through to create.
            $player = Player::where('nrl_slug', $slug)
                ->when($nrlId, fn ($q) => $q->where(
                    fn ($qq) => $qq->whereNull('nrl_player_id')->orWhere('nrl_player_id', $nrlId),
                ))
                ->first();
        }
        if (! $player && $nrlId) {
            // Legacy rows keyed by nrl-{id} slug; migrate to name-based slug
            $player = Player::where('nrl_slug', 'nrl-'.$nrlId)->first();
            if ($player && ! Player::where('nrl_slug', $slug)->exists()) {
                $player->update(['nrl_slug' => $slug]);
            }
        }

        if (! $player) {
            return Player::create([
                'nrl_slug' => Player::where('nrl_slug', $slug)->exists() ? "{$slug}-{$nrlId}" : $slug,
                'nrl_player_id' => $nrlId,
                'name' => $name,
                'team_id' => $teamId,
                'position' => $this->mapPosition($rawPosition),
            ]);
        }

        // Capture the stable id the first time we see it for an existing row.
        if ($nrlId && $player->nrl_player_id === null) {
            $player->update(['nrl_player_id' => $nrlId]);
        }

        return $player;
    }

    protected function mapPosition(string $raw): ?string
    {
        return match (Str::lower(trim($raw))) {
            'fullback' => 'fullback',
            'wing', 'winger' => 'winger',
            'centre' => 'centre',
            'five-eighth', 'five eighth' => 'five-eighth',
            'halfback' => 'halfback',
            'prop', 'front row' => 'prop',
            'hooker' => 'hooker',
            'second row', 'second-row' => 'second-row',
            'lock' => 'lock',
            default => null,
        };
    }
}
