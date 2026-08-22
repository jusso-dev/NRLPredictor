<?php

namespace App\Livewire;

use App\Services\SignalCalculator;
use App\Services\WinPredictor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

class Methodology extends Component
{
    #[Title('How It Works — NRL Try Predictor')]
    #[Layout('layouts.app')]
    public function render()
    {
        $tryWeights = app(SignalCalculator::class)->weights();
        $trySignals = collect($tryWeights)->map(fn ($weight, $type) => [
            'type' => $type,
            'weight' => $weight,
            'max_contribution' => $weight,
            'pct_of_total' => round(($weight / array_sum($tryWeights)) * 100, 1),
            'description' => $this->trySignalDescription($type),
            'source' => $this->trySignalSource($type),
        ])->sortByDesc('weight')->values();

        $winSignals = collect(WinPredictor::WEIGHTS)->map(fn ($weight, $type) => [
            'type' => $type,
            'weight' => $weight,
            'pct_of_total' => round(($weight / array_sum(WinPredictor::WEIGHTS)) * 100, 1),
            'description' => $this->winSignalDescription($type),
            'source' => $this->winSignalSource($type),
        ])->sortByDesc('weight')->values();

        $positionWeights = collect(SignalCalculator::POSITION_ADVANTAGE)->map(fn ($weight, $pos) => [
            'position' => ucfirst($pos),
            'weight' => $weight,
            'pct' => round($weight * 100),
        ])->sortByDesc('weight')->values();

        return view('livewire.methodology', [
            'trySignals' => $trySignals,
            'winSignals' => $winSignals,
            'positionWeights' => $positionWeights,
            'tryMaxScore' => array_sum($tryWeights),
            'winMaxScore' => array_sum(WinPredictor::WEIGHTS),
        ]);
    }

    protected function trySignalDescription(string $type): string
    {
        return match ($type) {
            'season_try_rate' => 'Tries scored per game this season. Higher rate = more likely to score again.',
            'recent_form' => 'Number of tries in the player\'s last 3 games. Hot streaks matter.',
            'position_advantage' => 'Base try-scoring likelihood by position. Wingers score most, props least.',
            'opponent_edge_weakness' => 'How many of the opponent\'s back-five defenders are injured or suspended.',
            'opponent_missing_defenders' => 'New/replacement defenders in the opponent\'s edges compared to last week.',
            'head_to_head' => 'Player\'s historical try rate against this specific opponent.',
            'venue_record' => 'Player\'s try rate at this specific venue.',
            'career_try_rate' => 'Lifetime tries per game across the player\'s entire career.',
            'milestone_game' => 'A documented try, game, record, or themed-round milestone. The tuner measures whether this context adds predictive value.',
            'team_attacking_form' => 'How many tries the player\'s team has scored in recent rounds.',
            'returning_player' => 'Player returning from injury or omission — fresh legs and a point to prove.',
            'edge_mismatch' => 'Matches the player\'s attacking side with the opponent\'s left, middle, or right-side try leakage.',
            'weather_adjustment' => 'Position-aware scoring adjustment for forecast rain, heat, humidity, and wind.',
            'debut_boost' => 'Flags a player named for an NRL debut in the final team list.',
            'revenge_game' => 'Flags a player facing a former club recorded in club history.',
            'short_turnaround' => 'Accounts for recovery time, travel, and a compressed fixture turnaround.',
            'tactical_shift' => 'Captures a documented positional or team-list tactical change.',
            'opponent_try_concede_rate' => 'Opponent tries conceded per game across recent completed matches.',
            'bookmaker_try_odds' => 'Median de-vigged anytime try-scorer probability across available Australian bookmakers.',
            'match_total_line' => 'Uses the market total to distinguish high-scoring games from low-scoring contests.',
            'team_favouritism' => 'Scales opportunity using the team\'s market-implied chance of winning.',
            'rolling_try_share' => 'Player share of their team\'s tries across recent matches.',
            'red_zone_pressure', 'team_drop_outs_forced' => 'Team forced drop-outs per game, measuring repeat sets near the try line.',
            'opponent_ruck_speed', 'opp_ruck_penalties' => 'Opponent ruck infringements per game, a proxy for vulnerable play-the-ball speed.',
            'opponent_error_rate' => 'Opponent errors per game, creating turnover-ball scoring opportunities.',
            'starter_role' => 'Separates starting XIII, interchange, and reserve minutes/opportunity.',
            'opp_missed_tackles' => 'Opponent missed tackles per game over the rolling form window.',
            'opp_line_breaks_conceded' => 'Opponent line breaks conceded per game before they become tries.',
            'opp_position_concede' => 'Opponent try leakage split by the scorer\'s position group.',
            'opp_def_form_decay' => 'Compares recent defensive leakage with the opponent\'s earlier form window.',
            'team_tackle_breaks' => 'Attacking team tackle breaks per game, weighted toward likely finishers.',
            'opp_post_contact_concede' => 'Post-contact metres conceded by the opponent across recent matches.',
            'team_offload_rate' => 'Attacking team offloads per game, measuring live-ball opportunities.',
            'tries_ema' => 'Exponentially weighted player try rate over eight matches, favouring newer games.',
            'opp_completion_pressure' => 'Low opponent completion rate translated into extra turnover possession.',
            'team_possession_pct' => 'Attacking team rolling possession share and resulting opportunity volume.',
            'team_kick_pressure' => 'Team kicking metres as a proxy for territory and repeat attacking field position.',
            'opp_late_concede' => 'Opponent share of tries conceded after the 60th minute.',
            'team_first_try_rate' => 'How often the attacking team scores the first try in recent matches.',
            'opp_first_concede' => 'Opponent share of tries conceded inside the opening 20 minutes.',
            'opp_yardage_concede' => 'Run metres conceded by the opponent per recent match.',
            'team_set_efficiency' => 'Attacking team tries per completed set, measuring conversion quality.',
            'opp_set_concede_rate' => 'Opponent tries conceded per set faced, separating pressure from resilience.',
            'team_explosive_rate' => 'Team tackle breaks, line breaks, and offloads per carry.',
            'opp_explosive_concede' => 'Explosive plays conceded by the opponent per defensive carry faced.',
            'team_penalty_diff' => 'Rolling penalties drawn minus penalties conceded, a field-position edge.',
            'opp_effective_tackle_pct' => 'Opponent effective tackle percentage, inverted so weaker defence scores higher.',
            'team_completion_rate' => 'Attacking team completed sets as a share of total sets.',
            'team_pcm_per_run' => 'Attacking team post-contact metres per run, measuring bend-the-line quality.',
            'opp_pcm_per_run_concede' => 'Opponent post-contact metres conceded per run.',
            'team_attacking_streak' => 'Consecutive recent matches where the team scored at least four tries.',
            'learned_pattern' => 'Bounded boost or penalty from a compound pattern that repeated across at least three prior rounds.',
            default => 'Derived statistical context included in the weighted try-scorer model.',
        };
    }

