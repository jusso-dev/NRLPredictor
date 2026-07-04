<?php

use App\Jobs\AutoTuneAfterRound;
use App\Jobs\BackfillCalibrationGrades;
use App\Jobs\ComputeMatchMetadata;
use App\Jobs\ComputeTeamTryDistributions;
use App\Jobs\DispatchAiAnalysisFanout;
use App\Jobs\FetchDraw;
use App\Jobs\FetchInjuryUpdates;
use App\Jobs\FetchLiveScores;
use App\Jobs\FetchMatchResults;
use App\Jobs\FetchNrlArticles;
use App\Jobs\FetchOdds;
use App\Jobs\FetchPlayerStats;
use App\Jobs\FetchPreGameNews;
use App\Jobs\FetchTeamLists;
use App\Jobs\FetchWeatherForecasts;
use App\Jobs\RunPredictionAnalysis;
use App\Models\Matchup;
use Illuminate\Support\Facades\Schedule;

// Note: these tasks *dispatch* queued jobs and finish in milliseconds, so
// `withoutOverlapping` only de-dupes the dispatch itself. The real guard
// against concurrent runs is each job's ShouldBeUnique lock (uniqueFor is
// sized to the job's worst-case runtime).
Schedule::job(new FetchDraw)->dailyAt('04:30')->withoutOverlapping(10);
Schedule::job(new FetchTeamLists)->everyThirtyMinutes()->withoutOverlapping(25);
Schedule::job(new FetchInjuryUpdates)->everyThirtyMinutes()->withoutOverlapping(25);
Schedule::job(new FetchPlayerStats)->everyTwoHours()->withoutOverlapping(115);
Schedule::job(new FetchNrlArticles)->everySixHours()->withoutOverlapping(355);
Schedule::job(new FetchLiveScores)->everyTwoMinutes()
    ->when(fn () => Matchup::where('status', 'live')->exists()
        || Matchup::where('status', 'upcoming')
            ->whereNotNull('kickoff_at')
            ->where('kickoff_at', '<=', now()->addMinutes(5))
            ->where('kickoff_at', '>', now()->subMinutes(10))
            ->exists())
    ->withoutOverlapping(2);
Schedule::job(new FetchMatchResults)->everyThirtyMinutes()->withoutOverlapping(25);

// Pre-game monitoring: refresh team lists, injuries, and news 45 min before kickoff
// Runs every 15 min but only does work when a match is imminent
Schedule::job(new FetchPreGameNews)->everyFifteenMinutes()
    ->when(fn () => Matchup::where('status', 'upcoming')
        ->whereNotNull('kickoff_at')
        ->where('kickoff_at', '>', now())
        ->where('kickoff_at', '<=', now()->addMinutes(45))
        ->exists())
    ->withoutOverlapping(10);

// Weather forecasts for upcoming matches
Schedule::job(new FetchWeatherForecasts)->everyTwoHours()->withoutOverlapping(25);

// Betting odds from The Odds API — every 4 hours to conserve API credits
Schedule::job(new FetchOdds)->everyFourHours()->withoutOverlapping(25);

// Compute match metadata (turnaround, travel, tactical shifts) before scoring
Schedule::job(new ComputeMatchMetadata)->everyThirtyMinutes()->withoutOverlapping(5);

// Roll up left/middle/right try distribution for each team so the
// edge_mismatch signal has real data to work with.
Schedule::job(new ComputeTeamTryDistributions)->hourly()->withoutOverlapping(55);

// Fast statistical scoring every 30 minutes.
Schedule::job(new RunPredictionAnalysis)
    ->everyThirtyMinutes()
    ->withoutOverlapping(25);

// Self-tuning: grade predictions and adjust weights after each completed round
Schedule::job(new AutoTuneAfterRound)->hourly()->withoutOverlapping(55);

// Calibration backfill: catches any completed rounds whose predictions
// weren't graded by the per-round tuner (missed runs, restored backups,
// historical seasons loaded after the fact). Runs daily.
Schedule::job(new BackfillCalibrationGrades)->dailyAt('05:00')->withoutOverlapping(595);

// Expensive AI review every 2 hours — fans out one job per upcoming match
// so the queue processes them in parallel if multiple workers are running.
Schedule::job(new DispatchAiAnalysisFanout)
    ->everyTwoHours()
    ->withoutOverlapping(115);

// Stale-log sweeper: marks any data_fetch_logs stuck in "running" for >15 min
// as failed. Catches worker kills (container restart, OOM) so the UI and
// metrics don't show false "in-flight" rows forever.
Schedule::command('nrl:sweep-stuck-logs')->everyTenMinutes()->withoutOverlapping(5);

// Prune failed_jobs older than 14 days so the table stays small.
Schedule::command('queue:prune-failed --hours=336')->dailyAt('03:00');
