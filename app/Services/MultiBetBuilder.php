<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Prediction;
use App\Models\Round;
use Illuminate\Support\Collection;

class MultiBetBuilder
{
    /**
     * Build a multi-bet for the current round.
     *
     * @param  int  $maxLegs  Maximum legs in the multi
     * @param  string  $risk  'safe', 'balanced', or 'value'
     * @return array{legs: array, summary: array}
     */
    public function build(int $maxLegs = 6, string $risk = 'balanced'): array
    {
        $round = Round::current();
        if (! $round) {
            return ['legs' => [], 'summary' => ['error' => 'No current round found']];
        }

        $matches = Matchup::with(['homeTeam', 'awayTeam', 'predictions.player.team'])
            ->where('round_id', $round->id)
            ->where('status', 'upcoming')
            ->whereNotNull('home_win_pct')
            ->orderBy('kickoff_at')
            ->get();

        if ($matches->isEmpty()) {
            return ['legs' => [], 'summary' => ['error' => 'No upcoming matches with predictions']];
        }

        $legs = collect();

        // --- Winner legs ---
        $winnerLegs = $this->buildWinnerLegs($matches, $risk);
        $legs = $legs->merge($winnerLegs);

        // --- Anytime try scorer legs ---
        $tryScorerLegs = $this->buildTryScorerLegs($matches, $risk);
        $legs = $legs->merge($tryScorerLegs);

        // Split by type so we can build a balanced multi
        $winnerPool = $legs->where('type', 'match_winner')->sortByDesc('confidence')->values();
        $tryScorerPool = $legs->where('type', 'anytime_try_scorer')->sortByDesc('confidence')->values();

        // Reserve ~35% of slots for winner legs (at least 1 if available), rest
        // try scorers. round(), not ceil(): ceil(6 * 0.35) = 3 made half of a
        // default 6-leg multi winner legs.
        $winnerSlots = min($winnerPool->count(), max(1, (int) round($maxLegs * 0.35)));
        $tryScorerSlots = $maxLegs - $winnerSlots;

        // Pick winner legs first (max 1 per match)
        $selected = collect();
        $matchCounts = [];
        foreach ($winnerPool as $leg) {
            if ($selected->count() >= $winnerSlots) break;
            $mid = $leg['match_id'];
            if (($matchCounts[$mid] ?? 0) >= 1) continue; // only 1 winner per match
            $selected->push($leg);
            $matchCounts[$mid] = ($matchCounts[$mid] ?? 0) + 1;
        }

        // Fill remaining with try scorers (max 2 per match total)
        foreach ($tryScorerPool as $leg) {
            if ($selected->count() >= $maxLegs) break;
            $mid = $leg['match_id'];
            $matchCounts[$mid] = $matchCounts[$mid] ?? 0;
            if ($matchCounts[$mid] >= 2) continue;
            $selected->push($leg);
            $matchCounts[$mid]++;
        }

        // If still under limit, backfill from unused winner legs
        foreach ($winnerPool as $leg) {
            if ($selected->count() >= $maxLegs) break;
            if ($selected->contains(fn ($s) => $s['match_id'] === $leg['match_id'] && $s['type'] === 'match_winner')) continue;
            $mid = $leg['match_id'];
            $matchCounts[$mid] = $matchCounts[$mid] ?? 0;
            if ($matchCounts[$mid] >= 2) continue;
            $selected->push($leg);
            $matchCounts[$mid]++;
        }

        // Calculate combined probability
        $combinedProb = $selected->reduce(fn ($carry, $leg) => $carry * ($leg['probability'] / 100), 1.0);
        $overallConfidence = $this->overallConfidence($selected);

        return [
            'round' => $round->round_number,
            'season' => $round->season,
            'risk_profile' => $risk,
            'legs' => $selected->values()->all(),
            'summary' => [
                'total_legs' => $selected->count(),
                'combined_probability_pct' => round($combinedProb * 100, 2),
                'overall_confidence' => $overallConfidence,
                'confidence_label' => $this->confidenceLabel($overallConfidence),
                'recommendation' => $this->recommendation($selected, $combinedProb, $risk),
            ],
        ];
    }

