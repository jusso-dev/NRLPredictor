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
        return response()->json([
            'try_scorer_prediction' => [
                'description' => 'Each player in the team list is scored against 11 weighted signals. Scores are normalized to 0-100 per match.',
                'max_raw_score' => array_sum(SignalCalculator::WEIGHTS),
                'signals' => collect(SignalCalculator::WEIGHTS)->map(fn ($w, $type) => [
                    'type' => $type,
                    'weight' => $w,
                    'pct_of_total' => round(($w / array_sum(SignalCalculator::WEIGHTS)) * 100, 1),
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
                'description' => 'Each match gets home/away win probabilities from 7 signals scored independently for each team.',
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
                ['name' => 'Live Scores', 'url' => 'nrl.com live data', 'refresh' => 'Every 5 min (when live)'],
            ],
            'ai_review' => [
                'description' => 'Optional AI review adjusts scores by up to +/-15 points using team lists, injuries, venue history, and news context.',
                'model' => env('CODEX_MODEL') ?: 'codex-cli (config default)',
            ],
        ]);
    }
}
