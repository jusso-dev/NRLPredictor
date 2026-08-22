<?php

namespace Tests\Feature;

use App\Jobs\AnalyseMatchWithAi;
use App\Jobs\AutoTuneAfterRound;
use App\Jobs\FetchMatchResults;
use App\Jobs\RunPredictionAnalysis;
use App\Models\DataFetchLog;
use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\MilestoneEvent;
use App\Models\Player;
use App\Models\Prediction;
use App\Models\Round;
use App\Models\Team;
use App\Models\TryEvent;
use App\Models\WeightAdjustment;
use App\Services\AgentContext;
use App\Services\AiImpactAnalyzer;
use App\Services\CalibrationGrader;
use App\Services\CodexAuthenticationException;
use App\Services\CodexClient;
use App\Services\LearnedPatternSignal;
use App\Services\MilestoneDetector;
use App\Services\ModelAlertChecker;
use App\Services\OutcomeTrendAnalyzer;
use App\Services\PredictionScorer;
use App\Services\SignalCalculator;
use App\Services\SignalPatternAnalyzer;
use App\Services\SignalTuner;
use App\Services\TryPredictionAgent;
use App\Support\HttpScraper;
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

    public function test_ai_learning_context_includes_discovered_compound_patterns(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);
        $this->adjustment(2, [], [[
            'key' => 'milestone_game+recent_form',
            'label' => 'Milestone game + Recent form',
            'signals' => ['milestone_game', 'recent_form'],
            'sample_size' => 14,
            'hit_rate' => 42.9,
            'baseline_hit_rate' => 20.0,
            'lift' => 22.9,
            'confidence' => 'developing',
        ]]);

        $context = app(AgentContext::class)->learningContext($match);

        $this->assertSame('milestone_game+recent_form', $context['learned_patterns'][0]['key']);
        $this->assertSame(22.9, $context['learned_patterns'][0]['lift']);
    }

    public function test_ai_prediction_context_excludes_zero_value_signal_noise(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);
        $player = Player::create([
            'nrl_slug' => 'compact-context-player',
            'name' => 'Compact Context Player',
            'team_id' => $home->id,
        ]);
        Prediction::create([
            'match_id' => $match->id,
            'player_id' => $player->id,
            'score' => 72,
            'rank_in_match' => 1,
            'signals' => [
                ['type' => 'recent_form', 'weight' => 18, 'strength' => 0.8, 'description' => 'Strong recent form'],
                ['type' => 'late_withdrawal_risk', 'weight' => 10, 'strength' => -0.7, 'description' => 'Negative team news'],
                ['type' => 'learned_pattern', 'weight' => 6, 'strength' => 0.2, 'description' => 'Repeated pattern'],
                ['type' => 'weak_noise', 'weight' => 10, 'strength' => 0.5, 'description' => 'Weak evidence'],
                ['type' => 'zero_noise', 'weight' => 20, 'strength' => 0.0, 'description' => str_repeat('Noise ', 100)],
            ],
        ]);

        $prediction = app(AgentContext::class)->topPredictions($match, 1)[0];

        $this->assertSame(
            ['recent_form', 'late_withdrawal_risk', 'learned_pattern'],
            collect($prediction['signals'])->pluck('type')->all(),
        );
        $this->assertSame(14.4, $prediction['signals'][0]['contribution']);
        $this->assertLessThan(800, strlen(json_encode($prediction)));
    }

    public function test_repeated_ai_review_is_clamped_to_the_statistical_baseline(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'upcoming',
        ]);
        $player = Player::create([
            'nrl_slug' => 'repeat-ai-player',
            'name' => 'Repeat AI Player',
            'team_id' => $home->id,
        ]);
        Prediction::create([
            'match_id' => $match->id,
            'player_id' => $player->id,
            'score' => 65,
            'statistical_score' => 50,
            'rank_in_match' => 1,
            'statistical_rank' => 1,
            'ai_adjusted_score' => 65,
            'signals' => [
                ['type' => 'recent_form', 'weight' => 18, 'strength' => 0.8, 'description' => 'Strong recent form'],
                ['type' => 'starter_role', 'weight' => 5, 'strength' => 1.0, 'description' => 'Named starter'],
                ['type' => 'venue_record', 'weight' => 8, 'strength' => 0.7, 'description' => 'Strong venue record'],
            ],
        ]);

        $codex = Mockery::mock(CodexClient::class);
        $codex->shouldReceive('exec')->once()->andReturn('{"adjustments":[]}');
        $codex->shouldReceive('extractJson')->once()->andReturn([
            'adjustments' => [[
                'player_id' => $player->id,
                'adjusted_score' => 100,
                'signal_types' => ['recent_form', 'starter_role', 'venue_record'],
                'reasoning' => 'Three corroborating signals support the upper bounded adjustment.',
            ]],
        ]);

        (new TryPredictionAgent($codex, app(AgentContext::class)))->analyse($match->id);

        $prediction = Prediction::firstOrFail();
        $this->assertSame(65, $prediction->score);
        $this->assertSame(65, $prediction->ai_adjusted_score);
        $this->assertSame(['recent_form', 'starter_role', 'venue_record'], $prediction->ai_cited_signals);
        $this->assertNotNull($prediction->ai_reviewed_at);
        $this->assertSame(1, $match->fresh()->ai_adjustments_count);
        $this->assertNotNull($match->fresh()->ai_reviewed_at);

        $hallucinatingCodex = Mockery::mock(CodexClient::class);
        $hallucinatingCodex->shouldReceive('exec')->once()->andReturn('{"adjustments":[]}');
        $hallucinatingCodex->shouldReceive('extractJson')->once()->andReturn([
            'adjustments' => [[
                'player_id' => $player->id,
                'adjusted_score' => 35,
                'signal_types' => ['recent_form', 'starter_role', 'imaginary_signal'],
                'reasoning' => 'This response invents evidence and must be rejected by the server.',
            ]],
        ]);

        try {
            (new TryPredictionAgent($hallucinatingCodex, app(AgentContext::class)))->analyse($match->id);
            $this->fail('Unsupported AI citations should fail the review.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no valid adjustments', $exception->getMessage());
        }

        $this->assertSame(65, $prediction->fresh()->score);

        $holdingCodex = Mockery::mock(CodexClient::class);
        $holdingCodex->shouldReceive('exec')->once()->andReturn('{"adjustments":[]}');
        $holdingCodex->shouldReceive('extractJson')->once()->andReturn(['adjustments' => []]);

        $applied = (new TryPredictionAgent($holdingCodex, app(AgentContext::class)))->analyse($match->id);

        $this->assertSame(0, $applied);
        $prediction = $prediction->fresh();
        $this->assertSame(50, $prediction->score);
        $this->assertNull($prediction->ai_adjusted_score);
        $this->assertNull($prediction->ai_reasoning);
        $this->assertSame(0, $match->fresh()->ai_adjustments_count);
        $this->assertNotNull($match->fresh()->ai_reviewed_at);
        $this->get("/match/{$match->id}")
            ->assertOk()
            ->assertSee('AI reviewed · held statistical baseline')
            ->assertSee('every change requires three verified active signals');
    }

    public function test_ai_output_schema_avoids_unsupported_codex_keywords(): void
    {
        $reflection = new \ReflectionClass(TryPredictionAgent::class);
        $schema = $reflection->getReflectionConstant('ANALYSIS_SCHEMA')->getValue();
        $signalTypes = $schema['properties']['adjustments']['items']['properties']['signal_types'];

        $this->assertSame(3, $signalTypes['minItems']);
        $this->assertArrayNotHasKey('uniqueItems', $signalTypes);
    }

    public function test_expired_codex_auth_is_logged_without_retrying_the_match_job(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'upcoming',
        ]);
        $player = Player::create([
            'nrl_slug' => 'auth-failure-player',
            'name' => 'Auth Failure Player',
            'team_id' => $home->id,
        ]);
        Prediction::create([
            'match_id' => $match->id,
            'player_id' => $player->id,
            'score' => 70,
            'statistical_score' => 70,
            'rank_in_match' => 1,
            'statistical_rank' => 1,
            'signals' => [],
        ]);
        $agent = Mockery::mock(TryPredictionAgent::class);
        $agent->shouldReceive('analyse')
            ->once()
            ->andThrow(new CodexAuthenticationException('Codex authentication expired.'));

        (new AnalyseMatchWithAi($match->id))->handle($agent);

        $this->assertDatabaseHas('data_fetch_logs', [
            'source' => "ai.agent:match:{$match->id}",
            'status' => 'failed',
            'error' => 'Codex authentication expired.',
        ]);
        $this->assertTrue(CodexClient::isAuthenticationFailure('Failed to refresh token: access token could not be refreshed'));
        $this->assertFalse(CodexClient::isAuthenticationFailure('network connection timed out'));
    }

    public function test_ai_impact_is_measured_against_the_saved_statistical_ranking(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
            'ai_reviewed_at' => now(),
            'ai_adjustments_count' => 1,
        ]);
        $players = collect(range(1, 6))->map(fn (int $index) => Player::create([
            'nrl_slug' => "ai-impact-player-{$index}",
            'name' => "AI Impact Player {$index}",
            'team_id' => $home->id,
        ]));

        foreach ($players as $index => $player) {
            $baselineRank = $index + 1;
            $finalRank = $baselineRank === 6 ? 1 : ($baselineRank === 1 ? 6 : $baselineRank);
            Prediction::create([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'score' => 100 - $finalRank,
                'statistical_score' => 100 - $baselineRank,
                'rank_in_match' => $finalRank,
                'statistical_rank' => $baselineRank,
                'ai_adjusted_score' => $baselineRank === 6 ? 99 : null,
                'ai_reviewed_at' => $baselineRank === 6 ? now() : null,
                'signals' => [],
            ]);
        }
        TryEvent::create(['match_id' => $match->id, 'player_id' => $players->last()->id]);

        $heldMatch = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
            'ai_reviewed_at' => now(),
            'ai_adjustments_count' => 0,
        ]);
        foreach ($players as $index => $player) {
            $rank = $index + 1;
            Prediction::create([
                'match_id' => $heldMatch->id,
                'player_id' => $player->id,
                'score' => 101 - $rank,
                'statistical_score' => 101 - $rank,
                'rank_in_match' => $rank,
                'statistical_rank' => $rank,
                'signals' => [],
            ]);
        }
        TryEvent::create(['match_id' => $heldMatch->id, 'player_id' => $players->first()->id]);

        $impact = app(AiImpactAnalyzer::class)->analyze(2026);

        $this->assertSame(2, $impact['reviewed_matches']);
        $this->assertSame(1, $impact['adjusted_predictions']);
        $this->assertSame(10.0, $impact['statistical_top5_accuracy']);
        $this->assertSame(20.0, $impact['ai_top5_accuracy']);
        $this->assertSame(10.0, $impact['lift']);
        $this->assertSame(1, $impact['helped_matches']);
        $this->assertSame(1, $impact['held_matches']);
        $this->assertSame(10.0, $impact['rounds'][0]['lift']);

        $this->getJson("/api/v1/matches/{$match->id}/predictions")
            ->assertOk()
            ->assertJsonPath('ai_review.adjustments_count', 1)
            ->assertJsonPath('ai_review.held_statistical_baseline', false)
            ->assertJsonPath('data.0.player.id', $players->last()->id)
            ->assertJsonPath('data.0.statistical_rank', 6)
            ->assertJsonPath('data.0.ai_delta', 5);

        $this->get("/match/{$match->id}")
            ->assertOk()
            ->assertSee('AI reviewed · 1 score change')
            ->assertSee('AI +5 from 94')
            ->assertSee('R6 → R1');

        $this->get('/learning')
            ->assertOk()
            ->assertSee('AI Outcome Lift')
            ->assertSee('10.00 pts AI lift')
            ->assertSee('20.00%');
    }

    public function test_observed_outcome_trend_compares_stable_completed_round_windows(): void
    {
        [$home, $away] = $this->teams();

        foreach ([1, 1, 1, 1, 3] as $offset => $hitCount) {
            $roundNumber = $offset + 1;
            $round = Round::create(['season' => 2026, 'round_number' => $roundNumber]);
            $match = Matchup::create([
                'round_id' => $round->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'status' => 'completed',
            ]);

            foreach (range(1, 5) as $rank) {
                $player = Player::create([
                    'nrl_slug' => "trend-r{$roundNumber}-p{$rank}",
                    'name' => "Trend R{$roundNumber} P{$rank}",
                    'team_id' => $home->id,
                ]);
                Prediction::create([
                    'match_id' => $match->id,
                    'player_id' => $player->id,
                    'score' => 101 - $rank,
                    'rank_in_match' => $rank,
                    'signals' => [],
                ]);
                if ($rank <= $hitCount) {
                    TryEvent::create(['match_id' => $match->id, 'player_id' => $player->id]);
                }
            }
        }

        $trend = app(OutcomeTrendAnalyzer::class)->analyze(2026);

        $this->assertSame(5, $trend['completed_rounds']);
        $this->assertSame(5, $trend['graded_matches']);
        $this->assertSame(60.0, $trend['latest_accuracy']);
        $this->assertSame(30.0, $trend['rolling_accuracy']);
        $this->assertSame(20.0, $trend['prior_rolling_accuracy']);
        $this->assertSame(10.0, $trend['rolling_change']);
        $this->assertSame('improving', $trend['state']);
        $this->assertSame(30.0, $trend['rounds'][4]['rolling_accuracy']);

        $this->get('/learning')
            ->assertOk()
            ->assertSee('Observed Outcome Trend')
            ->assertSee('+10.0 pts')
            ->assertSee('vs prior four-round window')
            ->assertSee('5/5 completed matches graded');

        $this->getJson('/api/v1/learning?season=2026')
            ->assertOk()
            ->assertJsonPath('observed_outcomes.state', 'improving')
            ->assertJsonPath('observed_outcomes.rolling_change', 10)
            ->assertJsonPath('observed_outcomes.coverage_pct', 100);
        $this->getJson('/api/v1/learning?season=1999')->assertUnprocessable();
    }

    public function test_pattern_analyzer_finds_compound_milestone_patterns(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 1]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
        ]);

        foreach (range(1, 12) as $index) {
            $player = Player::create([
                'nrl_slug' => "pattern-player-{$index}",
                'name' => "Pattern Player {$index}",
                'team_id' => $home->id,
            ]);
            $signals = $index <= 8
                ? [
                    ['type' => 'milestone_game', 'strength' => 1.0, 'weight' => 8],
                    ['type' => 'recent_form', 'strength' => 0.8, 'weight' => 18],
                ]
                : [['type' => 'position_advantage', 'strength' => 0.8, 'weight' => 15]];
            Prediction::create([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'score' => 90 - $index,
                'rank_in_match' => $index,
                'signals' => $signals,
            ]);
            if ($index <= 5 || $index === 12) {
                TryEvent::create(['match_id' => $match->id, 'player_id' => $player->id]);
            }
        }

        $report = app(SignalPatternAnalyzer::class)->analyze(2026, 1);
        $pattern = collect($report['patterns'])->firstWhere('key', 'milestone_game+recent_form');

        $this->assertNotNull($pattern);
        $this->assertSame(8, $pattern['sample_size']);
        $this->assertSame(62.5, $pattern['hit_rate']);
        $this->assertSame(50.0, $pattern['baseline_hit_rate']);
        $this->assertSame(12.5, $pattern['lift']);
        $this->assertFalse($pattern['is_actionable']);
        $this->assertSame(12, $report['predictions_graded']);
    }

    public function test_pattern_analyzer_requires_repeated_round_evidence_before_action(): void
    {
        [$home, $away] = $this->teams();
        $players = collect(range(1, 6))->map(fn (int $index) => Player::create([
            'nrl_slug' => "repeat-pattern-player-{$index}",
            'name' => "Repeat Pattern Player {$index}",
            'team_id' => $home->id,
        ]));

        foreach (range(1, 3) as $roundNumber) {
            $round = Round::create(['season' => 2026, 'round_number' => $roundNumber]);
            $match = Matchup::create([
                'round_id' => $round->id,
                'home_team_id' => $home->id,
                'away_team_id' => $away->id,
                'status' => 'completed',
            ]);
            foreach ($players as $index => $player) {
                Prediction::create([
                    'match_id' => $match->id,
                    'player_id' => $player->id,
                    'score' => 90 - $index,
                    'rank_in_match' => $index + 1,
                    'signals' => $index < 4
                        ? [
                            ['type' => 'milestone_game', 'strength' => 1.0, 'weight' => 8],
                            ['type' => 'recent_form', 'strength' => 0.8, 'weight' => 18],
                        ]
                        : [['type' => 'position_advantage', 'strength' => 0.8, 'weight' => 15]],
                ]);
                if ($index < 3) {
                    TryEvent::create(['match_id' => $match->id, 'player_id' => $player->id]);
                }
            }
        }

        $pattern = collect(app(SignalPatternAnalyzer::class)->analyze(2026, 3)['patterns'])
            ->firstWhere('key', 'milestone_game+recent_form');

        $this->assertTrue($pattern['is_actionable']);
        $this->assertSame(3, $pattern['rounds']);
        $this->assertSame(1.0, $pattern['direction_consistency']);

        $this->get('/learning')
            ->assertOk()
            ->assertSee('live snapshot through R3')
            ->assertSee('Milestone Game + Recent Form')
            ->assertSee('ranking active');

        $this->getJson('/api/v1/learning?season=2026')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'milestone_game+recent_form',
                'is_actionable' => true,
            ]);
    }

    public function test_learned_pattern_signal_uses_actionable_prior_round_evidence_only(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);
        $actionable = [[
            'key' => 'milestone_game+recent_form',
            'label' => 'Milestone Game + Recent Form',
            'signals' => ['milestone_game', 'recent_form'],
            'sample_size' => 18,
            'rounds' => 4,
            'lift' => 20.0,
            'confidence' => 'developing',
            'is_actionable' => true,
        ], [
            'key' => 'milestone_game+venue_record',
            'label' => 'Milestone Game + Venue Record',
            'signals' => ['milestone_game', 'venue_record'],
            'sample_size' => 3,
            'rounds' => 1,
            'lift' => 60.0,
            'confidence' => 'early',
            'is_actionable' => false,
        ]];
        $this->adjustment(2, [], $actionable);
        $this->adjustment(3, [], [[
            ...$actionable[0],
            'lift' => -20.0,
        ]]);

        $signal = app(LearnedPatternSignal::class)->forMatch([
            ['type' => 'milestone_game', 'strength' => 1.0],
            ['type' => 'recent_form', 'strength' => 0.8],
            ['type' => 'venue_record', 'strength' => 0.9],
        ], $match, 6.0);

        $this->assertNotNull($signal);
        $this->assertSame('learned_pattern', $signal['type']);
        $this->assertSame(0.8, $signal['strength']);
        $this->assertSame('milestone_game+recent_form', $signal['pattern_key']);
        $this->assertSame(2, $signal['trained_through_round']);
    }

    public function test_actionable_pattern_changes_future_deterministic_ranking(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'upcoming',
            'ai_reviewed_at' => now(),
            'ai_adjustments_count' => 2,
        ]);
        $patternPlayer = Player::create([
            'nrl_slug' => 'pattern-pick',
            'name' => 'Pattern Pick',
            'team_id' => $home->id,
        ]);
        $controlPlayer = Player::create([
            'nrl_slug' => 'control-pick',
            'name' => 'Control Pick',
            'team_id' => $home->id,
        ]);
        foreach ([$patternPlayer, $controlPlayer] as $index => $player) {
            MatchTeamList::create([
                'match_id' => $match->id,
                'player_id' => $player->id,
                'team_id' => $home->id,
                'position_number' => $index + 1,
                'role' => 'starting',
            ]);
        }
        $this->adjustment(2, [], [[
            'key' => 'milestone_game+recent_form',
            'label' => 'Milestone Game + Recent Form',
            'signals' => ['milestone_game', 'recent_form'],
            'sample_size' => 18,
            'rounds' => 4,
            'lift' => 25.0,
            'confidence' => 'developing',
            'is_actionable' => true,
        ]]);

        $calculator = Mockery::mock(SignalCalculator::class);
        $calculator->shouldReceive('weights')->andReturn([
            'base_signal' => 10,
            'learned_pattern' => 6,
        ]);
        $calculator->shouldReceive('calculate')->andReturnUsing(
            fn (Player $player) => $player->is($patternPlayer)
                ? [
                    ['type' => 'base_signal', 'weight' => 10, 'strength' => 0.9, 'description' => 'Base'],
                    ['type' => 'milestone_game', 'weight' => 0, 'strength' => 1.0, 'description' => 'Milestone'],
                    ['type' => 'recent_form', 'weight' => 0, 'strength' => 0.8, 'description' => 'Form'],
                ]
                : [['type' => 'base_signal', 'weight' => 10, 'strength' => 1.0, 'description' => 'Base']],
        );

        $predictions = (new PredictionScorer($calculator, app(LearnedPatternSignal::class)))
            ->score($match->id);

        $this->assertSame($patternPlayer->id, $predictions[0]->player_id);
        $this->assertNull($match->fresh()->ai_reviewed_at);
        $this->assertNull($match->fresh()->ai_adjustments_count);
        $this->assertSame($predictions[0]->score, $predictions[0]->statistical_score);
        $this->assertSame(1, $predictions[0]->statistical_rank);
        $learned = collect($predictions[0]->signals)->firstWhere('type', 'learned_pattern');
        $this->assertNotNull($learned);
        $this->assertSame('milestone_game+recent_form', $learned['pattern_key']);
    }

    public function test_explicit_milestone_event_becomes_prediction_signal(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 1]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);
        $player = Player::create([
            'nrl_slug' => 'record-chaser',
            'name' => 'Record Chaser',
            'team_id' => $home->id,
            'career_games' => 17,
            'career_tries' => 4,
        ]);
        MilestoneEvent::create([
            'player_id' => $player->id,
            'match_id' => $match->id,
            'type' => 'record_chase',
            'current_count' => 4,
            'target_count' => 10,
            'distance' => 6,
        ]);

        $signal = collect(app(SignalCalculator::class)->calculate($player, $match))
            ->firstWhere('type', 'milestone_game');

        $this->assertSame(1.0, $signal['strength']);
        $this->assertStringContainsString('record', strtolower($signal['description']));
    }

    public function test_game_milestone_fallback_only_activates_on_the_exact_hallmark_game(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 1]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
        ]);
        $player = Player::create([
            'nrl_slug' => 'near-milestone-player',
            'name' => 'Near Milestone Player',
            'team_id' => $home->id,
            'career_games' => 98,
            'career_tries' => 0,
        ]);

        $signals = app(SignalCalculator::class)->calculate($player, $match);
        $milestone = collect($signals)->firstWhere('type', 'milestone_game');

        $this->assertSame(0.0, $milestone['strength']);
        $this->assertSame('No milestone upcoming (98 games, 0 tries)', $milestone['description']);
    }

    public function test_milestone_detector_materializes_upcoming_hallmark_games_idempotently(): void
    {
        [$home, $away] = $this->teams();
        $round = Round::create(['season' => 2026, 'round_number' => 3]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'upcoming',
        ]);
        $player = Player::create([
            'nrl_slug' => 'double-milestone',
            'name' => 'Double Milestone',
            'team_id' => $home->id,
            'career_games' => 99,
            'career_tries' => 48,
        ]);
        MatchTeamList::create([
            'match_id' => $match->id,
            'player_id' => $player->id,
            'team_id' => $home->id,
            'position_number' => 2,
            'role' => 'starting',
        ]);

        $detector = app(MilestoneDetector::class);
        $this->assertSame(2, $detector->syncForMatch($match));
        $this->assertSame(2, $detector->syncForMatch($match));
        $this->assertDatabaseCount('milestone_events', 2);
        $this->assertDatabaseHas('milestone_events', [
            'player_id' => $player->id,
            'match_id' => $match->id,
            'type' => 'game_milestone',
            'target_count' => 100,
            'distance' => 1,
        ]);
        $this->assertDatabaseHas('milestone_events', [
            'player_id' => $player->id,
            'match_id' => $match->id,
            'type' => 'try_milestone',
            'target_count' => 50,
            'distance' => 2,
        ]);
    }

    public function test_learning_page_shows_weekly_improvement_and_pattern_evidence(): void
    {
        DataFetchLog::create([
            'source' => 'ai.agent:match:123',
            'job_class' => AnalyseMatchWithAi::class,
            'status' => 'failed',
            'started_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);

        WeightAdjustment::create([
            'season' => now()->year,
            'after_round' => 5,
            'old_weights' => ['recent_form' => 18],
            'new_weights' => ['recent_form' => 18.5],
            'signal_deltas' => ['recent_form' => 0.12],
            'accuracy_before' => 40.0,
            'accuracy_after' => 42.5,
            'validation_matches' => 32,
            'ndcg_before' => 0.7100,
            'ndcg_after' => 0.7250,
            'candidate_accepted' => true,
            'learning_step' => 0.5,
            'pattern_insights' => [[
                'key' => 'milestone_game+recent_form',
                'label' => 'Milestone Game + Recent Form',
                'signals' => ['milestone_game', 'recent_form'],
                'sample_size' => 12,
                'hit_count' => 6,
                'rounds' => 4,
                'hit_rate' => 50.0,
                'baseline_hit_rate' => 25.0,
                'lift' => 25.0,
                'confidence' => 'developing',
                'is_actionable' => true,
            ]],
        ]);

        $this->get('/learning')
            ->assertOk()
            ->assertSee('Update Validation')
            ->assertSee('+2.50 points this update')
            ->assertSee('Milestone Game + Recent Form')
            ->assertSee('ranking active')
            ->assertSee('AI review layer')
            ->assertSee('Unavailable — deterministic rankings continue')
            ->assertSee('AI Outcome Lift')
            ->assertSee('Tracking starts after the next AI-reviewed match is completed')
            ->assertSee('+25.0 pts');
    }

    public function test_tuning_persists_validation_and_pattern_evidence_as_data(): void
    {
        $tuner = new BacktestableSignalTuner(
            Mockery::mock(CalibrationGrader::class),
            Mockery::mock(ModelAlertChecker::class),
        );
        $patterns = [[
            'key' => 'milestone_game+recent_form',
            'label' => 'Milestone Game + Recent Form',
            'signals' => ['milestone_game', 'recent_form'],
            'sample_size' => 12,
            'hit_count' => 6,
            'rounds' => 4,
            'hit_rate' => 50.0,
            'baseline_hit_rate' => 25.0,
            'lift' => 25.0,
            'confidence' => 'developing',
            'is_actionable' => true,
        ]];

        $adjustment = $tuner->persistForTest(
            ['recent_form' => 18.0],
            ['recent_form' => 18.5],
            ['recent_form' => 0.12],
            ['matches' => 32, 'top5_accuracy' => 40.0, 'ndcg' => 0.7100],
            ['matches' => 32, 'top5_accuracy' => 42.5, 'ndcg' => 0.7250, 'step_scale' => 0.5],
            $patterns,
        );

        $this->assertSame(32, $adjustment->validation_matches);
        $this->assertSame(0.71, $adjustment->ndcg_before);
        $this->assertSame(0.725, $adjustment->ndcg_after);
        $this->assertTrue($adjustment->candidate_accepted);
        $this->assertSame(0.5, $adjustment->learning_step);
        $this->assertSame('milestone_game+recent_form', $adjustment->pattern_insights[0]['key']);
    }

    public function test_methodology_documents_the_live_signal_stack_and_learning_sources(): void
    {
        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('Try Scorer Prediction — 54 Signals')
            ->assertSee('Milestone event records + career totals')
            ->assertSee('The Odds API + AU bookmaker markets')
            ->assertSee('Open-Meteo fixture forecast')
            ->assertSee('How the Model Improves')
            ->assertSee('Measure the AI')
            ->assertSee('Exact 50th/100th/150th/200th game');

        $this->getJson('/api/v1/methodology')
            ->assertOk()
            ->assertJsonPath('self_improvement.pattern_activation.minimum_sample', 12)
            ->assertJsonPath('self_improvement.pattern_activation.minimum_rounds', 3)
            ->assertJsonPath('self_improvement.leakage_control', 'Weights, patterns, and AI learning evidence are read only from rounds before the target match.');
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

    public function test_espn_results_sync_try_scorers_idempotently(): void
    {
        $home = Team::create(['nrl_slug' => 'panthers', 'name' => 'Panthers']);
        $away = Team::create(['nrl_slug' => 'broncos', 'name' => 'Broncos']);
        $round = Round::create(['season' => 2026, 'round_number' => 21]);
        $match = Matchup::create([
            'round_id' => $round->id,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'completed',
        ]);
        $player = Player::create([
            'nrl_slug' => 'test-scorer',
            'name' => 'Test Scorer',
            'team_id' => $home->id,
        ]);
        $http = Mockery::mock(HttpScraper::class);
        $http->shouldReceive('json')->once()->andReturn([
            'boxscore' => [
                'players' => [[
                    'team' => ['displayName' => 'Panthers'],
                    'statistics' => [[
                        'athletes' => [[
                            'athlete' => ['displayName' => 'Test Scorer'],
                            'statistics' => [[
                                'stats' => [[
                                    'name' => 'tries',
                                    'value' => 2,
                                ]],
                            ]],
                        ]],
                    ]],
                ]],
            ],
        ]);
        $job = new TestableFetchMatchResults;
        $fixture = [
            'homeTeam' => ['nickName' => 'Panthers'],
            'awayTeam' => ['nickName' => 'Broncos'],
        ];

        $this->assertSame(2, $job->processEspnForTest($http, '603402', $fixture, $round));
        $this->assertSame(0, $job->processEspnForTest($http, '603402', $fixture, $round));
        $this->assertSame(2, TryEvent::where('match_id', $match->id)->where('player_id', $player->id)->count());
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

    private function adjustment(int $round, array $deltas, array $patterns = []): WeightAdjustment
    {
        return WeightAdjustment::create([
            'season' => 2026,
            'after_round' => $round,
            'old_weights' => [],
            'new_weights' => [],
            'signal_deltas' => $deltas,
            'accuracy_before' => 50,
            'accuracy_after' => 51,
            'pattern_insights' => $patterns,
        ]);
    }
}

class TestableFetchMatchResults extends FetchMatchResults
{
    public function findForTest(string $home, string $away, int $roundId): ?Matchup
    {
        return $this->findMatch($home, $away, $roundId);
    }

    public function processEspnForTest(HttpScraper $http, string $eventId, array $fixture, Round $round): int
    {
        return $this->processEspnMatch($http, $eventId, $fixture, $round);
    }
}

class BacktestableSignalTuner extends SignalTuner
{
    public function backtestForTest(int $season, int $round, array $weights): array
    {
        return $this->backtestWeights($season, $round, $weights);
    }

    public function persistForTest(
        array $old,
        array $new,
        array $deltas,
        array $before,
        array $after,
        array $patterns,
    ): WeightAdjustment {
        return $this->persistAdjustment(
            2026,
            1,
            $old,
            $new,
            $deltas,
            37.5,
            [],
            $before,
            $after,
            true,
            ['patterns' => $patterns],
        );
    }
}
