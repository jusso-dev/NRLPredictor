<?php

namespace App\Livewire;

use App\Models\DataFetchLog;
use App\Models\SignalPerformanceLog;
use App\Models\WeightAdjustment;
use App\Services\AiImpactAnalyzer;
use App\Services\OutcomeTrendAnalyzer;
use App\Services\SignalCalculator;
use App\Services\SignalPatternAnalyzer;
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
        $latest = $adjustments->first();
        $top5RoundChange = $latest && $latest->accuracy_before !== null && $latest->accuracy_after !== null
            ? round($latest->accuracy_after - $latest->accuracy_before, 2)
            : null;
        $ndcgRoundChange = $latest && $latest->ndcg_before !== null && $latest->ndcg_after !== null
            ? round($latest->ndcg_after - $latest->ndcg_before, 4)
            : null;
        $aiImpact = app(AiImpactAnalyzer::class)->analyze($season);
        $outcomeTrend = app(OutcomeTrendAnalyzer::class)->analyze($season);
        $latestObservedRound = collect($outcomeTrend['rounds'])->last()['round'] ?? null;
        $livePatternReport = $latestObservedRound
            ? app(SignalPatternAnalyzer::class)->analyze($season, $latestObservedRound)
            : [];
        $persistedPatterns = collect($latest?->pattern_insights ?? []);
        $livePatterns = collect($livePatternReport['patterns'] ?? []);
        $latestPatterns = $livePatterns->isNotEmpty() ? $livePatterns : $persistedPatterns;
        $patternEvidence = $livePatterns->isNotEmpty()
            ? [
                'source' => 'live snapshot',
                'through_round' => $latestObservedRound,
                'matches' => $livePatternReport['matches_analyzed'],
                'predictions' => $livePatternReport['predictions_graded'],
                'baseline' => $livePatternReport['baseline_hit_rate'],
            ]
            : [
                'source' => $persistedPatterns->isNotEmpty() ? 'saved tuning run' : 'waiting',
                'through_round' => $latest?->after_round,
                'matches' => null,
                'predictions' => null,
                'baseline' => null,
            ];
        $latestAiRun = DataFetchLog::where('source', 'like', 'ai.agent:%')
            ->orderByDesc('started_at')
            ->first();
        $aiFailures24Hours = DataFetchLog::where('source', 'like', 'ai.agent:%')
            ->where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->count();
        $learningState = match (true) {
            ! $latest => 'waiting',
            $latest->candidate_accepted === null => 'legacy evidence',
            $latest->candidate_accepted === false => 'guarded',
            ($top5RoundChange ?? 0) > 0 || ($ndcgRoundChange ?? 0) > 0 => 'improved',
            ($top5RoundChange ?? 0) < 0 || ($ndcgRoundChange ?? 0) < 0 => 'regressed',
            default => 'held',
        };

        return view('livewire.learning', [
            'adjustments' => $adjustments,
            'signalTrends' => $signalTrends,
            'currentWeights' => $currentWeights,
            'latest' => $latest,
            'latestPatterns' => $latestPatterns,
            'patternEvidence' => $patternEvidence,
            'aiSummary' => [
                'state' => $latestAiRun?->status ?? 'waiting',
                'last_run' => $latestAiRun?->started_at,
                'failures_24h' => $aiFailures24Hours,
            ],
            'aiImpact' => $aiImpact,
            'outcomeTrend' => $outcomeTrend,
            'learningSummary' => [
                'state' => $learningState,
                'top5_round_change' => $top5RoundChange,
                'ndcg_round_change' => $ndcgRoundChange,
                'runs' => $adjustments->count(),
                'accepted_runs' => $adjustments->where('candidate_accepted', true)->count(),
            ],
        ]);
    }
}
