<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Round;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OddsController extends Controller
{
    /**
     * GET /api/v1/odds — odds for all current-round matches.
     */
    public function index(): JsonResponse
    {
        $round = Round::current();
        if (! $round) {
            return response()->json(['data' => []]);
        }

        $matchIds = Matchup::where('round_id', $round->id)->pluck('id');

        $odds = OddsSnapshot::with(['match.homeTeam', 'match.awayTeam', 'player'])
            ->whereIn('match_id', $matchIds)
            ->orderBy('match_id')
            ->orderBy('market')
            ->orderBy('bookmaker')
            ->get()
            ->groupBy('match_id')
            ->map(fn ($group) => $this->formatMatchOdds($group));

        return response()->json([
            'round' => $round->round_number,
            'season' => $round->season,
            'data' => $odds->values(),
        ]);
    }

    /**
     * GET /api/v1/matches/{match}/odds — odds for a specific match.
     */
    public function forMatch(Matchup $match): JsonResponse
    {
        $match->load(['homeTeam', 'awayTeam']);

        $odds = OddsSnapshot::with('player')
            ->where('match_id', $match->id)
            ->orderBy('market')
            ->orderBy('bookmaker')
            ->get();

        return response()->json([
            'match_id' => $match->id,
            'match' => ($match->homeTeam?->short_name ?? '') . ' v ' . ($match->awayTeam?->short_name ?? ''),
            'data' => $this->formatMatchOdds($odds),
        ]);
    }

    protected function formatMatchOdds($odds): array
    {
        $match = $odds->first()?->match;

        $grouped = $odds->groupBy('market');

        $result = [
            'match_id' => $match?->id,
            'match' => $match ? (($match->homeTeam?->short_name ?? '') . ' v ' . ($match->awayTeam?->short_name ?? '')) : null,
            'kickoff_at' => $match?->kickoff_at?->toIso8601String(),
        ];

        // Match winner (h2h)
        if ($grouped->has('match_winner')) {
            $result['match_winner'] = $this->formatMarket($grouped['match_winner']);
        }

        // Spreads
        if ($grouped->has('spreads')) {
            $result['spreads'] = $this->formatMarket($grouped['spreads']);
        }

        // Totals
        if ($grouped->has('totals')) {
            $result['totals'] = $this->formatMarket($grouped['totals']);
        }

        // Anytime try scorers
        if ($grouped->has('ats')) {
            $result['anytime_try_scorer'] = $grouped['ats']
                ->groupBy('player_id')
                ->map(function ($playerOdds) {
                    $player = $playerOdds->first()->player;
                    return [
                        'player_id' => $player?->id,
                        'player_name' => $player?->name,
                        'position' => $player?->position,
                        'team' => $player?->team?->short_name,
                        'bookmakers' => $playerOdds->map(fn ($o) => [
                            'bookmaker' => $o->bookmaker,
                            'decimal_odds' => $o->decimal_odds,
                            'implied_probability' => round($o->impliedProbability() * 100, 1) . '%',
                        ])->values(),
                        'best_odds' => $playerOdds->max('decimal_odds'),
                        'avg_implied_probability' => round($playerOdds->avg(fn ($o) => $o->impliedProbability()) * 100, 1) . '%',
                    ];
                })
                ->sortBy('best_odds')
                ->values();
        }

        return $result;
    }

    protected function formatMarket($odds): array
    {
        return $odds->groupBy('bookmaker')->map(function ($bookmakerOdds, $bookmaker) {
            return [
                'bookmaker' => $bookmaker,
                'outcomes' => $bookmakerOdds->map(fn ($o) => array_filter([
                    'decimal_odds' => $o->decimal_odds,
                    'implied_probability' => round($o->impliedProbability() * 100, 1) . '%',
                    'point' => $o->point,
                ]))->values(),
                'captured_at' => $bookmakerOdds->first()->captured_at?->toIso8601String(),
            ];
        })->values()->all();
    }
}
