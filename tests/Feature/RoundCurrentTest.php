<?php

namespace Tests\Feature;

use App\Models\Matchup;
use App\Models\Round;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoundCurrentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTeams(): array
    {
        return [
            Team::create(['nrl_slug' => 'broncos', 'name' => 'Brisbane Broncos', 'short_name' => 'Broncos']),
            Team::create(['nrl_slug' => 'storm', 'name' => 'Melbourne Storm', 'short_name' => 'Storm']),
        ];
    }

    protected function makeMatch(Round $round, Team $home, Team $away, string $status): Matchup
    {
        return Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => $status,
            'kickoff_at' => Carbon::parse($round->start_date)->setTime(19, 50),
        ]);
    }

    public function test_returns_null_with_no_rounds(): void
    {
        $this->assertNull(Round::current());
    }

    public function test_round_in_window_with_incomplete_matches_wins(): void
    {
        [$home, $away] = $this->makeTeams();
        $round = Round::create([
            'season' => 2026, 'round_number' => 17,
            'start_date' => Carbon::today()->subDay(),
            'end_date' => Carbon::today()->addDay(),
        ]);
        $this->makeMatch($round, $home, $away, 'upcoming');

        $this->assertSame($round->id, Round::current()?->id);
    }

    public function test_completed_round_hands_over_to_round_starting_today(): void
    {
        [$home, $away] = $this->makeTeams();
        $done = Round::create([
            'season' => 2026, 'round_number' => 17,
            'start_date' => Carbon::today()->subDays(2),
            'end_date' => Carbon::today()->subDay(),
        ]);
        $this->makeMatch($done, $home, $away, 'completed');

        // Regression: with `start_date > today` this round was invisible and
        // the dashboard stayed pinned on the finished one all day.
        $next = Round::create([
            'season' => 2026, 'round_number' => 18,
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(2),
        ]);

        $this->assertSame($next->id, Round::current()?->id);
    }

    public function test_falls_back_to_upcoming_round_outside_any_window(): void
    {
        $future = Round::create([
            'season' => 2026, 'round_number' => 20,
            'start_date' => Carbon::today()->addDays(10),
            'end_date' => Carbon::today()->addDays(12),
        ]);

        $this->assertSame($future->id, Round::current()?->id);
    }

    public function test_round_with_null_dates_does_not_shadow_valid_rounds(): void
    {
        Round::create(['season' => 2026, 'round_number' => 1]);
        $valid = Round::create([
            'season' => 2026, 'round_number' => 2,
            'start_date' => Carbon::today()->addDays(3),
            'end_date' => Carbon::today()->addDays(5),
        ]);

        $this->assertSame($valid->id, Round::current()?->id);
    }
}
