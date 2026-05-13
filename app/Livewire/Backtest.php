<?php

namespace App\Livewire;

use App\Jobs\RunBacktest;
use App\Models\BacktestRun;
use App\Models\Round;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * UI for triggering walk-forward backtests and inspecting their results.
 *
 * Submitting the form creates a BacktestRun row and dispatches RunBacktest
 * onto the queue. The page polls the active run while it's pending/running
 * and stops once it's terminal — no wasted polling once results are in.
 */
class Backtest extends Component
{
    public int $season;
    public ?int $fromRound = null;
    public ?int $toRound = null;
    public bool $apply = false;

    /**
     * ID of the most-recently-submitted run, used to decide whether to keep
     * polling and which row to highlight. URL-bindable so a run can be
     * deep-linked.
     */
    #[Url(as: 'run')]
    public ?int $activeRunId = null;

    public function mount(): void
    {
        $this->season = now()->year;

        $bounds = $this->resolveBounds($this->season);
        if ($bounds) {
            [$this->fromRound, $this->toRound] = $bounds;
        }
    }

    public function startRun(): void
    {
        $this->validate([
            'season' => 'required|integer|min:2000|max:2100',
            'fromRound' => 'required|integer|min:1|max:30',
            'toRound' => 'required|integer|min:1|max:30|gt:fromRound',
        ]);

        $run = BacktestRun::create([
            'season' => $this->season,
            'from_round' => $this->fromRound,
            'to_round' => $this->toRound,
            'apply' => $this->apply,
            'status' => 'pending',
        ]);

        RunBacktest::dispatch($run->id);

        $this->activeRunId = $run->id;
    }

    /**
     * Should the polling tick fire? Only while there's an active non-terminal
     * run. Once it's done we render the result and let polling stop.
     */
    public function getShouldPollProperty(): bool
    {
        if (! $this->activeRunId) {
            return false;
        }
        $run = BacktestRun::find($this->activeRunId);
        return $run !== null && ! $run->isTerminal();
    }

    #[Title('Backtest — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $activeRun = $this->activeRunId ? BacktestRun::find($this->activeRunId) : null;

        $history = BacktestRun::orderByDesc('created_at')->limit(10)->get();

        $availableSeasons = Round::query()
            ->select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->all();

        return view('livewire.backtest', [
            'activeRun' => $activeRun,
            'history' => $history,
            'availableSeasons' => $availableSeasons,
            'shouldPoll' => $this->shouldPoll,
        ]);
    }

    protected function resolveBounds(int $season): ?array
    {
        $rounds = Round::where('season', $season)
            ->whereHas('matches', fn ($q) => $q
                ->where('status', 'completed')
                ->whereHas('predictions', fn ($q2) => $q2->whereNotNull('was_hit'))
                ->whereHas('tryEvents'))
            ->orderBy('round_number')
            ->pluck('round_number');

        if ($rounds->count() < 2) {
            return null;
        }
        return [$rounds->first(), $rounds->last()];
    }
}
