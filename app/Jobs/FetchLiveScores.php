<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
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
 * Fetch live scores and try events from nrl.com's JSON API.
 * Runs every 5 minutes when there are live matches.
 */
class FetchLiveScores implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsDataFetch, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 2;

    public int $uniqueFor = 240;

    public function uniqueId(): string
    {
        return 'fetch:live-scores';
    }

    public function backoff(): array
    {
        return [10];
    }

    public function handle(HttpScraper $http): void
    {
        $round = Round::current();
        if (! $round) {
            return;
        }

        $this->startLog('nrl.com/draw-live');
        $records = 0;

        try {
            // Use the draw JSON API to get current scores and status
            $url = sprintf(
                'https://www.nrl.com/draw/data?competition=111&season=%d&round=%d',
                $round->season,
                $round->round_number,
            );

            $response = $http->get($url);
            if (! $response->successful()) {
                $this->completeLog(0);

                return;
            }

            $fixtures = data_get($response->json(), 'fixtures', []);

            foreach ($fixtures as $fixture) {
                $homeNickname = data_get($fixture, 'homeTeam.nickName');
                $awayNickname = data_get($fixture, 'awayTeam.nickName');
                $homeScore = data_get($fixture, 'homeTeam.score');
                $awayScore = data_get($fixture, 'awayTeam.score');
                $matchState = Str::lower($fixture['matchState'] ?? '');

                $status = match (true) {
                    in_array($matchState, ['fulltime', 'post', 'postmatch']) => 'completed',
                    in_array($matchState, ['live', 'inprogress', 'halftime']) => 'live',
                    str_contains($matchState, 'half') => 'live',
                    str_contains($matchState, 'live') => 'live',
                    default => null,
                };

                if ($status === null) {
                    continue; // Skip upcoming matches
                }

                $match = $this->findMatch($homeNickname, $awayNickname, $round);
                if (! $match) {
                    continue;
                }

                $match->update([
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'status' => $status,
                ]);

                // For live or just-completed matches, try to fetch try events
                $matchCentreUrl = $fixture['matchCentreUrl'] ?? null;
                if ($matchCentreUrl && ! TryEvent::where('match_id', $match->id)->exists()) {
                    $records += $this->fetchTryEvents($http, $match, $matchCentreUrl);
                } elseif ($matchCentreUrl && $status === 'live') {
                    // For live matches, always refresh try events
                    $records += $this->fetchTryEvents($http, $match, $matchCentreUrl);
                }

                $records++;
            }

            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    protected function findMatch(?string $homeNickname, ?string $awayNickname, Round $round): ?Matchup
    {
        if (! $homeNickname || ! $awayNickname) {
            return null;
        }

        $homeSlug = Str::slug($homeNickname);
        $awaySlug = Str::slug($awayNickname);
        $aliases = ['tigers' => 'wests-tigers', 'eagles' => 'sea-eagles'];
        $homeSlug = $aliases[$homeSlug] ?? $homeSlug;
        $awaySlug = $aliases[$awaySlug] ?? $awaySlug;

        return Matchup::where('round_id', $round->id)
            ->whereHas('homeTeam', fn ($q) => $q->where('nrl_slug', $homeSlug))
            ->whereHas('awayTeam', fn ($q) => $q->where('nrl_slug', $awaySlug))
            ->first();
    }

    protected function fetchTryEvents(HttpScraper $http, Matchup $match, string $matchCentreUrl): int
    {
        $url = 'https://www.nrl.com'.rtrim($matchCentreUrl, '/').'/data';
        $response = $http->get($url);
        if (! $response->successful()) {
            return 0;
        }

        $data = $response->json();
        $timeline = $data['timeline'] ?? [];
        $count = 0;

        // Build team ID mapping
        $teamMap = [];
        foreach (['homeTeam', 'awayTeam'] as $side) {
            $nrlTeamId = data_get($data, "{$side}.teamId");
            if ($nrlTeamId) {
                $teamMap[$nrlTeamId] = $side === 'homeTeam' ? $match->home_team_id : $match->away_team_id;
            }
        }

        foreach ($timeline as $event) {
            if (($event['type'] ?? '') !== 'Try') {
                continue;
            }

            $nrlPlayerId = $event['playerId'] ?? null;
            if (! $nrlPlayerId) {
                continue;
            }

            // Resolve player name from match data
            $name = null;
            foreach (['homeTeam', 'awayTeam'] as $side) {
                foreach ($data[$side]['players'] ?? [] as $p) {
                    if (($p['playerId'] ?? 0) === $nrlPlayerId) {
                        $name = trim(($p['firstName'] ?? '').' '.($p['lastName'] ?? ''));
                        break 2;
                    }
                }
            }

            if (! $name) {
                $contentName = data_get($event, 'content.name');
                if ($contentName) {
                    $name = preg_replace('/\s+\d*(st|nd|rd|th)?\s*try\s*$/i', '', trim($contentName));
                }
            }

            if (! $name) {
                continue;
            }

            $slug = Str::slug($name);
            $player = Player::where('nrl_slug', $slug)->first();
            if (! $player) {
                $teamId = $teamMap[$event['teamId'] ?? 0] ?? null;
                $player = Player::create([
                    'nrl_slug' => $slug,
                    'name' => $name,
                    'team_id' => $teamId,
                ]);
            }

            $minute = isset($event['gameSeconds']) ? (int) round($event['gameSeconds'] / 60) : null;

            TryEvent::firstOrCreate([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'minute' => $minute,
            ]);
            $count++;
        }

        return $count;
    }
}
