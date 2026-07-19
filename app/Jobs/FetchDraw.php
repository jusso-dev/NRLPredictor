<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Matchup;
use App\Models\Round;
use App\Models\Team;
use App\Support\HttpScraper;
use App\Support\NrlDrawPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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

    public function handle(HttpScraper $http, NrlDrawPage $drawPage): void
    {
        $season = $this->season ?? now()->year;
        $rounds = $this->round ? [$this->round] : $this->detectCurrentRounds($season);

        $this->startLog('site.api.espn.com/nrl-scoreboard');
        $records = 0;

        try {
            $fixturesByRound = $drawPage->fixturesForRounds($http, $season, $rounds);
            foreach ($rounds as $roundNumber) {
                $records += $this->fetchRound($fixturesByRound[$roundNumber], $season, $roundNumber);
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

        // Empty rounds from a failed prior fetch must not block a full bootstrap.
        if (\App\Models\Round::where('season', $season)->whereHas('matches')->count() === 0) {
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

    /** @param array<int, array<string, mixed>> $fixtures */
    protected function fetchRound(array $fixtures, int $season, int $roundNumber): int
    {
        // Never create a round until all fixture teams can be resolved.
        // Otherwise Round::current() can select an empty, unusable round.
        $unresolvedTeams = [];
        foreach ($fixtures as $fixture) {
            foreach (['homeTeam.nickName', 'awayTeam.nickName'] as $path) {
                $nickname = data_get($fixture, $path);
                if (! is_string($nickname) || ! $this->resolveTeam($nickname)) {
                    $unresolvedTeams[] = (string) ($nickname ?: '[missing]');
                }
            }
        }
        if ($unresolvedTeams !== []) {
            throw new \RuntimeException('Unable to resolve NRL draw teams: '.implode(', ', array_unique($unresolvedTeams)));
        }

        $kickoffs = collect($fixtures)
            ->pluck('clock.kickOffTimeLong')
            ->filter()
            ->map(fn ($t) => $this->parseKickoff($t));

        // Only touch the round dates when the feed actually supplied kickoff
        // times — overwriting them with NULL breaks Round::current()'s date
        // window and sends every downstream job to the wrong round.
        $round = Round::updateOrCreate(
            ['season' => $season, 'round_number' => $roundNumber],
            $kickoffs->isNotEmpty() ? [
                'start_date' => $kickoffs->min()->toDateString(),
                'end_date' => $kickoffs->max()->toDateString(),
            ] : [],
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
        $status = \App\Support\NrlMatchState::toStatus(data_get($fixture, 'matchState')) ?? 'upcoming';

        return Matchup::updateOrCreate(
            [
                'round_id' => $round->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
            ],
            [
                'venue' => data_get($fixture, 'venue') ?: data_get($fixture, 'venueCity'),
                'kickoff_at' => $kickoffRaw ? $this->parseKickoff($kickoffRaw) : null,
                'status' => $status,
                'home_score' => data_get($fixture, 'homeTeam.score'),
                'away_score' => data_get($fixture, 'awayTeam.score'),
            ],
        );
    }

    /**
     * NRL kickoff times are Sydney wall-clock. Normalise into the app
     * timezone so date comparisons stay correct even if APP_TIMEZONE is
     * not Australia/Sydney. If the feed ever includes an explicit offset,
     * Carbon honours it and the fallback tz is ignored — still correct.
     */
    protected function parseKickoff(string $raw): Carbon
    {
        return Carbon::parse($raw, 'Australia/Sydney')->setTimezone(config('app.timezone'));
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