    protected function buildWinnerLegs(Collection $matches, string $risk): Collection
    {
        $legs = collect();

        foreach ($matches as $match) {
            $homePct = $match->home_win_pct ?? 50;
            $awayPct = $match->away_win_pct ?? 50;
            $spread = abs($homePct - $awayPct);

            // Skip toss-ups for safe bets
            if ($risk === 'safe' && $spread < 12) {
                continue;
            }
            // For balanced, skip very tight contests
            if ($risk === 'balanced' && $spread < 4) {
                continue;
            }

            $winnerId = $homePct >= $awayPct ? $match->home_team_id : $match->away_team_id;
            $winnerTeam = $homePct >= $awayPct ? $match->homeTeam : $match->awayTeam;
            $winnerPct = max($homePct, $awayPct);
            $loserTeam = $homePct >= $awayPct ? $match->awayTeam : $match->homeTeam;

            // Build signal explanations
            $keySignals = $this->extractWinSignals($match, $winnerId);

            $confidence = $this->calculateWinConfidence($winnerPct, $spread, $keySignals);

            // For value bets, also consider the underdog
            if ($risk === 'value' && $spread < 8) {
                $underdogId = $homePct < $awayPct ? $match->home_team_id : $match->away_team_id;
                $underdogTeam = $homePct < $awayPct ? $match->homeTeam : $match->awayTeam;
                $underdogPct = min($homePct, $awayPct);
                $underdogSignals = $this->extractWinSignals($match, $underdogId);

                if ($underdogPct >= 42) {
                    $legs->push([
                        'type' => 'match_winner',
                        'match_id' => $match->id,
                        'match' => ($match->homeTeam->short_name ?? $match->homeTeam->name) . ' v ' . ($match->awayTeam->short_name ?? $match->awayTeam->name),
                        'venue' => $match->venue,
                        'kickoff_at' => $match->kickoff_at?->toIso8601String(),
                        'selection' => $underdogTeam->short_name ?? $underdogTeam->name,
                        'selection_team_id' => $underdogId,
                        'probability' => $underdogPct,
                        'confidence' => max(30, $confidence - 15),
                        'is_value_pick' => true,
                        'reasoning' => "Value underdog pick at {$underdogPct}% implied probability in a tight contest.",
                        'signals' => $underdogSignals,
                    ]);
                }
            }

            $legs->push(array_filter([
                'type' => 'match_winner',
                'match_id' => $match->id,
                'match' => ($match->homeTeam->short_name ?? $match->homeTeam->name) . ' v ' . ($match->awayTeam->short_name ?? $match->awayTeam->name),
                'venue' => $match->venue,
                'kickoff_at' => $match->kickoff_at?->toIso8601String(),
                'selection' => $winnerTeam->short_name ?? $winnerTeam->name,
                'selection_team_id' => $winnerId,
                'probability' => $winnerPct,
                'confidence' => $confidence,
                'is_value_pick' => false,
                'reasoning' => $this->winReasoning($winnerTeam, $loserTeam, $winnerPct, $keySignals),
                'signals' => $keySignals,
                'bookmaker_odds' => $this->bestMatchWinnerOdds($match->id),
            ], fn ($v) => $v !== null));
        }

        return $legs;
    }

    protected function buildTryScorerLegs(Collection $matches, string $risk): Collection
    {
        $legs = collect();

        foreach ($matches as $match) {
            $predictions = Prediction::with('player.team')
                ->where('match_id', $match->id)
                ->orderBy('rank_in_match')
                ->limit(5)
                ->get();

            if ($predictions->isEmpty()) {
                continue;
            }

            // Calculate raw scores for proper differentiation
            foreach ($predictions as $pred) {
                $rawScore = collect($pred->signals ?? [])->sum(fn ($s) => ($s['weight'] ?? 0) * ($s['strength'] ?? 0));
                $pred->_raw = $rawScore;
            }

            $maxRaw = $predictions->max('_raw') ?: 1;

            // Pick top 1-2 try scorers per match
            $limit = $risk === 'safe' ? 1 : 2;
            $picked = 0;

            foreach ($predictions as $pred) {
                if ($picked >= $limit) {
                    break;
                }

                $player = $pred->player;
                if (! $player) {
                    continue;
                }

                $signals = $pred->signals ?? [];
                $activeSignals = collect($signals)->filter(fn ($s) => ($s['strength'] ?? 0) > 0)->sortByDesc(fn ($s) => ($s['weight'] ?? 0) * ($s['strength'] ?? 0));
                $signalCount = $activeSignals->count();

                // Estimate try probability based on position and signals
                $tryProb = $this->estimateTryProbability($player, $pred->_raw, $maxRaw, $signals);

                // For safe mode, only pick high-probability try scorers
                if ($risk === 'safe' && $tryProb < 45) {
                    continue;
                }

                $confidence = $this->calculateTryScorerConfidence($pred, $tryProb, $signalCount);

                $keySignals = $activeSignals->take(4)->map(fn ($s) => [
                    'type' => str_replace('_', ' ', $s['type']),
                    'strength' => round(($s['strength'] ?? 0) * 100),
                    'description' => $s['description'] ?? '',
                    'impact' => round(($s['weight'] ?? 0) * ($s['strength'] ?? 0), 1),
                ])->values()->all();

                $playerOdds = $this->bestPlayerOdds($match->id, $player->id);

                $legs->push(array_filter([
                    'type' => 'anytime_try_scorer',
                    'match_id' => $match->id,
                    'match' => ($match->homeTeam->short_name ?? $match->homeTeam->name) . ' v ' . ($match->awayTeam->short_name ?? $match->awayTeam->name),
                    'venue' => $match->venue,
                    'kickoff_at' => $match->kickoff_at?->toIso8601String(),
                    'selection' => $player->name,
                    'selection_player_id' => $player->id,
                    'team' => $player->team?->short_name ?? $player->team?->name,
                    'position' => $player->position,
                    'rank_in_match' => $pred->rank_in_match,
                    'prediction_score' => $pred->score,
                    'probability' => $tryProb,
                    'confidence' => $confidence,
                    'is_value_pick' => false,
                    'reasoning' => $this->tryScorerReasoning($player, $tryProb, $signals, $match),
                    'signals' => $keySignals,
                    'ai_reasoning' => $pred->ai_reasoning,
                    'bookmaker_odds' => $playerOdds,
                ], fn ($v) => $v !== null));

                $picked++;
            }
        }

        return $legs;
    }