    protected function trySignalSource(string $type): string
    {
        return match ($type) {
            'season_try_rate', 'career_try_rate' => 'nrl.com player profiles',
            'recent_form' => 'Match results + try events',
            'position_advantage' => 'Statistical model (position-based weights)',
            'opponent_edge_weakness' => 'nrl.com injury/suspension lists',
            'opponent_missing_defenders' => 'Team lists compared week-to-week',
            'head_to_head' => 'Historical match data',
            'venue_record' => 'Historical venue try data',
            'milestone_game' => 'Milestone event records + career totals',
            'team_attacking_form' => 'Team try totals from recent rounds',
            'returning_player' => 'Team list changes + injury reports',
            'edge_mismatch', 'opponent_try_concede_rate', 'rolling_try_share', 'tries_ema',
            'opp_position_concede', 'opp_def_form_decay', 'opp_late_concede', 'team_first_try_rate',
            'opp_first_concede', 'team_attacking_streak' => 'Completed matches + try events',
            'weather_adjustment' => 'Open-Meteo fixture forecast',
            'debut_boost', 'short_turnaround', 'tactical_shift', 'starter_role' => 'Fixtures + match team lists',
            'revenge_game' => 'Player club history',
            'bookmaker_try_odds', 'match_total_line', 'team_favouritism' => 'The Odds API + AU bookmaker markets',
            'red_zone_pressure', 'opponent_ruck_speed', 'opponent_error_rate', 'opp_missed_tackles',
            'opp_line_breaks_conceded', 'team_tackle_breaks', 'opp_post_contact_concede', 'team_offload_rate',
            'opp_completion_pressure', 'team_possession_pct', 'team_kick_pressure', 'opp_yardage_concede',
            'team_set_efficiency', 'opp_set_concede_rate', 'team_explosive_rate', 'opp_explosive_concede',
            'team_drop_outs_forced', 'opp_ruck_penalties', 'team_penalty_diff', 'opp_effective_tackle_pct',
            'team_completion_rate', 'team_pcm_per_run', 'opp_pcm_per_run_concede' => 'Rolling match team statistics',
            'learned_pattern' => 'Prior-round prediction outcomes + repeated-pattern validation',
            default => 'NRL and derived model data',
        };
    }

    protected function winSignalDescription(string $type): string
    {
        return match ($type) {
            'recent_form' => 'Win/loss record in the team\'s last 5 games.',
            'home_advantage' => 'Home teams win ~57% of NRL games historically. A baseline edge.',
            'head_to_head' => 'Results from the last 5 meetings between these two teams.',
            'injury_impact' => 'How many players the opponent has out injured or suspended.',
            'squad_stability' => 'How many changes from last week\'s team. Settled teams perform better.',
            'points_for' => 'Average points scored per game over the last 5 matches.',
            'points_against' => 'Average points conceded per game over the last 5 matches. Lower is better.',
            default => '',
        };
    }

    protected function winSignalSource(string $type): string
    {
        return match ($type) {
            'recent_form' => 'Match results (scores)',
            'home_advantage' => 'Statistical baseline',
            'head_to_head' => 'Historical match data',
            'injury_impact' => 'nrl.com injury/suspension lists',
            'squad_stability' => 'Team lists compared week-to-week',
            'points_for', 'points_against' => 'Match scores from recent rounds',
            default => 'nrl.com',
        };
    }
}
