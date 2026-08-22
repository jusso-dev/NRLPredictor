<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Matchup;
use App\Models\Prediction;
use App\Models\Round;
use Illuminate\Http\JsonResponse;

class PredictionController extends Controller
{
    public function forMatch(Matchup $match): JsonResponse
    {
        $predictions = Prediction::with('player.team')
            ->where('match_id', $match->id)
            ->orderBy('rank_in_match')
            ->get()
            ->map(fn ($p) => [
                'rank' => $p->rank_in_match,
                'player' => [
                    'id' => $p->player_id,
                    'name' => $p->player?->name,
                    'team' => $p->player?->team?->short_name ?? $p->player?->team?->name,
                    'position' => $p->player?->position,
                ],
                'score' => $p->score,
                'statistical_score' => $p->statistical_score,
                'statistical_rank' => $p->statistical_rank,
                'ai_adjusted_score' => $p->ai_adjusted_score,
                'ai_cited_signals' => $p->ai_cited_signals,
                'ai_delta' => $p->ai_adjusted_score !== null && $p->statistical_score !== null
                    ? $p->ai_adjusted_score - $p->statistical_score
                    : null,
                'ai_reviewed_at' => $p->ai_reviewed_at?->toIso8601String(),
                'signals' => $p->signals,
                'ai_reasoning' => $p->ai_reasoning,
            ]);

        return response()->json([
            'ai_review' => $match->ai_reviewed_at ? [
                'reviewed_at' => $match->ai_reviewed_at->toIso8601String(),
                'adjustments_count' => $match->ai_adjustments_count ?? 0,
                'held_statistical_baseline' => ($match->ai_adjustments_count ?? 0) === 0,
            ] : null,
            'data' => $predictions,
        ]);
    }

    public function leaderboard(): JsonResponse
    {
        $round = Round::current();
        if (! $round) {
            return response()->json(['data' => []]);
        }

        $predictions = Prediction::with(['player.team', 'match.homeTeam', 'match.awayTeam'])
            ->whereHas('match', fn ($q) => $q->where('round_id', $round->id))
            ->orderByDesc('score')
            ->limit(30)
            ->get()
            ->map(fn ($p) => [
                'rank' => $p->rank_in_match,
                'player' => [
                    'id' => $p->player_id,
                    'name' => $p->player?->name,
                    'team' => $p->player?->team?->short_name ?? $p->player?->team?->name,
                    'position' => $p->player?->position,
                ],
                'match' => $p->match ? ($p->match->homeTeam?->short_name.' v '.$p->match->awayTeam?->short_name) : null,
                'match_id' => $p->match_id,
                'score' => $p->score,
                'statistical_score' => $p->statistical_score,
                'statistical_rank' => $p->statistical_rank,
                'ai_adjusted_score' => $p->ai_adjusted_score,
                'ai_cited_signals' => $p->ai_cited_signals,
                'ai_delta' => $p->ai_adjusted_score !== null && $p->statistical_score !== null
                    ? $p->ai_adjusted_score - $p->statistical_score
                    : null,
                'ai_reviewed_at' => $p->ai_reviewed_at?->toIso8601String(),
                'signals' => $p->signals,
                'ai_reasoning' => $p->ai_reasoning,
            ]);

        return response()->json([
            'round' => $round->round_number,
            'season' => $round->season,
            'data' => $predictions,
        ]);
    }
}
