<?php

namespace App\Livewire;

use App\Models\Matchup;
use App\Models\Round;
use App\Models\TryEvent;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Accuracy extends Component
{
    #[Title('Accuracy — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        // Only get rounds that have completed matches with both predictions AND try events
        $rounds = Round::whereHas('matches', fn ($q) => $q
                ->where('status', 'completed')
                ->whereHas('predictions')
                ->whereHas('tryEvents'))
            ->orderByDesc('round_number')
            ->limit(8)
            ->get();

        $summary = ['total' => 0, 'hits' => 0];
        $rows = [];

        foreach ($rounds as $round) {
            $matches = Matchup::with(['homeTeam', 'awayTeam', 'predictions.player', 'tryEvents'])
                ->where('round_id', $round->id)
                ->where('status', 'completed')
                ->whereHas('predictions')
                ->whereHas('tryEvents')
                ->get();

            if ($matches->isEmpty()) continue;

            $roundHits = 0;
            $roundTotal = 0;
            $items = [];

            foreach ($matches as $match) {
                $scorers = $match->tryEvents->pluck('player_id')->unique();
                $topN = $match->predictions->sortBy('rank_in_match')->take(5);
                foreach ($topN as $p) {
                    $hit = $scorers->contains($p->player_id);
                    $items[] = compact('match', 'p', 'hit');
                    $roundTotal++;
                    $roundHits += $hit ? 1 : 0;
                }
            }

            $summary['total'] += $roundTotal;
            $summary['hits'] += $roundHits;
            $rows[] = [
                'round' => $round,
                'hits' => $roundHits,
                'total' => $roundTotal,
                'items' => $items,
            ];
        }

        $pct = $summary['total'] > 0 ? round($summary['hits'] / $summary['total'] * 100, 1) : 0;

        return view('livewire.accuracy', [
            'rows' => $rows,
            'pct' => $pct,
            'summary' => $summary,
        ]);
    }
}
