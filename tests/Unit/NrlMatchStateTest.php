<?php

namespace Tests\Unit;

use App\Support\NrlMatchState;
use PHPUnit\Framework\TestCase;

class NrlMatchStateTest extends TestCase
{
    public function test_completed_tokens(): void
    {
        foreach (['FullTime', 'fulltime', 'Post', 'PostMatch', 'Final', 'Ended'] as $token) {
            $this->assertSame('completed', NrlMatchState::toStatus($token), "token: {$token}");
        }
    }

    public function test_live_tokens(): void
    {
        foreach (['Live', 'InProgress', 'Ongoing', 'Current', 'FirstHalf', 'HalfTime', 'SecondHalf'] as $token) {
            $this->assertSame('live', NrlMatchState::toStatus($token), "token: {$token}");
        }
    }

    public function test_upcoming_tokens(): void
    {
        foreach (['Upcoming', 'Pre', 'PreMatch', 'NotStarted', 'Scheduled'] as $token) {
            $this->assertSame('upcoming', NrlMatchState::toStatus($token), "token: {$token}");
        }
    }

    public function test_unknown_tokens_return_null_so_callers_choose_the_fallback(): void
    {
        $this->assertNull(NrlMatchState::toStatus('SomethingNew'));
        $this->assertNull(NrlMatchState::toStatus(null));
        $this->assertNull(NrlMatchState::toStatus(''));
    }
}
