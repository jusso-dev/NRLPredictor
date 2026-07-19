<?php

namespace Tests\Unit;

use App\Support\HttpScraper;
use App\Support\NrlDrawPage;
use Mockery;
use Tests\TestCase;

class NrlDrawPageTest extends TestCase
{
    public function test_it_rejects_an_empty_public_draw_payload(): void
    {
        $http = Mockery::mock(HttpScraper::class);
        $http->shouldReceive('json')->once()->andReturn(['events' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains no fixtures for round 20');

        (new NrlDrawPage)->fixtures($http, 2026, 20);
    }

    public function test_it_reads_match_fixtures_from_the_public_draw_payload(): void
    {
        $http = Mockery::mock(HttpScraper::class);
        $http->shouldReceive('json')->once()->andReturn([
            'events' => [[
                'id' => '603394',
                'season' => ['year' => 2026],
                'week' => ['number' => 20],
                'date' => '2026-07-16T09:50Z',
                'competitions' => [[
                    'status' => ['type' => ['state' => 'post']],
                    'venue' => ['fullName' => 'CommBank Stadium'],
                    'competitors' => [
                        ['homeAway' => 'home', 'score' => '12', 'team' => ['displayName' => 'Panthers']],
                        ['homeAway' => 'away', 'score' => '14', 'team' => ['displayName' => 'Broncos']],
                    ],
                ]],
            ]],
        ]);

        $fixtures = (new NrlDrawPage)->fixtures($http, 2026, 20);

        $this->assertCount(1, $fixtures);
        $this->assertSame('Panthers', data_get($fixtures, '0.homeTeam.nickName'));
        $this->assertSame('fulltime', data_get($fixtures, '0.matchState'));
    }
}
