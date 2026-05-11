<?php

namespace App\Services;

use App\Models\Injury;
use App\Models\MatchTeamList;
use App\Models\MatchTeamStats;
use App\Models\Matchup;
use App\Models\OddsSnapshot;
use App\Models\Suspension;

class WinPredictor
{
    /** Fallback weights if config/nrl-weights.php is missing the section. */
    public const WEIGHTS = [
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
        // Market consensus win probability. Weighted strongly because AU bookmaker
        // lines aggregate sharp money and reach calibration that beats most
        // individual signals; we still blend with our own for when the market is soft.
        'bookmaker_line'    => 25,
        // Rest and travel matter — a team with three extra rest days and no
        // interstate flight has a measurable edge over a side on a short
        // turnaround. Kept modest because the effect is real but small
        // relative to form/market.
        'rest_advantage'    => 6,
        // Rolling completion-rate advantage over the last 5 games. Teams above
        // ~78% completion win at a noticeably higher rate — the ball-retention
        // differential directly drives field-position dominance.
        'completion_edge'   => 10,
        // Average run-metres dominance (post-contact + total) over last 5 games.
        // Yardage battle is the strongest process-level win predictor.
        'yardage_dominance' => 12,
        // Attacking kick pressure: kick metres + forced drop-outs force repeat
        // sets near the opponent's line, which is where tries originate in the
        // post-2024 rule set.
        'kick_pressure'     => 7,
        // Discipline/error differential. Errors in general and penalties conceded
        // both give possession back and cost ~0.5–1 point each in expected score.
        'discipline'        => 6,
        // Pythagorean expectation: PF^2 / (PF^2 + PA^2) — well-established baseline
        // from baseball, validated for rugby league (pythagonrl.com). Captures
        // dominance independent of W/L noise; correlates with future win rate
        // better than raw record once you have ~5 games of data.
        'pythagorean'       => 12,
        // Rolling line-break + tackle-break advantage over the last 5 games.
        // Line breaks per game is one of the strongest team-level try-rate
        // predictors; tackle breaks chain into them. Independent of yardage
        // (which can come from forwards crashing it up without breaking the line).
        'attacking_threat'  => 9,
        // Net error differential over last 5. Eye Test 2025: teams with fewer
        // errors than their opponent win ~77.5% of games — the strongest single
        // process signal. Decouples from completion_edge in low-possession matches
        // where completion% can stay high despite costly turnovers.
        'error_differential'=> 12,
        // Rolling possession share (last 5). UOWTV/Eye Test trends show
        // possession-dominant sides win ~71% of studied games. Distinct from
        // yardage_dominance — possession is time-of-ball, yardage is metres-per-carry.
        'possession_share'  => 8,
        // Per-carry explosive-play differential = (TB + LB + offloads)/runs (last 5).
        // Captures attacking quality independent of volume — orthogonal to
        // attacking_threat (raw counts) and yardage_dominance (metres). League
        // spread ~0.21–0.31; teams above by ≥3pp win at materially higher rate.
        'explosive_rate_diff' => 9,
        // Forced drop-outs differential per game (last 5). Each drop-out gifts
        // a fresh attacking set inside the opp 40m. Sides that consistently
        // create more drop-outs than they concede control field position and
        // win at materially higher rate. League spread ~0.5–2.6 per game.
        'drop_out_diff'       => 7,
    ];

    public function weights(): array
    {
        return config('nrl-weights.win_prediction', self::WEIGHTS) + self::WEIGHTS;
    }

