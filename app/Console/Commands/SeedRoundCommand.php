<?php

namespace App\Console\Commands;

use App\Models\Matchup;
use App\Models\Round;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SeedRoundCommand extends Command
{
    protected $signature = 'nrl:seed-round {--round=1} {--season=} {--start=}';
    protected $description = 'Seed a round with a hardcoded fixture list so the app has something to render.';

    /**
     * Canonical 17-club NRL fixture set for Round 1 2026. Slugs match nrl.com URL slugs.
     */
    protected array $teams = [
        ['broncos', 'Brisbane Broncos', 'BRI', '#800020', '#FFD700'],
        ['raiders', 'Canberra Raiders', 'CAN', '#00A651', '#FFFFFF'],
        ['bulldogs', 'Canterbury Bulldogs', 'CBY', '#005BAC', '#FFFFFF'],
        ['dolphins', 'Dolphins', 'DOL', '#CF1B41', '#0E1F3F'],
        ['sharks', 'Cronulla Sharks', 'CRO', '#00A6CE', '#FFFFFF'],
        ['titans', 'Gold Coast Titans', 'GLD', '#00A4E4', '#F3C300'],
        ['sea-eagles', 'Manly Sea Eagles', 'MAN', '#6F243C', '#FFFFFF'],
        ['storm', 'Melbourne Storm', 'MEL', '#4B2E83', '#FFFFFF'],
        ['knights', 'Newcastle Knights', 'NEW', '#EE3124', '#0055A4'],
        ['cowboys', 'North Queensland Cowboys', 'NQL', '#003A5D', '#FFD100'],
        ['eels', 'Parramatta Eels', 'PAR', '#005BAC', '#FFD100'],
        ['panthers', 'Penrith Panthers', 'PEN', '#231F20', '#8BC53F'],
        ['rabbitohs', 'South Sydney Rabbitohs', 'SOU', '#0F4028', '#B32025'],
        ['dragons', 'St George Illawarra Dragons', 'SGI', '#E1261C', '#FFFFFF'],
        ['roosters', 'Sydney Roosters', 'SYD', '#042B61', '#ED1A3A'],
        ['warriors', 'New Zealand Warriors', 'WAR', '#000000', '#BEE7F5'],
        ['wests-tigers', 'Wests Tigers', 'WST', '#F37021', '#000000'],
    ];

    protected array $fixtures = [
        ['broncos', 'storm', 'Suncorp Stadium'],
        ['panthers', 'rabbitohs', 'BlueBet Stadium'],
        ['roosters', 'sea-eagles', 'Allianz Stadium'],
        ['sharks', 'bulldogs', 'PointsBet Stadium'],
        ['cowboys', 'eels', 'Queensland Country Bank Stadium'],
        ['raiders', 'titans', 'GIO Stadium'],
        ['warriors', 'knights', 'Go Media Stadium'],
        ['dolphins', 'dragons', 'Kayo Stadium'],
    ];

    public function handle(): int
    {
        $season = (int) ($this->option('season') ?: now()->year);
        $roundNumber = (int) $this->option('round');

        // Default to this week so Round::current() picks the seeded round
        // instead of a stale past-dated one.
        $start = $this->option('start')
            ? Carbon::parse($this->option('start'))->startOfDay()
            : Carbon::now()->startOfWeek(Carbon::THURSDAY);
        if ($start->isPast() && $start->diffInDays(Carbon::today()) > 4) {
            $start = Carbon::now()->startOfWeek(Carbon::THURSDAY)->addWeek();
        }

        foreach ($this->teams as [$slug, $name, $short, $primary, $secondary]) {
            Team::updateOrCreate(
                ['nrl_slug' => $slug],
                ['name' => $name, 'short_name' => $short, 'color_primary' => $primary, 'color_secondary' => $secondary],
            );
        }

        Round::where('season', $season)->update(['is_current' => false]);
        $round = Round::updateOrCreate(
            ['season' => $season, 'round_number' => $roundNumber],
            [
                'start_date' => $start,
                'end_date' => $start->copy()->addDays(3),
                'is_current' => true,
            ],
        );

        $kickoff = $round->start_date->copy()->setTime(19, 50);
        foreach ($this->fixtures as [$homeSlug, $awaySlug, $venue]) {
            $home = Team::where('nrl_slug', $homeSlug)->first();
            $away = Team::where('nrl_slug', $awaySlug)->first();
            if (! $home || ! $away) continue;

            Matchup::updateOrCreate(
                [
                    'round_id' => $round->id,
                    'home_team_id' => $home->id,
                    'away_team_id' => $away->id,
                ],
                [
                    'venue' => $venue,
                    'kickoff_at' => $kickoff->copy(),
                    'status' => 'upcoming',
                ],
            );
            $kickoff->addHours(2);
        }

        $this->info("Seeded round {$roundNumber} for season {$season} with " . count($this->fixtures) . ' matches.');
        return self::SUCCESS;
    }
}
