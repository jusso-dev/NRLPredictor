<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Models\Round;
use App\Models\Team;
use App\Support\HttpScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls the real NRL fixture list from nrl.com's JSON endpoint and
 * upserts rounds + matches. Replaces the hardcoded SeedRoundCommand data.
 */
class FetchDraw implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 600;
    public int $tries = 1;
    public int $uniqueFor = 900;

    public function __construct(
        public ?int $season = null,
        public ?int $round = null,
    ) {}

    public function uniqueId(): string
    {
        return 'fetch:draw:'.($this->season ?? 'current').':'.($this->round ?? 'all');
    }

    public function handle(HttpScraper $http): void
    {
        $season = $this->season ?? now()->year;
        $rounds = $this->round ? [$this->round] : $this->detectCurrentRounds($season);

        $this->startLog('nrl.com/draw/data');
        $records = 0;

        try {
            foreach ($rounds as $roundNumber) {
                $records += $this->fetchRound($http, $season, $roundNumber);
            }
            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    /**
     * Work out which round(s) to fetch when none was specified.
     * If a current round exists, fetch it plus the next one (for previews).
     * On a fresh DB with no rounds yet, fall back to all 27 so we can bootstrap.
     */
    protected function detectCurrentRounds(int $season): array
    {
        $current = \App\Models\Round::current();
        if ($current && $current->season === $season) {
            $nums = [$current->round_number];
            if ($current->round_number < 27) {
                $nums[] = $current->round_number + 1;
            }
            return $nums;
        }

        // No rounds at all yet — bootstrap by fetching all
        if (\App\Models\Round::where('season', $season)->count() === 0) {
            return range(1, 27);
        }

        // Fallback: latest round + next
        $latest = \App\Models\Round::where('season', $season)->orderByDesc('round_number')->first();
        $nums = [$latest->round_number];
        if ($latest->round_number < 27) {
            $nums[] = $latest->round_number + 1;
        }
        return $nums;
    }

    protected function fetchRound(HttpScraper $http, int $season, int $roundNumber): int
    {
        $url = sprintf(
            'https://www.nrl.com/draw/data?competition=111&season=%d&round=%d',
            $season,
            $roundNumber,
        );

        // The JSON endpoint needs a JSON accept header; HttpScraper is HTML-focused.
        // We borrow its throttler by calling the get() method but then re-parse.
        $response = $http->get($url);
        if (! $response->successful()) {
            return 0;
        }

        $data = $response->json();
        $fixtures = data_get($data, 'fixtures', []);
        if (empty($fixtures)) {
            return 0;
        }

        $kickoffs = collect($fixtures)
            ->pluck('clock.kickOffTimeLong')
            ->filter()
            ->map(fn ($t) => Carbon::parse($t, 'Australia/Sydney'));

        $round = Round::updateOrCreate(
            ['season' => $season, 'round_number' => $roundNumber],
            [
                'start_date' => $kickoffs->min()?->toDateString(),
                'end_date' => $kickoffs->max()?->toDateString(),
            ],
        );

        $keptIds = [];
        $count = 0;
        foreach ($fixtures as $fixture) {
            $match = $this->syncFixture($round, $fixture);
            if ($match) {
                $keptIds[] = $match->id;
                $count++;
            }
        }

        // Anything left in this round that *wasn't* in the live draw — eg. dummy
        // rows from nrl:seed-round or old cancelled fixtures — gets removed.
        if ($keptIds) {
            Matchup::where('round_id', $round->id)
                ->whereNotIn('id', $keptIds)
                ->delete();
        }

        return $count;
    }

    protected function syncFixture(Round $round, array $fixture): ?Matchup
    {
        $home = $this->resolveTeam(data_get($fixture, 'homeTeam.nickName'));
        $away = $this->resolveTeam(data_get($fixture, 'awayTeam.nickName'));
        if (! $home || ! $away) {
            return null;
        }

        $kickoffRaw = data_get($fixture, 'clock.kickOffTimeLong');
        $status = match (Str::lower((string) data_get($fixture, 'matchState'))) {
            'fulltime', 'post', 'postmatch' => 'completed',
            'live', 'inprogress' => 'live',
            default => 'upcoming',
        };

        return Matchup::updateOrCreate(
            [
                'round_id' => $round->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
            ],
            [
                'venue' => data_get($fixture, 'venue') ?: data_get($fixture, 'venueCity'),
                'kickoff_at' => $kickoffRaw ? Carbon::parse($kickoffRaw, 'Australia/Sydney') : null,
                'status' => $status,
                'home_score' => data_get($fixture, 'homeTeam.score'),
                'away_score' => data_get($fixture, 'awayTeam.score'),
            ],
        );
    }

    /**
     * nrl.com's JSON uses short nicknames ("Broncos", "Sea Eagles", "Wests Tigers");
     * our Team rows are keyed by a nrl_slug. Map nickname → slug with a few aliases.
     */
    protected function resolveTeam(?string $nickname): ?Team
    {
        if (! $nickname) {
            return null;
        }

        $slug = Str::slug($nickname);
        $aliases = [
            'tigers' => 'wests-tigers',
            'eagles' => 'sea-eagles',
            'cowboys' => 'cowboys',
            'broncos' => 'broncos',
            'raiders' => 'raiders',
            'bulldogs' => 'bulldogs',
            'sharks' => 'sharks',
            'titans' => 'titans',
            'storm' => 'storm',
            'knights' => 'knights',
            'eels' => 'eels',
            'panthers' => 'panthers',
            'rabbitohs' => 'rabbitohs',
            'dragons' => 'dragons',
            'roosters' => 'roosters',
            'warriors' => 'warriors',
            'dolphins' => 'dolphins',
        ];
        $slug = $aliases[$slug] ?? $slug;

        return Team::where('nrl_slug', $slug)->first()
            ?? Team::where('name', 'LIKE', "%{$nickname}%")->first();
    }
}
