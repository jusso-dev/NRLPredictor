<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Player;
use App\Models\Round;
use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches NRL match and player odds from The Odds API (v4).
 *
 * Flow:
 *  1. GET /events (free) — link event IDs to our matches table
 *  2. GET /odds (h2h, spreads, totals) — match-level odds from AU bookmakers
 *  3. GET /events/{id}/odds (player_try_scorer_anytime) per event — ATS odds
 *
 * Costs ~3 credits for match odds + 1 per event for player props.
 */
class FetchOdds implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, LogsDataFetch, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function backoff(): array
    {
        return [30];
    }

    protected const SPORT_KEY = 'rugbyleague_nrl';

    protected const BASE_URL = 'https://api.the-odds-api.com/v4';

    protected const REGION = 'au';

    public function __construct(
        public bool $includePlayerProps = true,
    ) {}

    public function uniqueId(): string
    {
        return 'fetch:odds';
    }

    public function handle(): void
    {
        $apiKey = config('services.odds_api.key');
        if (! $apiKey) {
            Log::warning('FetchOdds: ODDS_API_KEY not configured, skipping.');

            return;
        }

        $this->startLog('the-odds-api.com');
        $records = 0;

        try {
            // Step 1: Fetch events (free) and link to our matches
            $events = $this->fetchEvents($apiKey);
            if ($events->isEmpty()) {
                $this->completeLog(0);

                return;
            }

            $this->linkEventsToMatches($events);

            // Step 2: Fetch match-level odds (h2h, spreads, totals)
            $records += $this->fetchMatchOdds($apiKey);

            // Step 3: Fetch player try scorer odds per event
            if ($this->includePlayerProps) {
                $records += $this->fetchPlayerOdds($apiKey);
            }

            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    /**
     * GET /v4/sports/{sport}/events — free, returns event list with team names and times.
     */
    protected function fetchEvents(string $apiKey): Collection
    {
        $response = Http::timeout(30)->get(self::BASE_URL.'/sports/'.self::SPORT_KEY.'/events', [
            'apiKey' => $apiKey,
        ]);

        if (! $response->successful()) {
            Log::warning('FetchOdds: events endpoint returned '.$response->status());

            return collect();
        }

        $this->logQuota($response);

        return collect($response->json());
    }

    /**
     * Match Odds API events to our matches table by team names + kickoff proximity.
     */
    protected function linkEventsToMatches(Collection $events): void
    {
        $round = Round::current();
        $matches = Matchup::with(['homeTeam', 'awayTeam'])
            ->where('status', '!=', 'completed')
            ->when($round, fn ($q) => $q->where('round_id', $round->id))
            ->get();

        foreach ($events as $event) {
            $homeTeam = $this->resolveTeam($event['home_team'] ?? '');
            $awayTeam = $this->resolveTeam($event['away_team'] ?? '');

            if (! $homeTeam || ! $awayTeam) {
                Log::debug('FetchOdds: could not resolve teams for event', [
                    'home' => $event['home_team'] ?? null,
                    'away' => $event['away_team'] ?? null,
                ]);

                continue;
            }

            // Find matching fixture
            $match = $matches->first(function ($m) use ($homeTeam, $awayTeam, $event) {
                if ($m->home_team_id !== $homeTeam->id || $m->away_team_id !== $awayTeam->id) {
                    return false;
                }
                // If we have kickoff times, verify they're within 24h of each other
                if ($m->kickoff_at && isset($event['commence_time'])) {
                    $eventTime = Carbon::parse($event['commence_time']);

                    return abs($m->kickoff_at->diffInHours($eventTime)) < 24;
                }

                return true;
            });

            if ($match) {
                $match->update(['odds_api_event_id' => $event['id']]);
            }
        }
    }

    /**
     * GET /v4/sports/{sport}/odds — h2h, spreads, totals for all events.
     * Cost: 3 credits (3 markets × 1 region).
     */
    protected function fetchMatchOdds(string $apiKey): int
    {
        $response = Http::timeout(30)->get(self::BASE_URL.'/sports/'.self::SPORT_KEY.'/odds', [
            'apiKey' => $apiKey,
            'regions' => self::REGION,
            'markets' => 'h2h,spreads,totals',
            'oddsFormat' => 'decimal',
        ]);

        if (! $response->successful()) {
            Log::warning('FetchOdds: odds endpoint returned '.$response->status());

            return 0;
        }

        $this->logQuota($response);
        $now = now();
        $records = 0;

        foreach ($response->json() as $event) {
            $match = Matchup::where('odds_api_event_id', $event['id'])->first();
            if (! $match) {
                continue;
            }

            foreach ($event['bookmakers'] ?? [] as $bookmaker) {
                foreach ($bookmaker['markets'] ?? [] as $market) {
                    $records += $this->storeMatchMarket(
                        $match,
                        $bookmaker['key'],
                        $market,
                        $now,
                    );
                }
            }
        }

        return $records;
    }

    /**
     * Store a single market's outcomes as odds snapshots.
     */
    protected function storeMatchMarket(Matchup $match, string $bookmakerKey, array $market, Carbon $now): int
    {
        $marketKey = $market['key'];

        // Map Odds API market keys to our market names
        $marketName = match ($marketKey) {
            'h2h' => 'match_winner',
            'spreads' => 'spreads',
            'totals' => 'totals',
            default => $marketKey,
        };

        $count = 0;
        foreach ($market['outcomes'] ?? [] as $outcome) {
            $price = $outcome['price'] ?? null;
            if (! $price || $price <= 1.0) {
                continue;
            }

            // Normalise outcome name so match_winner/spreads rows distinguish home vs away
            // (totals use Over/Under, which is already distinct). Without this, two outcomes
            // per market+bookmaker collide on the unique key and we lose one side's price.
            $outcomeName = $this->normaliseOutcomeName($outcome['name'] ?? null, $match, $marketName);

            OddsSnapshot::updateOrCreate(
                [
                    'match_id' => $match->id,
                    'player_id' => null,
                    'market' => $marketName,
                    'outcome' => $outcomeName,
                    'bookmaker' => $bookmakerKey,
                ],
                [
                    'decimal_odds' => $price,
                    'point' => $outcome['point'] ?? null,
                    'captured_at' => $now,
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Reduce an Odds API outcome name to "home", "away", "over", "under", or the raw name.
     * Used so each side of a two-way market has its own stored row.
     */
    protected function normaliseOutcomeName(?string $name, Matchup $match, string $marketName): ?string
    {
        if (! $name) {
            return null;
        }

        $lower = Str::lower($name);
        if ($lower === 'over' || str_starts_with($lower, 'over ')) {
            return 'over';
        }
        if ($lower === 'under' || str_starts_with($lower, 'under ')) {
            return 'under';
        }

        $team = $this->resolveTeam($name);
        if ($team) {
            if ($team->id === $match->home_team_id) {
                return 'home';
            }
            if ($team->id === $match->away_team_id) {
                return 'away';
            }
        }

        return Str::limit($lower, 16, '');
    }

    /**
     * Fetch anytime try scorer odds per linked event.
     * Cost: 1 credit per event.
     */
    protected function fetchPlayerOdds(string $apiKey): int
    {
        $matches = Matchup::whereNotNull('odds_api_event_id')
            ->where('status', '!=', 'completed')
            ->get();

        $records = 0;

        foreach ($matches as $match) {
            $response = Http::timeout(30)->get(
                self::BASE_URL.'/sports/'.self::SPORT_KEY.'/events/'.$match->odds_api_event_id.'/odds',
                [
                    'apiKey' => $apiKey,
                    'regions' => self::REGION,
                    'markets' => 'player_try_scorer_anytime',
                    'oddsFormat' => 'decimal',
                ],
            );

            if (! $response->successful()) {
                Log::debug("FetchOdds: player odds failed for event {$match->odds_api_event_id}", [
                    'status' => $response->status(),
                ]);

                continue;
            }

            $this->logQuota($response);
            $now = now();

            foreach ($response->json('bookmakers') ?? [] as $bookmaker) {
                foreach ($bookmaker['markets'] ?? [] as $market) {
                    if ($market['key'] !== 'player_try_scorer_anytime') {
                        continue;
                    }

                    foreach ($market['outcomes'] ?? [] as $outcome) {
                        // Only store "Yes" outcomes (the player scores a try)
                        if (($outcome['name'] ?? '') !== 'Yes') {
                            continue;
                        }

                        $playerName = $outcome['description'] ?? null;
                        if (! $playerName) {
                            continue;
                        }

                        $player = $this->resolvePlayer($playerName, $match);
                        if (! $player) {
                            continue;
                        }

                        $price = $outcome['price'] ?? null;
                        if (! $price || $price <= 1.0) {
                            continue;
                        }

                        OddsSnapshot::updateOrCreate(
                            [
                                'match_id' => $match->id,
                                'player_id' => $player->id,
                                'market' => 'ats',
                                'bookmaker' => $bookmaker['key'],
                            ],
                            [
                                'decimal_odds' => $price,
                                'point' => null,
                                'captured_at' => $now,
                            ],
                        );
                        $records++;
                    }
                }
            }

            // Brief pause between per-event requests to be respectful
            usleep(250_000);
        }

        return $records;
    }

    /**
     * Resolve a team name from The Odds API to our Team model.
     * The API uses names like "Brisbane Broncos", "Sydney Roosters", etc.
     */
    protected function resolveTeam(?string $name): ?Team
    {
        if (! $name) {
            return null;
        }

        // Direct match on full name
        $team = Team::where('name', $name)->first();
        if ($team) {
            return $team;
        }

        // Match on short_name being contained in the API name
        $team = Team::get()->first(function ($t) use ($name) {
            return Str::contains($name, $t->short_name, ignoreCase: true);
        });
        if ($team) {
            return $team;
        }

        // Alias map for common Odds API name differences
        $aliases = [
            'Canterbury Bulldogs' => 'bulldogs',
            'Cronulla Sharks' => 'sharks',
            'Manly Sea Eagles' => 'sea-eagles',
            'Dolphins' => 'dolphins',
            'Warriors' => 'warriors',
        ];

        $slug = $aliases[$name] ?? null;
        if ($slug) {
            return Team::where('nrl_slug', $slug)->first();
        }

        // Last resort: extract the last word (nickname) and slug-match
        $nickname = Str::afterLast($name, ' ');

        return Team::where('nrl_slug', Str::slug($nickname))->first();
    }

    /**
     * Resolve a player name from odds data to our Player model.
     * Tries exact match, then fuzzy last-name match scoped to the match teams.
     */
    protected function resolvePlayer(string $name, Matchup $match): ?Player
    {
        $teamIds = [$match->home_team_id, $match->away_team_id];

        // Exact name match within teams playing this match
        $player = Player::where('name', $name)->whereIn('team_id', $teamIds)->first();
        if ($player) {
            return $player;
        }

        // The Odds API typically uses "First Last" format.
        // Try matching on last name + team scope for uniqueness.
        $parts = explode(' ', $name);
        $lastName = end($parts);

        $candidates = Player::where('name', 'LIKE', "%{$lastName}")
            ->whereIn('team_id', $teamIds)
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // If multiple last-name matches, try first initial + last name
        if ($candidates->count() > 1 && count($parts) >= 2) {
            $firstInitial = mb_substr($parts[0], 0, 1);
            $match = $candidates->first(fn ($p) => Str::startsWith($p->name, $firstInitial));
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Log API quota usage from response headers.
     */
    protected function logQuota($response): void
    {
        $remaining = $response->header('x-requests-remaining');
        $used = $response->header('x-requests-used');
        $cost = $response->header('x-requests-last');

        if ($remaining !== null) {
            Log::info("FetchOdds quota: used={$used}, remaining={$remaining}, last_cost={$cost}");
        }
    }
}
