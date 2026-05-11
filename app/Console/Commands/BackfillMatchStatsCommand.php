<?php

namespace App\Console\Commands;

use App\Jobs\FetchMatchResults;
use App\Models\MatchTeamStats;
use App\Models\Matchup;
use App\Models\Round;
use App\Support\HttpScraper;
use Illuminate\Console\Command;
use ReflectionClass;

/**
 * One-shot helper that backfills match-centre team stats (completion rate,
 * run metres, kick metres, etc.) for all completed matches. Idempotent —
 * skips any match that already has two MatchTeamStats rows.
 */
class BackfillMatchStatsCommand extends Command
{
    protected $signature = 'nrl:backfill-match-stats {--round= : Specific round number to backfill, default all}';
    protected $description = 'Backfill match-centre team stats for completed matches';

    public function handle(HttpScraper $http): int
    {
        $fetcher = app(FetchMatchResults::class);
        $reflection = new ReflectionClass($fetcher);
        $capture = $reflection->getMethod('captureMatchTeamStats');
        $capture->setAccessible(true);
        $findMatch = $reflection->getMethod('findMatch');
        $findMatch->setAccessible(true);

        $query = Round::whereHas('matches', fn ($q) => $q->where('status', 'completed'))
            ->orderBy('round_number');

        if ($roundNum = $this->option('round')) {
            $query->where('round_number', (int) $roundNum);
        }

        $rounds = $query->get();
        $this->info("Backfilling across {$rounds->count()} rounds");
        $totalCaptured = 0;

        foreach ($rounds as $round) {
            $drawUrl = sprintf(
                'https://www.nrl.com/draw/data?competition=111&season=%d&round=%d',
                $round->season,
                $round->round_number,
            );

            $drawResp = $http->get($drawUrl);
            if (! $drawResp->successful()) {
                $this->warn("R{$round->round_number}: draw fetch failed");
                continue;
            }

            $fixtures = data_get($drawResp->json(), 'fixtures', []);
            $captured = 0;

            foreach ($fixtures as $fixture) {
                $state = strtolower($fixture['matchState'] ?? '');
                if (! in_array($state, ['fulltime', 'post', 'postmatch'], true)) {
                    continue;
                }

                $matchCentreUrl = $fixture['matchCentreUrl'] ?? null;
                if (! $matchCentreUrl) {
                    continue;
                }

                $homeNick = data_get($fixture, 'homeTeam.nickName');
                $awayNick = data_get($fixture, 'awayTeam.nickName');
                $match = $findMatch->invoke($fetcher, $homeNick, $awayNick);
                if (! $match) {
                    continue;
                }

                if (MatchTeamStats::where('match_id', $match->id)->count() >= 2) {
                    continue;
                }

                $url = 'https://www.nrl.com' . rtrim($matchCentreUrl, '/') . '/data';
                $resp = $http->get($url);
                if (! $resp->successful()) {
                    $this->warn("  R{$round->round_number} {$homeNick} v {$awayNick}: fetch failed");
                    continue;
                }

                $capture->invoke($fetcher, $match, $resp->json());
                $captured++;
                $totalCaptured++;
            }

            $this->line("R{$round->round_number}: captured {$captured} matches");
        }

        $this->info("Done. Total rows: " . MatchTeamStats::count() . " (captured {$totalCaptured} matches this run)");
        return self::SUCCESS;
    }
}
