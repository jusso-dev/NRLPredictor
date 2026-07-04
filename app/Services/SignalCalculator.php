<?php

namespace App\Services;

use App\Models\Injury;
use App\Models\MatchTeamList;
use App\Models\MatchTeamStats;
use App\Models\Matchup;
use App\Models\MilestoneEvent;
use App\Models\OddsSnapshot;
use App\Models\Player;
use App\Models\PlayerClubHistory;
use App\Models\RefereeAssignment;
use App\Models\Round;
use App\Models\Suspension;
use App\Models\TeamTryDistribution;
use App\Models\TryEvent;
use App\Models\WeatherForecast;

class SignalCalculator
{
    /** Cached tuned weights for the current request. */
    protected static ?array $cachedTunedWeights = null;

    public function weights(): array
    {
        $base = config('nrl-weights.try_scorer', self::WEIGHTS);

        // Overlay the latest auto-tuned weights from the DB, if any. This lets
        // SignalTuner persist adjustments across container rebuilds (the PHP
        // config file is baked into the image, so writing to it is volatile).
        $tuned = static::latestTunedWeights();
        if ($tuned) {
            foreach ($tuned as $key => $val) {
                if (isset($base[$key])) {
                    $base[$key] = $val;
                }
            }
        }

        return $base;
    }

    protected static function latestTunedWeights(): ?array
    {
        if (static::$cachedTunedWeights !== null) {
            return static::$cachedTunedWeights ?: null;
        }
        try {
            $latest = \App\Models\WeightAdjustment::where('season', now()->year)
                ->orderByDesc('after_round')
                ->first();
            static::$cachedTunedWeights = $latest?->new_weights ?: [];
        } catch (\Throwable $e) {
            static::$cachedTunedWeights = [];
        }
        return static::$cachedTunedWeights ?: null;
    }

    public static function clearTunedCache(): void
    {
        static::$cachedTunedWeights = null;
    }

    // Fallback hardcoded weights (used if config missing)
    public const WEIGHTS = [
        'season_try_rate'            => 20,
        'recent_form'                => 18,
        'position_advantage'         => 15,
        'opponent_edge_weakness'     => 15,
        'opponent_missing_defenders' => 12,
        'head_to_head'               => 10,
        'venue_record'               => 8,
        'career_try_rate'            => 8,
        'milestone_game'             => 8,
        'team_attacking_form'        => 7,
        'returning_player'           => 3,
        'edge_mismatch'              => 15,
        'weather_adjustment'         => 8,
        'debut_boost'                => 5,
        'revenge_game'               => 3,
        'short_turnaround'           => 5,
        'tactical_shift'             => 8,
        'opponent_try_concede_rate'  => 10,
        // Market-consensus try-scorer probability, sourced from multiple AU bookmakers.
        // The market prices in info we can't feasibly scrape (late mail, coaches' hints,
        // combo plays). Weighted high because betting markets are well-calibrated.
        'bookmaker_try_odds'         => 25,
        // Totals-market implied point line. High totals (45+) signal an expected
        // shoot-out — more tries for everyone. Low totals (<38) signal a grind.
        'match_total_line'           => 10,
        // Favourite teams score more tries. Scales with market-implied win %,
        // so a big favourite's players get a lift and underdogs get dampened.
        'team_favouritism'           => 8,
        // Share of team tries scored in recent games — identifies genuine finishers.
        'rolling_try_share'          => 9,
        // Goal-line pressure proxy: rolling forced drop-outs per game from the
        // attacking team. Forced drop-outs almost always mean repeat sets near
        // the line, which is where outside backs and edge runners actually score.
        // Eye Test analytics rank teams on this metric; orthogonal to PF and edge_mismatch.
        'red_zone_pressure'          => 8,
        // Opponent's recent ruck-infringement rate. Slow ruck speed gets exposed by
        // attacking sides who play through the ruck — quicker play-the-balls cascade
        // into more line breaks and tries for support runners and edge backs.
        'opponent_ruck_speed'        => 6,
        // Rolling opponent error rate (errors per game, last 5). Eye Test 2025
        // surfaces error count as the strongest single process-level losing
        // signal. Sloppy opponents cough up the ball mid-field and concede
        // tries off the back of those turnovers. Outside backs and edge
        // forwards convert turnover ball at a higher rate than middles.
        'opponent_error_rate'        => 7,
        // Starting XIII score ~10x more than bench — back-line gets ~80 mins,
        // bench gets ~25 mins of mostly defensive middle-third work. Reserves
        // listed but unlikely to play. Computed from match_team_lists.role.
        'starter_role'               => 14,
        // Opponent's rolling missed-tackles per game (last 5). Sister metric
        // to opponent_try_concede_rate but earlier in the causal chain — leaky
        // tackling shows up before the try, and rewards edge runners + support.
        'opp_missed_tackles'         => 8,
        // Opponent's rolling line-breaks-conceded per game. Direct precursor
        // of tries and orthogonal to error-rate (you can break a clean line
        // without forcing a turnover). Heavy weight on outside backs.
        'opp_line_breaks_conceded'   => 9,
        // Opponent's tries-conceded broken down by scorer position-class over
        // last 5 games. Some sides specifically leak wing tries while holding
        // up middles; others get blasted through the ruck. Reading the channel
        // mismatch is sharper than a flat tries-conceded average.
        'opp_position_concede'       => 9,
        // Opponent defensive trend (last 3 games concede vs prior 5). Captures
        // teams whose defence is collapsing right now — orthogonal to flat
        // tries-conceded levels which can mask a steep decline. Outside backs
        // benefit most from a defence in free-fall.
        'opp_def_form_decay'         => 6,
        // Attacking team's tackle-breaks per game (last 5). Tackle breaks are
        // the leading indicator of line breaks — orthogonal to line_breaks
        // (you can break tackles without breaking the line, and the volume is
        // ~10x higher so it's a less noisy team-momentum signal). Position-
        // weighted to outside backs and edge runners who finish off broken
        // tackles in space.
        'team_tackle_breaks'         => 8,
        // Opponent's post-contact metres conceded per game (last 5). Defences
        // that don't stop on first contact get rolled over — yardage shifts
        // attacking sides closer to the line and tires their middle. Distinct
        // from missed_tackles (a soak tackle that loses 5m is still bad) and
        // from line_breaks_conceded (PCM is leakage without a clean break).
        'opp_post_contact_concede'   => 7,
        // Attacking team's offloads per game (last 5). Offloads keep the ball
        // alive in tackle, which catches defences out of position and creates
        // unstructured try chances for support runners. Edge forwards and
        // backs benefit most. Orthogonal to other team-attack signals.
        'team_offload_rate'          => 6,
        // Exponentially-smoothed try rate over last 8 player matches (alpha=0.3).
        // Recency-weighted form curve — newer games count more, but window is
        // longer than the flat 3-game recent_form cliff. Wicky.ai's NRL research
        // shows EMA-smoothed try rates outperform flat windows for next-match
        // try probability (a player on EMA=1 t/g is ~65% likely to score next).
        'tries_ema'                  => 12,
        // Opponent's rolling completion rate — low completion means more turnover ball
        // in good field position for our team, so back-five attackers cash in.
        'opp_completion_pressure'    => 8,
        // Team's rolling possession dominance — more time with ball ⇒ more sets
        // ⇒ more try opportunities. Distinct from completion (set-end success).
        'team_possession_pct'        => 6,
        // Team's rolling kicking metres — territorial dominance pushes opp into their
        // own end, leading to short-field repeat sets and goal-line tries.
        'team_kick_pressure'         => 5,
        // Opponent's share of tries conceded after the 60th minute. Fitness/cardio
        // signal — sides that leak late get carved by fresh legs in space. Heavily
        // weighted to back-five and edges who beat tired markers in the final 20.
        // Source: try_events.minute (filter ≥60). League avg ~28% of tries late;
        // teams above ~38% are conditioning failures.
        'opp_late_concede'           => 7,
        // Team's recent first-try-scorer rate (rolling last 8 completed matches).
        // Teams that strike first establish set-piece pressure and tend to keep
        // scoring through the match. Useful first-try-market signal and a momentum
        // proxy. Source: try_events.minute, taking the earliest per match.
        'team_first_try_rate'        => 6,
        // Opponent's share of tries conceded in opening 20 minutes (last 8 games).
        // Slow-starting defences leak early — pairs with team_first_try_rate to
        // boost first-try-market value for back-five attackers. League avg ~22%;
        // above ~32% flags a defence that hasn't settled before kickoff dust clears.
        'opp_first_concede'          => 6,
        // Opponent's run metres conceded per game (last 5). Forward-pack push
        // shifts attacking sides into try zone; complements opp_post_contact_concede
        // (contact-only) by including kick-return + structured carries. League avg
        // ~1550m/g; above ~1700 means the defensive line is on the back foot.
        'opp_yardage_concede'        => 6,
        // Team's tries scored per completed set (last 5). Conversion-quality
        // signal — orthogonal to volume metrics like team_tackle_breaks and
        // team_offload_rate (which measure work done) by measuring whether
        // sets actually finish in tries. League avg ~0.10 tries/set (3 t/g
        // ÷ ~30 sets). Above ~0.13 = elite finishers; below ~0.08 = blunt.
        'team_set_efficiency'        => 7,
        // Opponent's tries conceded per opp-set-faced (last 5). Defensive
        // resilience under pressure — distinct from raw try-concede rate
        // (which mixes set-volume and conversion). A team facing 35 sets and
        // conceding 4 tries is leakier per-set than one facing 28 sets/3 tries.
        'opp_set_concede_rate'       => 7,
        // Phase 15: explosive-play rate = (tackle_breaks + line_breaks + offloads) / all_runs.
        // Per-carry attacking danger — orthogonal to volume metrics (a team
        // running 180/g with 0.30 explosive rate is far more dangerous than
        // 200/g at 0.21). League spread (R1-R10): ~0.21 (Broncos) to ~0.31
        // (Raiders), median ~0.27. Maps [0.22, 0.30] → [0, 1].
        'team_explosive_rate'        => 8,
        // Opponent's explosive plays conceded per opp run (last 5). Defences
        // that bleed line breaks + offloads + tackle busts under contact get
        // carved by support runners. Same calibration band, position-shared
        // toward back-five and edges.
        'opp_explosive_concede'      => 7,
        // Phase 16: attacking pressure indicators.
        // Forced drop-outs / game (last 5). Each drop-out grants a fresh set
        // inside the opp 40m — direct attacking pressure that converts to
        // tries above average. League spread: ~0.5 (Dragons) → ~2.6 (Roosters)
        // per game. Maps [0.6, 2.5] → [0, 1]. Position share mirrors who
        // finishes tries off attacking sets (back-five + edges).
        'team_drop_outs_forced'      => 7,
        // Opponent ruck infringements / game (last 5). High ruck penalties
        // = slow defensive PTBs ruled against = quicker play-the-ball + 10m
        // retreats for attack = back-five getting clean front-foot ball.
        // Spread: ~1.9 (Sharks) → ~5.0 (Panthers). Maps [2.0, 5.0] → [0, 1].
        'opp_ruck_penalties'         => 5,
        // Phase 17: field-position + defensive-efficiency signals.
        // Net penalty differential per game (last 5) = opp_penalties_conceded
        // − team_penalties_conceded. Positive = more penalties drawn than
        // conceded ⇒ more attacking sets in opp half + better field position.
        // Distinct from `opp_ruck_penalties` (opp ruck-only count) and
        // `team_kick_pressure` (territory by boot). Captures own-side
        // discipline edge. League spread typically -3.0 to +3.5 per game;
        // maps [-1.5, +3.0] → [0, 1]. Position share: back-five and edges
        // finish off short-field attacking sets.
        'team_penalty_diff'          => 6,
        // Opponent's rolling effective tackle % (last 5). Direct defensive
        // efficiency measure — lower pct means more first-up busts and
        // broken-line carries available for support runners. Orthogonal to
        // missed_tackles (raw count) and post_contact_concede (yardage)
        // because effective_tackle blends both contact failure and roll-off
        // assists into a single % rating. League band 88–94%; maps
        // (92 − pct)/6 ⇒ [0, 1]. Position share favours back-five who feast
        // on broken tackles in space.
        'opp_effective_tackle_pct'   => 6,
        // Phase 18: own rolling completion % (last 5). Set-end retention —
        // distinct from `opp_completion_pressure` (opp's completion as inverse),
        // `opponent_error_rate` (opp errors give us ball), and `team_set_efficiency`
        // (tries-per-set conversion quality). Higher own completion = more
        // sustained attacking sets and more red-zone reps. League band 76–86%;
        // maps [0.76, 0.86] → [0, 1]. Position share favours back-five who
        // finish prolonged attacking sets.
        'team_completion_rate'       => 6,
        // Phase 19: post-contact metres per run (bend-the-line yardage quality).
        // Own PCM/run (last 5) measures relentless forward push at carry level —
        // orthogonal to `team_explosive_rate` (events: TB+LB+offloads ÷ runs)
        // and `yardage_dominance` (raw m total without per-carry normalisation).
        // 2026-05-13 R1–R10 spread: 2.39 (Knights) → 3.28 (Roosters), median ~3.0.
        // Maps [2.50, 3.20] → [0, 1]. Position share weights edge runners +
        // back-five who finish off bent-line sets.
        'team_pcm_per_run'           => 7,
        // Opp PCM/run conceded (last 5) — defences that don't anchor first contact
        // give up bent-line carries that set up short-field tries. Mirror of
        // team_pcm_per_run on the defensive side, same band. Distinct from
        // `opp_post_contact_concede` (raw m/g, volume) by per-carry normalisation.
        'opp_pcm_per_run_concede'    => 6,
        // Phase 20: consecutive recent matches where team scored ≥4 tries.
        // Captures rolling-boil attacking momentum that flat per-game averages
        // smear out. Threshold ≥4 (NRL avg ~3.3 t/g) so signal actually
        // discriminates — ≥3 saturates with most teams on max streak.
        // Orthogonal to `team_attacking_form` (flat average) and
        // `team_first_try_rate` (start dominance). Cap at 4; maps [0, 4] → [0, 1].
        // Position share favours back-five + edges who finish high-scoring sets.
        'team_attacking_streak'      => 6,
    ];

