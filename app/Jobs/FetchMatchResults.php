<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\MatchTeamList;
use App\Models\MatchTeamStats;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Round;
use App\Models\TryEvent;
use App\Support\HttpScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetch try events and player stats from nrl.com match centre JSON
 * for completed matches. This populates the try_events table (needed
 * for accuracy tracking) and updates player season stats.
 */
class FetchMatchResults implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 600;
    public int $tries = 1;
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'fetch:match-results';
    }

    public function handle(HttpScraper $http): void
    {
        $round = Round::current();
        if (! $round) {
            return;
        }

        $this->startLog('nrl.com/match-data');
        $records = 0;

        try {
            // Get the draw to find matchCentreUrls for completed matches
            $drawUrl = sprintf(
                'https://www.nrl.com/draw/data?competition=111&season=%d&round=%d',
                $round->season,
                $round->round_number,
            );

            $drawResponse = $http->get($drawUrl);
            if (! $drawResponse->successful()) {
                $this->completeLog(0);
                return;
            }

            $fixtures = data_get($drawResponse->json(), 'fixtures', []);
            $completed = collect($fixtures)->filter(fn ($f) => in_array(
                Str::lower($f['matchState'] ?? ''),
                ['fulltime', 'post', 'postmatch'],
            ));

            foreach ($completed as $fixture) {
                $matchCentreUrl = $fixture['matchCentreUrl'] ?? null;
                if (! $matchCentreUrl) {
                    continue;
                }

                $records += $this->processMatch($http, $matchCentreUrl, $fixture);
            }

            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    protected function processMatch(HttpScraper $http, string $matchCentreUrl, array $fixture): int
    {
        // Resolve the match in our database
        $homeNickname = data_get($fixture, 'homeTeam.nickName');
        $awayNickname = data_get($fixture, 'awayTeam.nickName');
        $match = $this->findMatch($homeNickname, $awayNickname);
        if (! $match) {
            return 0;
        }

        // If we already have try events, team lists, AND team stats, skip
        $hasTryEvents = TryEvent::where('match_id', $match->id)->exists();
        $hasTeamLists = MatchTeamList::where('match_id', $match->id)->exists();
        $hasTeamStats = MatchTeamStats::where('match_id', $match->id)->count() >= 2;
        if ($hasTryEvents && $hasTeamLists && $hasTeamStats) {
            return 0;
        }

        // Fetch match centre JSON
        $url = 'https://www.nrl.com' . rtrim($matchCentreUrl, '/') . '/data';
        $response = $http->get($url);
        if (! $response->successful()) {
            return 0;
        }

        $data = $response->json();
        $count = 0;

        // Build NRL teamId → our team_id mapping
        $teamMap = [];
        foreach (['homeTeam', 'awayTeam'] as $side) {
            $nrlTeamId = data_get($data, "{$side}.teamId");
            if ($nrlTeamId) {
                $teamMap[$nrlTeamId] = $side === 'homeTeam' ? $match->home_team_id : $match->away_team_id;
            }
        }

        // Extract try events from timeline
        $timeline = $data['timeline'] ?? [];
        foreach ($timeline as $event) {
            if (($event['type'] ?? '') !== 'Try') {
                continue;
            }

            $nrlPlayerId = $event['playerId'] ?? null;
            if (! $nrlPlayerId) {
                continue;
            }

            // Find or create the player
            $player = $this->resolvePlayer($nrlPlayerId, $event, $data, $teamMap);
            if (! $player) {
                continue;
            }

            $minute = isset($event['gameSeconds']) ? (int) round($event['gameSeconds'] / 60) : null;

            TryEvent::firstOrCreate([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'minute' => $minute,
            ]);
            $count++;
        }

        // Build team lists if missing (for completed matches we didn't scrape live)
        if (MatchTeamList::where('match_id', $match->id)->count() === 0) {
            $this->buildTeamLists($match, $data, $teamMap);
        }

        // Update player season stats from the match data
        $this->updatePlayerStats($data);

        // Capture team-level match stats (completion rate, run metres, etc.)
        if (! $hasTeamStats) {
            $this->captureMatchTeamStats($match, $data);
        }

        return $count;
    }

    /**
     * Parse stats.groups from nrl.com match centre JSON and persist per-team
     * aggregates we care about for prediction signals. Silently skips stats
     * that aren't present — NRL occasionally reshuffles the group layout.
     */
    protected function captureMatchTeamStats(Matchup $match, array $data): void
    {
        $groups = data_get($data, 'stats.groups', []);
        if (empty($groups)) {
            return;
        }

        $home = ['match_id' => $match->id, 'team_id' => $match->home_team_id, 'side' => 'home'];
        $away = ['match_id' => $match->id, 'team_id' => $match->away_team_id, 'side' => 'away'];

        foreach ($groups as $group) {
            foreach (($group['stats'] ?? []) as $stat) {
                $title = $stat['title'] ?? '';
                $homeVal = data_get($stat, 'homeValue.value');
                $awayVal = data_get($stat, 'awayValue.value');

                switch ($title) {
                    case 'Possession %':
                        $home['possession_pct'] = (int) $homeVal;
                        $away['possession_pct'] = (int) $awayVal;
                        break;
                    case 'Completion Rate':
                        $home['completion_numerator'] = (int) data_get($stat, 'homeValue.numerator');
                        $home['completion_denominator'] = (int) data_get($stat, 'homeValue.denominator');
                        $away['completion_numerator'] = (int) data_get($stat, 'awayValue.numerator');
                        $away['completion_denominator'] = (int) data_get($stat, 'awayValue.denominator');
                        break;
                    case 'All Runs':
                        $home['all_runs'] = (int) $homeVal;
                        $away['all_runs'] = (int) $awayVal;
                        break;
                    case 'All Run Metres':
                        $home['all_run_metres'] = (int) $homeVal;
                        $away['all_run_metres'] = (int) $awayVal;
                        break;
                    case 'Post Contact Metres':
                        $home['post_contact_metres'] = (int) $homeVal;
                        $away['post_contact_metres'] = (int) $awayVal;
                        break;
                    case 'Line Breaks':
                        $home['line_breaks'] = (int) $homeVal;
                        $away['line_breaks'] = (int) $awayVal;
                        break;
                    case 'Tackle Breaks':
                        $home['tackle_breaks'] = (int) $homeVal;
                        $away['tackle_breaks'] = (int) $awayVal;
                        break;
                    case 'Offloads':
                        $home['offloads'] = (int) $homeVal;
                        $away['offloads'] = (int) $awayVal;
                        break;
                    case 'Kicks':
                        $home['kicks'] = (int) $homeVal;
                        $away['kicks'] = (int) $awayVal;
                        break;
                    case 'Kicking Metres':
                        $home['kicking_metres'] = (int) $homeVal;
                        $away['kicking_metres'] = (int) $awayVal;
                        break;
                    case 'Forced Drop Outs':
                        $home['forced_drop_outs'] = (int) $homeVal;
                        $away['forced_drop_outs'] = (int) $awayVal;
                        break;
                    case 'Effective Tackle %':
                        $home['effective_tackle_pct'] = (float) $homeVal;
                        $away['effective_tackle_pct'] = (float) $awayVal;
                        break;
                    case 'Tackles Made':
                        $home['tackles_made'] = (int) $homeVal;
                        $away['tackles_made'] = (int) $awayVal;
                        break;
                    case 'Missed Tackles':
                        $home['missed_tackles'] = (int) $homeVal;
                        $away['missed_tackles'] = (int) $awayVal;
                        break;
                    case 'Errors':
                        $home['errors'] = (int) $homeVal;
                        $away['errors'] = (int) $awayVal;
                        break;
                    case 'Penalties Conceded':
                        $home['penalties_conceded'] = (int) $homeVal;
                        $away['penalties_conceded'] = (int) $awayVal;
                        break;
                    case 'Ruck Infringements':
                        $home['ruck_infringements'] = (int) $homeVal;
                        $away['ruck_infringements'] = (int) $awayVal;
                        break;
                }
            }
        }

        MatchTeamStats::updateOrCreate(
            ['match_id' => $match->id, 'team_id' => $match->home_team_id],
            $home,
        );
        MatchTeamStats::updateOrCreate(
            ['match_id' => $match->id, 'team_id' => $match->away_team_id],
            $away,
        );
    }

    protected function findMatch(?string $homeNickname, ?string $awayNickname): ?Matchup
    {
        if (! $homeNickname || ! $awayNickname) {
            return null;
        }

        $homeSlug = $this->nicknameToSlug($homeNickname);
        $awaySlug = $this->nicknameToSlug($awayNickname);

        return Matchup::whereHas('homeTeam', fn ($q) => $q->where('nrl_slug', $homeSlug))
            ->whereHas('awayTeam', fn ($q) => $q->where('nrl_slug', $awaySlug))
            ->where('status', 'completed')
            ->first();
    }

    protected function nicknameToSlug(string $nickname): string
    {
        $slug = Str::slug($nickname);
        $aliases = [
            'tigers' => 'wests-tigers',
            'eagles' => 'sea-eagles',
        ];
        return $aliases[$slug] ?? $slug;
    }

    protected function resolvePlayer(int $nrlPlayerId, array $event, array $matchData, array $teamMap = []): ?Player
    {
        $name = null;
        $teamId = $event['teamId'] ?? null;

        // First try to find from the match player lists (most reliable)
        foreach (['homeTeam', 'awayTeam'] as $side) {
            $players = $matchData[$side]['players'] ?? [];
            foreach ($players as $p) {
                if (($p['playerId'] ?? 0) === $nrlPlayerId) {
                    $name = trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? ''));
                    break 2;
                }
            }
        }

        // Fallback to content name
        if (! $name) {
            $contentName = data_get($event, 'content.name');
            if ($contentName) {
                // "Lehi Hopoate 2nd Try" → "Lehi Hopoate"
                $name = preg_replace('/\s+\d*(st|nd|rd|th)?\s*try\s*$/i', '', trim($contentName));
                $name = trim($name);
            }
        }

        if (! $name) {
            return null;
        }

        $slug = Str::slug($name);

        // Try to find existing player
        $player = Player::where('nrl_slug', $slug)->first()
            ?? Player::where('name', 'LIKE', "%{$name}%")->first();

        // If not found, create them
        if (! $player) {
            $resolvedTeamId = $teamMap[$teamId] ?? null;

            $player = Player::create([
                'nrl_slug' => $slug,
                'name' => $name,
                'team_id' => $resolvedTeamId,
            ]);
        }

        return $player;
    }

    protected function buildTeamLists(Matchup $match, array $matchData, array $teamMap): void
    {
        foreach (['homeTeam', 'awayTeam'] as $side) {
            $teamId = $side === 'homeTeam' ? $match->home_team_id : $match->away_team_id;
            $players = $matchData[$side]['players'] ?? [];

            foreach ($players as $p) {
                $name = trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? ''));
                if (! $name) {
                    continue;
                }

                $slug = Str::slug($name);
                $player = Player::where('nrl_slug', $slug)->first();
                if (! $player) {
                    $player = Player::create([
                        'nrl_slug' => $slug,
                        'name' => $name,
                        'team_id' => $teamId,
                    ]);
                }

                $posNumber = $p['number'] ?? null;
                $position = Str::lower($p['position'] ?? '');
                $validPositions = ['fullback', 'winger', 'centre', 'five-eighth', 'halfback', 'hooker', 'prop', 'second-row', 'lock'];
                $role = ($posNumber && $posNumber <= 13) ? 'starting' : 'interchange';

                // Update player position if missing
                if (! $player->position && in_array($position, $validPositions, true)) {
                    $player->update(['position' => $position]);
                }

                MatchTeamList::firstOrCreate([
                    'match_id' => $match->id,
                    'player_id' => $player->id,
                ], [
                    'team_id' => $teamId,
                    'position_number' => $posNumber,
                    'role' => $role,
                ]);
            }
        }
    }

    protected function updatePlayerStats(array $matchData): void
    {
        // The stats.players section has per-player stats if available
        $playerStats = data_get($matchData, 'stats.players', []);

        foreach (['homeTeam', 'awayTeam'] as $side) {
            $players = $matchData[$side]['players'] ?? [];
            foreach ($players as $p) {
                $name = trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? ''));
                if (! $name) {
                    continue;
                }

                $slug = Str::slug($name);
                $player = Player::where('nrl_slug', $slug)->first();
                if (! $player) {
                    continue;
                }

                // Update position if available and valid
                $position = Str::lower($p['position'] ?? '');
                $validPositions = ['fullback', 'winger', 'centre', 'five-eighth', 'halfback', 'hooker', 'prop', 'second-row', 'lock'];
                if ($position && ! $player->position && in_array($position, $validPositions, true)) {
                    $player->update(['position' => $position]);
                }
            }
        }
    }
}
