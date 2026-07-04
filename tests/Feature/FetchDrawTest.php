<?php

namespace Tests\Feature;

use App\Jobs\FetchDraw;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class FetchDrawTest extends TestCase
{
    use RefreshDatabase;

    /** Every nickname nrl.com's draw feed uses must resolve to a seeded team. */
    public function test_all_current_nrl_nicknames_resolve(): void
    {
        $this->seed(TeamSeeder::class);

        $nicknames = [
            'Broncos', 'Raiders', 'Bulldogs', 'Sharks', 'Dolphins', 'Titans',
            'Sea Eagles', 'Storm', 'Knights', 'Cowboys', 'Eels', 'Panthers',
            'Rabbitohs', 'Dragons', 'Roosters', 'Warriors', 'Wests Tigers',
            // Aliases the feed has used
            'Tigers', 'Eagles',
        ];

        $resolve = new ReflectionMethod(FetchDraw::class, 'resolveTeam');

        foreach ($nicknames as $nickname) {
            $team = $resolve->invoke(new FetchDraw, $nickname);
            $this->assertNotNull($team, "nickname failed to resolve: {$nickname}");
        }
    }

    public function test_unknown_or_missing_nickname_returns_null(): void
    {
        $this->seed(TeamSeeder::class);
        $resolve = new ReflectionMethod(FetchDraw::class, 'resolveTeam');

        $this->assertNull($resolve->invoke(new FetchDraw, 'Bears'));
        $this->assertNull($resolve->invoke(new FetchDraw, null));
    }

    /** Kickoffs are Sydney wall-clock; storage must be app-timezone correct. */
    public function test_kickoff_parse_normalises_sydney_time_into_app_timezone(): void
    {
        $parse = new ReflectionMethod(FetchDraw::class, 'parseKickoff');

        $kickoff = $parse->invoke(new FetchDraw, '2026-07-04T19:50:00');

        $this->assertSame(config('app.timezone'), $kickoff->timezone->getName());
        // 19:50 AEST == 09:50 UTC regardless of what the app TZ is set to.
        $this->assertSame('2026-07-04 09:50', $kickoff->copy()->setTimezone('UTC')->format('Y-m-d H:i'));
    }
}
