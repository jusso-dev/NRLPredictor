<?php

namespace Tests\Unit;

use App\Services\CalibrationGrader;
use App\Services\ModelAlertChecker;
use App\Services\SignalTuner;
use Mockery;
use Tests\TestCase;

class SignalTunerTest extends TestCase
{
    public function test_weight_updates_are_fractional_relative_and_normalised(): void
    {
        config()->set('nrl-weights.try_scorer', [
            'good_signal' => 20,
            'bad_signal' => 10,
        ]);

        $weights = $this->tuner()->adjustForTest(
            ['good_signal' => 30, 'bad_signal' => 30],
            ['good_signal' => 0.10, 'bad_signal' => -0.10],
        );

        $this->assertGreaterThan($weights['bad_signal'], $weights['good_signal']);
        $this->assertNotEquals(round($weights['good_signal']), $weights['good_signal']);
        $this->assertEqualsWithDelta(30, array_sum($weights), 0.01);
        $this->assertLessThan(30, $weights['good_signal']);
    }

    public function test_validation_rejects_any_candidate_that_degrades_known_results(): void
    {
        $tuner = $this->tuner();
        $before = ['matches' => 8, 'top5_accuracy' => 50.0, 'ndcg' => 0.72];

        $this->assertTrue($tuner->passesForTest($before, [
            'matches' => 8,
            'top5_accuracy' => 50.0,
            'ndcg' => 0.72,
        ]));
        $this->assertFalse($tuner->passesForTest($before, [
            'matches' => 8,
            'top5_accuracy' => 49.9,
            'ndcg' => 0.74,
        ]));
        $this->assertFalse($tuner->passesForTest($before, [
            'matches' => 8,
            'top5_accuracy' => 51.0,
            'ndcg' => 0.71,
        ]));
        $this->assertFalse($tuner->passesForTest($before, [
            'matches' => 8,
            'top5_accuracy' => 50.0,
            'ndcg' => 0.7199,
        ]));
    }

    private function tuner(): TestableSignalTuner
    {
        return new TestableSignalTuner(
            Mockery::mock(CalibrationGrader::class),
            Mockery::mock(ModelAlertChecker::class),
        );
    }
}

class TestableSignalTuner extends SignalTuner
{
    public function adjustForTest(array $weights, array $deltas): array
    {
        return $this->adjustWeights($weights, $deltas);
    }

    public function passesForTest(array $before, array $after): bool
    {
        return $this->candidatePassesValidation($before, $after);
    }
}
