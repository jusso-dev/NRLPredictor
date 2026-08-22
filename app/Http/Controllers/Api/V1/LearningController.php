<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DataFetchLog;
use App\Models\WeightAdjustment;
use App\Services\AiImpactAnalyzer;
use App\Services\OutcomeTrendAnalyzer;
use App\Services\SignalPatternAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearningController extends Controller
{
    public function index(
        Request $request,
        OutcomeTrendAnalyzer $outcomes,
        SignalPatternAnalyzer $patterns,
        AiImpactAnalyzer $aiImpact,
    ): JsonResponse {
        $season = $request->integer('season', now()->year);
        abort_unless($season >= 2000 && $season <= 2100, 422, 'Season must be between 2000 and 2100.');

        $observed = $outcomes->analyze($season);
        $latestRound = collect($observed['rounds'])->last()['round'] ?? null;
        $latestAdjustment = WeightAdjustment::where('season', $season)
            ->orderByDesc('after_round')
            ->first();
        $latestAiRun = DataFetchLog::where('source', 'like', 'ai.agent:%')
            ->orderByDesc('started_at')
            ->first();

        return response()->json([
            'season' => $season,
            'observed_outcomes' => $observed,
            'validation' => $latestAdjustment ? [
                'after_round' => $latestAdjustment->after_round,
                'matches' => $latestAdjustment->validation_matches,
                'top5_before' => $latestAdjustment->accuracy_before,
                'top5_after' => $latestAdjustment->accuracy_after,
                'ndcg_before' => $latestAdjustment->ndcg_before,
                'ndcg_after' => $latestAdjustment->ndcg_after,
                'candidate_accepted' => $latestAdjustment->candidate_accepted,
                'learning_step' => $latestAdjustment->learning_step,
                'brier_score' => $latestAdjustment->brier_score,
            ] : null,
            'patterns' => $latestRound ? $patterns->analyze($season, $latestRound) : null,
            'ai_impact' => $aiImpact->analyze($season),
            'ai_health' => [
                'state' => $latestAiRun?->status ?? 'waiting',
                'last_run_at' => $latestAiRun?->started_at?->toIso8601String(),
                'failures_24h' => DataFetchLog::where('source', 'like', 'ai.agent:%')
                    ->where('status', 'failed')
                    ->where('started_at', '>=', now()->subDay())
                    ->count(),
            ],
        ]);
    }
}
