<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Prediction;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * HTTP surface that the Python Claude Agent service calls into
 * to implement its five tools. Protected by agent.internal middleware.
 */
class AgentToolController extends Controller
{
    public function matchContext(Matchup $match): JsonResponse
    {
        $match->load(['homeTeam', 'awayTeam', 'round', 'teamLists.player']);

        return response()->json([
            'match_id' => $match->id,
            'round' => $match->round?->round_number,
            'season' => $match->round?->season,
            'venue' => $match->venue,
            'kickoff_at' => optional($match->kickoff_at)->toIso8601String(),
            'status' => $match->status,
            'home' => $this->sidePayload($match, $match->home_team_id),
            'away' => $this->sidePayload($match, $match->away_team_id),
        ]);
    }

    public function topPredictions(Matchup $match): JsonResponse
    {
        $predictions = Prediction::with('player.team')
            ->where('match_id', $match->id)
            ->orderBy('rank_in_match')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'player_id' => $p->player_id,
                'name' => $p->player?->name,
                'team' => $p->player?->team?->name,
                'position' => $p->player?->position,
                'score' => $p->score,
                'rank' => $p->rank_in_match,
                'signals' => $p->signals,
            ])->all();

        return response()->json([
            'match_id' => $match->id,
            'predictions' => $predictions,
        ]);
    }

    public function playerDeepStats(Player $player): JsonResponse
    {
        $player->load(['team', 'venueStats', 'opponentStats.opponent', 'activeInjury']);

        return response()->json([
            'player_id' => $player->id,
            'name' => $player->name,
            'team' => $player->team?->name,
            'position' => $player->position,
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
                'try_rate' => (float) $player->current_season_try_rate,
            ],
            'injury' => $player->activeInjury ? [
                'type' => $player->activeInjury->injury_type,
                'status' => $player->activeInjury->status,
                'notes' => $player->activeInjury->notes,
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
        ]);
    }

    public function teamArticles(Team $team): JsonResponse
    {
        $articles = Article::whereJsonContains('team_tags', $team->id)
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn ($a) => [
                'title' => $a->title,
                'url' => $a->url,
                'published_at' => optional($a->published_at)->toIso8601String(),
                'excerpt' => Str::limit($a->content ?? '', 1500, '...'),
            ])->all();

        return response()->json([
            'team_id' => $team->id,
            'team' => $team->name,
            'articles' => $articles,
        ]);
    }

    public function currentMatches(): JsonResponse
    {
        $round = \App\Models\Round::current();
        if (! $round) {
            return response()->json(['round' => null, 'matches' => []]);
        }

        $matches = Matchup::with(['homeTeam', 'awayTeam'])
            ->where('round_id', $round->id)
            ->orderBy('kickoff_at')
            ->get()
            ->map(fn ($m) => [
                'match_id' => $m->id,
                'home_team' => $m->homeTeam?->name,
                'home_team_id' => $m->home_team_id,
                'away_team' => $m->awayTeam?->name,
                'away_team_id' => $m->away_team_id,
                'venue' => $m->venue,
                'kickoff_at' => optional($m->kickoff_at)->toIso8601String(),
                'status' => $m->status,
                'home_win_pct' => $m->home_win_pct,
                'away_win_pct' => $m->away_win_pct,
                'predicted_winner_id' => $m->predicted_winner_id,
            ])->all();

        return response()->json([
            'round' => $round->round_number,
            'season' => $round->season,
            'matches' => $matches,
        ]);
    }

    public function submitAdjustedPrediction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'match_id' => ['required', 'integer'],
            'player_id' => ['required', 'integer'],
            'adjusted_score' => ['required', 'integer', 'min:0', 'max:100'],
            'reasoning' => ['required', 'string', 'max:2000'],
        ]);

        $prediction = Prediction::where('match_id', $data['match_id'])
            ->where('player_id', $data['player_id'])
            ->first();

        if (! $prediction) {
            return response()->json(['error' => 'prediction not found'], 404);
        }

        $prediction->update([
            'score' => $data['adjusted_score'],
            'ai_reasoning' => $data['reasoning'],
        ]);

        Prediction::where('match_id', $data['match_id'])
            ->orderByDesc('score')
            ->get()
            ->each(fn ($p, $i) => $p->update(['rank_in_match' => $i + 1]));

        return response()->json([
            'ok' => true,
            'prediction_id' => $prediction->id,
            'new_score' => $data['adjusted_score'],
        ]);
    }

    protected function sidePayload(Matchup $match, int $teamId): array
    {
        $team = Team::find($teamId);
        $lists = $match->teamLists->where('team_id', $teamId)->sortBy('position_number');

        $injuries = Player::with('activeInjury')
            ->where('team_id', $teamId)
            ->whereHas('activeInjury')
            ->get()
            ->map(fn ($p) => [
                'player_id' => $p->id,
                'name' => $p->name,
                'injury' => $p->activeInjury?->injury_type,
                'status' => $p->activeInjury?->status,
            ])->all();

        return [
            'team_id' => $teamId,
            'team' => $team?->name,
            'players' => $lists->map(fn ($l) => [
                'player_id' => $l->player_id,
                'name' => $l->player?->name,
                'position' => $l->player?->position,
                'position_number' => $l->position_number,
                'role' => $l->role,
                'season_tries' => $l->player?->current_season_tries,
                'season_try_rate' => (float) ($l->player?->current_season_try_rate ?? 0),
            ])->values()->all(),
            'injuries' => $injuries,
        ];
    }
}
