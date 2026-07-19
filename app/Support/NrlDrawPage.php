<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads fixtures from ESPN's public NRL scoreboard API.
 *
 * NRL's former draw and match-data endpoints now return an authenticated HTML
 * flow (or 406) to automated clients. ESPN's scoreboard exposes the complete
 * NRL fixture feed as JSON without authentication.
 */
class NrlDrawPage
{
    /** @var array<int, string> */
    private const NRL_CLUB_NAMES = [
        'Broncos', 'Bulldogs', 'Cowboys', 'Dolphins', 'Dragons', 'Eels',
        'Knights', 'Panthers', 'Rabbitohs', 'Raiders', 'Roosters', 'Sea Eagles',
        'Sharks', 'Storm', 'Tigers', 'Titans', 'Warriors',
    ];

    /** @return array<int, array<string, mixed>> */
    public function fixtures(HttpScraper $http, int $season, int $round): array
    {
        return $this->fixturesForRounds($http, $season, [$round])[$round];
    }

    /** @param array<int, int> $rounds
     *  @return array<int, array<int, array<string, mixed>>> */
    public function fixturesForRounds(HttpScraper $http, int $season, array $rounds): array
    {
        $rounds = array_values(array_unique(array_map('intval', $rounds)));
        if ($rounds === []) {
            throw new RuntimeException('At least one NRL round must be requested');
        }

        $url = sprintf(
            'https://site.api.espn.com/apis/site/v2/sports/rugby-league/3/scoreboard?limit=500&dates=%d0101-%d1231',
            $season,
            $season,
        );

        $payload = $http->json($url, ['events']);
        $events = data_get($payload, 'events');
        if (! is_array($events)) {
            throw new RuntimeException("The ESPN NRL scoreboard at {$url} is missing events");
        }

        $fixtures = array_fill_keys($rounds, []);
        foreach ($events as $event) {
            $eventRound = (int) data_get($event, 'week.number');
            if (! is_array($event)
                || (int) data_get($event, 'season.year') !== $season
                || ! in_array($eventRound, $rounds, true)
                || ! $this->isClubFixture($event)) {
                continue;
            }

            $fixtures[$eventRound][] = $this->normaliseEvent($event, $url);
        }

        $missingRounds = array_keys(array_filter($fixtures, fn (array $matches) => $matches === []));
        if ($missingRounds !== []) {
            throw new RuntimeException(
                "The ESPN NRL scoreboard at {$url} contains no fixtures for round ".implode(', ', $missingRounds),
            );
        }

        return $fixtures;
    }

    /** @return array<string, mixed> */
    protected function normaliseEvent(array $event, string $url): array
    {
        $competition = data_get($event, 'competitions.0');
        $home = collect(data_get($competition, 'competitors', []))->firstWhere('homeAway', 'home');
        $away = collect(data_get($competition, 'competitors', []))->firstWhere('homeAway', 'away');
        $kickoff = data_get($competition, 'date', data_get($event, 'date'));
        $homeName = data_get($home, 'team.displayName', data_get($home, 'team.name'));
        $awayName = data_get($away, 'team.displayName', data_get($away, 'team.name'));

        if (! is_array($competition) || ! is_array($home) || ! is_array($away)
            || ! is_string($kickoff) || ! is_string($homeName) || ! is_string($awayName)) {
            $eventId = data_get($event, 'id', 'unknown');
            throw new RuntimeException("The ESPN NRL scoreboard at {$url} has an invalid fixture ({$eventId})");
        }

        return [
            'type' => 'Match',
            'matchState' => match (data_get($competition, 'status.type.state')) {
                'post' => 'fulltime',
                'in' => 'live',
                default => 'upcoming',
            },
            'venue' => data_get($competition, 'venue.fullName'),
            'venueCity' => data_get($competition, 'venue.address.city'),
            'clock' => ['kickOffTimeLong' => $kickoff],
            'homeTeam' => [
                'nickName' => $homeName,
                'score' => $this->score($home),
            ],
            'awayTeam' => [
                'nickName' => $awayName,
                'score' => $this->score($away),
            ],
        ];
    }

    protected function isClubFixture(array $event): bool
    {
        $teams = collect(data_get($event, 'competitions.0.competitors', []))
            ->map(fn (mixed $competitor) => data_get($competitor, 'team.displayName', data_get($competitor, 'team.name')))
            ->all();

        return count($teams) === 2
            && collect($teams)->every(fn (mixed $team) => is_string($team) && in_array($team, self::NRL_CLUB_NAMES, true));
    }

    protected function score(array $competitor): ?int
    {
        $score = data_get($competitor, 'score');

        return is_numeric($score) ? (int) $score : null;
    }
}