    /**
     * Return [home_pct, away_pct, predicted_winner_id, signals[]]
     */
    public function predict(Matchup $match): array
    {
        $match->loadMissing(['homeTeam', 'awayTeam', 'round']);
        $w = $this->weights();

        $homeSignals = [];
        $awaySignals = [];

        $homeScore = 0.0;
        $awayScore = 0.0;

        // --- Recent form (last 5 matches W/L) ---
        [$hForm, $hDesc] = $this->recentForm($match->home_team_id);
        [$aForm, $aDesc] = $this->recentForm($match->away_team_id);
        $homeScore += $w['recent_form'] * $hForm;
        $awayScore += $w['recent_form'] * $aForm;
        $homeSignals[] = ['type' => 'recent_form', 'weight' => $w['recent_form'], 'strength' => $hForm, 'description' => $hDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'recent_form', 'weight' => $w['recent_form'], 'strength' => $aForm, 'description' => $aDesc, 'side' => 'away'];

        // --- Home advantage ---
        $homeScore += $w['home_advantage'] * 0.6;
        $awayScore += $w['home_advantage'] * 0.4;
        $homeSignals[] = ['type' => 'home_advantage', 'weight' => $w['home_advantage'], 'strength' => 0.6, 'description' => 'Playing at home', 'side' => 'home'];
        $awaySignals[] = ['type' => 'home_advantage', 'weight' => $w['home_advantage'], 'strength' => 0.4, 'description' => 'Playing away', 'side' => 'away'];

        // --- Head to head ---
        [$hH2h, $aH2h, $h2hDesc] = $this->headToHead($match->home_team_id, $match->away_team_id);
        $homeScore += $w['head_to_head'] * $hH2h;
        $awayScore += $w['head_to_head'] * $aH2h;
        $homeSignals[] = ['type' => 'head_to_head', 'weight' => $w['head_to_head'], 'strength' => $hH2h, 'description' => $h2hDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'head_to_head', 'weight' => $w['head_to_head'], 'strength' => $aH2h, 'description' => $h2hDesc, 'side' => 'away'];

        // --- Injury impact ---
        [$hInj, $hInjDesc] = $this->injuryImpact($match->home_team_id);
        [$aInj, $aInjDesc] = $this->injuryImpact($match->away_team_id);
        $homeScore += $w['injury_impact'] * (1 - $aInj);
        $awayScore += $w['injury_impact'] * (1 - $hInj);
        $homeSignals[] = ['type' => 'injury_impact', 'weight' => $w['injury_impact'], 'strength' => $aInj, 'description' => "Opponent: {$aInjDesc}", 'side' => 'home'];
        $awaySignals[] = ['type' => 'injury_impact', 'weight' => $w['injury_impact'], 'strength' => $hInj, 'description' => "Opponent: {$hInjDesc}", 'side' => 'away'];

        // --- Squad stability ---
        [$hStab, $hStabDesc] = $this->squadStability($match, $match->home_team_id);
        [$aStab, $aStabDesc] = $this->squadStability($match, $match->away_team_id);
        $homeScore += $w['squad_stability'] * $hStab;
        $awayScore += $w['squad_stability'] * $aStab;
        $homeSignals[] = ['type' => 'squad_stability', 'weight' => $w['squad_stability'], 'strength' => $hStab, 'description' => $hStabDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'squad_stability', 'weight' => $w['squad_stability'], 'strength' => $aStab, 'description' => $aStabDesc, 'side' => 'away'];

        // --- Points for ---
        [$hPf, $hPfDesc, $hPfAvg] = $this->pointsFor($match->home_team_id);
        [$aPf, $aPfDesc, $aPfAvg] = $this->pointsFor($match->away_team_id);
        $homeScore += $w['points_for'] * $hPf;
        $awayScore += $w['points_for'] * $aPf;
        $homeSignals[] = ['type' => 'points_for', 'weight' => $w['points_for'], 'strength' => $hPf, 'description' => $hPfDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'points_for', 'weight' => $w['points_for'], 'strength' => $aPf, 'description' => $aPfDesc, 'side' => 'away'];

        // --- Points against ---
        [$hPa, $hPaDesc, $hPaAvg] = $this->pointsAgainst($match->home_team_id);
        [$aPa, $aPaDesc, $aPaAvg] = $this->pointsAgainst($match->away_team_id);
        $homeScore += $w['points_against'] * $hPa;
        $awayScore += $w['points_against'] * $aPa;
        $homeSignals[] = ['type' => 'points_against', 'weight' => $w['points_against'], 'strength' => $hPa, 'description' => $hPaDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'points_against', 'weight' => $w['points_against'], 'strength' => $aPa, 'description' => $aPaDesc, 'side' => 'away'];

        // --- Margin trend (average point differential last 5) ---
        [$hMar, $hMarDesc] = $this->marginTrend($match->home_team_id);
        [$aMar, $aMarDesc] = $this->marginTrend($match->away_team_id);
        $homeScore += $w['margin_trend'] * $hMar;
        $awayScore += $w['margin_trend'] * $aMar;
        $homeSignals[] = ['type' => 'margin_trend', 'weight' => $w['margin_trend'], 'strength' => $hMar, 'description' => $hMarDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'margin_trend', 'weight' => $w['margin_trend'], 'strength' => $aMar, 'description' => $aMarDesc, 'side' => 'away'];

        // --- Momentum (recent games weighted more than older) ---
        [$hMom, $hMomDesc] = $this->momentum($match->home_team_id);
        [$aMom, $aMomDesc] = $this->momentum($match->away_team_id);
        $homeScore += $w['momentum'] * $hMom;
        $awayScore += $w['momentum'] * $aMom;
        $homeSignals[] = ['type' => 'momentum', 'weight' => $w['momentum'], 'strength' => $hMom, 'description' => $hMomDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'momentum', 'weight' => $w['momentum'], 'strength' => $aMom, 'description' => $aMomDesc, 'side' => 'away'];

        // --- Attack vs. opponent defence matchup ---
        $hAvd = $this->attackVsDefence($hPfAvg, $aPaAvg);
        $aAvd = $this->attackVsDefence($aPfAvg, $hPaAvg);
        $homeScore += $w['attack_vs_defence'] * $hAvd;
        $awayScore += $w['attack_vs_defence'] * $aAvd;
        $homeSignals[] = ['type' => 'attack_vs_defence', 'weight' => $w['attack_vs_defence'], 'strength' => $hAvd, 'description' => sprintf('%.0f PF vs opp %.0f PA', $hPfAvg, $aPaAvg), 'side' => 'home'];
        $awaySignals[] = ['type' => 'attack_vs_defence', 'weight' => $w['attack_vs_defence'], 'strength' => $aAvd, 'description' => sprintf('%.0f PF vs opp %.0f PA', $aPfAvg, $hPaAvg), 'side' => 'away'];

        // --- Rest & travel advantage ---
        [$hRest, $aRest, $hRestDesc, $aRestDesc] = $this->restAdvantage($match);
        $homeScore += $w['rest_advantage'] * $hRest;
        $awayScore += $w['rest_advantage'] * $aRest;
        $homeSignals[] = ['type' => 'rest_advantage', 'weight' => $w['rest_advantage'], 'strength' => $hRest, 'description' => $hRestDesc, 'side' => 'home'];
        $awaySignals[] = ['type' => 'rest_advantage', 'weight' => $w['rest_advantage'], 'strength' => $aRest, 'description' => $aRestDesc, 'side' => 'away'];

        // --- Process-metric signals (only fire if we've captured match stats) ---
        [$hComp, $aComp, $compDesc] = $this->completionEdge($match->home_team_id, $match->away_team_id);
        if ($hComp !== null) {
            $compWeight = $w['completion_edge'] ?? 0;
            $homeScore += $compWeight * $hComp;
            $awayScore += $compWeight * $aComp;
            $homeSignals[] = ['type' => 'completion_edge', 'weight' => $compWeight, 'strength' => $hComp, 'description' => $compDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'completion_edge', 'weight' => $compWeight, 'strength' => $aComp, 'description' => $compDesc, 'side' => 'away'];
        }

        [$hYard, $aYard, $yardDesc] = $this->yardageDominance($match->home_team_id, $match->away_team_id);
        if ($hYard !== null) {
            $yardWeight = $w['yardage_dominance'] ?? 0;
            $homeScore += $yardWeight * $hYard;
            $awayScore += $yardWeight * $aYard;
            $homeSignals[] = ['type' => 'yardage_dominance', 'weight' => $yardWeight, 'strength' => $hYard, 'description' => $yardDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'yardage_dominance', 'weight' => $yardWeight, 'strength' => $aYard, 'description' => $yardDesc, 'side' => 'away'];
        }

        [$hKick, $aKick, $kickDesc] = $this->kickPressure($match->home_team_id, $match->away_team_id);
        if ($hKick !== null) {
            $kickWeight = $w['kick_pressure'] ?? 0;
            $homeScore += $kickWeight * $hKick;
            $awayScore += $kickWeight * $aKick;
            $homeSignals[] = ['type' => 'kick_pressure', 'weight' => $kickWeight, 'strength' => $hKick, 'description' => $kickDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'kick_pressure', 'weight' => $kickWeight, 'strength' => $aKick, 'description' => $kickDesc, 'side' => 'away'];
        }

        [$hDisc, $aDisc, $discDesc] = $this->discipline($match->home_team_id, $match->away_team_id);
        if ($hDisc !== null) {
            $discWeight = $w['discipline'] ?? 0;
            $homeScore += $discWeight * $hDisc;
            $awayScore += $discWeight * $aDisc;
            $homeSignals[] = ['type' => 'discipline', 'weight' => $discWeight, 'strength' => $hDisc, 'description' => $discDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'discipline', 'weight' => $discWeight, 'strength' => $aDisc, 'description' => $discDesc, 'side' => 'away'];
        }

        // --- Pythagorean expectation (PF/PA based) ---
        [$hPyth, $aPyth, $pythDesc] = $this->pythagorean($match->home_team_id, $match->away_team_id);
        if ($hPyth !== null) {
            $pythWeight = $w['pythagorean'] ?? 0;
            $homeScore += $pythWeight * $hPyth;
            $awayScore += $pythWeight * $aPyth;
            $homeSignals[] = ['type' => 'pythagorean', 'weight' => $pythWeight, 'strength' => $hPyth, 'description' => $pythDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'pythagorean', 'weight' => $pythWeight, 'strength' => $aPyth, 'description' => $pythDesc, 'side' => 'away'];
        }

        // --- Attacking threat (line breaks + tackle breaks) ---
        [$hAtt, $aAtt, $attDesc] = $this->attackingThreat($match->home_team_id, $match->away_team_id);
        if ($hAtt !== null) {
            $attWeight = $w['attacking_threat'] ?? 0;
            $homeScore += $attWeight * $hAtt;
            $awayScore += $attWeight * $aAtt;
            $homeSignals[] = ['type' => 'attacking_threat', 'weight' => $attWeight, 'strength' => $hAtt, 'description' => $attDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'attacking_threat', 'weight' => $attWeight, 'strength' => $aAtt, 'description' => $attDesc, 'side' => 'away'];
        }

        // --- Net error differential ---
        [$hErrD, $aErrD, $errDDesc] = $this->errorDifferential($match->home_team_id, $match->away_team_id);
        if ($hErrD !== null) {
            $errDWeight = $w['error_differential'] ?? 0;
            $homeScore += $errDWeight * $hErrD;
            $awayScore += $errDWeight * $aErrD;
            $homeSignals[] = ['type' => 'error_differential', 'weight' => $errDWeight, 'strength' => $hErrD, 'description' => $errDDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'error_differential', 'weight' => $errDWeight, 'strength' => $aErrD, 'description' => $errDDesc, 'side' => 'away'];
        }

        // --- Rolling possession share ---
        [$hPos, $aPos, $posDesc] = $this->possessionShare($match->home_team_id, $match->away_team_id);
        if ($hPos !== null) {
            $posWeight = $w['possession_share'] ?? 0;
            $homeScore += $posWeight * $hPos;
            $awayScore += $posWeight * $aPos;
            $homeSignals[] = ['type' => 'possession_share', 'weight' => $posWeight, 'strength' => $hPos, 'description' => $posDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'possession_share', 'weight' => $posWeight, 'strength' => $aPos, 'description' => $posDesc, 'side' => 'away'];
        }

        // --- Per-carry explosive-play differential ---
        [$hExp, $aExp, $expDesc] = $this->explosiveRateDiff($match->home_team_id, $match->away_team_id);
        if ($hExp !== null) {
            $expWeight = $w['explosive_rate_diff'] ?? 0;
            $homeScore += $expWeight * $hExp;
            $awayScore += $expWeight * $aExp;
            $homeSignals[] = ['type' => 'explosive_rate_diff', 'weight' => $expWeight, 'strength' => $hExp, 'description' => $expDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'explosive_rate_diff', 'weight' => $expWeight, 'strength' => $aExp, 'description' => $expDesc, 'side' => 'away'];
        }

        // --- Forced drop-outs differential ---
        [$hDo, $aDo, $doDesc] = $this->dropOutDiff($match->home_team_id, $match->away_team_id);
        if ($hDo !== null) {
            $doWeight = $w['drop_out_diff'] ?? 0;
            $homeScore += $doWeight * $hDo;
            $awayScore += $doWeight * $aDo;
            $homeSignals[] = ['type' => 'drop_out_diff', 'weight' => $doWeight, 'strength' => $hDo, 'description' => $doDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'drop_out_diff', 'weight' => $doWeight, 'strength' => $aDo, 'description' => $doDesc, 'side' => 'away'];
        }

        // --- Bookmaker line consensus ---
        [$hBook, $aBook, $bookDesc] = $this->bookmakerLine($match);
        if ($hBook !== null && $aBook !== null) {
            $bookWeight = $w['bookmaker_line'] ?? 0;
            $homeScore += $bookWeight * $hBook;
            $awayScore += $bookWeight * $aBook;
            $homeSignals[] = ['type' => 'bookmaker_line', 'weight' => $bookWeight, 'strength' => $hBook, 'description' => $bookDesc, 'side' => 'home'];
            $awaySignals[] = ['type' => 'bookmaker_line', 'weight' => $bookWeight, 'strength' => $aBook, 'description' => $bookDesc, 'side' => 'away'];
        }

        // Normalise
        $total = $homeScore + $awayScore;
        if ($total === 0.0) {
            $homePct = 50;
            $awayPct = 50;
        } else {
            $homePct = (int) round(($homeScore / $total) * 100);
            $awayPct = 100 - $homePct;
        }

        $winnerId = $homePct >= $awayPct ? $match->home_team_id : $match->away_team_id;

        $allSignals = array_merge(
            array_map(fn ($s) => array_merge($s, ['team' => $match->homeTeam?->short_name ?? $match->homeTeam?->name]), $homeSignals),
            array_map(fn ($s) => array_merge($s, ['team' => $match->awayTeam?->short_name ?? $match->awayTeam?->name]), $awaySignals),
        );

        return [
            'home_win_pct' => $homePct,
            'away_win_pct' => $awayPct,
            'predicted_winner_id' => $winnerId,
            'win_signals' => $allSignals,
        ];
    }

