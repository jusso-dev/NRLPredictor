<?php

namespace Tests\Feature;

use App\Models\MatchLateChange;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Round;
use App\Models\Team;
use App\Services\LateChangeRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LateChangeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> player name => id */
    private array $players = [];

    private function match(?string $kickoff = '+2 hours', string $status = 'upcoming'): Matchup
    {
        $round = Round::create(['season' => 2026, 'round_number' => 23]);
        $home = Team::create(['nrl_slug' => 'broncos', 'name' => 'Brisbane Broncos', 'short_name' => 'Broncos']);
        $away = Team::create(['nrl_slug' => 'storm', 'name' => 'Melbourne Storm', 'short_name' => 'Storm']);

        foreach (['Reece Walsh', 'Selwyn Cobbo', 'Kotoni Staggs', 'Jesse Arthars'] as $name) {
            $this->players[$name] = Player::create([
                'nrl_slug' => Str::slug($name),
                'name' => $name,
                'team_id' => $home->id,
            ])->id;
        }

        return Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'venue' => 'Suncorp Stadium',
            'kickoff_at' => $kickoff ? now()->modify($kickoff) : null,
            'status' => $status,
        ]);
    }

    private function id(string $name): int
    {
        return $this->players[$name];
    }

    /** @return array<int, array{name: string, number: int, role: string}> */
    private function list(array $overrides = []): array
    {
        return $overrides + [
            $this->id('Reece Walsh') => ['name' => 'Reece Walsh', 'number' => 1, 'role' => 'starting'],
            $this->id('Selwyn Cobbo') => ['name' => 'Selwyn Cobbo', 'number' => 2, 'role' => 'starting'],
            $this->id('Kotoni Staggs') => ['name' => 'Kotoni Staggs', 'number' => 3, 'role' => 'starting'],
        ];
    }

    public function test_a_withdrawn_player_is_recorded_as_an_out(): void
    {
        $match = $this->match();
        $after = $this->list();
        unset($after[$this->id('Selwyn Cobbo')]);

        $recorded = app(LateChangeRecorder::class)
            ->recordTeamListDiff($match, $match->home_team_id, $this->list(), $after);

        $this->assertSame(1, $recorded);
        $change = MatchLateChange::first();
        $this->assertSame(MatchLateChange::TYPE_OUT, $change->type);
        $this->assertSame('Selwyn Cobbo (2) out of the side', $change->summary);
        $this->assertSame($this->id('Selwyn Cobbo'), $change->player_id);
        $this->assertGreaterThan(0, $change->minutes_to_kickoff);
    }

    public function test_a_new_name_and_a_reshuffle_are_recorded(): void
    {
        $match = $this->match();
        $after = $this->list([
            $this->id('Selwyn Cobbo') => ['name' => 'Selwyn Cobbo', 'number' => 5, 'role' => 'starting'],
            $this->id('Jesse Arthars') => ['name' => 'Jesse Arthars', 'number' => 2, 'role' => 'starting'],
        ]);

        app(LateChangeRecorder::class)->recordTeamListDiff($match, $match->home_team_id, $this->list(), $after);

        $this->assertSame(
            ['Jesse Arthars named at 2', 'Selwyn Cobbo moves 2 → 5'],
            MatchLateChange::orderBy('summary')->pluck('summary')->all()
        );
    }

    public function test_the_same_change_seen_twice_is_not_recorded_twice(): void
    {
        $match = $this->match();
        $after = $this->list();
        unset($after[$this->id('Selwyn Cobbo')]);

        $recorder = app(LateChangeRecorder::class);
        $this->assertSame(1, $recorder->recordTeamListDiff($match, $match->home_team_id, $this->list(), $after));
        $this->assertSame(0, $recorder->recordTeamListDiff($match, $match->home_team_id, $this->list(), $after));
        $this->assertSame(1, MatchLateChange::count());
    }

    public function test_the_first_sync_of_a_match_is_not_treated_as_late_news(): void
    {
        $match = $this->match();

        $recorded = app(LateChangeRecorder::class)
            ->recordTeamListDiff($match, $match->home_team_id, [], $this->list());

        $this->assertSame(0, $recorded);
        $this->assertSame(0, MatchLateChange::count());
    }

    public function test_changes_outside_the_window_are_ignored(): void
    {
        $match = $this->match('+5 days');
        $after = $this->list();
        unset($after[$this->id('Selwyn Cobbo')]);

        $recorded = app(LateChangeRecorder::class)
            ->recordTeamListDiff($match, $match->home_team_id, $this->list(), $after);

        $this->assertSame(0, $recorded);
    }

    public function test_a_kicked_off_match_no_longer_accrues_late_changes(): void
    {
        $match = $this->match('-30 minutes', 'live');
        $after = $this->list();
        unset($after[$this->id('Selwyn Cobbo')]);

        $this->assertSame(
            0,
            app(LateChangeRecorder::class)->recordTeamListDiff($match, $match->home_team_id, $this->list(), $after)
        );
    }

    public function test_a_big_price_move_is_recorded_as_drift(): void
    {
        $match = $this->match();

        $change = app(LateChangeRecorder::class)->recordOddsDrift(
            $match,
            'ats',
            4.00,   // 25.0% implied
            2.50,   // 40.0% implied — a 15 point move
            'sportsbet',
            $this->id('Selwyn Cobbo'),
            'Selwyn Cobbo',
        );

        $this->assertNotNull($change);
        $this->assertSame(MatchLateChange::TYPE_ODDS_DRIFT, $change->type);
        $this->assertStringContainsString('Selwyn Cobbo shortened 4.00 → 2.50', $change->summary);
        $this->assertSame('sportsbet', $change->detail['bookmaker']);
        $this->assertEqualsWithDelta(0.15, $change->detail['implied_delta'], 0.001);
    }

    public function test_a_small_price_move_is_noise_not_news(): void
    {
        $match = $this->match();

        $this->assertNull(app(LateChangeRecorder::class)->recordOddsDrift(
            $match, 'ats', 2.50, 2.45, 'tab', $this->id('Selwyn Cobbo'), 'Selwyn Cobbo',
        ));
    }

    public function test_price_moves_only_count_close_to_kickoff(): void
    {
        $match = $this->match('+20 hours');

        $this->assertNull(app(LateChangeRecorder::class)->recordOddsDrift(
            $match, 'ats', 4.00, 2.50, 'tab', $this->id('Selwyn Cobbo'), 'Selwyn Cobbo',
        ));
    }

    public function test_the_api_exposes_late_changes_on_the_match_detail(): void
    {
        $match = $this->match();
        app(LateChangeRecorder::class)->record($match, MatchLateChange::TYPE_OUT, 'Selwyn Cobbo (2) out of the side');

        $this->getJson("/api/v1/matches/{$match->id}")
            ->assertOk()
            ->assertJsonPath('data.late_change_count', 1)
            ->assertJsonPath('data.late_changes.0.type', 'out')
            ->assertJsonPath('data.late_changes.0.summary', 'Selwyn Cobbo (2) out of the side');
    }

    public function test_list_views_carry_a_count_but_not_the_rows(): void
    {
        $match = $this->match();
        app(LateChangeRecorder::class)->record($match, MatchLateChange::TYPE_OUT, 'Selwyn Cobbo (2) out of the side');

        $response = $this->getJson('/api/v1/matches?round=23&season=2026')->assertOk();

        $this->assertSame(1, $response->json('data.0.late_change_count'));
        $this->assertArrayNotHasKey('late_changes', $response->json('data.0'));
    }
}
