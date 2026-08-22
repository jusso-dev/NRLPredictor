<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\Team;
use App\Models\WeightAdjustment;
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
            ->orderByRaw('COALESCE(statistical_rank, rank_in_match)')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'player_id' => $p->player_id,
                'name' => $p->player?->name,
                'team' => $p->player?->team?->name,
                'position' => $p->player?->position,
                'score' => $p->statistical_score ?? $p->score,
                'statistical_score' => $p->statistical_score ?? $p->score,
                'statistical_rank' => $p->statistical_rank ?? $p->rank_in_match,
                'current_score' => $p->score,
                'current_rank' => $p->rank_in_match,
                'signals' => $this->compactSignals($p->signals ?? []),
            ])->all();
    }

    /**
     * Keep evidence the AI can act on. Persisted predictions retain the full
     * signal audit trail; the prompt omits zero/weak rows that add context cost
     * without changing a recommendation.
     */
    protected function compactSignals(array $signals): array
    {
        return collect($signals)
            ->filter(fn (array $signal) => abs((float) ($signal['strength'] ?? 0)) >= 0.6
                || ($signal['type'] ?? null) === 'learned_pattern')
            ->map(function (array $signal) {
                $weight = (float) ($signal['weight'] ?? 0);
                $strength = (float) ($signal['strength'] ?? 0);

                return [
                    'type' => $signal['type'] ?? 'unknown',
                    'weight' => round($weight, 4),
                    'strength' => round($strength, 4),
                    'contribution' => round($weight * $strength, 4),
                    'description' => Str::limit((string) ($signal['description'] ?? ''), 180, '...'),
                ];
            })
            ->values()
            ->all();
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

    /**
     * Prior-round feedback only. Strict round bounds prevent future results
     * leaking into an upcoming match's AI review.
     */
    public function learningContext(Matchup $match): array
    {
        $match->loadMissing('round');
        if (! $match->round) {
            return [];
        }

        $adjustment = WeightAdjustment::where('season', $match->round->season)
            ->where('after_round', '<', $match->round->round_number)
            ->orderByDesc('after_round')
            ->first();

        if (! $adjustment) {
            return [];
        }

        $deltas = $adjustment->signal_deltas ?? [];
        arsort($deltas);
        $positive = collect($deltas)
            ->filter(fn ($delta) => $delta > 0)
            ->take(5)
            ->map(fn ($delta, $type) => ['signal' => $type, 'delta' => round($delta, 4)])
            ->values()
            ->all();
        $negative = collect(array_reverse($deltas, true))
            ->filter(fn ($delta) => $delta < 0)
            ->take(5)
            ->map(fn ($delta, $type) => ['signal' => $type, 'delta' => round($delta, 4)])
            ->values()
            ->all();

        return [
            'trained_through_round' => $adjustment->after_round,
            'validation_top5_before' => $adjustment->accuracy_before,
            'validation_top5_after' => $adjustment->accuracy_after,
            'validation_ndcg_before' => $adjustment->ndcg_before,
            'validation_ndcg_after' => $adjustment->ndcg_after,
            'validation_matches' => $adjustment->validation_matches,
            'learning_update_accepted' => $adjustment->candidate_accepted,
            'learning_step' => $adjustment->learning_step,
            'brier_score' => $adjustment->brier_score,
            'market_brier' => $adjustment->market_brier,
            'signals_to_trust' => $positive,
            'signals_to_discount' => $negative,
            'learned_patterns' => collect($adjustment->pattern_insights ?? [])
                ->take(8)
                ->values()
                ->all(),
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