    public const POSITION_ADVANTAGE = [
        'winger'      => 1.00,
        'fullback'    => 0.90,
        'centre'      => 0.80,
        'five-eighth' => 0.50,
        'halfback'    => 0.40,
        'hooker'      => 0.30,
        'second-row'  => 0.30,
        'lock'        => 0.20,
        'prop'        => 0.10,
    ];

    /**
     * @return array<int, array{type:string, weight:int, strength:float, description:string}>
     */
    public function calculate(Player $player, Matchup $match): array
    {
        $w = $this->weights();
        $opponentId = $match->home_team_id === $player->team_id
            ? $match->away_team_id
            : $match->home_team_id;

        $signals = [];
        $signals[] = $this->seasonTryRate($player, $w);
        $signals[] = $this->recentForm($player, $w);
        $signals[] = $this->positionAdvantage($player, $w);
        $signals[] = $this->opponentEdgeWeakness($player, $opponentId, $w);
        $signals[] = $this->opponentMissingDefenders($player, $match, $opponentId, $w);
        $signals[] = $this->headToHead($player, $opponentId, $match, $w);
        $signals[] = $this->venueRecord($player, $match, $w);
        $signals[] = $this->careerTryRate($player, $w);
        $signals[] = $this->milestoneGame($player, $match, $w);
        $signals[] = $this->teamAttackingForm($player, $w);
        $signals[] = $this->returningPlayer($player, $match, $w);

        // Phase 3 signals
        $signals[] = $this->edgeMismatch($player, $match, $opponentId, $w);
        $signals[] = $this->weatherAdjustment($player, $match, $w);
        $signals[] = $this->debutBoost($player, $match, $w);
        $signals[] = $this->revengeGame($player, $opponentId, $w);
        $signals[] = $this->shortTurnaround($player, $match, $w);
        $signals[] = $this->tacticalShift($player, $match, $w);
        $signals[] = $this->opponentTryConcedeRate($opponentId, $w);

        // Market + advanced-stat signals
        $signals[] = $this->bookmakerTryOdds($player, $match, $w);
        $signals[] = $this->matchTotalLine($player, $match, $w);
        $signals[] = $this->teamFavouritism($player, $match, $w);

        // Phase 5: rolling usage signals
        $signals[] = $this->rollingTryShare($player, $w);

        // Phase 6: process-stat signals derived from team match-stats
        $signals[] = $this->redZonePressure($player, $w);
        $signals[] = $this->opponentRuckSpeed($player, $opponentId, $w);
        $signals[] = $this->opponentErrorRate($player, $opponentId, $w);

        // Phase 7: lineup + opponent-defence signals
        $signals[] = $this->starterRole($player, $match, $w);
        $signals[] = $this->oppMissedTackles($player, $opponentId, $w);
        $signals[] = $this->oppLineBreaksConceded($player, $opponentId, $w);

        // Phase 8: channel-specific opp leakage + form trend
        $signals[] = $this->oppPositionConcede($player, $opponentId, $w);
        $signals[] = $this->oppDefFormDecay($player, $opponentId, $w);

        // Phase 9: leading-indicator team/opp yardage signals
        $signals[] = $this->teamTackleBreaks($player, $w);
        $signals[] = $this->oppPostContactConcede($player, $opponentId, $w);
        $signals[] = $this->teamOffloadRate($player, $w);

        // Phase 10: recency-weighted try form (EMA over last 8 player matches).
        $signals[] = $this->triesEma($player, $w);

        // Phase 11: completion + possession + kick pressure
        $signals[] = $this->oppCompletionPressure($player, $opponentId, $w);
        $signals[] = $this->teamPossessionPct($player, $w);
        $signals[] = $this->teamKickPressure($player, $w);

        // Phase 12: late-game concede + first-try team momentum
        $signals[] = $this->oppLateConcede($player, $opponentId, $w);
        $signals[] = $this->teamFirstTryRate($player, $w);

        // Phase 13: opening-20 leak + total yardage concede
        $signals[] = $this->oppFirstConcede($player, $opponentId, $w);
        $signals[] = $this->oppYardageConcede($player, $opponentId, $w);

        // Phase 14: set-conversion efficiency (attack + defence)
        $signals[] = $this->teamSetEfficiency($player, $w);
        $signals[] = $this->oppSetConcedeRate($player, $opponentId, $w);

        // Phase 15: per-carry explosive-play quality (attack + defence)
        $signals[] = $this->teamExplosiveRate($player, $w);
        $signals[] = $this->oppExplosiveConcede($player, $opponentId, $w);

        // Phase 16: attacking-pressure indicators (drop-outs forced + opp ruck infringements)
        $signals[] = $this->teamDropOutsForced($player, $w);
        $signals[] = $this->oppRuckPenalties($player, $opponentId, $w);

        // Phase 17: penalty differential (field position) + opp effective tackle %
        $signals[] = $this->teamPenaltyDiff($player, $w);
        $signals[] = $this->oppEffectiveTacklePct($player, $opponentId, $w);

        // Phase 18: own completion-rate retention
        $signals[] = $this->teamCompletionRate($player, $w);

        // Phase 19: per-carry post-contact metres (attack + defence)
        $signals[] = $this->teamPcmPerRun($player, $w);
        $signals[] = $this->oppPcmPerRunConcede($player, $opponentId, $w);

        // Phase 20: rolling-boil attacking streak (consecutive 3+ try matches)
        $signals[] = $this->teamAttackingStreak($player, $w);

        return array_values(array_filter($signals));
    }

    public function maxPossibleScore(): int
    {
        return array_sum($this->weights());
    }

    // ── Original signals ──────────────────────────────────

    protected function seasonTryRate(Player $player, array $w): array
    {
        $rate = (float) $player->current_season_try_rate;
        // Top NRL try-scorers peak around 1.0–1.4 tries/game across a full season.
        // Map [0, 1.4] → [0, 1] so the signal continues discriminating across the
        // top-15 prediction set (where the median player already sits near 0.7+
        // and a /1.0 divisor would saturate). 2026-05-08: bumped from 1.0 to 1.4
        // after observing avg_strength_hits ≈ avg_strength_misses ≈ 0.92 (delta ≈ 0).
        return [
            'type' => 'season_try_rate',
            'weight' => $w['season_try_rate'] ?? 20,
            'strength' => min(1.0, $rate / 1.4),
            'description' => sprintf('%.2f tries/game this season', $rate),
        ];
    }

    protected function recentForm(Player $player, array $w): array
    {
        // Get the player's team's last 3 completed matches by date, then count
        // tries the player scored in those matches. Eloquent count() strips
        // ORDER BY/LIMIT, so we resolve the match IDs first via a subquery-style
        // pluck and then count tries against that ID set.
        $teamId = $player->team_id;
        $matchIds = $teamId
            ? Matchup::where('status', 'completed')
                ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
                ->orderByDesc('kickoff_at')
                ->limit(3)
                ->pluck('id')
            : collect();

        $tries = $matchIds->isEmpty()
            ? 0
            : TryEvent::where('player_id', $player->id)
                ->whereIn('match_id', $matchIds)
                ->count();

        $games = $matchIds->count();
        // 2 tries across 3 games saturates the signal (i.e. ~0.67 tries/game).
        return [
            'type' => 'recent_form',
            'weight' => $w['recent_form'] ?? 18,
            'strength' => $games > 0 ? min(1.0, $tries / 2) : 0.0,
            'description' => sprintf('Scored %d tries in last %d game(s)', $tries, $games),
        ];
    }

    protected function positionAdvantage(Player $player, array $w): array
    {
        $positions = config('nrl-weights.position_advantage', self::POSITION_ADVANTAGE);
        $strength = $positions[$player->position] ?? 0.2;
        return [
            'type' => 'position_advantage',
            'weight' => $w['position_advantage'] ?? 15,
            'strength' => $strength,
            'description' => sprintf('%s — base position weight', ucfirst($player->position ?? 'unknown')),
        ];
    }

    protected function opponentEdgeWeakness(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opponent_edge_weakness'] ?? 15;

        // Only attacking outside backs and edge runners benefit from a weakened back five.
        // A prop doesn't score more tries because the opponent's fullback is injured.
        $attackerBeneficiary = in_array($player->position, ['winger', 'centre', 'fullback', 'second-row']);
        if (! $attackerBeneficiary) {
            return [
                'type' => 'opponent_edge_weakness',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'Not an outside/edge attacker',
            ];
        }

        $missingIds = Injury::where('resolved', false)
            ->whereIn('status', ['out', 'doubt'])
            ->whereHas('player', fn ($q) => $q->where('team_id', $opponentId)
                ->whereIn('position', ['fullback', 'winger', 'centre']))
            ->pluck('player_id');

        $missingIds = $missingIds->merge(
            Suspension::where('games_remaining', '>', 0)
                ->whereHas('player', fn ($q) => $q->where('team_id', $opponentId)
                    ->whereIn('position', ['fullback', 'winger', 'centre']))
                ->pluck('player_id'),
        )->unique()->count();

        return [
            'type' => 'opponent_edge_weakness',
            'weight' => $weight,
            'strength' => min(1.0, $missingIds / 3),
            'description' => sprintf('%d back-five players out/suspended for opponent', $missingIds),
        ];
    }