    protected function recentForm(int $teamId): array
    {
        $recent = $this->recentMatches($teamId, 5);
        if ($recent->isEmpty()) {
            return [0.5, 'No recent results'];
        }

        $wins = $recent->filter(function ($m) use ($teamId) {
            if ($m->home_team_id === $teamId) {
                return ($m->home_score ?? 0) > ($m->away_score ?? 0);
            }
            return ($m->away_score ?? 0) > ($m->home_score ?? 0);
        })->count();

        $losses = $recent->count() - $wins;
        return [$wins / $recent->count(), "{$wins}W {$losses}L in last {$recent->count()} games"];
    }

    protected function headToHead(int $homeId, int $awayId): array
    {
        $matches = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->where('home_team_id', $homeId)->where('away_team_id', $awayId))
                ->orWhere(fn ($q2) => $q2->where('home_team_id', $awayId)->where('away_team_id', $homeId))
            )
            ->orderByDesc('kickoff_at')
            ->limit(5)
            ->get();

        if ($matches->isEmpty()) {
            return [0.5, 0.5, 'No head-to-head history'];
        }

        $homeWins = $matches->filter(function ($m) use ($homeId) {
            if ($m->home_team_id === $homeId) {
                return ($m->home_score ?? 0) > ($m->away_score ?? 0);
            }
            return ($m->away_score ?? 0) > ($m->home_score ?? 0);
        })->count();

        $awayWins = $matches->count() - $homeWins;
        return [$homeWins / $matches->count(), $awayWins / $matches->count(), "{$homeWins}-{$awayWins} in last {$matches->count()} meetings"];
    }

    protected function injuryImpact(int $teamId): array
    {
        $outCount = Injury::where('resolved', false)
            ->whereIn('status', ['out', 'doubt'])
            ->whereHas('player', fn ($q) => $q->where('team_id', $teamId))
            ->count();

        $suspCount = Suspension::where('games_remaining', '>', 0)
            ->whereHas('player', fn ($q) => $q->where('team_id', $teamId))
            ->count();

        $total = $outCount + $suspCount;
        return [min(1.0, $total / 6), "{$total} players out/doubtful"];
    }

    protected function squadStability(Matchup $match, int $teamId): array
    {
        $current = MatchTeamList::where('match_id', $match->id)
            ->where('team_id', $teamId)
            ->pluck('player_id');

        if ($current->isEmpty()) {
            return [0.5, 'No team list available'];
        }

        $priorMatch = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('kickoff_at')
            ->first();

        if (! $priorMatch) {
            return [0.5, 'No prior match to compare'];
        }

        $prior = MatchTeamList::where('match_id', $priorMatch->id)
            ->where('team_id', $teamId)
            ->pluck('player_id');

        $retained = $current->intersect($prior)->count();
        $total = max($current->count(), 1);
        $changes = $total - $retained;

        return [$retained / $total, "{$changes} change(s) from last match"];
    }

    protected function pointsFor(int $teamId): array
    {
        $recent = $this->recentMatches($teamId, 5);
        if ($recent->isEmpty()) {
            return [0.5, 'No scoring data', 0.0];
        }

        $avg = $recent->avg(fn ($m) => $m->home_team_id === $teamId ? ($m->home_score ?? 0) : ($m->away_score ?? 0));
        return [min(1.0, $avg / 30), sprintf('%.0f avg pts/game (last %d)', $avg, $recent->count()), $avg];
    }

    protected function pointsAgainst(int $teamId): array
    {
        $recent = $this->recentMatches($teamId, 5);
        if ($recent->isEmpty()) {
            return [0.5, 'No defensive data', 0.0];
        }

        $avg = $recent->avg(fn ($m) => $m->home_team_id === $teamId ? ($m->away_score ?? 0) : ($m->home_score ?? 0));
        return [max(0.0, 1.0 - ($avg / 30)), sprintf('%.0f avg pts conceded/game (last %d)', $avg, $recent->count()), $avg];
    }

    /**
     * Average point differential over the last 5 games — scaled so a +20
     * margin maps to 1.0 and a -20 margin to 0.0. Margin is more predictive
     * than raw W/L because it captures dominance vs. narrow escapes.
     */
    protected function marginTrend(int $teamId): array
    {
        $recent = $this->recentMatches($teamId, 5);
        if ($recent->isEmpty()) {
            return [0.5, 'No margin data'];
        }

        $avgMargin = $recent->avg(function ($m) use ($teamId) {
            if ($m->home_team_id === $teamId) {
                return ($m->home_score ?? 0) - ($m->away_score ?? 0);
            }
            return ($m->away_score ?? 0) - ($m->home_score ?? 0);
        });

        // Map [-20, +20] onto [0, 1], clamp outside.
        $strength = max(0.0, min(1.0, 0.5 + ($avgMargin / 40)));
        $sign = $avgMargin >= 0 ? '+' : '';
        return [$strength, sprintf('%s%.1f avg margin (last %d)', $sign, $avgMargin, $recent->count())];
    }

    /**
     * Exponentially-weighted recent form: most recent game counts 2x what the
     * 5th-most-recent does. A team that just beat the leaders is worth more
     * than one that beat the wooden-spooners six weeks ago.
     */
    protected function momentum(int $teamId): array
    {
        $recent = $this->recentMatches($teamId, 5);
        if ($recent->isEmpty()) {
            return [0.5, 'No momentum data'];
        }

        $weighted = 0.0;
        $totalWeight = 0.0;
        $ordered = $recent->values(); // 0 = most recent

        foreach ($ordered as $i => $m) {
            // Weight halves every ~5 games; i=0 → 1.0, i=4 → ~0.5
            $weight = pow(0.87, $i);
            $won = $m->home_team_id === $teamId
                ? ($m->home_score ?? 0) > ($m->away_score ?? 0)
                : ($m->away_score ?? 0) > ($m->home_score ?? 0);
            $weighted += $weight * ($won ? 1 : 0);
            $totalWeight += $weight;
        }

        $strength = $totalWeight > 0 ? $weighted / $totalWeight : 0.5;
        $lastResult = $ordered->first();
        $lastWon = $lastResult->home_team_id === $teamId
            ? ($lastResult->home_score ?? 0) > ($lastResult->away_score ?? 0)
            : ($lastResult->away_score ?? 0) > ($lastResult->home_score ?? 0);

        return [$strength, sprintf('Weighted form %.0f%% (last game %s)', $strength * 100, $lastWon ? 'won' : 'lost')];
    }

    /**
     * Strength of our attack versus their defence. A 30-ppg attack facing a
     * 30-ppg defence is neutral; a 35-ppg attack facing a 20-ppg defence is
     * a strong matchup.
     */
    protected function attackVsDefence(float $attackPpg, float $opponentPaPpg): float
    {
        if ($attackPpg <= 0.0 && $opponentPaPpg <= 0.0) {
            return 0.5;
        }

        // Combine: higher PF and higher opponent PA both favour us.
        // Map 10–40 range to 0–1.
        $combined = ($attackPpg + $opponentPaPpg) / 2;
        return max(0.0, min(1.0, ($combined - 10) / 30));
    }

    /**
     * Median AU-bookmaker match_winner implied probability, de-vigged so home+away sum to 1.
     * Returns null if fewer than two books priced the match (thin market = stale).
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function bookmakerLine(Matchup $match): array
    {
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
            return [null, null, 'No bookmaker match-winner market'];
        }

        $homeMedian = $this->median(array_map(fn ($o) => 1.0 / $o, $homeOdds));
        $awayMedian = $this->median(array_map(fn ($o) => 1.0 / $o, $awayOdds));

        // Remove bookmaker overround so probabilities sum to 1.
        $sum = $homeMedian + $awayMedian;
        if ($sum <= 0) {
            return [null, null, 'Bookmaker odds collapsed'];
        }
        $homeProb = $homeMedian / $sum;
        $awayProb = $awayMedian / $sum;

        $desc = sprintf(
            'Market %.0f%% home / %.0f%% away (%d+%d books)',
            $homeProb * 100,
            $awayProb * 100,
            count($homeOdds),
            count($awayOdds),
        );

        return [$homeProb, $awayProb, $desc];
    }

    /**
     * Rest/travel comparison. Each side starts at 0.5 (neutral) and moves based on:
     *   - rest-day differential (more rest days = higher)
     *   - interstate travel (penalises the travelling side)
     *   - 5-day or shorter turnarounds (penalty)
     * Returns strengths in [0, 1] for each side.
     *
     * @return array{0:float,1:float,2:string,3:string}
     */
    protected function restAdvantage(Matchup $match): array
    {
        $hDays = $match->days_since_last_home;
        $aDays = $match->days_since_last_away;

        if ($hDays === null && $aDays === null) {
            return [0.5, 0.5, 'No turnaround data', 'No turnaround data'];
        }

        $hScore = 0.5;
        $aScore = 0.5;
        $hParts = [];
        $aParts = [];

        // Rest-day differential — favours the better-rested side.
        if ($hDays !== null && $aDays !== null) {
            $diff = $hDays - $aDays;
            $rel = max(-0.25, min(0.25, $diff / 16));
            $hScore += $rel;
            $aScore -= $rel;
            if ($diff !== 0) {
                $hParts[] = sprintf('%+d days vs away', $diff);
                $aParts[] = sprintf('%+d days vs home', -$diff);
            }
        }

        // Short turnaround penalties (≤5 days is hard, ≤6 is notable).
        foreach ([['home', $hDays, &$hScore, &$hParts], ['away', $aDays, &$aScore, &$aParts]] as [$side, $days, &$score, &$parts]) {
            if ($days !== null && $days <= 5) {
                $score -= 0.15;
                $parts[] = sprintf('%dd short turnaround', $days);
            } elseif ($days !== null && $days >= 10) {
                $score += 0.05;
                $parts[] = 'well rested';
            }
        }

        // Interstate travel bites mostly for the away side.
        if ($match->interstate_travel_away) {
            $aScore -= 0.10;
            $aParts[] = 'interstate travel';
        }
        if ($match->interstate_travel_home) {
            // Rare, but possible when a team "hosts" at a neutral ground.
            $hScore -= 0.05;
            $hParts[] = 'travelled to host';
        }

        $hScore = max(0.0, min(1.0, $hScore));
        $aScore = max(0.0, min(1.0, $aScore));

        return [
            $hScore,
            $aScore,
            $hParts ? implode(', ', $hParts) : 'Neutral rest/travel',
            $aParts ? implode(', ', $aParts) : 'Neutral rest/travel',
        ];
    }

    /**
     * Rolling completion-rate comparison over the last 5 completed matches for
     * each side. Returns [home_strength, away_strength, description] normalised
     * so the two strengths sum to 1.0, or [null, null, …] when we lack stats
     * for either team yet.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function completionEdge(int $homeId, int $awayId): array
    {
        $homeRate = $this->rollingStatRate($homeId, fn ($s) => $s->completionRate());
        $awayRate = $this->rollingStatRate($awayId, fn ($s) => $s->completionRate());
        if ($homeRate === null || $awayRate === null) {
            return [null, null, 'No completion-rate history yet'];
        }
        [$hStr, $aStr] = $this->normalisePair($homeRate, $awayRate);
        $desc = sprintf('%.0f%% vs %.0f%% completion (last 5)', $homeRate * 100, $awayRate * 100);
        return [$hStr, $aStr, $desc];
    }

    /**
     * Rolling run-metres dominance. Combines total run metres and
     * post-contact metres (weighted 0.6 / 0.4 respectively) over the last
     * 5 completed matches for each side.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function yardageDominance(int $homeId, int $awayId): array
    {
        $homeTotal = $this->rollingStatRate($homeId, fn ($s) => $s->all_run_metres);
        $awayTotal = $this->rollingStatRate($awayId, fn ($s) => $s->all_run_metres);
        $homePost = $this->rollingStatRate($homeId, fn ($s) => $s->post_contact_metres);
        $awayPost = $this->rollingStatRate($awayId, fn ($s) => $s->post_contact_metres);

        if ($homeTotal === null || $awayTotal === null) {
            return [null, null, 'No yardage history yet'];
        }

        $homeMix = ($homeTotal * 0.6) + (($homePost ?? 0) * 0.4);
        $awayMix = ($awayTotal * 0.6) + (($awayPost ?? 0) * 0.4);
        [$hStr, $aStr] = $this->normalisePair($homeMix, $awayMix);

        $desc = sprintf('%.0fm vs %.0fm run metres (last 5)', $homeTotal, $awayTotal);
        return [$hStr, $aStr, $desc];
    }

    /**
     * Attacking kick pressure — kicking metres plus forced drop-outs, which
     * are the main leading indicator of goal-line repeat sets (and therefore
     * tries) in the current NRL rule set.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function kickPressure(int $homeId, int $awayId): array
    {
        $hKick = $this->rollingStatRate($homeId, fn ($s) => $s->kicking_metres);
        $aKick = $this->rollingStatRate($awayId, fn ($s) => $s->kicking_metres);
        $hDo = $this->rollingStatRate($homeId, fn ($s) => $s->forced_drop_outs);
        $aDo = $this->rollingStatRate($awayId, fn ($s) => $s->forced_drop_outs);
        if ($hKick === null || $aKick === null) {
            return [null, null, 'No kicking stats yet'];
        }

        // Forced drop-outs matter ~40m per drop-out in pressure terms.
        $hMix = $hKick + (($hDo ?? 0) * 40);
        $aMix = $aKick + (($aDo ?? 0) * 40);
        [$hStr, $aStr] = $this->normalisePair($hMix, $aMix);

        $desc = sprintf('%.0fm+%d DOs vs %.0fm+%d DOs', $hKick, (int) round($hDo ?? 0), $aKick, (int) round($aDo ?? 0));
        return [$hStr, $aStr, $desc];
    }

    /**
     * Discipline differential: inverse of average errors + penalties-conceded.
     * A team that gives the ball back 14+ times per game is at a material
     * disadvantage vs a disciplined opponent (~8/game).
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function discipline(int $homeId, int $awayId): array
    {
        $hErr = $this->rollingStatRate($homeId, fn ($s) => $s->errors);
        $aErr = $this->rollingStatRate($awayId, fn ($s) => $s->errors);
        $hPen = $this->rollingStatRate($homeId, fn ($s) => $s->penalties_conceded);
        $aPen = $this->rollingStatRate($awayId, fn ($s) => $s->penalties_conceded);
        if ($hErr === null || $aErr === null) {
            return [null, null, 'No discipline stats yet'];
        }

        // Lower is better — invert. Penalties hurt more than unforced errors.
        $hBad = $hErr + (($hPen ?? 0) * 1.3);
        $aBad = $aErr + (($aPen ?? 0) * 1.3);
        // Add a small constant to avoid div-by-zero and to avoid lopsided swings.
        $hGood = 1.0 / ($hBad + 2.0);
        $aGood = 1.0 / ($aBad + 2.0);
        [$hStr, $aStr] = $this->normalisePair($hGood, $aGood);

        $desc = sprintf('%.1f err+%.1f pens vs %.1f err+%.1f pens', $hErr, $hPen ?? 0, $aErr, $aPen ?? 0);
        return [$hStr, $aStr, $desc];
    }

    /**
     * Average a numeric stat across a team's last 5 MatchTeamStats rows. Uses
     * a static cache keyed on team id + callable identity so the four signal
     * methods don't each hit the database.
     */
    protected function rollingStatRate(int $teamId, \Closure $extractor): ?float
    {
        static $statsCache = [];
        if (! isset($statsCache[$teamId])) {
            $statsCache[$teamId] = MatchTeamStats::where('team_id', $teamId)
                ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
                ->orderByDesc('id')
                ->limit(5)
                ->get();
        }
        $rows = $statsCache[$teamId];
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
     * Normalise a pair of raw team values into two strengths in [0,1] that sum
     * to 1.0. Used by the rolling-stat signals so both sides contribute to the
     * home/away score in a way consistent with the rest of the signal stack.
     *
     * @return array{0:float,1:float}
     */
    protected function normalisePair(float $home, float $away): array
    {
        $sum = $home + $away;
        if ($sum <= 0) {
            return [0.5, 0.5];
        }
        return [$home / $sum, $away / $sum];
    }

    /**
     * Pythagorean expectation: PF^2 / (PF^2 + PA^2). Empirically the PF/PA
     * exponent for rugby league sits near 2 (per pythagonrl.com), close to the
     * baseball default. Returns null until both sides have at least 3 games of
     * scoring data — earlier than that the variance is too high to lean on.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function pythagorean(int $homeId, int $awayId): array
    {
        $home = $this->recentMatches($homeId, 8);
        $away = $this->recentMatches($awayId, 8);
        if ($home->count() < 3 || $away->count() < 3) {
            return [null, null, 'Not enough data for Pythagorean'];
        }

        $homeExp = $this->pythagFor($homeId, $home);
        $awayExp = $this->pythagFor($awayId, $away);
        if ($homeExp === null || $awayExp === null) {
            return [null, null, 'No scoring data for Pythagorean'];
        }

        // Already in [0,1] each — normalise to a relative pair so the signal
        // contributes consistently with the rest of the stack.
        [$hStr, $aStr] = $this->normalisePair($homeExp, $awayExp);

        return [
            $hStr,
            $aStr,
            sprintf('Pythag %.0f%% vs %.0f%% (last 8)', $homeExp * 100, $awayExp * 100),
        ];
    }

    protected function pythagFor(int $teamId, $matches): ?float
    {
        $pf = 0;
        $pa = 0;
        foreach ($matches as $m) {
            if ($m->home_team_id === $teamId) {
                $pf += $m->home_score ?? 0;
                $pa += $m->away_score ?? 0;
            } else {
                $pf += $m->away_score ?? 0;
                $pa += $m->home_score ?? 0;
            }
        }
        if ($pf === 0 && $pa === 0) {
            return null;
        }
        $pf2 = $pf ** 2;
        $pa2 = $pa ** 2;
        return $pf2 / max(1, $pf2 + $pa2);
    }

    /**
     * Rolling line-breaks + tackle-breaks advantage over the last 5 completed
     * matches. Line breaks weight 2x because each one is essentially a half-try
     * already (~50% of line breaks convert to a try in the same set).
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function attackingThreat(int $homeId, int $awayId): array
    {
        $hLb = $this->rollingStatRate($homeId, fn ($s) => $s->line_breaks);
        $aLb = $this->rollingStatRate($awayId, fn ($s) => $s->line_breaks);
        $hTb = $this->rollingStatRate($homeId, fn ($s) => $s->tackle_breaks);
        $aTb = $this->rollingStatRate($awayId, fn ($s) => $s->tackle_breaks);
        if ($hLb === null || $aLb === null) {
            return [null, null, 'No line-break stats yet'];
        }

        $hMix = ($hLb * 2.0) + ($hTb ?? 0);
        $aMix = ($aLb * 2.0) + ($aTb ?? 0);
        [$hStr, $aStr] = $this->normalisePair($hMix, $aMix);

        $desc = sprintf(
            '%.1f LB+%.1f TB vs %.1f LB+%.1f TB',
            $hLb,
            $hTb ?? 0,
            $aLb,
            $aTb ?? 0,
        );
        return [$hStr, $aStr, $desc];
    }

    /**
     * Net error differential — home avg errors minus away avg errors over last 5.
     * Negative means home commits fewer errors (good). Eye Test 2025 finds the
     * fewer-errors side wins ~77.5% of games, so this is intentionally given
     * meaningful weight in the stack.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function errorDifferential(int $homeId, int $awayId): array
    {
        $hErr = $this->rollingStatRate($homeId, fn ($s) => $s->errors);
        $aErr = $this->rollingStatRate($awayId, fn ($s) => $s->errors);
        if ($hErr === null || $aErr === null) {
            return [null, null, 'No error history yet'];
        }

        // Diff in [-8, +8] is realistic; map onto strength pair.
        // Home strength rises as their errors fall relative to away.
        $diff = $aErr - $hErr; // positive ⇒ home has fewer errors ⇒ favoured
        $hStr = max(0.0, min(1.0, 0.5 + ($diff / 12.0)));
        $aStr = 1.0 - $hStr;

        $desc = sprintf('%.1f vs %.1f errors/game (last 5)', $hErr, $aErr);
        return [$hStr, $aStr, $desc];
    }

    /**
     * Rolling possession-share differential. Possession-dominant sides win at
     * roughly 70%+, per UOWTV stat-mining of NRL trends. Stat is already a
     * percentage so we average it directly and normalise to a relative pair.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function possessionShare(int $homeId, int $awayId): array
    {
        $hPos = $this->rollingStatRate($homeId, fn ($s) => $s->possession_pct);
        $aPos = $this->rollingStatRate($awayId, fn ($s) => $s->possession_pct);
        if ($hPos === null || $aPos === null) {
            return [null, null, 'No possession history yet'];
        }
        [$hStr, $aStr] = $this->normalisePair($hPos, $aPos);
        $desc = sprintf('%.0f%% vs %.0f%% avg possession (last 5)', $hPos, $aPos);
        return [$hStr, $aStr, $desc];
    }

    /**
     * Per-carry explosive-play rate differential: (TB + LB + offloads) / runs
     * over last 5. Captures attacking quality independent of carry volume.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function explosiveRateDiff(int $homeId, int $awayId): array
    {
        $hRate = $this->teamExplosiveRate($homeId);
        $aRate = $this->teamExplosiveRate($awayId);
        if ($hRate === null || $aRate === null) {
            return [null, null, 'No explosive-play history yet'];
        }
        [$hStr, $aStr] = $this->normalisePair($hRate, $aRate);
        $desc = sprintf('%.3f vs %.3f explosive plays/carry (last 5)', $hRate, $aRate);
        return [$hStr, $aStr, $desc];
    }

    protected function teamExplosiveRate(int $teamId): ?float
    {
        $rows = MatchTeamStats::where('team_id', $teamId)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->where('all_runs', '>', 0)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['tackle_breaks', 'line_breaks', 'offloads', 'all_runs']);

        if ($rows->count() < 3) {
            return null;
        }

        $explosive = $rows->sum(fn ($r) => (int) ($r->tackle_breaks ?? 0) + (int) ($r->line_breaks ?? 0) + (int) ($r->offloads ?? 0));
        $runs = $rows->sum(fn ($r) => (int) ($r->all_runs ?? 0));
        if ($runs <= 0) {
            return null;
        }
        return $explosive / $runs;
    }

    /**
     * Forced drop-outs per game differential: avg drop-outs forced over last 5.
     * Sides creating more drop-outs win field-position battles → higher win rate.
     *
     * @return array{0:?float,1:?float,2:string}
     */
    protected function dropOutDiff(int $homeId, int $awayId): array
    {
        $hAvg = $this->teamDropOutsPerGame($homeId);
        $aAvg = $this->teamDropOutsPerGame($awayId);
        if ($hAvg === null || $aAvg === null) {
            return [null, null, 'No drop-out history yet'];
        }
        [$hStr, $aStr] = $this->normalisePair($hAvg, $aAvg);
        $desc = sprintf('%.2f vs %.2f forced drop-outs/game (last 5)', $hAvg, $aAvg);
        return [$hStr, $aStr, $desc];
    }

    protected function teamDropOutsPerGame(int $teamId): ?float
    {
        $rows = MatchTeamStats::where('team_id', $teamId)
            ->whereHas('match', fn ($q) => $q->where('status', 'completed'))
            ->whereNotNull('forced_drop_outs')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['forced_drop_outs']);

        if ($rows->count() < 3) {
            return null;
        }
        return (float) $rows->avg(fn ($r) => (float) $r->forced_drop_outs);
    }

    protected function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $mid = (int) floor(count($values) / 2);
        if (count($values) % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }
        return $values[$mid];
    }

    /**
     * Shared cached lookup so the signal methods don't each hit the DB.
     */
    protected function recentMatches(int $teamId, int $limit)
    {
        static $cache = [];
        $key = $teamId . ':' . $limit;
        if (! isset($cache[$key])) {
            $cache[$key] = Matchup::where('status', 'completed')
                ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
                ->orderByDesc('kickoff_at')
                ->limit($limit)
                ->get();
        }
        return $cache[$key];
    }
}
