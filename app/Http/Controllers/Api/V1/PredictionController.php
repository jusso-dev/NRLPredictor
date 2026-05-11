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
                'signals' => $p->signals,
                'ai_reasoning' => $p->ai_reasoning,
            ]);

        return response()->json(['data' => $predictions]);
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
                'match' => $p->match ? ($p->match->homeTeam?->short_name . ' v ' . $p->match->awayTeam?->short_name) : null,
                'match_id' => $p->match_id,
                'score' => $p->score,
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