    protected function opponentMissingDefenders(Player $player, Matchup $match, int $opponentId, array $w): array
    {
        $weight = $w['opponent_missing_defenders'] ?? 12;

        // Only attackers who run at the back five get a lift from defensive churn.
        // Middle forwards don't benefit from new faces at wing/centre/fullback.
        $attackerBeneficiary = in_array($player->position, ['winger', 'centre', 'fullback', 'second-row']);
        if (! $attackerBeneficiary) {
            return [
                'type' => 'opponent_missing_defenders',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'Not an outside/edge attacker',
            ];
        }

        $currentDefenders = MatchTeamList::where('match_id', $match->id)
            ->where('team_id', $opponentId)
            ->whereIn('position_number', [1, 2, 3, 4, 5, 11, 12])
            ->pluck('player_id');

        $priorMatch = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->first();

        if (! $priorMatch) {
            return [
                'type' => 'opponent_missing_defenders',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'No prior match for opponent to compare',
            ];
        }

        $priorDefenders = MatchTeamList::where('match_id', $priorMatch->id)
            ->where('team_id', $opponentId)
            ->whereIn('position_number', [1, 2, 3, 4, 5, 11, 12])
            ->pluck('player_id');

        $newFaces = $currentDefenders->diff($priorDefenders)->count();

        // Only count as weakness if the newcomer is also listed as injured/out — replacing
        // an injured starter is a true weakness; a rotation swap often brings a fresh player.
        $priorInjured = Injury::where('resolved', false)
            ->whereIn('status', ['out', 'doubt'])
            ->whereIn('player_id', $priorDefenders)
            ->count();

        if ($priorInjured === 0 && $newFaces < 2) {
            return [
                'type' => 'opponent_missing_defenders',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'Defensive changes look like rotation, not weakness',
            ];
        }

        $strength = match (true) {
            $priorInjured >= 2 || $newFaces >= 3 => 1.0,
            $priorInjured >= 1 || $newFaces >= 2 => 0.7,
            default => 0.3,
        };

        return [
            'type' => 'opponent_missing_defenders',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('%d new faces / %d injured defenders in opponent back-five', $newFaces, $priorInjured),
        ];
    }

    protected function headToHead(Player $player, int $opponentId, Matchup $match, array $w): array
    {
        $stat = $player->opponentStats->firstWhere('opponent_team_id', $opponentId);
        if (! $stat || $stat->games === 0) {
            return [
                'type' => 'head_to_head',
                'weight' => $w['head_to_head'] ?? 10,
                'strength' => 0.0,
                'description' => 'No head-to-head history',
            ];
        }

        $opponentName = $match->home_team_id === $opponentId ? $match->homeTeam?->name : $match->awayTeam?->name;

        return [
            'type' => 'head_to_head',
            'weight' => $w['head_to_head'] ?? 10,
            'strength' => min(1.0, $stat->try_rate / 1.0),
            'description' => sprintf('Has scored %d tries in %d games vs %s', $stat->tries, $stat->games, $opponentName ?? 'opponent'),
        ];
    }

    protected function venueRecord(Player $player, Matchup $match, array $w): array
    {
        $venue = $match->venue;
        if (! $venue) {
            return [
                'type' => 'venue_record',
                'weight' => $w['venue_record'] ?? 8,
                'strength' => 0.0,
                'description' => 'Venue unknown',
            ];
        }

        $stat = $player->venueStats->firstWhere('venue', $venue);
        if (! $stat || $stat->games === 0) {
            return [
                'type' => 'venue_record',
                'weight' => $w['venue_record'] ?? 8,
                'strength' => 0.0,
                'description' => sprintf('No prior games at %s', $venue),
            ];
        }

        return [
            'type' => 'venue_record',
            'weight' => $w['venue_record'] ?? 8,
            'strength' => min(1.0, $stat->try_rate / 1.0),
            'description' => sprintf('%.2f try rate at %s (%d games)', $stat->try_rate, $venue, $stat->games),
        ];
    }

    protected function careerTryRate(Player $player, array $w): array
    {
        $rate = $player->careerTryRate();
        // Career rate saturated at /0.5 — almost every top-15-pick player sits ≥
        // 0.5, which collapsed the signal (delta ≈ 0). Widen to /0.8 so genuinely
        // elite finishers (Tedesco/Tupou-tier ~ 0.8+) still hit 1.0 while typical
        // 0.3–0.5 starters now spread across 0.4–0.6 instead of all clipping high.
        return [
            'type' => 'career_try_rate',
            'weight' => $w['career_try_rate'] ?? 8,
            'strength' => min(1.0, $rate / 0.8),
            'description' => sprintf('%.2f career tries per game', $rate),
        ];
    }

    protected function milestoneGame(Player $player, Matchup $match, array $w): array
    {
        $tryMilestones = config('nrl-weights.try_milestones', [50, 100, 150, 200, 212, 250]);
        $gameMilestones = config('nrl-weights.game_milestones', [50, 100, 150, 200, 250, 300, 350]);
        $tryDistance = config('nrl-weights.milestone_try_distance', 3);
        $gameDistance = config('nrl-weights.milestone_game_distance', 1);

        // Check try milestones
        $careerTries = $player->career_tries ?? 0;
        foreach ($tryMilestones as $target) {
            $distance = $target - $careerTries;
            if ($distance > 0 && $distance <= $tryDistance) {
                return [
                    'type' => 'milestone_game',
                    'weight' => $w['milestone_game'] ?? 8,
                    'strength' => 1.0,
                    'description' => sprintf('Chasing try #%d (%d away)', $target, $distance),
                ];
            }
        }

        // Check game milestones
        $nextGame = ($player->career_games ?? 0) + 1;
        foreach ($gameMilestones as $target) {
            if (abs($nextGame - $target) <= $gameDistance) {
                return [
                    'type' => 'milestone_game',
                    'weight' => $w['milestone_game'] ?? 8,
                    'strength' => 1.0,
                    'description' => sprintf('Playing %dth NRL game', $nextGame),
                ];
            }
        }

        return [
            'type' => 'milestone_game',
            'weight' => $w['milestone_game'] ?? 8,
            'strength' => 0.0,
            'description' => sprintf('No milestone upcoming (%d games, %d tries)', $player->career_games ?? 0, $careerTries),
        ];
    }

    protected function teamAttackingForm(Player $player, array $w): array
    {
        $weight = $w['team_attacking_form'] ?? 7;
        if (! $player->team_id) {
            return [
                'type' => 'team_attacking_form',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'Team unknown',
            ];
        }

        $recentMatchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($recentMatchIds->isEmpty()) {
            return [
                'type' => 'team_attacking_form',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'No recent team matches',
            ];
        }

        $teamTries = TryEvent::whereHas('player', fn ($q) => $q->where('team_id', $player->team_id))
            ->whereIn('match_id', $recentMatchIds)
            ->count();

        $tryRate = $teamTries / $recentMatchIds->count(); // tries per game

        // Only reward players proportional to their position's share of the team's tries.
        // A lock on a 4-tries-a-game team shouldn't get the same boost as the winger.
        $positionShare = match ($player->position) {
            'winger'      => 1.00,
            'fullback'    => 0.90,
            'centre'      => 0.80,
            'second-row' => 0.55,
            'five-eighth' => 0.45,
            'halfback'    => 0.40,
            'hooker'      => 0.30,
            'lock'        => 0.25,
            'prop'        => 0.15,
            default       => 0.30,
        };

        // Strong attacking team = 4+ tries/game. Weak = 2 or fewer.
        // Discount anything under the league avg (~3.2) and scale up above it.
        $aboveAverage = max(0.0, $tryRate - 3.0) / 3.0; // 6 tries/g → 1.0
        $strength = min(1.0, $aboveAverage * $positionShare);

        return [
            'type' => 'team_attacking_form',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('%.1f team tries/game over last %d (position share=%.0f%%)', $tryRate, $recentMatchIds->count(), $positionShare * 100),
        ];
    }

    protected function returningPlayer(Player $player, Matchup $match, array $w): array
    {
        $priorRound = Round::where('round_number', '<', $match->round->round_number ?? 0)
            ->where('season', $match->round->season ?? now()->year)
            ->orderByDesc('round_number')
            ->first();

        if (! $priorRound) {
            return ['type' => 'returning_player', 'weight' => $w['returning_player'] ?? 6, 'strength' => 0.0, 'description' => 'No prior round to compare'];
        }

        $priorRoundHasTeamLists = MatchTeamList::whereHas('match', fn ($q) => $q->where('round_id', $priorRound->id))->exists();
        if (! $priorRoundHasTeamLists) {
            return ['type' => 'returning_player', 'weight' => $w['returning_player'] ?? 6, 'strength' => 0.0, 'description' => 'No team list data for prior round'];
        }

        $playedLast = MatchTeamList::where('player_id', $player->id)
            ->whereHas('match', fn ($q) => $q->where('round_id', $priorRound->id))
            ->exists();

        if ($playedLast) {
            return ['type' => 'returning_player', 'weight' => $w['returning_player'] ?? 6, 'strength' => 0.0, 'description' => 'Played last round'];
        }

        // Tightened gate (perf-log shows the looser gate carried a -0.033 edge):
        // require ≥15 career games and ≥0.25 t/g career rate, and only fire for
        // back-five attackers — second-row returnees scored at noise levels.
        // Weight also dropped (6 → 3) until the signal earns its keep.
        $games = (int) ($player->career_games ?? 0);
        $tries = (int) ($player->career_tries ?? 0);
        $careerRate = $games > 0 ? $tries / $games : 0.0;
        $isAttackingPos = in_array($player->position, ['winger', 'centre', 'fullback'], true);
        if ($games < 15 || $careerRate < 0.25 || ! $isAttackingPos) {
            return ['type' => 'returning_player', 'weight' => $w['returning_player'] ?? 3, 'strength' => 0.0, 'description' => 'Returnee but not a proven try threat'];
        }

        $injury = $player->activeInjury?->injury_type ?? 'absence';
        $strength = min(1.0, 0.4 + ($careerRate / 0.5) * 0.6); // 0.4 floor; saturates at 0.5 t/g
        return [
            'type' => 'returning_player',
            'weight' => $w['returning_player'] ?? 3,
            'strength' => $strength,
            'description' => sprintf('Returning from %s (%.2f career t/g)', $injury, $careerRate),
        ];
    }

    // ── Phase 3 signals ───────────────────────────────────

