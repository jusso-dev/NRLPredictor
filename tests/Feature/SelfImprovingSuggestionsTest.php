<?php

namespace Tests\Feature;

use App\Jobs\AnalyseMatchWithAi;
use App\Jobs\AutoTuneAfterRound;
use App\Jobs\FetchMatchResults;
use App\Jobs\RunPredictionAnalysis;
use App\Models\Matchup;
use App\Models\Player;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\Team;
use App\Models\TryEvent;
use App\Models\WeightAdjustment;
use App\Services\AgentContext;
use App\Services\CalibrationGrader;
use App\Services\ModelAlertChecker;
use App\Services\SignalTuner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SelfImprovingSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_learning_context_uses_latest_prior_round_only(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);

        $this->adjustment(1, ['trusted' => 0.2, 'discounted' => -0.3]);
        $this->adjustment(3, ['future_leak' => 1.0]);

        $context = app(AgentContext::class)->learningContext($match);

        $this->assertSame(1, $context['trained_through_round']);
        $this->assertSame('trusted', $context['signals_to_trust'][0]['signal']);
        $this->assertSame('discounted', $context['signals_to_discount'][0]['signal']);
        $this->assertArrayNotHasKey('future_leak', collect($context['signals_to_trust'])->keyBy('signal')->all());
    }

    public function test_auto_tuner_fills_oldest_gap_then_refreshes_upcoming_suggestions(): void
    {
        Bus::fake();
        [$home, $away] = $this->teams();
        $player = Player::create([
            'nrl_slug' => 'test-player',
            'name' => 'Test Player',
            'team_id' => $home->id,
        ]);

        foreach ([1, 2] as $number) {
            $round = Round::create(['season' => 2026, 'round_number' => $number]);
            $match = Matchup::create([
                'round_id' => $round->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'status' => 'completed',
            ]);
            Prediction::create([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'score' => 80,
                'rank_in_match' => 1,
                'signals' => [],
            ]);
            TryEvent::create(['match_id' => $match->id, 'player_id' => $player->id]);
        }

        $currentRound = Round::create([
            'season' => 2026,
            'round_number' => 3,
            'is_current' => true,
        ]);
        Matchup::create([
            'round_id' => $currentRound->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'upcoming',
        ]);
        $this->adjustment(2, []);

        $tuner = Mockery::mock(SignalTuner::class);
        $tuner->shouldReceive('tuneAfterRound')
            ->once()
            ->with(2026, 1)
            ->andReturn(['accuracy' => 50.0, 'weights_changed' => 2]);

        (new AutoTuneAfterRound)->handle($tuner);

        Bus::assertChained([
            RunPredictionAnalysis::class,
            AnalyseMatchWithAi::class,
        ]);
    }

    public function test_result_matching_is_scoped_to_the_fixture_round(): void
    {
        [$home, $away] = $this->teams();
        $firstRound = Round::create(['season' => 2026, 'round_number' => 1]);
        $secondRound = Round::create(['season' => 2026, 'round_number' => 2]);
        Matchup::create([
            'round_id' => $firstRound->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
        ]);
        $expected = Matchup::create([
            'round_id' => $secondRound->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
        ]);

        $actual = (new TestableFetchMatchResults)->findForTest('Home', 'Away', $secondRound->id);

        $this->assertSame($expected->id, $actual?->id);
    }

    public function test_backtest_grades_candidate_weights_by_recomputed_rank(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 1]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
        ]);

        $scorer = null;
        foreach (range(1, 6) as $index) {
            $player = Player::create([
                'nrl_slug' => "rank-player-{$index}",
                'name' => "Rank Player {$index}",
                'team_id' => $home->id,
            ]);
            $isScorer = $index === 6;
            Prediction::create([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'score' => 80 - $index,
                'rank_in_match' => $index,
                'signals' => [[
                    'type' => $isScorer ? 'good' : 'bad',
                    'weight' => 1,
                    'strength' => 1.0,
                ]],
            ]);
            if ($isScorer) {
                $scorer = $player;
            }
        }
        TryEvent::create(['match_id' => $match->id, 'player_id' => $scorer->id]);

        $tuner = new BacktestableSignalTuner(
            Mockery::mock(CalibrationGrader::class),
            Mockery::mock(ModelAlertChecker::class),
        );
        $before = $tuner->backtestForTest(2026, 1, ['good' => 1, 'bad' => 10]);
        $after = $tuner->backtestForTest(2026, 1, ['good' => 20, 'bad' => 1]);

        $this->assertSame(0.0, $before['top5_accuracy']);
        $this->assertSame(20.0, $after['top5_accuracy']);
        $this->assertGreaterThan($before['ndcg'], $after['ndcg']);
    }

    private function teams(): array
    {
        return [
            Team::create(['nrl_slug' => 'home', 'name' => 'Home']),
            Team::create(['nrl_slug' => 'away', 'name' => 'Away']),
        ];
    }

    private function adjustment(int $round, array $deltas): WeightAdjustment
    {
        return WeightAdjustment::create([
            'season' => 2026,
            'after_round' => $round,
            'old_weights' => [],
            'new_weights' => [],
            'signal_deltas' => $deltas,
            'accuracy_before' => 50,
            'accuracy_after' => 51,
        ]);
    }
}

class TestableFetchMatchResults extends FetchMatchResults
{
    public function findForTest(string $home, string $away, int $roundId): ?Matchup
    {
        return $this->findMatch($home, $away, $roundId);
    }
}

class BacktestableSignalTuner extends SignalTuner
{
    public function backtestForTest(int $season, int $round, array $weights): array
    {
        return $this->backtestWeights($season, $round, $weights);
    }
}
