<?php

/**
 * Signal weights for the NRL prediction model.
 * These are starting priors — tune via `php artisan nrl:tune` after backtesting.
 */
return [
    // ── Try-scorer signals ────────────────────────────────
    'try_scorer' => [
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
        // New Phase 3 signals
        'edge_mismatch'              => 15,  // §3.1
        'weather_adjustment'         => 8,   // §3.4
        'debut_boost'                => 5,   // §3.5
        'revenge_game'               => 3,   // §3.6
        'short_turnaround'           => 5,   // §3.7
        'tactical_shift'             => 8,   // §3.9
        'opponent_try_concede_rate'  => 10,  // How leaky the opposition defence has been
        // Phase 4 signals (market + advanced stats)
        'bookmaker_try_odds'         => 25,  // Median AU bookmaker ATS probability
        'match_total_line'           => 10,  // Market-implied total points → try volume
        'team_favouritism'           => 8,   // Favourites score more; scales by implied win %
        // Phase 5: usage-based signal
        // Player's share of team tries in recent games — identifies genuine finishers
        // independent of position or season rate.
        'rolling_try_share'          => 9,
        // Phase 6: process-stat signals
        'red_zone_pressure'          => 8,   // Rolling team forced drop-outs ⇒ goal-line repeat sets
        'opponent_ruck_speed'        => 6,   // Opp ruck infringements + penalties ⇒ quick PTBs
        'opponent_error_rate'        => 7,   // Opp errors/game ⇒ turnover-ball tries down the edges
        // Phase 7: lineup + opponent-defence signals
        'starter_role'               => 14,  // Starting XIII vs bench (~10x try-rate gap)
        'opp_missed_tackles'         => 8,   // Opp rolling missed tackles/game (last 5)
        'opp_line_breaks_conceded'   => 9,   // Opp rolling line breaks conceded/game (last 5)
        // Phase 8: channel-specific opp leakage + form trend
        'opp_position_concede'       => 9,   // Opp tries-conceded by scorer position class (back_five/edge/middle)
        'opp_def_form_decay'         => 6,   // Opponent's tries-conceded last 3 vs prior 5 (trend, not level)
        // Phase 9: leading-indicator yardage signals
        'team_tackle_breaks'         => 8,   // Attacking team's TBs/game (last 5) — leading indicator of line breaks
        'opp_post_contact_concede'   => 7,   // Opp's PCM conceded — defences that don't stop on first contact get rolled
        'team_offload_rate'          => 6,   // Attacking team's offloads/game — unstructured try chances
        // Phase 10: recency-weighted try form
        'tries_ema'                  => 12,  // Exponentially-smoothed tries over last 8 player matches (alpha=0.3)
        // Phase 11: completion + possession + territory
        'opp_completion_pressure'    => 8,   // Low opp completion ⇒ turnover ball ⇒ short-field tries
        'team_possession_pct'        => 6,   // Rolling possession dominance (last 5)
        'team_kick_pressure'         => 5,   // Rolling kicking-metres ⇒ field position
        // Phase 12: late-game concede + first-try team momentum
        'opp_late_concede'           => 7,   // Opp's share of tries conceded 60min+ (fitness/cardio leak)
        'team_first_try_rate'        => 6,   // Team's first-try-scored share over last 8 (set-piece pressure)
        // Phase 13: opening-20 leak + total yardage concede
        'opp_first_concede'          => 6,   // Opp's share of tries conceded ≤20min (slow-start defence)
        'opp_yardage_concede'        => 6,   // Opp's run metres conceded/game (last 5) — forward push into try zone
        // Phase 14: set-conversion efficiency (quality, not volume)
        'team_set_efficiency'        => 7,   // Team tries per completed set (last 5) — attack conversion quality
        'opp_set_concede_rate'       => 7,   // Tries conceded per opp-set-faced (last 5) — defensive resilience
        // Phase 15: per-carry explosive-play quality
        'team_explosive_rate'        => 8,   // (TB + LB + offloads) / runs — danger per carry
        'opp_explosive_concede'      => 7,   // Opp explosive plays conceded per opp run (last 5)
        // Phase 16: attacking-pressure indicators
        'team_drop_outs_forced'      => 7,   // Goal-line repeat-set generation (last 5)
        'opp_ruck_penalties'         => 5,   // Opp's rolling ruck infringements/game (last 5)
        // Phase 17: penalty differential + opp effective-tackle %
        'team_penalty_diff'          => 6,   // Net penalties drawn per game (last 5) — field position
        'opp_effective_tackle_pct'   => 6,   // Lower opp effective-tackle % ⇒ more broken-line carries
        // Phase 18: own completion-rate retention (set-end quality)
        'team_completion_rate'       => 6,   // Own rolling completion % (last 5) ⇒ sustained pressure
        // Phase 19: per-carry post-contact metres (bend-the-line quality)
        'team_pcm_per_run'           => 7,   // Own PCM/run (last 5) ⇒ relentless forward push
        'opp_pcm_per_run_concede'    => 6,   // Opp PCM/run conceded — defences that don't anchor
    ],

    // ── Match-level signals (applied to all players) ─────
    'match_level' => [
        'referee_paa'                => 4,   // §3.3
    ],

    // ── Win-prediction signals ────────────────────────────
    // Margin-based signals (margin_trend, momentum, attack_vs_defence) carry
    // real weight because raw W/L counts lose dominance information — beating
    // a cellar-dweller by 40 is not the same as scraping home by 2.
    'win_prediction' => [
        'recent_form'       => 22,
        'home_advantage'    => 10,
        'head_to_head'      => 12,
        'injury_impact'     => 14,
        'squad_stability'   => 8,
        'points_for'        => 12,
        'points_against'    => 10,
        'margin_trend'      => 14,
        'momentum'          => 10,
        'attack_vs_defence' => 10,
        'bookmaker_line'    => 25,  // Median de-vigged AU book win probability
        'rest_advantage'    => 6,   // Rest days + interstate travel differential
        // Process-metric signals from nrl.com match centre stats.
        'completion_edge'   => 10,  // Rolling completion % advantage (last 5)
        'yardage_dominance' => 12,  // Rolling run-metres + post-contact advantage
        'kick_pressure'     => 7,   // Rolling kicking metres + forced drop-outs
        'discipline'        => 6,   // Inverse errors + penalties conceded
        'pythagorean'       => 12,  // PF^2 / (PF^2 + PA^2) — last 8 games
        'attacking_threat'  => 9,   // Rolling line-breaks + tackle-breaks advantage
        'error_differential'=> 12,  // Net errors/game (last 5) — Eye Test 2025 finds fewer-errors team wins ~77.5%
        'possession_share'  => 8,   // Rolling possession % advantage (last 5)
        // Phase 18: net effective-tackle % edge (last 5). First-contact quality —
        // distinct from raw missed-tackle count (volume) and yardage_dominance (metres).
        // League band 88–94%; ~2pp gap is meaningful.
        'tackle_efficiency_edge' => 8,
    ],

    // ── Position base try-scoring weights ─────────────────
    'position_advantage' => [
        'winger'      => 1.00,
        'fullback'    => 0.90,
        'centre'      => 0.80,
        'five-eighth' => 0.50,
        'halfback'    => 0.40,
        'hooker'      => 0.30,
        'second-row'  => 0.30,
        'lock'        => 0.20,
        'prop'        => 0.10,
    ],

    // ── Milestone thresholds ──────────────────────────────
    'try_milestones'  => [50, 100, 150, 200, 212, 250],
    'game_milestones' => [50, 100, 150, 200, 250, 300, 350],
    'milestone_try_distance'  => 3,  // within N tries of milestone
    'milestone_game_distance' => 1,  // within N games (i.e. this match)

    // ── Venue-to-state mapping for travel detection ───────
    'venue_states' => [
        'Accor Stadium' => 'NSW',
        'Allianz Stadium' => 'NSW',
        'CommBank Stadium' => 'NSW',
        'BlueBet Stadium' => 'NSW',
        'McDonald Jones Stadium' => 'NSW',
        'Campbelltown Sports Stadium' => 'NSW',
        '4 Pines Park' => 'NSW',
        'Leichhardt Oval' => 'NSW',
        'WIN Stadium' => 'NSW',
        'Suncorp Stadium' => 'QLD',
        'Queensland Country Bank Stadium' => 'QLD',
        'Cbus Super Stadium' => 'QLD',
        'Kayo Stadium' => 'QLD',
        'TIO Stadium' => 'NT',
        'AAMI Park' => 'VIC',
        'GIO Stadium' => 'ACT',
        'Go Media Stadium' => 'NZ',
        'Mt Smart Stadium' => 'NZ',
    ],

    // ── Team home states ──────────────────────────────────
    'team_states' => [
        'broncos'      => 'QLD',
        'cowboys'      => 'QLD',
        'titans'       => 'QLD',
        'dolphins'     => 'QLD',
        'storm'        => 'VIC',
        'raiders'      => 'ACT',
        'warriors'     => 'NZ',
        'roosters'     => 'NSW',
        'rabbitohs'    => 'NSW',
        'bulldogs'     => 'NSW',
        'eels'         => 'NSW',
        'panthers'     => 'NSW',
        'knights'      => 'NSW',
        'sharks'       => 'NSW',
        'sea-eagles'   => 'NSW',
        'dragons'      => 'NSW',
        'wests-tigers' => 'NSW',
    ],
];
