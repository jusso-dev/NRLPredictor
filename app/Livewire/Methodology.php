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
        $trySignals = collect(SignalCalculator::WEIGHTS)->map(fn ($weight, $type) => [
            'type' => $type,
            'weight' => $weight,
            'max_contribution' => $weight,
            'pct_of_total' => round(($weight / array_sum(SignalCalculator::WEIGHTS)) * 100, 1),
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
            'tryMaxScore' => array_sum(SignalCalculator::WEIGHTS),
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
            'milestone_game' => 'Playing near a milestone (50th, 100th, 150th, 200th game). Players often lift for milestones.',
            'team_attacking_form' => 'How many tries the player\'s team has scored in recent rounds.',
            'returning_player' => 'Player returning from injury or omission — fresh legs and a point to prove.',
            default => '',
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
            'milestone_game' => 'Career games count',
            'team_attacking_form' => 'Team try totals from recent rounds',
            'returning_player' => 'Team list changes + injury reports',
            default => 'nrl.com',
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