    protected function extractWinSignals(Matchup $match, int $teamId): array
    {
        $side = $match->home_team_id === $teamId ? 'home' : 'away';

        return collect($match->win_signals ?? [])
            ->filter(fn ($s) => ($s['side'] ?? '') === $side && ($s['strength'] ?? 0) > 0.1)
            ->sortByDesc(fn ($s) => ($s['weight'] ?? 0) * ($s['strength'] ?? 0))
            ->take(4)
            ->map(fn ($s) => [
                'type' => str_replace('_', ' ', $s['type']),
                'strength' => round(($s['strength'] ?? 0) * 100),
                'description' => $s['description'] ?? '',
                'impact' => round(($s['weight'] ?? 0) * ($s['strength'] ?? 0), 1),
            ])
            ->values()
            ->all();
    }

    protected function calculateWinConfidence(int $winPct, int $spread, array $signals): int
    {
        // Base confidence from win percentage
        $conf = match (true) {
            $winPct >= 70 => 85,
            $winPct >= 60 => 70,
            $winPct >= 55 => 55,
            default => 40,
        };

        // Bonus for strong signals
        $strongSignals = collect($signals)->filter(fn ($s) => ($s['strength'] ?? 0) >= 60)->count();
        $conf += min(10, $strongSignals * 3);

        return min(95, max(20, $conf));
    }

    protected function estimateTryProbability($player, float $rawScore, float $maxRaw, array $signals): int
    {
        // Base probability by position
        $baseProb = match ($player->position) {
            'winger' => 48,
            'fullback' => 42,
            'centre' => 38,
            'five-eighth' => 28,
            'halfback' => 22,
            'second-row' => 22,
            'hooker' => 20,
            'lock' => 18,
            'prop' => 12,
            default => 25,
        };

        // Adjust by signal strength relative to field
        $relativeStrength = $maxRaw > 0 ? $rawScore / $maxRaw : 0.5;
        $signalBonus = (int) round(($relativeStrength - 0.5) * 20);

        // Season try rate boost
        $seasonRate = collect($signals)->firstWhere('type', 'season_try_rate');
        if ($seasonRate && ($seasonRate['strength'] ?? 0) > 0.5) {
            $signalBonus += 8;
        }

        // Recent form boost
        $recentForm = collect($signals)->firstWhere('type', 'recent_form');
        if ($recentForm && ($recentForm['strength'] ?? 0) > 0.5) {
            $signalBonus += 6;
        }

        // Opponent weakness boost
        $oppWeak = collect($signals)->firstWhere('type', 'opponent_edge_weakness');
        $oppMissing = collect($signals)->firstWhere('type', 'opponent_missing_defenders');
        if (($oppWeak && ($oppWeak['strength'] ?? 0) > 0.5) || ($oppMissing && ($oppMissing['strength'] ?? 0) > 0.5)) {
            $signalBonus += 5;
        }

        return max(10, min(80, $baseProb + $signalBonus));
    }

    protected function calculateTryScorerConfidence(Prediction $pred, int $tryProb, int $signalCount): int
    {
        $conf = match (true) {
            $tryProb >= 55 => 75,
            $tryProb >= 45 => 60,
            $tryProb >= 35 => 45,
            default => 30,
        };

        // Rank bonus
        if ($pred->rank_in_match <= 2) {
            $conf += 8;
        } elseif ($pred->rank_in_match <= 5) {
            $conf += 3;
        }

        // Multiple active signals = more conviction
        $conf += min(10, max(0, $signalCount - 2) * 3);

        return min(90, max(20, $conf));
    }

