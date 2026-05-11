<?php

namespace App\Livewire;

use App\Jobs\DispatchAiAnalysisFanout;
use App\Jobs\FetchDraw;
use App\Jobs\SyncCurrentRoundData;
use App\Jobs\FetchInjuryUpdates;
use App\Jobs\FetchLiveScores;
use App\Jobs\FetchNrlArticles;
use App\Jobs\FetchPlayerStats;
use App\Jobs\FetchTeamLists;
use App\Jobs\RunPredictionAnalysis;
use App\Models\DataFetchLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Jobs extends Component
{
    /** @var array<string, array{label:string, class:class-string, description:string}> */
    protected array $jobs = [
        'sync' => [
            'label' => 'Sync current round',
            'class' => SyncCurrentRoundData::class,
            'description' => 'Full bootstrap: fixtures → team lists → injuries → news → scoring → AI.',
        ],
        'draw' => [
            'label' => 'Draw / fixtures',
            'class' => FetchDraw::class,
            'description' => 'Sync every round\'s real fixtures from the nrl.com draw API.',
        ],
        'team_lists' => [
            'label' => 'Team lists',
            'class' => FetchTeamLists::class,
            'description' => 'Scrape nrl.com for this round\'s named team lists.',
        ],
        'injuries' => [
            'label' => 'Injury updates',
            'class' => FetchInjuryUpdates::class,
            'description' => 'Refresh the casualty ward feed.',
        ],
        'player_stats' => [
            'label' => 'Player stats',
            'class' => FetchPlayerStats::class,
            'description' => 'Crawl every player\'s NRL.com profile for career + season stats.',
        ],
        'live_scores' => [
            'label' => 'Live scores',
            'class' => FetchLiveScores::class,
            'description' => 'Pull the current round\'s live scoreboard and try scorers.',
        ],
        'articles' => [
            'label' => 'News articles',
            'class' => FetchNrlArticles::class,
            'description' => 'Grab the 5 latest NRL.com articles per team.',
        ],
        'predict' => [
            'label' => 'Statistical scoring',
            'class' => RunPredictionAnalysis::class,
            'description' => 'Recompute signals + try-scorer ranks for every upcoming match. Fast (seconds).',
        ],
        'ai_review' => [
            'label' => 'AI review (fanout)',
            'class' => DispatchAiAnalysisFanout::class,
            'description' => 'Queue one Claude agent pass per upcoming match. Slow (~1–2 min per match, in parallel).',
        ],
    ];

    public ?string $justDispatched = null;

    /** @var array<int, bool> */
    public array $expanded = [];

    public function run(string $key): void
    {
        if (! isset($this->jobs[$key])) return;

        $jobClass = $this->jobs[$key]['class'];

        // Belt-and-braces: ShouldBeUnique already prevents duplicate queue
        // entries, but also refuse here so the UI gives immediate feedback.
        $running = DataFetchLog::where('job_class', $jobClass)
            ->whereNull('completed_at')
            ->where('started_at', '>', now()->subMinutes(15))
            ->exists();
        if ($running) {
            $this->justDispatched = $this->jobs[$key]['label'].' — already running, skipped';
            return;
        }

        dispatch(new $jobClass());
        $this->justDispatched = 'Queued: '.$this->jobs[$key]['label'];
    }

    public function toggle(int $logId): void
    {
        $this->expanded[$logId] = ! ($this->expanded[$logId] ?? false);
    }

    /**
     * Mark logs still "running" for longer than the threshold as failed.
     * Happens when a queue worker died mid-job and never updated the row.
     */
    public function clearStuck(int $minutes = 15): int
    {
        $cutoff = now()->subMinutes($minutes);
        $count = DataFetchLog::whereNull('completed_at')
            ->where('started_at', '<', $cutoff)
            ->update([
                'status' => 'failed',
                'error' => 'Marked failed by user — worker never reported completion.',
                'completed_at' => now(),
            ]);
        $this->justDispatched = $count ? "Cleared {$count} stuck job(s)" : 'No stuck jobs found';
        return $count;
    }

    #[Title('Jobs — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $logs = DataFetchLog::orderByDesc('id')->limit(60)->get();

        $lastPerClass = $logs->groupBy('job_class')->map(fn ($rows) => $rows->first());

        $stuckCount = DataFetchLog::whereNull('completed_at')
            ->where('started_at', '<', now()->subMinutes(15))
            ->count();

        return view('livewire.jobs', [
            'jobs' => $this->jobs,
            'logs' => $logs,
            'lastPerClass' => $lastPerClass,
            'stuckCount' => $stuckCount,
        ]);
    }
}
