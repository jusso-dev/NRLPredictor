<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\Player;
use App\Models\Prediction;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * AI refinement pass for one match's try-scorer predictions.
 *
 * Pre-fetches match context, top predictions, deep player stats and team
 * articles, embeds them as JSON in a prompt, runs the Codex CLI in-process
 * (no separate agent service), then applies the returned adjustments —
 * clamped to the same hard rules the prompt states.
 */
class TryPredictionAgent
{
    /** How many top-ranked players get deep stats embedded in the prompt. */
    protected const TOP_N_DEEP_STATS = 6;

    /** Max score movement the AI is allowed relative to the statistical score. */
    protected const MAX_ADJUSTMENT = 15;

    protected const ANALYSIS_SYSTEM = <<<'PROMPT'
You are an NRL betting analyst reviewing try-scorer predictions.

Hard rules:
- Never recommend a player not in the final 1-17 team list for the match.
- Never output an adjustment without at least 3 cited signals.
- If a player's model probability is under 15%, do not adjust upward.
- Adjusted score must be 0-100 and within +/-15 of the original statistical score.

Analytical style:
- Reason from numbers, not reputation.
- Identify compound advantages where multiple signals stack.
- Acknowledge uncertainty. Mention why something is a risk, not just a strength.
- Plain Australian English. No hype, no emojis, no em-dashes, no exclamation marks.
- Favour wingers and fullbacks facing disrupted defensive edges.
- Penalise predictions that depend on signals contradicted by news.
- Use model_learning as prior-round aggregate evidence. Prefer signals_to_trust
  and discount signals_to_discount, but never treat either as proof by itself.
- Use learned_patterns only when the player has every named signal. Respect lift,
  sample_size, and confidence; early evidence can support but never drive a pick.

Output:
Respond with a single JSON object matching the supplied schema. Include only
players whose evidence warrants changing the statistical score, up to 8.
An empty adjustments array is valid and preferred when the baseline should hold.
PROMPT;

