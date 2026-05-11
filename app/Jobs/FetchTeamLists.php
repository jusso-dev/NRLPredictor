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
        foreach ($players as $raw) {
            $firstName = trim((string) data_get($raw, 'firstName'));
            $lastName = trim((string) data_get($raw, 'lastName'));
            $name = trim("{$firstName} {$lastName}");
            $number = (int) data_get($raw, 'number', 0);
            if ($name === '' || $number < 1) {
                continue;
            }

            $slug = Str::slug($name);

            // Find existing player by name slug, or by legacy nrl-ID slug
            $nrlId = data_get($raw, 'playerId');
            $player = Player::where('nrl_slug', $slug)->first();
            if (! $player && $nrlId) {
                $player = Player::where('nrl_slug', 'nrl-' . $nrlId)->first();
                // Migrate legacy slug to name-based slug
                if ($player) {
                    $player->update(['nrl_slug' => $slug]);
                }
            }
            if (! $player) {
                $player = Player::create([
                    'nrl_slug' => $slug,
                    'name' => $name,
                    'team_id' => $teamId,
                    'position' => $this->mapPosition((string) data_get($raw, 'position')),
                ]);
            }

            if ($player->team_id !== $teamId) {
                $player->update(['team_id' => $teamId]);
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
            $count++;
        }
        return $count;
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