    /** §3.1 Edge/side-of-field mismatch */
    protected function edgeMismatch(Player $player, Matchup $match, int $opponentId, array $w): array
    {
        $weight = $w['edge_mismatch'] ?? 15;
        $side = $this->derivePlayerSide($player, $match);
        if (! $side || $side === 'middle') {
            return ['type' => 'edge_mismatch', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Not an edge player'];
        }

        $teamDist = TeamTryDistribution::where('team_id', $player->team_id)->where('period', 'last_5')->first();
        $oppDist = TeamTryDistribution::where('team_id', $opponentId)->where('period', 'last_5')->first();

        if (! $teamDist || ! $oppDist) {
            return ['type' => 'edge_mismatch', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No try distribution data available'];
        }

        $attackPct = $side === 'left' ? $teamDist->attack_left_pct : $teamDist->attack_right_pct;
        $concedePct = $side === 'left' ? $oppDist->concede_left_pct : $oppDist->concede_right_pct;

        $score = ($attackPct / 100) * ($concedePct / 100);
        $strength = min(1.0, $score / 0.12); // 12%+ combined = max

        return [
            'type' => 'edge_mismatch',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team attacks %s %d%%, opponent concedes %s %d%%', $side, $attackPct, $side, $concedePct),
        ];
    }

    /** §3.4 Weather adjustment */
    protected function weatherAdjustment(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['weather_adjustment'] ?? 8;
        $forecast = WeatherForecast::where('match_id', $match->id)->latest('captured_at')->first();

        if (! $forecast) {
            return ['type' => 'weather_adjustment', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No weather data'];
        }

        $position = $player->position ?? '';
        $strength = 0.0;
        $desc = [];

        if ($forecast->is_wet) {
            if (in_array($position, ['winger', 'centre', 'fullback'])) {
                $strength -= 0.15; // Wet reduces outside backs
                $desc[] = 'Wet conditions — backs disadvantaged';
            } elseif (in_array($position, ['hooker', 'lock'])) {
                $strength += 0.10; // Wet favours middle forwards
                $desc[] = 'Wet conditions — middle forwards favoured';
            }
        }

        if ($forecast->is_hot) {
            $strength -= 0.05;
            $desc[] = sprintf('Hot conditions (%.0f°C)', $forecast->temp_c);
        }

        if (($forecast->wind_kph ?? 0) > 25) {
            if (in_array($position, ['halfback', 'five-eighth', 'fullback'])) {
                $strength += 0.05;
                $desc[] = 'High wind favours kick-chase tries';
            }
        }

        // Normalize to 0-1 range (can be negative for bad weather)
        $strength = max(0.0, min(1.0, 0.5 + $strength));

        return [
            'type' => 'weather_adjustment',
            'weight' => $weight,
            'strength' => $strength,
            'description' => implode('; ', $desc) ?: sprintf('Clear conditions (%.0f°C, %dkph wind)', $forecast->temp_c, $forecast->wind_kph),
        ];
    }

    /** §3.5 Debut boost */
    protected function debutBoost(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['debut_boost'] ?? 5;

        $listEntry = MatchTeamList::where('match_id', $match->id)
            ->where('player_id', $player->id)
            ->first();

        if (! $listEntry || ! $listEntry->is_debut) {
            return ['type' => 'debut_boost', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Not a debutant'];
        }

        // Debut outside-back on favoured home team gets a boost
        $isOutsideBack = in_array($player->position, ['winger', 'centre', 'fullback']);
        $isHomeTeam = $match->home_team_id === $player->team_id;
        $homeFavoured = ($match->home_win_pct ?? 50) > 60;

        $strength = 0.5; // Base debut boost
        if ($isOutsideBack && $isHomeTeam && $homeFavoured) {
            $strength = 1.0;
        } elseif ($isOutsideBack) {
            $strength = 0.7;
        }

        return [
            'type' => 'debut_boost',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('NRL debut — %s on %s', ucfirst($player->position ?? 'player'), $isHomeTeam ? 'home team' : 'away team'),
        ];
    }

    /** §3.6 Revenge game */
    protected function revengeGame(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['revenge_game'] ?? 3;

        $history = PlayerClubHistory::where('player_id', $player->id)
            ->where('team_id', $opponentId)
            ->where('games', '>=', 20)
            ->first();

        if (! $history) {
            return ['type' => 'revenge_game', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No history with opponent'];
        }

        return [
            'type' => 'revenge_game',
            'weight' => $weight,
            'strength' => 1.0,
            'description' => sprintf('Former %s player (%d games, %d tries)', $history->team?->short_name ?? 'club', $history->games, $history->tries),
        ];
    }

    /** §3.7 Short turnaround / travel */
    protected function shortTurnaround(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['short_turnaround'] ?? 5;
        $isHome = $match->home_team_id === $player->team_id;

        $daysSince = $isHome ? $match->days_since_last_home : $match->days_since_last_away;
        $interstate = $isHome ? $match->interstate_travel_home : $match->interstate_travel_away;

        if ($daysSince === null) {
            return ['type' => 'short_turnaround', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No turnaround data'];
        }

        $strength = 0.0;
        $desc = sprintf('%d day turnaround', $daysSince);

        if ($daysSince <= 5 && $interstate) {
            $strength = -1.0; // Negative = penalise
            $desc .= ' + interstate travel';
        } elseif ($daysSince <= 5) {
            $strength = -0.5;
            $desc .= ' (short)';
        } elseif ($daysSince >= 10) {
            $strength = 0.3; // Well rested
            $desc .= ' (well rested)';
        }

        // Convert to 0-1 range (0.5 = neutral, <0.5 = bad, >0.5 = good)
        $normalised = max(0.0, min(1.0, 0.5 + ($strength * 0.5)));

        return [
            'type' => 'short_turnaround',
            'weight' => $weight,
            'strength' => $normalised,
            'description' => $desc,
        ];
    }

    /** §3.9 Tactical shift detection */
    protected function tacticalShift(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['tactical_shift'] ?? 8;
        $isHome = $match->home_team_id === $player->team_id;
        $hasShift = $isHome ? $match->tactical_shift_home : $match->tactical_shift_away;

        if (! $hasShift) {
            return ['type' => 'tactical_shift', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No tactical shift detected'];
        }

        // Dampen score toward mean — reduce confidence when attacking shape is new
        return [
            'type' => 'tactical_shift',
            'weight' => $weight,
            'strength' => 0.3, // Low strength = dampen toward mean
            'description' => 'Spine/halves change detected — widened uncertainty',
        ];
    }

    /**
     * How many tries the opponent has conceded per game in the last 5.
     * Straight-up "leaky defence" signal — if the other side is coughing
     * up tries, anyone lined up against them gets a lift.
     */
    protected function opponentTryConcedeRate(int $opponentId, array $w): array
    {
        $weight = $w['opponent_try_concede_rate'] ?? 10;

        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->get();

        if ($matches->isEmpty()) {
            return ['type' => 'opponent_try_concede_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent defence data'];
        }

        $triesConceded = 0;
        foreach ($matches as $m) {
            $triesConceded += TryEvent::where('match_id', $m->id)
                ->whereHas('player', fn ($q) => $q->where('team_id', '!=', $opponentId))
                ->count();
        }

        $perGame = $triesConceded / $matches->count();
        // 3 tries/game is league average, 5+ is leaky.
        $strength = max(0.0, min(1.0, ($perGame - 2) / 4));

        return [
            'type' => 'opponent_try_concede_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opponent concedes %.1f tries/game (last %d)', $perGame, $matches->count()),
        ];
    }

    /**
     * Consensus anytime-try-scorer probability across AU bookmakers.
     * Uses the median implied probability so a single outlier bookmaker can't
     * dominate, and skips the match entirely if fewer than two books priced the
     * player (thin markets are often stale/wrong).
     */
    protected function bookmakerTryOdds(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['bookmaker_try_odds'] ?? 25;

        $odds = OddsSnapshot::where('match_id', $match->id)
            ->where('player_id', $player->id)
            ->where('market', 'ats')
            ->where('decimal_odds', '>', 1.0)
            ->pluck('decimal_odds')
            ->all();

        if (count($odds) < 2) {
            return [
                'type' => 'bookmaker_try_odds',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'No bookmaker try-scorer market',
            ];
        }

        $probs = array_map(fn ($o) => 1.0 / $o, $odds);
        sort($probs);
        $mid = (int) floor(count($probs) / 2);
        $median = count($probs) % 2 === 0
            ? ($probs[$mid - 1] + $probs[$mid]) / 2
            : $probs[$mid];

        // Market prices for anytime try scorer range ~15% (outside forward) to ~70%
        // (in-form winger vs weak defence). Scale [10%, 70%] onto [0, 1].
        $strength = max(0.0, min(1.0, ($median - 0.10) / 0.60));

        return [
            'type' => 'bookmaker_try_odds',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf(
                'Market consensus %.0f%% try-scorer probability (%d books)',
                $median * 100,
                count($odds),
            ),
        ];
    }

    /**
     * Median implied total match points from the AU totals market (over/under).
     * High lines (45+) mean books expect a shoot-out → more tries for everyone.
     * Outside backs are scaled a touch harder since a shoot-out inflates their
     * share disproportionately (wider play, more back-line tries). Falls through
     * silently when totals data is thin — avoids forcing a weak signal.
     */
    protected function matchTotalLine(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['match_total_line'] ?? 10;

        $points = OddsSnapshot::where('match_id', $match->id)
            ->where('market', 'totals')
            ->whereNotNull('point')
            ->pluck('point')
            ->map(fn ($p) => (float) $p)
            ->all();

        if (count($points) < 2) {
            return [
                'type' => 'match_total_line',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'No totals market for this match',
            ];
        }

        sort($points);
        $mid = (int) floor(count($points) / 2);
        $median = count($points) % 2 === 0
            ? ($points[$mid - 1] + $points[$mid]) / 2
            : $points[$mid];

        // Real NRL totals lines sit in the 44–56 range (median ~52.5). The earlier
        // [36, 48] window saturated every match at strength 1.0, killing the signal.
        // Map [44, 56] → [0, 1] so genuine shoot-out lines (54+) outweigh grinders (~46).
        $base = max(0.0, min(1.0, ($median - 44) / 12));
        $isOutsideBack = in_array($player->position, ['winger', 'centre', 'fullback'], true);
        $strength = $isOutsideBack ? min(1.0, $base * 1.2) : $base;

        return [
            'type' => 'match_total_line',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Market expects ~%.1f total points', $median),
        ];
    }

    /**
     * Players on heavier market favourites score more tries — favourites
     * spend more time in the opponent's red zone. Scales by de-vigged
     * implied win % of the player's team above 50%. A 65% favourite's
     * scorers get a solid boost; a coin-flip match contributes nothing.
     */
    protected function teamFavouritism(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['team_favouritism'] ?? 8;

        $homeOdds = OddsSnapshot::where('match_id', $match->id)
            ->where('market', 'match_winner')
            ->where('outcome', 'home')
            ->where('decimal_odds', '>', 1.0)
            ->pluck('decimal_odds')
            ->all();

        $awayOdds = OddsSnapshot::where('match_id', $match->id)
            ->where('market', 'match_winner')
            ->where('outcome', 'away')
            ->where('decimal_odds', '>', 1.0)
            ->pluck('decimal_odds')
            ->all();

        if (count($homeOdds) < 2 || count($awayOdds) < 2) {
            return [
                'type' => 'team_favouritism',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'No market line for favouritism',
            ];
        }

        $homeMed = $this->medianProb($homeOdds);
        $awayMed = $this->medianProb($awayOdds);
        $sum = $homeMed + $awayMed;
        if ($sum <= 0) {
            return [
                'type' => 'team_favouritism',
                'weight' => $weight,
                'strength' => 0.0,
                'description' => 'Market line collapsed',
            ];
        }

        $homeProb = $homeMed / $sum;
        $awayProb = $awayMed / $sum;
        $teamProb = $match->home_team_id === $player->team_id ? $homeProb : $awayProb;

        // Scale above-even probability onto [0, 1]. 50% → 0, 80% → 1.
        $strength = max(0.0, min(1.0, ($teamProb - 0.50) / 0.30));

        return [
            'type' => 'team_favouritism',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Market implied %.0f%% win prob for team', $teamProb * 100),
        ];
    }

    protected function medianProb(array $decimalOdds): float
    {
        if (empty($decimalOdds)) {
            return 0.0;
        }
        $probs = array_map(fn ($o) => 1.0 / (float) $o, $decimalOdds);
        sort($probs);
        $mid = (int) floor(count($probs) / 2);
        return count($probs) % 2 === 0
            ? ($probs[$mid - 1] + $probs[$mid]) / 2
            : $probs[$mid];
    }

    /**
     * What share of their team's tries has this player scored in recent games?
     * This finds the genuine finishers: someone scoring 30%+ of their team's tries
     * is locked in as the go-to, regardless of absolute season rate. Gated on
     * team sample size to avoid noise from a couple of standout games.
     */
    protected function rollingTryShare(Player $player, array $w): array
    {
        $weight = $w['rolling_try_share'] ?? 9;
        if (! $player->team_id) {
            return ['type' => 'rolling_try_share', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $recentMatchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($recentMatchIds->isEmpty()) {
            return ['type' => 'rolling_try_share', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No recent team matches'];
        }

        $teamTries = TryEvent::whereHas('player', fn ($q) => $q->where('team_id', $player->team_id))
            ->whereIn('match_id', $recentMatchIds)
            ->count();

        if ($teamTries < 4) {
            return ['type' => 'rolling_try_share', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient team try sample'];
        }

        $playerTries = TryEvent::where('player_id', $player->id)
            ->whereIn('match_id', $recentMatchIds)
            ->count();

        $share = $playerTries / $teamTries;
        // 30%+ share = top finisher on the team. Scale [0, 0.35] → [0, 1].
        $strength = min(1.0, $share / 0.35);

        return [
            'type' => 'rolling_try_share',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Scored %d of team\'s %d recent tries (%.0f%%)', $playerTries, $teamTries, $share * 100),
        ];
    }

    /**
     * Goal-line pressure: rolling forced drop-outs per game by the player's team.
     * High forced-DO rate ⇒ repeat sets near the opp line ⇒ tries for outside
     * backs and edge runners. Position-weighted because middles rarely finish
     * goal-line plays even when their team forces the DOs.
     */
    protected function redZonePressure(Player $player, array $w): array
    {
        $weight = $w['red_zone_pressure'] ?? 8;
        if (! $player->team_id) {
            return ['type' => 'red_zone_pressure', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rate = $this->teamRollingStat($player->team_id, fn ($s) => $s->forced_drop_outs);
        if ($rate === null) {
            return ['type' => 'red_zone_pressure', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No goal-line stats yet'];
        }

        // Real 2026 NRL spread: ~0.5 (Dragons) → ~2.6 (Roosters), median ~1.4.
        // Prior band [1.5, 4.0] clipped most teams to 0 — recalibrated to [0.5, 2.5]
        // to span the actual distribution and produce a usable strength gradient.
        $base = max(0.0, min(1.0, ($rate - 0.5) / 2.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.50,
            'hooker', 'lock'               => 0.30,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'red_zone_pressure',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team forces %.1f drop-outs/game (last 5)', $rate),
        ];
    }

    /**
     * Opponent ruck speed proxy: their rolling ruck infringements + penalties
     * conceded per game. Slow/sloppy ruck defence lets attacking sides string
     * together quick PTBs and break the line — boosts try chances for runners
     * who play off the ball (halves, fullback, edge forwards).
     */
    protected function opponentRuckSpeed(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opponent_ruck_speed'] ?? 6;

        $ruckInf = $this->teamRollingStat($opponentId, fn ($s) => $s->ruck_infringements);
        $pens = $this->teamRollingStat($opponentId, fn ($s) => $s->penalties_conceded);
        if ($ruckInf === null && $pens === null) {
            return ['type' => 'opponent_ruck_speed', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent ruck data'];
        }

        // Combine ruck infringements (high signal) with raw penalties (broader, lower signal).
        $combined = ($ruckInf ?? 0) * 1.5 + ($pens ?? 0) * 0.6;
        // 6+ combined per game = leaky ruck; 2 = elite. Map [2, 8] → [0, 1].
        $base = max(0.0, min(1.0, ($combined - 2.0) / 6.0));

        // Position-weight: support runners + edges benefit most from quick PTBs.
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 0.85,
            'second-row'                   => 0.80,
            'five-eighth', 'halfback'      => 0.90,
            'hooker'                       => 0.50,
            'lock'                         => 0.40,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opponent_ruck_speed',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf(
                'Opp ruck pressure %.1f infr+%.1f pens/g',
                $ruckInf ?? 0,
                $pens ?? 0,
            ),
        ];
    }

    /**
     * Opponent's rolling error count per game. Sloppy opponents cough up
     * mid-field ball, which converts to tries down the edges at a much
     * higher rate than tight-game possession. Position-share keeps middles
     * from inheriting a signal that mostly benefits outside attackers.
     */
    protected function opponentErrorRate(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opponent_error_rate'] ?? 7;
        $rate = $this->teamRollingStat($opponentId, fn ($s) => $s->errors);
        if ($rate === null) {
            return ['type' => 'opponent_error_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent error history'];
        }

        // 8 errors/game is sloppy, 4 is elite. Map [4, 12] → [0, 1].
        $base = max(0.0, min(1.0, ($rate - 4.0) / 8.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 0.95,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.65,
            'hooker'                       => 0.40,
            'lock'                         => 0.30,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opponent_error_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp commits %.1f errors/game (last 5)', $rate),
        ];
    }

    /**
     * Average a numeric stat across a team's last 5 completed match-team-stats rows.
     * Cached on the instance (NOT `static` — that would live for the whole
     * queue-worker daemon and serve stale form data for days).
     */
    protected array $teamRollingStatCache = [];

    protected function teamRollingStat(int $teamId, \Closure $extractor): ?float
    {
        $cache = &$this->teamRollingStatCache;
        if (! isset($cache[$teamId])) {
            $cache[$teamId] = MatchTeamStats::where('team_id', $teamId)
                ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
                ->orderByDesc('id')
                ->limit(5)
                ->get();
        }
        $rows = $cache[$teamId];
        if ($rows->isEmpty()) {
            return null;
        }
        $values = $rows->map($extractor)->filter(fn ($v) => $v !== null && $v !== '');
        if ($values->isEmpty()) {
            return null;
        }
        return (float) $values->avg();
    }

    /**
     * Starting XIII vs interchange vs reserve. Sample shows starters score
     * ~32% try-rate per game, bench ~3%, reserves ~3% — a 10x gap. Reserves
     * mostly never take the field. Filtering this out of try-scorer odds is
     * one of the most diagnostic single signals available.
     */
    protected function starterRole(Player $player, Matchup $match, array $w): array
    {
        $weight = $w['starter_role'] ?? 14;
        $entry = MatchTeamList::where('match_id', $match->id)
            ->where('player_id', $player->id)
            ->first();

        if (! $entry) {
            return ['type' => 'starter_role', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Not on team list'];
        }

        $strength = match ($entry->role) {
            'starting'    => 1.0,
            'interchange' => 0.30,
            'reserve'     => 0.05,
            default       => 0.20,
        };

        return [
            'type' => 'starter_role',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Lineup role: %s (#%d)', $entry->role, $entry->position_number),
        ];
    }

    /**
     * Opponent's rolling missed-tackles per game (last 5). Earlier in the
     * causal chain than tries-conceded — leaky tackling shows up before the
     * line is broken. Edge runners and support backs benefit most.
     */
    protected function oppMissedTackles(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_missed_tackles'] ?? 8;
        $rate = $this->teamRollingStat($opponentId, fn ($s) => $s->missed_tackles);
        if ($rate === null) {
            return ['type' => 'opp_missed_tackles', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent missed-tackle data'];
        }

        // League distribution: avg 33.4, σ 8.5, range [16, 63]. Map [22, 45] → [0, 1]
        // so a μ−σ stout defence reads 0 and a μ+σ leaky one reads 1.
        $base = max(0.0, min(1.0, ($rate - 22.0) / 23.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 0.95,
            'second-row'                   => 0.80,
            'five-eighth', 'halfback'      => 0.70,
            'lock'                         => 0.45,
            'hooker'                       => 0.40,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_missed_tackles',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp misses %.1f tackles/game (last 5)', $rate),
        ];
    }

    /**
     * Opponent's rolling line-breaks-conceded per game (last 5). Direct
     * precursor of tries — outside backs in particular feast on broken-line
     * defence. Orthogonal to error-rate (a clean break needs no turnover).
     */
    protected function oppLineBreaksConceded(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_line_breaks_conceded'] ?? 9;

        // For each of the opponent's last 5 completed matches, line breaks
        // *conceded* = the opposing team's line_breaks in that match.
        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['type' => 'opp_line_breaks_conceded', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent line-break history'];
        }

        $rows = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', '!=', $opponentId)
            ->whereNotNull('line_breaks')
            ->pluck('line_breaks');

        if ($rows->isEmpty()) {
            return ['type' => 'opp_line_breaks_conceded', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent line-break stats parsed'];
        }

        $rate = $rows->avg();
        // League distribution: avg 5.1, σ 2.5, range [1, 14]. Map [3, 8] → [0, 1].
        $base = max(0.0, min(1.0, ($rate - 3.0) / 5.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.0,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.65,
            'lock'                         => 0.40,
            'hooker'                       => 0.35,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_line_breaks_conceded',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.1f line breaks/game (last 5)', $rate),
        ];
    }

    /**
     * Opponent's tries conceded broken down by scorer's position-class over
     * last 5 completed matches. Channel-specific: a team that leaks wing tries
     * doesn't necessarily leak forward tries. Buckets:
     *   back_five  → winger, fullback, centre
     *   edge       → second-row, five-eighth
     *   middle     → prop, lock, hooker, halfback
     * League average ~3 tries conceded/game; ~55% in back-five class. Anything
     * above the bucket's typical share = an exploitable lane for that player's
     * position. Returns 0 strength if the player is unpositioned.
     */
    protected function oppPositionConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_position_concede'] ?? 9;
        $bucket = $this->positionBucket($player->position);
        if ($bucket === null) {
            return ['type' => 'opp_position_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Position unknown'];
        }

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['type' => 'opp_position_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent match history'];
        }

        $tries = TryEvent::whereIn('match_id', $matchIds)
            ->whereHas('player', fn ($q) => $q->where('team_id', '!=', $opponentId))
            ->with('player:id,position')
            ->get();

        if ($tries->isEmpty()) {
            return ['type' => 'opp_position_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No tries conceded recorded'];
        }

        $bucketCount = $tries->filter(fn ($t) => $this->positionBucket($t->player?->position) === $bucket)->count();
        $perGame = $bucketCount / $matchIds->count();

        // Per-bucket calibration based on league avg shares of the ~3 tries/game total:
        //   back_five  ~1.7 tries/g (saturate at 3.0)
        //   edge       ~0.7 tries/g (saturate at 1.5)
        //   middle     ~0.5 tries/g (saturate at 1.2)
        $saturate = match ($bucket) {
            'back_five' => 3.0,
            'edge'      => 1.5,
            'middle'    => 1.2,
        };
        $strength = max(0.0, min(1.0, $perGame / $saturate));

        return [
            'type' => 'opp_position_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.1f %s tries/g (last %d)', $perGame, str_replace('_', ' ', $bucket), $matchIds->count()),
        ];
    }

    /**
     * Opponent defensive form trend: tries conceded per game in last 3 vs the
     * 5 games prior. Strongly positive delta = defence in free-fall, which is
     * a different signal from flat tries-conceded level (a side at 4 t/g
     * conceded all season is steady; a side that jumped from 2 → 5 is broken).
     * Position-weighted toward back-five who feast on a collapsing back line.
     * Returns 0 when we don't have enough match history (need 8 completed).
     */
    protected function oppDefFormDecay(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_def_form_decay'] ?? 6;

        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(8)
            ->get();

        if ($matches->count() < 6) {
            return ['type' => 'opp_def_form_decay', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opponent history'];
        }

        $recent = $matches->take(3);
        $prior = $matches->slice(3);

        $countConceded = function ($matchSet) use ($opponentId) {
            $ids = $matchSet->pluck('id');
            return TryEvent::whereIn('match_id', $ids)
                ->whereHas('player', fn ($q) => $q->where('team_id', '!=', $opponentId))
                ->count();
        };

        $recentRate = $countConceded($recent) / max(1, $recent->count());
        $priorRate = $countConceded($prior) / max(1, $prior->count());
        $delta = $recentRate - $priorRate;

        // Positive delta = trending leakier. Map [0, +2.5 t/g delta] → [0, 1].
        // Negative deltas (defence improving) emit no signal — the level is
        // already covered by opponent_try_concede_rate.
        if ($delta <= 0) {
            return ['type' => 'opp_def_form_decay', 'weight' => $weight, 'strength' => 0.0, 'description' => sprintf('Opp defence holding (%.1f → %.1f t/g)', $priorRate, $recentRate)];
        }

        $base = max(0.0, min(1.0, $delta / 2.5));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.0,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.30,
            'hooker'                       => 0.30,
            default                        => 0.15,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_def_form_decay',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp leak trend +%.1f t/g (%.1f → %.1f)', $delta, $priorRate, $recentRate),
        ];
    }

    protected function positionBucket(?string $position): ?string
    {
        return match ($position) {
            'winger', 'fullback', 'centre'  => 'back_five',
            'second-row', 'five-eighth'     => 'edge',
            'prop', 'lock', 'hooker', 'halfback' => 'middle',
            default                          => null,
        };
    }

    // ── Helpers ───────────────────────────────────────────

    protected function derivePlayerSide(Player $player, Matchup $match): ?string
    {
        $listEntry = MatchTeamList::where('match_id', $match->id)
            ->where('player_id', $player->id)
            ->first();

        if (! $listEntry || ! $listEntry->position_number) {
            return null;
        }

        return match ($listEntry->position_number) {
            2, 4 => 'left',   // Left winger, left centre
            3, 5 => 'right',  // Right centre, right winger
            11   => 'left',   // Left second-row
            12   => 'right',  // Right second-row
            default => 'middle',
        };
    }

    /**
     * Attacking team's tackle-breaks per game (last 5). Tackle-breaks are the
     * leading indicator of line breaks: defences leaking ~30+ TBs/game crack
     * eventually. Position-weighted to outside backs and edges who finish
     * broken-tackle plays in space.
     */
    protected function teamTackleBreaks(Player $player, array $w): array
    {
        $weight = $w['team_tackle_breaks'] ?? 8;
        if (! $player->team_id) {
            return ['type' => 'team_tackle_breaks', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rate = $this->teamRollingStat($player->team_id, fn ($s) => $s->tackle_breaks);
        if ($rate === null) {
            return ['type' => 'team_tackle_breaks', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No team tackle-break stats'];
        }

        // 22 TBs/g = league average, 34+/g = elite (top quartile). Map [22, 38] → [0, 1].
        $base = max(0.0, min(1.0, ($rate - 22.0) / 16.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.65,
            'lock'                         => 0.50,
            'hooker'                       => 0.35,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_tackle_breaks',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team breaks %.1f tackles/game (last 5)', $rate),
        ];
    }

    /**
     * Opponent's post-contact metres conceded per game (last 5). Defences that
     * fail to halt momentum on first contact get rolled deep into their own
     * half repeatedly — high PCM concede correlates with goal-line repeat
     * sets. Distinct from missed_tackles (a tackle that loses 5m is still
     * "made") and from line_breaks_conceded (PCM is yardage leakage without
     * a clean break). Computed as opponent's *own* PCM-allowed proxy: their
     * own PCM stat is what they ran for, so we invert via the conceded-side
     * row. We approximate via opponent's tackles_made + missed_tackles
     * relationship instead, taking the mean PCM their *opponents* (i.e.
     * the attacking sides facing them) have racked up.
     */
    protected function oppPostContactConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_post_contact_concede'] ?? 7;

        // Pull last 5 completed matches the opponent played, then sum the
        // *other* side's post_contact_metres in each — that's PCM conceded.
        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->get();

        if ($matches->count() < 3) {
            return ['type' => 'opp_post_contact_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opponent history'];
        }

        $matchIds = $matches->pluck('id');
        $allowed = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', '!=', $opponentId)
            ->pluck('post_contact_metres')
            ->filter(fn ($v) => $v !== null)
            ->avg();

        if (! $allowed) {
            return ['type' => 'opp_post_contact_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No PCM data'];
        }

        // 550m/g = league average, 750+ = leaky. Map [500, 800] → [0, 1].
        $base = max(0.0, min(1.0, ($allowed - 500.0) / 300.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 0.95,
            'second-row'                   => 0.80,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.45,
            'hooker'                       => 0.30,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_post_contact_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.0f PCM/game (last 5)', $allowed),
        ];
    }

    /**
     * Attacking team's offloads per game (last 5). Offloads keep the ball
     * alive in the tackle, generating unstructured try chances for support
     * runners. Edge forwards and outside backs benefit most.
     */
    protected function teamOffloadRate(Player $player, array $w): array
    {
        $weight = $w['team_offload_rate'] ?? 6;
        if (! $player->team_id) {
            return ['type' => 'team_offload_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rate = $this->teamRollingStat($player->team_id, fn ($s) => $s->offloads);
        if ($rate === null) {
            return ['type' => 'team_offload_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No team offload stats'];
        }

        // 8 offloads/g = league average, 14+ = elite (Storm/Roosters territory). Map [6, 16] → [0, 1].
        $base = max(0.0, min(1.0, ($rate - 6.0) / 10.0));
        $share = match ($player->position) {
            'second-row', 'lock'           => 1.00,
            'winger', 'fullback', 'centre' => 0.85,
            'five-eighth', 'halfback'      => 0.60,
            'hooker'                       => 0.50,
            'prop'                         => 0.35,
            default                        => 0.30,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_offload_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team offloads %.1f/game (last 5)', $rate),
        ];
    }

    /**
     * Exponentially-weighted try rate over the player's last 8 matches (alpha=0.3).
     * Newest match weighted ~30%, decays geometrically. A player with EMA ≈ 1.0
     * tries/game is roughly 65% likely to score next match per wicky.ai research.
     * Orthogonal to recent_form (flat 3-game) and season_try_rate (full season avg).
     */
    protected function triesEma(Player $player, array $w): array
    {
        $weight = $w['tries_ema'] ?? 12;
        $teamId = $player->team_id;
        if (! $teamId) {
            return ['type' => 'tries_ema', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        // Last 8 completed team matches in chronological order (oldest → newest)
        // so EMA accumulates correctly. Eloquent strips ORDER on count(); pluck
        // IDs explicitly with ORDER BY DESC then reverse for chronological play.
        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('kickoff_at')
            ->limit(8)
            ->pluck('id')
            ->reverse()
            ->values();

        if ($matchIds->isEmpty()) {
            return ['type' => 'tries_ema', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No prior matches'];
        }

        // Tries per match for this player, indexed by match_id.
        $triesByMatch = TryEvent::where('player_id', $player->id)
            ->whereIn('match_id', $matchIds)
            ->selectRaw('match_id, COUNT(*) as n')
            ->groupBy('match_id')
            ->pluck('n', 'match_id');

        // EMA: e_t = alpha * x_t + (1 - alpha) * e_{t-1}
        $alpha = 0.3;
        $ema = 0.0;
        $games = 0;
        foreach ($matchIds as $mid) {
            $tries = (int) ($triesByMatch[$mid] ?? 0);
            $ema = $alpha * $tries + (1 - $alpha) * $ema;
            $games++;
        }

        // Saturate at 0.7 EMA tries/game — top NRL finishers (Tedesco/Tupou peaks)
        // sit around 0.6–0.8 EMA mid-season. Maps [0, 0.7] → [0, 1].
        $strength = min(1.0, $ema / 0.7);

        return [
            'type' => 'tries_ema',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('EMA %.2f tries/game (last %d, alpha=0.3)', $ema, $games),
        ];
    }

    /**
     * Opponent's rolling completion rate (last 5). League average sits near 0.79;
     * sub-0.75 sides routinely give the ball back deep in their own half. The
     * lower the opponent's completion, the more attacking sets our team starts
     * with good field position — a direct driver of back-five tries.
     */
    protected function oppCompletionPressure(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_completion_pressure'] ?? 8;

        $rows = MatchTeamStats::where('team_id', $opponentId)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->where('completion_denominator', '>', 0)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['completion_numerator', 'completion_denominator']);

        if ($rows->count() < 3) {
            return ['type' => 'opp_completion_pressure', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opponent completion history'];
        }

        $rate = $rows->avg(fn ($r) => $r->completion_numerator / max(1, $r->completion_denominator));

        // Lower completion = higher signal. Map [0.85, 0.72] → [0, 1]; saturate beyond.
        $base = max(0.0, min(1.0, (0.85 - $rate) / 0.13));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.40,
            'hooker'                       => 0.30,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_completion_pressure',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp completes %.1f%% of sets (last 5)', $rate * 100),
        ];
    }

    /**
     * Team's rolling possession share (last 5). Sustained 53%+ possession means
     * extra attacking sets per game vs an opponent — more chances to score.
     */
    protected function teamPossessionPct(Player $player, array $w): array
    {
        $weight = $w['team_possession_pct'] ?? 6;
        if (! $player->team_id) {
            return ['type' => 'team_possession_pct', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $pct = $this->teamRollingStat($player->team_id, fn ($s) => $s->possession_pct);
        if ($pct === null) {
            return ['type' => 'team_possession_pct', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No possession history'];
        }

        // 50% = neutral, 56%+ = strongly dominant. Map [50, 56] → [0, 1].
        $base = max(0.0, min(1.0, ($pct - 50.0) / 6.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row', 'five-eighth', 'halfback' => 0.70,
            'lock', 'hooker'               => 0.45,
            default                        => 0.35,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_possession_pct',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team avg possession %.1f%% (last 5)', $pct),
        ];
    }

    /**
     * Team's rolling kicking metres (last 5). Strong kick yardage flips field
     * position, traps opponents in their own 30m, and generates repeat sets.
     * League avg sits near 1100m/g; 1300+ is genuine territorial dominance.
     */
    protected function teamKickPressure(Player $player, array $w): array
    {
        $weight = $w['team_kick_pressure'] ?? 5;
        if (! $player->team_id) {
            return ['type' => 'team_kick_pressure', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $metres = $this->teamRollingStat($player->team_id, fn ($s) => $s->kicking_metres);
        if ($metres === null) {
            return ['type' => 'team_kick_pressure', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No kicking-metres history'];
        }

        // 500-700m typical (NRL avg ~580); 700+ = elite kicking game. Map [500, 750] → [0, 1].
        $base = max(0.0, min(1.0, ($metres - 500.0) / 250.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.65,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.40,
            'hooker'                       => 0.25,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_kick_pressure',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team kicks %.0fm/game (last 5)', $metres),
        ];
    }

    /**
     * Opponent's late-tries-conceded share. Fitness/cardio leakage signal.
     * Computes the % of opponent's recent (last 8) conceded tries that came
     * at minute >= 60. League average sits ~28%; sides above ~38% are bleeding
     * late. Heavily favours back-five + edges who beat tired markers in space.
     */
    protected function oppLateConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_late_concede'] ?? 7;

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(8)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['type' => 'opp_late_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent match history'];
        }

        $tries = TryEvent::whereIn('match_id', $matchIds)
            ->whereHas('player', fn ($q) => $q->where('team_id', '!=', $opponentId))
            ->where('minute', '>', 0)
            ->get(['minute']);

        if ($tries->count() < 5) {
            return ['type' => 'opp_late_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Not enough timed tries vs opponent'];
        }

        $late = $tries->filter(fn ($t) => $t->minute >= 60)->count();
        $share = $late / $tries->count();

        // Map [0.25, 0.45] → [0, 1]. Below league avg ⇒ no signal; above 45% saturates.
        $base = max(0.0, min(1.0, ($share - 0.25) / 0.20));
        $posShare = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.50,
            'lock'                         => 0.40,
            'hooker'                       => 0.25,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $posShare);

        return [
            'type' => 'opp_late_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp leaks %.0f%% of tries 60min+ (%d/%d, last %d games)', $share * 100, $late, $tries->count(), $matchIds->count()),
        ];
    }

    /**
     * Team's first-try-scored share over rolling last 8 completed matches.
     * Striking first imposes set-piece pressure on the opponent and correlates
     * with sustained attacking sequences (per Eye Test analytics: ~33% of tries
     * come from 7+ play-the-balls in a row, which favours teams already on top).
     * Position-weighted to attackers; orthogonal to team_attacking_form (which
     * uses points-for) and to team_favouritism (market-implied).
     */
    protected function teamFirstTryRate(Player $player, array $w): array
    {
        $weight = $w['team_first_try_rate'] ?? 6;
        if (! $player->team_id) {
            return ['type' => 'team_first_try_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->orderByDesc('kickoff_at')
            ->limit(8)
            ->pluck('id');

        if ($matches->count() < 3) {
            return ['type' => 'team_first_try_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient team match history'];
        }

        // For each match, find the earliest-minute try and check if it was scored
        // by a player on this team. Skip matches where no timed try is recorded.
        $sample = 0;
        $firstStrikes = 0;
        foreach ($matches as $matchId) {
            $first = TryEvent::where('match_id', $matchId)
                ->where('minute', '>', 0)
                ->orderBy('minute')
                ->with('player:id,team_id')
                ->first();

            if (! $first || ! $first->player) {
                continue;
            }
            $sample++;
            if ($first->player->team_id === $player->team_id) {
                $firstStrikes++;
            }
        }

        if ($sample < 3) {
            return ['type' => 'team_first_try_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Not enough timed tries to score'];
        }

        $rate = $firstStrikes / $sample;
        // Map [0.40, 0.75] → [0, 1]. ~50% is league-neutral; >75% is dominant first-strike side.
        $base = max(0.0, min(1.0, ($rate - 0.40) / 0.35));
        $posShare = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.65,
            'lock'                         => 0.45,
            'hooker'                       => 0.30,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $posShare);

        return [
            'type' => 'team_first_try_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team scored first try %d/%d (%.0f%%, last 8 games)', $firstStrikes, $sample, $rate * 100),
        ];
    }

    /**
     * Opponent's share of tries conceded in opening 20 minutes (last 8 games).
     * Mirror of opp_late_concede. Slow-starting defences leak early — feeds
     * first-try market value for back-five attackers and pairs with
     * team_first_try_rate. Source: try_events.minute filtered ≤ 20.
     */
    protected function oppFirstConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_first_concede'] ?? 6;

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(8)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['type' => 'opp_first_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opponent match history'];
        }

        $tries = TryEvent::whereIn('match_id', $matchIds)
            ->whereHas('player', fn ($q) => $q->where('team_id', '!=', $opponentId))
            ->where('minute', '>', 0)
            ->get(['minute']);

        if ($tries->count() < 5) {
            return ['type' => 'opp_first_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Not enough timed tries vs opponent'];
        }

        $early = $tries->filter(fn ($t) => $t->minute <= 20)->count();
        $share = $early / $tries->count();

        // League avg ~22%. Above ~32% flags a slow-starting defence. Map [0.22, 0.40] → [0, 1].
        $base = max(0.0, min(1.0, ($share - 0.22) / 0.18));
        $posShare = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.40,
            'hooker'                       => 0.25,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $posShare);

        return [
            'type' => 'opp_first_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp leaks %.0f%% of tries in first 20min (%d/%d, last %d games)', $share * 100, $early, $tries->count(), $matchIds->count()),
        ];
    }

    /**
     * Opponent's run metres conceded per game (last 5). Captures forward-pack
     * push that shifts attacking sides into try zone — complements
     * opp_post_contact_concede (contact-only) by including kick-return and
     * structured carries. Distinct from opp_missed_tackles (count, not yards).
     */
    protected function oppYardageConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_yardage_concede'] ?? 6;

        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->get();

        if ($matches->count() < 3) {
            return ['type' => 'opp_yardage_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opponent history'];
        }

        $matchIds = $matches->pluck('id');
        $allowed = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', '!=', $opponentId)
            ->pluck('all_run_metres')
            ->filter(fn ($v) => $v !== null)
            ->avg();

        if (! $allowed) {
            return ['type' => 'opp_yardage_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No yardage data'];
        }

        // League avg ~1550 run metres/g. Above ~1700 = leaky. Map [1450, 1800] → [0, 1].
        $base = max(0.0, min(1.0, ($allowed - 1450.0) / 350.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 0.95,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.50,
            'lock'                         => 0.40,
            'hooker'                       => 0.25,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_yardage_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.0f run metres/game (last 5)', $allowed),
        ];
    }

    /**
     * Team's tries scored per completed set (last 5). Conversion quality —
     * many existing signals measure attack volume (TBs, offloads, drop-outs).
     * This measures whether those sets actually finish. League avg ~0.10
     * tries/set; elite finishers ~0.13+. Position-weighted to outside backs
     * and edge runners who finish set-piece plays.
     */
    protected function teamSetEfficiency(Player $player, array $w): array
    {
        $weight = $w['team_set_efficiency'] ?? 7;
        if (! $player->team_id) {
            return ['type' => 'team_set_efficiency', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->count() < 3) {
            return ['type' => 'team_set_efficiency', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient team history'];
        }

        $tries = TryEvent::whereIn('match_id', $matchIds)
            ->whereHas('player', fn ($q) => $q->where('team_id', $player->team_id))
            ->count();

        $sets = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', $player->team_id)
            ->pluck('completion_numerator')
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->sum();

        if ($sets <= 0) {
            return ['type' => 'team_set_efficiency', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No set-completion data'];
        }

        $rate = $tries / $sets;
        // Calibrated to live R1-R10 spread (Dragons 0.063, Storm 0.076 at floor;
        // Sea Eagles 0.27, Roosters 0.24 at ceiling; league median ~0.15).
        // Map [0.08, 0.20] → [0, 1] for usable mid-table differentiation.
        $base = max(0.0, min(1.0, ($rate - 0.08) / 0.12));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.30,
            'hooker'                       => 0.30,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_set_efficiency',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team converts %.3f tries/set (%d tries / %d sets, last %d)', $rate, $tries, $sets, $matchIds->count()),
        ];
    }

    /**
     * Opponent's tries conceded per opp-set-faced (last 5). Defensive
     * resilience under load — distinct from raw try-concede rate which
     * confounds set-volume with conversion. A defence facing 35 sets/4 tries
     * is leakier per-set than one facing 28 sets/3 tries. League avg ~0.10
     * tries-conceded/set; above ~0.13 = leaky goal-line defence.
     */
    protected function oppSetConcedeRate(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_set_concede_rate'] ?? 7;

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->count() < 3) {
            return ['type' => 'opp_set_concede_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opponent history'];
        }

        // Tries conceded by the opponent: try_events where the scorer is NOT on opponent.
        $triesConceded = TryEvent::whereIn('match_id', $matchIds)
            ->whereHas('player', fn ($q) => $q->where('team_id', '!=', $opponentId))
            ->count();

        // Sets faced by the opponent = the *attacking* side's completed sets in those matches.
        $setsFaced = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', '!=', $opponentId)
            ->pluck('completion_numerator')
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->sum();

        if ($setsFaced <= 0) {
            return ['type' => 'opp_set_concede_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opp set-faced data'];
        }

        $rate = $triesConceded / $setsFaced;
        // Calibrated to live R1-R10 spread (Warriors 0.117 / Roosters 0.118 at
        // floor, Eels 0.248 / Knights 0.210 at ceiling; median ~0.15).
        // Map [0.10, 0.22] → [0, 1].
        $base = max(0.0, min(1.0, ($rate - 0.10) / 0.12));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.30,
            'hooker'                       => 0.25,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_set_concede_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.3f tries/set faced (%d tries / %d sets, last %d)', $rate, $triesConceded, $setsFaced, $matchIds->count()),
        ];
    }

    /**
     * Attacking team's explosive-play rate per carry: (tackle_breaks + line_breaks
     * + offloads) / all_runs across last 5 completed matches. Captures per-carry
     * danger — orthogonal to volume metrics. League spread (R1-R10): ~0.21
     * (Broncos) → ~0.31 (Raiders), median ~0.27. Position-shared toward back-five
     * + edges who finish off broken-line plays in space.
     */
    protected function teamExplosiveRate(Player $player, array $w): array
    {
        $weight = $w['team_explosive_rate'] ?? 8;
        if (! $player->team_id) {
            return ['type' => 'team_explosive_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rows = MatchTeamStats::where('team_id', $player->team_id)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->where('all_runs', '>', 0)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['tackle_breaks', 'line_breaks', 'offloads', 'all_runs']);

        if ($rows->count() < 3) {
            return ['type' => 'team_explosive_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient team history'];
        }

        $explosive = $rows->sum(fn ($r) => (int) ($r->tackle_breaks ?? 0) + (int) ($r->line_breaks ?? 0) + (int) ($r->offloads ?? 0));
        $runs = $rows->sum(fn ($r) => (int) ($r->all_runs ?? 0));
        if ($runs <= 0) {
            return ['type' => 'team_explosive_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No run data'];
        }

        $rate = $explosive / $runs;
        $base = max(0.0, min(1.0, ($rate - 0.22) / 0.08));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.80,
            'five-eighth', 'halfback'      => 0.65,
            'lock'                         => 0.45,
            'hooker'                       => 0.35,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_explosive_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team %.3f explosive plays/carry (%d / %d, last %d)', $rate, $explosive, $runs, $rows->count()),
        ];
    }

    /**
     * Opponent's explosive plays conceded per opp carry (last 5). Sister metric
     * to team_explosive_rate but on the defensive side — defences that allow
     * more line breaks + tackle busts + offloads under contact get carved by
     * support runners. Same band [0.22, 0.30] → [0, 1].
     */
    protected function oppExplosiveConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_explosive_concede'] ?? 7;

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->count() < 3) {
            return ['type' => 'opp_explosive_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opponent history'];
        }

        $rows = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', '!=', $opponentId)
            ->where('all_runs', '>', 0)
            ->get(['tackle_breaks', 'line_breaks', 'offloads', 'all_runs']);

        $explosive = $rows->sum(fn ($r) => (int) ($r->tackle_breaks ?? 0) + (int) ($r->line_breaks ?? 0) + (int) ($r->offloads ?? 0));
        $runs = $rows->sum(fn ($r) => (int) ($r->all_runs ?? 0));
        if ($runs <= 0) {
            return ['type' => 'opp_explosive_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opp run data conceded'];
        }

        $rate = $explosive / $runs;
        $base = max(0.0, min(1.0, ($rate - 0.22) / 0.08));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.35,
            'hooker'                       => 0.25,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_explosive_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.3f explosive plays/carry (%d / %d, last %d)', $rate, $explosive, $runs, $rows->count()),
        ];
    }

    /**
     * Phase 16: team's forced drop-outs per game over last 5 completed games.
     * Each drop-out = restart of attacking set inside opp 40m → tries score
     * at well-above-average rate from these positions. Calibrated [0.6, 2.5].
     */
    protected function teamDropOutsForced(Player $player, array $w): array
    {
        $weight = $w['team_drop_outs_forced'] ?? 7;
        if (! $player->team_id) {
            return ['type' => 'team_drop_outs_forced', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rows = MatchTeamStats::where('team_id', $player->team_id)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->whereNotNull('forced_drop_outs')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['forced_drop_outs']);

        if ($rows->count() < 3) {
            return ['type' => 'team_drop_outs_forced', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient drop-out history'];
        }

        $avg = $rows->avg(fn ($r) => (float) $r->forced_drop_outs);
        $base = max(0.0, min(1.0, ($avg - 0.6) / 1.9));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.35,
            'hooker'                       => 0.30,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_drop_outs_forced',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team forces %.2f drop-outs/game (last %d)', $avg, $rows->count()),
        ];
    }

    /**
     * Phase 16: opponent's ruck infringements per game over last 5. High count
     * = referees penalising slow defensive PTBs → team gets quick play-the-ball
     * + 10m retreats → cleaner front-foot ball for outside backs. Maps [2.0, 5.0].
     */
    protected function oppRuckPenalties(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_ruck_penalties'] ?? 5;

        $rows = MatchTeamStats::where('team_id', $opponentId)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->whereNotNull('ruck_infringements')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['ruck_infringements']);

        if ($rows->count() < 3) {
            return ['type' => 'opp_ruck_penalties', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opp ruck history'];
        }

        $avg = $rows->avg(fn ($r) => (float) $r->ruck_infringements);
        $base = max(0.0, min(1.0, ($avg - 2.0) / 3.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.40,
            'hooker'                       => 0.35,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_ruck_penalties',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.2f ruck infringements/game (last %d)', $avg, $rows->count()),
        ];
    }

    /**
     * Phase 17a: rolling net penalty differential per game (last 5). For each
     * of the team's last 5 completed matches, compute (opp.penalties_conceded
     * − team.penalties_conceded). Positive avg = field-position dominance via
     * referee whistle ⇒ more attacking sets in opp half. Maps [-1.5, +3.0] → [0, 1].
     */
    protected function teamPenaltyDiff(Player $player, array $w): array
    {
        $weight = $w['team_penalty_diff'] ?? 6;
        if (! $player->team_id) {
            return ['type' => 'team_penalty_diff', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['type' => 'team_penalty_diff', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No recent matches'];
        }

        $rows = MatchTeamStats::whereIn('match_id', $matchIds)
            ->whereNotNull('penalties_conceded')
            ->get(['match_id', 'team_id', 'penalties_conceded']);

        $diffs = [];
        foreach ($matchIds as $matchId) {
            $pair = $rows->where('match_id', $matchId);
            if ($pair->count() !== 2) {
                continue;
            }
            $own = $pair->firstWhere('team_id', $player->team_id);
            $opp = $pair->first(fn ($r) => $r->team_id !== $player->team_id);
            if (! $own || ! $opp) {
                continue;
            }
            $diffs[] = (int) $opp->penalties_conceded - (int) $own->penalties_conceded;
        }

        if (count($diffs) < 3) {
            return ['type' => 'team_penalty_diff', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient penalty history'];
        }

        $avg = array_sum($diffs) / count($diffs);
        $base = max(0.0, min(1.0, ($avg + 1.5) / 4.5));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.35,
            'hooker'                       => 0.30,
            default                        => 0.20,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_penalty_diff',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Penalty diff %+.2f/game (last %d)', $avg, count($diffs)),
        ];
    }

    /**
     * Phase 17b: opponent's rolling effective tackle % (last 5). Lower pct =
     * more busts + ineffective contact ⇒ more clean-line carries for support
     * runners. Maps (92 − pct) / 6 ⇒ [0, 1] (so 92% → 0, 86% → 1).
     */
    protected function oppEffectiveTacklePct(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_effective_tackle_pct'] ?? 6;

        $rows = MatchTeamStats::where('team_id', $opponentId)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->whereNotNull('effective_tackle_pct')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['effective_tackle_pct']);

        if ($rows->count() < 3) {
            return ['type' => 'opp_effective_tackle_pct', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient effective-tackle history'];
        }

        $avg = (float) $rows->avg(fn ($r) => (float) $r->effective_tackle_pct);
        $base = max(0.0, min(1.0, (92.0 - $avg) / 6.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.75,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.40,
            'hooker'                       => 0.35,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_effective_tackle_pct',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp effective tackle %.1f%% (last %d)', $avg, $rows->count()),
        ];
    }

    /**
     * Phase 18: own rolling completion % (last 5). Set-end retention quality —
     * complements opp_completion_pressure (opp side) by capturing whether OUR
     * sets finish cleanly. Sustained completion ⇒ more attacking reps ⇒ more
     * red-zone chances. Maps [0.76, 0.86] → [0, 1].
     */
    protected function teamCompletionRate(Player $player, array $w): array
    {
        $weight = $w['team_completion_rate'] ?? 6;
        if (! $player->team_id) {
            return ['type' => 'team_completion_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rows = MatchTeamStats::where('team_id', $player->team_id)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->where('completion_denominator', '>', 0)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['completion_numerator', 'completion_denominator']);

        if ($rows->count() < 3) {
            return ['type' => 'team_completion_rate', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient own completion history'];
        }

        $rate = (float) $rows->avg(fn ($r) => $r->completion_numerator / max(1, $r->completion_denominator));
        $base = max(0.0, min(1.0, ($rate - 0.76) / 0.10));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.70,
            'five-eighth', 'halfback'      => 0.55,
            'lock'                         => 0.40,
            'hooker'                       => 0.35,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_completion_rate',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Own completion %.1f%% (last %d)', $rate * 100, $rows->count()),
        ];
    }

    /**
     * Phase 19: own post-contact metres per run (last 5). Per-carry bend-the-line
     * yardage quality. Maps [1.85, 2.30] → [0, 1].
     */
    protected function teamPcmPerRun(Player $player, array $w): array
    {
        $weight = $w['team_pcm_per_run'] ?? 7;
        if (! $player->team_id) {
            return ['type' => 'team_pcm_per_run', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $rows = MatchTeamStats::where('team_id', $player->team_id)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->where('all_runs', '>', 0)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['post_contact_metres', 'all_runs']);

        if ($rows->count() < 3) {
            return ['type' => 'team_pcm_per_run', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient PCM history'];
        }

        $pcm = $rows->sum(fn ($r) => (float) ($r->post_contact_metres ?? 0));
        $runs = $rows->sum(fn ($r) => (int) ($r->all_runs ?? 0));
        if ($runs <= 0) {
            return ['type' => 'team_pcm_per_run', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No run data'];
        }

        $rate = $pcm / $runs;
        $base = max(0.0, min(1.0, ($rate - 2.50) / 0.70));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.85,
            'five-eighth', 'halfback'      => 0.50,
            'lock'                         => 0.55,
            'hooker'                       => 0.35,
            'prop'                         => 0.45,
            default                        => 0.30,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_pcm_per_run',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('%.2f PCM/run (last %d)', $rate, $rows->count()),
        ];
    }

    /**
     * Phase 19: opp PCM/run conceded (last 5). Defensive anchor quality at
     * first contact. Same calibration band as team_pcm_per_run.
     */
    protected function oppPcmPerRunConcede(Player $player, int $opponentId, array $w): array
    {
        $weight = $w['opp_pcm_per_run_concede'] ?? 6;

        // Opp PCM/run CONCEDED = the attackers' PCM/run from matches against opp.
        // Pull matches the opp played, then take the OTHER team's stats.
        $matchIds = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $opponentId)->orWhere('away_team_id', $opponentId))
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return ['type' => 'opp_pcm_per_run_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opp history'];
        }

        $rows = MatchTeamStats::whereIn('match_id', $matchIds)
            ->where('team_id', '!=', $opponentId)
            ->where('all_runs', '>', 0)
            ->get(['post_contact_metres', 'all_runs']);

        if ($rows->count() < 3) {
            return ['type' => 'opp_pcm_per_run_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Insufficient opp PCM history'];
        }

        $pcm = $rows->sum(fn ($r) => (float) ($r->post_contact_metres ?? 0));
        $runs = $rows->sum(fn ($r) => (int) ($r->all_runs ?? 0));
        if ($runs <= 0) {
            return ['type' => 'opp_pcm_per_run_concede', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No opp run data'];
        }

        $rate = $pcm / $runs;
        $base = max(0.0, min(1.0, ($rate - 2.50) / 0.70));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.80,
            'five-eighth', 'halfback'      => 0.50,
            'lock'                         => 0.45,
            'hooker'                       => 0.35,
            default                        => 0.25,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'opp_pcm_per_run_concede',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Opp concedes %.2f PCM/run (last %d)', $rate, $rows->count()),
        ];
    }

    /**
     * Phase 20: count of consecutive recent matches where the team scored
     * ≥4 tries. Threshold sits above league-avg (~3.3 t/g) so the streak
     * actually discriminates — ≥3 t/g saturates with most teams on max.
     * Streak ends as soon as we hit a sub-4-try match. Walking back from
     * the most recent completed match captures "rolling boil" momentum
     * that flat per-game try averages smear out.
     *
     * Distinct from:
     *   - `team_attacking_form`     — flat avg tries/game (level)
     *   - `team_first_try_rate`     — first-try dominance (start)
     *   - `team_set_efficiency`     — tries per set (conversion quality)
     *
     * Cap streak at 4; maps [0, 4] → [0, 1] so a 2-game streak carries
     * 50% strength. Position share favours back-five and edge runners
     * who finish off high-scoring sets.
     */
    protected function teamAttackingStreak(Player $player, array $w): array
    {
        $weight = $w['team_attacking_streak'] ?? 6;

        if (! $player->team_id) {
            return ['type' => 'team_attacking_streak', 'weight' => $weight, 'strength' => 0.0, 'description' => 'Team unknown'];
        }

        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $player->team_id)->orWhere('away_team_id', $player->team_id))
            ->orderByDesc('kickoff_at')
            ->limit(8)
            ->pluck('id');

        if ($matches->isEmpty()) {
            return ['type' => 'team_attacking_streak', 'weight' => $weight, 'strength' => 0.0, 'description' => 'No team match history'];
        }

        $streak = 0;
        foreach ($matches as $matchId) {
            $tries = TryEvent::where('match_id', $matchId)
                ->whereHas('player', fn ($q) => $q->where('team_id', $player->team_id))
                ->count();
            if ($tries >= 4) {
                $streak++;
            } else {
                break;
            }
        }

        $base = max(0.0, min(1.0, $streak / 4.0));
        $share = match ($player->position) {
            'winger', 'fullback', 'centre' => 1.00,
            'second-row'                   => 0.80,
            'five-eighth', 'halfback'      => 0.65,
            'lock'                         => 0.45,
            'hooker'                       => 0.35,
            default                        => 0.30,
        };
        $strength = min(1.0, $base * $share);

        return [
            'type' => 'team_attacking_streak',
            'weight' => $weight,
            'strength' => $strength,
            'description' => sprintf('Team on %d-match 4+ try streak', $streak),
        ];
    }
}
