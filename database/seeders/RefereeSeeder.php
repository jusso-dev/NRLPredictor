<?php

namespace Database\Seeders;

use App\Models\Referee;
use Illuminate\Database\Seeder;

/**
 * Seed referee PAA (Penalties Above Average) data.
 * Source: Rugby League Eye Test / NRL officiating data.
 * Updated pre-season; hand-curated.
 */
class RefereeSeeder extends Seeder
{
    public function run(): void
    {
        $referees = [
            ['name' => 'Peter Gough',     'paa' => 0.77,  'sraa' => 0.12, 'avg_penalties_per_game' => 12.5, 'games_officiated' => 280],
            ['name' => 'Grant Atkins',    'paa' => 0.37,  'sraa' => 0.05, 'avg_penalties_per_game' => 12.1, 'games_officiated' => 320],
            ['name' => 'Chris Butler',    'paa' => 0.26,  'sraa' => 0.08, 'avg_penalties_per_game' => 12.0, 'games_officiated' => 200],
            ['name' => 'Todd Smith',      'paa' => 0.18,  'sraa' => 0.03, 'avg_penalties_per_game' => 11.9, 'games_officiated' => 180],
            ['name' => 'Ben Cummins',     'paa' => 0.10,  'sraa' => 0.02, 'avg_penalties_per_game' => 11.8, 'games_officiated' => 350],
            ['name' => 'Ashley Klein',    'paa' => -0.13, 'sraa' => -0.02, 'avg_penalties_per_game' => 11.6, 'games_officiated' => 310],
            ['name' => 'Adam Gee',        'paa' => 0.00,  'sraa' => 0.00, 'avg_penalties_per_game' => 11.7, 'games_officiated' => 250],
            ['name' => 'Liam Kennedy',    'paa' => -0.05, 'sraa' => 0.01, 'avg_penalties_per_game' => 11.7, 'games_officiated' => 120],
            ['name' => 'Darian Furner',   'paa' => -0.22, 'sraa' => -0.05, 'avg_penalties_per_game' => 11.5, 'games_officiated' => 90],
            ['name' => 'Wyatt Raymond',   'paa' => -1.19, 'sraa' => -0.15, 'avg_penalties_per_game' => 10.5, 'games_officiated' => 60],
            ['name' => 'Ziggy Przeklasa-Adamski', 'paa' => -0.30, 'sraa' => -0.03, 'avg_penalties_per_game' => 11.4, 'games_officiated' => 70],
            ['name' => 'Kasey Badger',    'paa' => 0.15,  'sraa' => 0.06, 'avg_penalties_per_game' => 11.9, 'games_officiated' => 110],
        ];

        foreach ($referees as $ref) {
            Referee::updateOrCreate(['name' => $ref['name']], $ref);
        }
    }
}