    protected function winReasoning($winnerTeam, $loserTeam, int $winPct, array $signals): string
    {
        $name = $winnerTeam->short_name ?? $winnerTeam->name;
        $loserName = $loserTeam->short_name ?? $loserTeam->name;

        $parts = ["{$name} predicted to beat {$loserName} at {$winPct}% probability."];

        $topSignals = array_slice($signals, 0, 2);
        foreach ($topSignals as $sig) {
            $parts[] = ucfirst($sig['description'] ?? $sig['type']) . '.';
        }

        return implode(' ', $parts);
    }

    protected function tryScorerReasoning($player, int $tryProb, array $signals, Matchup $match): string
    {
        $name = $player->name;
        $pos = ucfirst($player->position ?? 'player');
        $team = $player->team?->short_name ?? $player->team?->name ?? '';

        $parts = ["{$name} ({$team}, {$pos}) — {$tryProb}% estimated try probability."];

        $active = collect($signals)->filter(fn ($s) => ($s['strength'] ?? 0) >= 0.4)->sortByDesc(fn ($s) => ($s['weight'] ?? 0) * ($s['strength'] ?? 0))->take(3);

        foreach ($active as $sig) {
            $parts[] = ucfirst($sig['description'] ?? str_replace('_', ' ', $sig['type'])) . '.';
        }

        return implode(' ', $parts);
    }

    protected function overallConfidence(Collection $legs): int
    {
        if ($legs->isEmpty()) {
            return 0;
        }
        return (int) round($legs->avg('confidence'));
    }

    protected function confidenceLabel(int $confidence): string
    {
        return match (true) {
            $confidence >= 75 => 'HIGH — Strong signal alignment across legs',
            $confidence >= 55 => 'MEDIUM — Solid data support with some uncertainty',
            $confidence >= 35 => 'LOW — Speculative, limited signal strength',
            default => 'VERY LOW — High variance, treat as a punt',
        };
    }

    /**
     * Best bookmaker odds for match winner on a given match.
     * Returns null if no odds are stored.
     */
    protected function bestMatchWinnerOdds(int $matchId): ?array
    {
        $odds = OddsSnapshot::where('match_id', $matchId)
            ->where('market', 'match_winner')
            ->whereNull('player_id')
            ->get();

        if ($odds->isEmpty()) {
            return null;
        }

        // Group by bookmaker to build a compact summary
        $best = $odds->sortByDesc('decimal_odds')->first();

        return [
            'best_decimal_odds' => $best->decimal_odds,
            'best_bookmaker' => $best->bookmaker,
            'implied_probability' => round($best->impliedProbability() * 100, 1) . '%',
            'bookmaker_count' => $odds->unique('bookmaker')->count(),
        ];
    }

    /**
     * Best bookmaker ATS odds for a specific player in a match.
     * Returns null if no odds are stored.
     */
    protected function bestPlayerOdds(int $matchId, int $playerId): ?array
    {
        $odds = OddsSnapshot::where('match_id', $matchId)
            ->where('player_id', $playerId)
            ->where('market', 'ats')
            ->get();

        if ($odds->isEmpty()) {
            return null;
        }

        $best = $odds->sortByDesc('decimal_odds')->first();

        return [
            'best_decimal_odds' => $best->decimal_odds,
            'best_bookmaker' => $best->bookmaker,
            'implied_probability' => round($best->impliedProbability() * 100, 1) . '%',
            'bookmaker_count' => $odds->unique('bookmaker')->count(),
        ];
    }

    protected function recommendation(Collection $legs, float $combinedProb, string $risk): string
    {
        $legCount = $legs->count();
        if ($legCount === 0) {
            return 'No suitable legs found for this round.';
        }

        $pct = round($combinedProb * 100, 1);
        $winnerLegs = $legs->where('type', 'match_winner')->count();
        $tryLegs = $legs->where('type', 'anytime_try_scorer')->count();

        $parts = ["{$legCount}-leg multi ({$winnerLegs} match winner(s), {$tryLegs} try scorer(s))."];
        $parts[] = "Combined probability: ~{$pct}%.";

        if ($risk === 'safe') {
            $parts[] = 'Conservative selections — higher individual leg probability, lower combined return.';
        } elseif ($risk === 'value') {
            $parts[] = 'Includes value picks — lower probability but higher potential return.';
        } else {
            $parts[] = 'Balanced approach — blending likely outcomes with decent return potential.';
        }

        if ($combinedProb > 0.15) {
            $parts[] = 'This is a realistic multi with reasonable hit rate.';
        } elseif ($combinedProb > 0.05) {
            $parts[] = 'Moderate risk — consider splitting into two smaller multis.';
        } else {
            $parts[] = 'Long shot — fun bet but manage expectations.';
        }

        return implode(' ', $parts);
    }
}
