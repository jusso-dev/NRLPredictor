<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Player;
use Illuminate\Http\JsonResponse;

class PlayerController extends Controller
{
    public function show(Player $player): JsonResponse
    {
        $player->load(['team', 'venueStats', 'opponentStats.opponent', 'activeInjury']);

        return response()->json(['data' => [
            'id' => $player->id,
            'name' => $player->name,
            'position' => $player->position,
            'team' => $player->team ? [
                'id' => $player->team->id,
                'name' => $player->team->name,
                'short_name' => $player->team->short_name,
            ] : null,
            'career' => [
                'games' => $player->career_games,
                'tries' => $player->career_tries,
                'try_assists' => $player->career_try_assists,
                'line_breaks' => $player->career_line_breaks,
                'try_rate' => $player->careerTryRate(),
            ],
            'season' => [
                'games' => $player->current_season_games,
                'tries' => $player->current_season_tries,
                'try_rate' => $player->current_season_try_rate,
            ],
            'injury' => $player->activeInjury ? [
                'type' => $player->activeInjury->injury_type,
                'status' => $player->activeInjury->status,
            ] : null,
            'venue_stats' => $player->venueStats->map(fn ($s) => [
                'venue' => $s->venue,
                'games' => $s->games,
                'tries' => $s->tries,
                'try_rate' => $s->try_rate,
            ])->all(),
            'opponent_stats' => $player->opponentStats->map(fn ($s) => [
                'opponent' => $s->opponent?->name,
                'games' => $s->games,
                'tries' => $s->tries,
                'try_rate' => $s->try_rate,
            ])->all(),
        ]]);
    }
}
