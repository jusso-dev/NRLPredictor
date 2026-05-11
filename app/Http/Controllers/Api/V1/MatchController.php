<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Matchup;
use App\Models\Round;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roundNumber = $request->query('round');
        $season = $request->query('season', now()->year);

        $query = Matchup::with(['homeTeam', 'awayTeam', 'round']);

        if ($roundNumber) {
            $round = Round::where('season', $season)->where('round_number', $roundNumber)->first();
            if (! $round) {
                return response()->json(['data' => []]);
            }
            $query->where('round_id', $round->id);
        }

        $matches = $query->orderBy('kickoff_at')->get()->map(fn ($m) => $this->formatMatch($m));

        return response()->json(['data' => $matches]);
    }

    public function current(): JsonResponse
    {
        $round = Round::current();
        if (! $round) {
            return response()->json(['data' => []]);
        }

        $matches = Matchup::with(['homeTeam', 'awayTeam', 'round'])
            ->where('round_id', $round->id)
            ->orderBy('kickoff_at')
            ->get()
            ->map(fn ($m) => $this->formatMatch($m));

        return response()->json([
            'round' => $round->round_number,
            'season' => $round->season,
            'data' => $matches,
        ]);
    }

    public function show(Matchup $match): JsonResponse
    {
        $match->load(['homeTeam', 'awayTeam', 'round', 'teamLists.player.team', 'oddsSnapshots']);
        return response()->json(['data' => $this->formatMatch($match, detailed: true)]);
    }

    protected function formatMatch(Matchup $match, bool $detailed = false): array
    {
        $data = [
            'id' => $match->id,
            'round' => $match->round?->round_number,
            'season' => $match->round?->season,
            'home_team' => [
                'id' => $match->home_team_id,
                'name' => $match->homeTeam?->name,
                'short_name' => $match->homeTeam?->short_name,
            ],
            'away_team' => [
                'id' => $match->away_team_id,
                'name' => $match->awayTeam?->name,
                'short_name' => $match->awayTeam?->short_name,
            ],
            'venue' => $match->venue,
            'kickoff_at' => $match->kickoff_at?->toIso8601String(),
            'kickoff_aest' => $match->kickoff_at?->format('D j M H:i'),
            'status' => $match->status,
            'home_score' => $match->home_score,
            'away_score' => $match->away_score,
            'win_prediction' => [
                'home_win_pct' => $match->home_win_pct,
                'away_win_pct' => $match->away_win_pct,
                'predicted_winner_id' => $match->predicted_winner_id,
            ],
        ];

        if ($detailed) {
            $data['win_signals'] = $match->win_signals;

            // Include bookmaker odds summary if available
            if ($match->relationLoaded('oddsSnapshots') && $match->oddsSnapshots->isNotEmpty()) {
                $odds = $match->oddsSnapshots;
                $matchWinner = $odds->where('market', 'match_winner')->whereNull('player_id');
                if ($matchWinner->isNotEmpty()) {
                    $data['bookmaker_odds'] = [
                        'match_winner' => $matchWinner->groupBy('bookmaker')->map(fn ($group, $bk) => [
                            'bookmaker' => $bk,
                            'odds' => $group->pluck('decimal_odds')->all(),
                        ])->values(),
                    ];
                }

                $spreads = $odds->where('market', 'spreads');
                if ($spreads->isNotEmpty()) {
                    $data['bookmaker_odds']['spreads'] = $spreads->groupBy('bookmaker')->map(fn ($group, $bk) => [
                        'bookmaker' => $bk,
                        'odds' => $group->map(fn ($o) => ['price' => $o->decimal_odds, 'point' => $o->point])->values(),
                    ])->values();
                }
            }

            $data['team_lists'] = [
                'home' => $match->teamLists->where('team_id', $match->home_team_id)->sortBy('position_number')->values()->map(fn ($l) => [
                    'player_id' => $l->player_id,
                    'name' => $l->player?->name,
                    'position' => $l->player?->position,
                    'position_number' => $l->position_number,
                ])->all(),
                'away' => $match->teamLists->where('team_id', $match->away_team_id)->sortBy('position_number')->values()->map(fn ($l) => [
                    'player_id' => $l->player_id,
                    'name' => $l->player?->name,
                    'position' => $l->player?->position,
                    'position_number' => $l->position_number,
                ])->all(),
            ];
        }

        return $data;
    }
}
