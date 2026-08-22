<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SignalCalculator;
use App\Services\WinPredictor;
use Illuminate\Http\JsonResponse;

class MethodologyController extends Controller
{
    public function index(): JsonResponse
    {
        $tryWeights = app(SignalCalculator::class)->weights();

        return response()->json([
            'try_scorer_prediction' => [
                'description' => sprintf('Each player in the team list is scored against %d weighted signals. Completed rounds tune these weights behind non-regression guardrails. Scores are normalized to 0-100 per match.', count($tryWeights)),
                'max_raw_score' => array_sum($tryWeights),
                'signals' => collect($tryWeights)->map(fn ($w, $type) => [
                    'type' => $type,
                    'weight' => $w,
                    'pct_of_total' => round(($w / array_sum($tryWeights)) * 100, 1),
                ])->sortByDesc('weight')->values()->all(),
                'position_weights' => SignalCalculator::POSITION_ADVANTAGE,
                'score_tiers' => [
                    ['range' => '80-100', 'label' => 'Elite tier'],
                    ['range' => '65-79', 'label' => 'Strong pick'],
                    ['range' => '50-64', 'label' => 'Decent chance'],
                    ['range' => '0-49', 'label' => 'Outside shot'],
                ],
            ],
            'win_prediction' => [
                'description' => sprintf('Each match gets home/away win probabilities from %d signals scored independently for each team.', count(WinPredictor::WEIGHTS)),
                'max_raw_score' => array_sum(WinPredictor::WEIGHTS),
                'signals' => collect(WinPredictor::WEIGHTS)->map(fn ($w, $type) => [
                    'type' => $type,
                    'weight' => $w,
                    'pct_of_total' => round(($w / array_sum(WinPredictor::WEIGHTS)) * 100, 1),
                ])->sortByDesc('weight')->values()->all(),
            ],
            'multi_bet' => [
                'description' => 'Combines match winner and try scorer legs. Reserves ~35% of slots for winners. Max 2 legs per match.',
                'risk_profiles' => [
                    'safe' => 'Fewer legs, higher individual probability, conservative picks only',
                    'balanced' => 'Mix of likely outcomes with decent return potential',
                    'value' => 'More legs, includes underdogs and tighter contests',
                ],
            ],
            'data_sources' => [
                ['name' => 'Fixtures & Draw', 'url' => 'nrl.com/draw/data', 'refresh' => 'Daily'],
                ['name' => 'Team Lists', 'url' => 'nrl.com team pages', 'refresh' => 'Every 30 min'],
                ['name' => 'Player Stats', 'url' => 'nrl.com player profiles', 'refresh' => 'Every 2 hours'],
                ['name' => 'Injuries', 'url' => 'nrl.com casualty ward', 'refresh' => 'Every 30 min'],
                ['name' => 'Articles', 'url' => 'nrl.com news feed', 'refresh' => 'Every 6 hours'],
                ['name' => 'Results', 'url' => 'site.api.espn.com event summaries', 'refresh' => 'After completed rounds'],
                ['name' => 'Market Odds', 'url' => 'the-odds-api.com', 'refresh' => 'Every 4 hours'],
                ['name' => 'Weather', 'url' => 'open-meteo.com', 'refresh' => 'Daily + on sync'],
            ],
            'ai_review' => [
                'description' => 'Optional AI review may adjust scores by up to +/-15 points from a preserved statistical baseline using team lists, injuries, venue history, news, prior-round signal performance, and discovered compound patterns. No change is a valid reviewed outcome.',
                'model' => config('services.codex.model') ?: 'codex-cli (config default)',
                'measurement' => 'Completed AI-reviewed matches compare final top-five accuracy with the untouched statistical top five.',
            ],
            'self_improvement' => [
                'validation_window_rounds' => 8,
                'candidate_guardrail' => 'A weight update must not reduce either top-five accuracy or NDCG.',
                'pattern_activation' => [
                    'minimum_sample' => 12,
                    'minimum_rounds' => 3,
                    'minimum_absolute_lift_points' => 5,
                    'minimum_direction_consistency' => 0.67,
                ],
                'leakage_control' => 'Weights, patterns, and AI learning evidence are read only from rounds before the target match.',
            ],
        ]);
    }
}
