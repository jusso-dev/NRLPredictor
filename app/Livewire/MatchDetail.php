<?php

namespace App\Livewire;

use App\Jobs\AnalyseMatchWithAi;
use App\Jobs\RunPredictionAnalysis;
use App\Models\Matchup;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class MatchDetail extends Component
{
    public Matchup $match;
    public array $expanded = [];

    public function mount(Matchup $match): void
    {
        $this->match = $match->load(['homeTeam', 'awayTeam', 'round']);
    }

    public function toggle(int $predictionId): void
    {
        $this->expanded[$predictionId] = ! ($this->expanded[$predictionId] ?? false);
    }

    /**
     * Synchronously rescore, then queue the AI pass. AI runs in the background
     * so the UI returns immediately — the match card auto-refreshes via wire:poll
     * elsewhere. Re-clicking is safe: ShouldBeUnique drops duplicate jobs.
     */
    public function reanalyse(): void
    {
        RunPredictionAnalysis::dispatchSync($this->match->id);
        AnalyseMatchWithAi::dispatch($this->match->id);
        $this->dispatch('notify', message: 'Scored now; AI review queued');
    }

    #[Title('Match · NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $predictions = $this->match->predictions()
            ->with('player.team')
            ->orderBy('rank_in_match')
            ->get();

        $injuries = [
            'home' => $this->injuriesFor($this->match->home_team_id),
            'away' => $this->injuriesFor($this->match->away_team_id),
        ];

        $tryEvents = $this->match->tryEvents()
            ->with('player.team')
            ->orderBy('minute')
            ->get();

        $milestones = $predictions
            ->filter(fn ($p) => collect($p->signals ?? [])
                ->contains(fn ($s) => $s['type'] === 'milestone_game' && ($s['strength'] ?? 0) >= 1))
            ->values();

        return view('livewire.match-detail', [
            'predictions' => $predictions,
            'injuries' => $injuries,
            'milestones' => $milestones,
            'tryEvents' => $tryEvents,
        ]);
    }

    protected function injuriesFor(int $teamId)
    {
        return \App\Models\Player::with('activeInjury')
            ->where('team_id', $teamId)
            ->whereHas('activeInjury')
            ->get();
    }
}
