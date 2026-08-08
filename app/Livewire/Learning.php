<?php

namespace App\Livewire;

use App\Models\SignalPerformanceLog;
use App\Models\WeightAdjustment;
use App\Services\SignalCalculator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Learning extends Component
{
    #[Title('Self-Tuning — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $season = now()->year;

        $adjustments = WeightAdjustment::where('season', $season)
            ->orderByDesc('after_round')
            ->get();

        // Get signal performance trend across rounds
        $signalTrends = SignalPerformanceLog::where('season', $season)
            ->orderBy('round_number')
            ->get()
            ->groupBy('signal_type')
            ->map(fn ($logs) => $logs->map(fn ($l) => [
                'round' => $l->round_number,
                'delta' => $l->delta,
                'sample' => $l->sample_size,
            ])->values());

        // Current weights
        $currentWeights = app(SignalCalculator::class)->weights();

        return view('livewire.learning', [
            'adjustments' => $adjustments,
            'signalTrends' => $signalTrends,
            'currentWeights' => $currentWeights,
        ]);
    }
}
