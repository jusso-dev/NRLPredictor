<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\Team;
use Illuminate\Support\Str;

/**
 * Builds the data payloads the AI pass embeds in its Codex prompts.
 * Previously served over HTTP to the Python agent (AgentToolController);
 * now consumed in-process by TryPredictionAgent and ChatController.
 */
class AgentContext
{
    public function matchContext(Matchup $match): array
    {
        $match->load(['homeTeam', 'awayTeam', 'round', 'teamLists.player']);

        return [
            'match_id' => $match->id,
            'round' => $match->round?->round_number,
            'season' => $match->round?->season,
            'venue' => $match->venue,
            'kickoff_at' => optional($match->kickoff_at)->toIso8601String(),
            'status' => $match->status,
            'home' => $this->sidePayload($match, $match->home_team_id),
            'away' => $this->sidePayload($match, $match->away_team_id),
        ];
    }

    public function topPredictions(Matchup $match, int $limit = 10): array
    {
        return Prediction::with('player.team')
            ->where('match_id', $match->id)
            ->orderBy('rank_in_match')
            ->limit($limit)
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
    }

    public function playerDeepStats(Player $player): array
    {
        $player->load(['team', 'venueStats', 'opponentStats.opponent', 'activeInjury']);

        return [
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
        ];
    }

    public function teamArticles(Team $team, int $limit = 5): array
    {
        return [
            'team_id' => $team->id,
            'team' => $team->name,
            'articles' => Article::whereJsonContains('team_tags', $team->id)
                ->orderByDesc('published_at')
                ->limit($limit)
                ->get()
                ->map(fn ($a) => [
                    'title' => $a->title,
                    'url' => $a->url,
                    'published_at' => optional($a->published_at)->toIso8601String(),
                    'excerpt' => Str::limit($a->content ?? '', 1500, '...'),
                ])->all(),
        ];
    }

    public function currentMatches(): array
    {
        $round = Round::current();
        if (! $round) {
            return ['round' => null, 'matches' => []];
        }

        return [
            'round' => $round->round_number,
            'season' => $round->season,
            'matches' => Matchup::with(['homeTeam', 'awayTeam'])
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
                ])->all(),
        ];
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
