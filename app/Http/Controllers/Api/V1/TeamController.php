<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $teams = Team::orderBy('name')->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'short_name' => $t->short_name,
            'nrl_slug' => $t->nrl_slug,
            'color_primary' => $t->color_primary,
            'color_secondary' => $t->color_secondary,
        ]);

        return response()->json(['data' => $teams]);
    }

    public function show(Team $team): JsonResponse
    {
        $team->load(['players' => fn ($q) => $q->orderBy('position')->orderBy('name')]);

        return response()->json(['data' => [
            'id' => $team->id,
            'name' => $team->name,
            'short_name' => $team->short_name,
            'nrl_slug' => $team->nrl_slug,
            'color_primary' => $team->color_primary,
            'color_secondary' => $team->color_secondary,
            'players' => $team->players->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'position' => $p->position,
                'career_games' => $p->career_games,
                'career_tries' => $p->career_tries,
                'current_season_games' => $p->current_season_games,
                'current_season_tries' => $p->current_season_tries,
                'current_season_try_rate' => $p->current_season_try_rate,
            ])->all(),
        ]]);
    }
}