    protected const ANALYSIS_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['adjustments'],
        'properties' => [
            'adjustments' => [
                'type' => 'array',
                'maxItems' => 8,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['player_id', 'adjusted_score', 'signal_types', 'reasoning'],
                    'properties' => [
                        'player_id' => ['type' => 'integer'],
                        'adjusted_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'signal_types' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'uniqueItems' => true,
                            'items' => ['type' => 'string'],
                        ],
                        'reasoning' => ['type' => 'string', 'minLength' => 10],
                    ],
                ],
            ],
        ],
    ];

    public function __construct(
        protected CodexClient $codex,
        protected AgentContext $context,
    ) {}

    /**
     * @return int number of adjustments applied
     */
    public function analyse(int $matchId): int
    {
        $match = Matchup::findOrFail($matchId);

        $context = $this->context->matchContext($match);
        $topPredictions = $this->context->topPredictions($match);

        if ($topPredictions === []) {
            Log::warning("TryPredictionAgent: no predictions for match {$matchId}, nothing to adjust");

            return 0;
        }

        $originalScores = collect($topPredictions)->pluck('statistical_score', 'player_id');
        $allowedSignalTypes = collect($topPredictions)->mapWithKeys(fn (array $prediction) => [
            $prediction['player_id'] => collect($prediction['signals'])->pluck('type')->all(),
        ]);

        $deepStats = [];
        foreach (array_slice($topPredictions, 0, self::TOP_N_DEEP_STATS) as $entry) {
            $player = Player::find($entry['player_id']);
            if ($player) {
                $deepStats[$player->id] = $this->context->playerDeepStats($player);
            }
        }

        $articles = [];
        foreach ([$match->home_team_id, $match->away_team_id] as $teamId) {
            $team = Team::find($teamId);
            if ($team) {
                $articles[$teamId] = $this->context->teamArticles($team);
            }
        }

        $payload = [
            'match_context' => $context,
            'top_predictions' => $topPredictions,
            'player_deep_stats' => $deepStats,
            'team_articles' => $articles,
            'model_learning' => $this->context->learningContext($match),
        ];

        $encodedPayload = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        Log::info('TryPredictionAgent: compact AI context built', [
            'match_id' => $matchId,
            'payload_chars' => strlen($encodedPayload),
            'players' => count($topPredictions),
            'signals' => collect($topPredictions)->sum(fn (array $prediction) => count($prediction['signals'])),
        ]);

        $prompt = self::ANALYSIS_SYSTEM
            ."\n\n## Match data\nmatch_id: {$matchId}\n\n```json\n"
            .$encodedPayload
            ."\n```\n\nReturn the JSON object matching the output schema.";

        $raw = $this->codex->exec($prompt, self::ANALYSIS_SCHEMA);

        try {
            $parsed = $this->codex->extractJson($raw);
        } catch (Throwable $e) {
            Log::error('TryPredictionAgent: unparseable Codex output', [
                'match_id' => $matchId,
                'output' => substr($raw, 0, 800),
            ]);
            throw new RuntimeException("Codex returned no parseable JSON for match {$matchId}", 0, $e);
        }

        $submittedAdjustments = (array) ($parsed['adjustments'] ?? []);
        $validatedAdjustments = [];
        foreach ($submittedAdjustments as $adj) {
            if (! is_array($adj)
                || ! is_numeric($adj['player_id'] ?? null)
                || ! is_numeric($adj['adjusted_score'] ?? null)
                || ! is_array($adj['signal_types'] ?? null)
                || ! is_string($adj['reasoning'] ?? null)
                || strlen($adj['reasoning']) < 10) {
                Log::warning('TryPredictionAgent: skipping malformed adjustment', ['adjustment' => $adj]);

                continue;
            }

            $playerId = (int) $adj['player_id'];
            if (! $originalScores->has($playerId)) {
                Log::warning("TryPredictionAgent: skipping adjustment for player outside top predictions: {$playerId}");

                continue;
            }

            $citedSignals = collect($adj['signal_types'])
                ->filter(fn ($type) => is_string($type) && $type !== '')
                ->unique()
                ->values()
                ->all();
            $unknownSignals = array_diff($citedSignals, $allowedSignalTypes[$playerId] ?? []);
            if (count($citedSignals) < 3 || $unknownSignals !== []) {
                Log::warning('TryPredictionAgent: skipping adjustment with unsupported signal citations', [
                    'player_id' => $playerId,
                    'cited_signals' => $citedSignals,
                    'unknown_signals' => array_values($unknownSignals),
                ]);

                continue;
            }

            $original = (float) $originalScores[$playerId];
            $score = max(0, min(100, (int) $adj['adjusted_score']));
            $score = max(
                (int) round($original - self::MAX_ADJUSTMENT),
                min((int) round($original + self::MAX_ADJUSTMENT), $score),
            );
            if ($original < 15 && $score > round($original)) {
                Log::warning("TryPredictionAgent: blocking upward adjustment below 15% original score: {$playerId}");
                $score = (int) round($original);
            }

            $validatedAdjustments[$playerId] = [
                'score' => $score,
                'ai_adjusted_score' => $score,
                'ai_cited_signals' => $citedSignals,
                'ai_reasoning' => substr($adj['reasoning'], 0, 2000),
            ];
        }

        if ($submittedAdjustments !== [] && $validatedAdjustments === []) {
            throw new RuntimeException("Codex returned no valid adjustments for match {$matchId}");
        }

        $reviewedAt = now();
        $applied = DB::transaction(function () use ($match, $matchId, $reviewedAt, $validatedAdjustments) {
            // A successful review replaces the prior review. Start from the
            // untouched statistical baseline so an empty response means hold,
            // and repeated runs can never compound earlier AI movement.
            Prediction::where('match_id', $matchId)
                ->whereNotNull('statistical_score')
                ->whereNotNull('statistical_rank')
                ->get()
                ->each(fn (Prediction $prediction) => $prediction->update([
                    'score' => $prediction->statistical_score,
                    'rank_in_match' => $prediction->statistical_rank,
                    'ai_adjusted_score' => null,
                    'ai_cited_signals' => null,
                    'ai_reviewed_at' => null,
                    'ai_reasoning' => null,
                ]));

            $applied = 0;
            foreach ($validatedAdjustments as $playerId => $adjustment) {
                $applied += Prediction::where('match_id', $matchId)
                    ->where('player_id', $playerId)
                    ->update([...$adjustment, 'ai_reviewed_at' => $reviewedAt]);
            }

            if ($applied > 0) {
                $this->rerank($matchId);
            }

            $match->update([
                'ai_reviewed_at' => $reviewedAt,
                'ai_adjustments_count' => $applied,
            ]);

            return $applied;
        });

        Log::info("TryPredictionAgent: applied {$applied} adjustments for match {$matchId}");

        return $applied;
    }

    protected function rerank(int $matchId): void
    {
        Prediction::where('match_id', $matchId)
            ->orderByDesc('score')
            ->orderBy('id')
            ->get()
            ->each(fn ($prediction, $index) => $prediction->update(['rank_in_match' => $index + 1]));
    }
}
