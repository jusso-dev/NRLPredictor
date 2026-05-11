<?php

namespace App\Livewire;

use App\Jobs\SyncCurrentRoundData;
use App\Models\DataFetchLog;
use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\Prediction;
use App\Models\Round;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Dashboard extends Component
{
    public bool $syncing = false;
    public ?string $syncMessage = null;

    public function mount(): void
    {
        // Auto-trigger sync on first load if the current round has no team lists
        // AND we haven't tried recently. Without the "recently" guard, a scraper
        // that legitimately returns zero would cause every dashboard visit to
        // re-queue a full chain.
        $round = Round::current();
        if (! $round) {
            return;
        }

        $hasTeamLists = MatchTeamList::whereHas('match', fn ($q) => $q->where('round_id', $round->id))
            ->exists();
        if ($hasTeamLists) {
            return;
        }

        $recent = DataFetchLog::where('job_class', SyncCurrentRoundData::class)
            ->where('started_at', '>', now()->subMinutes(10))
            ->exists();
        if ($recent) {
            $this->syncMessage = 'Sync already in progress or attempted in the last 10 minutes — waiting for results.';
            return;
        }

        $this->triggerSync(auto: true);
    }

    public function triggerSync(bool $auto = false): void
    {
        SyncCurrentRoundData::dispatch();
        $this->syncing = true;
        $this->syncMessage = $auto
            ? 'Bootstrapping current round data — fixtures, team lists, injuries, news, predictions…'
            : 'Sync queued — new data will appear here as each job finishes.';
    }

    #[Title('Dashboard — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $round = Round::current();

        $matches = $round
            ? Matchup::with([
                    'homeTeam', 'awayTeam',
                    'predictions' => fn ($q) => $q->orderBy('rank_in_match')->limit(1),
                    'predictions.player',
                    'tryEvents.player.team',
                ])
                ->where('round_id', $round->id)
                ->orderBy('kickoff_at')
                ->get()
            : collect();

        // Load weather forecasts for upcoming matches
        $weatherByMatch = \App\Models\WeatherForecast::whereIn('match_id', $matches->pluck('id'))
            ->get()
            ->keyBy('match_id');

        // Load milestone flags
        $milestonesByMatch = \App\Models\MilestoneEvent::with('player')
            ->whereIn('match_id', $matches->pluck('id'))
            ->get()
            ->groupBy('match_id');

        $leaderboard = $round
            ? Prediction::with(['player.team', 'match.homeTeam', 'match.awayTeam'])
                ->whereHas('match', fn ($q) => $q->where('round_id', $round->id))
                ->orderByDesc('score')
                ->limit(20)
                ->get()
            : collect();

        $lastSync = DataFetchLog::where('job_class', SyncCurrentRoundData::class)
            ->orderByDesc('id')
            ->first();

        $runningJobs = DataFetchLog::whereNull('completed_at')
            ->where('started_at', '>', now()->subMinutes(15))
            ->count();

        return view('livewire.dashboard', [
            'round' => $round,
            'matches' => $matches,
            'leaderboard' => $leaderboard,
            'lastSync' => $lastSync,
            'runningJobs' => $runningJobs,
            'weatherByMatch' => $weatherByMatch,
            'milestonesByMatch' => $milestonesByMatch,
        ]);
    }
}
