<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Round;
use Illuminate\Http\JsonResponse;

class RoundController extends Controller
{
    public function index(): JsonResponse
    {
        $rounds = Round::where('season', now()->year)
            ->orderBy('round_number')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'season' => $r->season,
                'round_number' => $r->round_number,
                'start_date' => $r->start_date?->toDateString(),
                'end_date' => $r->end_date?->toDateString(),
            ]);

        return response()->json(['data' => $rounds]);
    }

    public function current(): JsonResponse
    {
        $round = Round::current();
        if (! $round) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $round->id,
            'season' => $round->season,
            'round_number' => $round->round_number,
            'start_date' => $round->start_date?->toDateString(),
            'end_date' => $round->end_date?->toDateString(),
        ]]);
    }
}
