<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $teams = [
            ['nrl_slug' => 'broncos',       'name' => 'Brisbane Broncos',           'short_name' => 'Broncos',      'color_primary' => '#6e0027', 'color_secondary' => '#fbbf15'],
            ['nrl_slug' => 'bulldogs',       'name' => 'Canterbury-Bankstown Bulldogs', 'short_name' => 'Bulldogs',  'color_primary' => '#003cb4', 'color_secondary' => '#ffffff'],
            ['nrl_slug' => 'raiders',        'name' => 'Canberra Raiders',          'short_name' => 'Raiders',      'color_primary' => '#00703c', 'color_secondary' => '#ffffff'],
            ['nrl_slug' => 'cowboys',        'name' => 'North Queensland Cowboys',  'short_name' => 'Cowboys',      'color_primary' => '#002b5c', 'color_secondary' => '#ffc72c'],
            ['nrl_slug' => 'dolphins',       'name' => 'Redcliffe Dolphins',        'short_name' => 'Dolphins',     'color_primary' => '#e4002b', 'color_secondary' => '#d4a843'],
            ['nrl_slug' => 'dragons',        'name' => 'St George Illawarra Dragons', 'short_name' => 'Dragons',    'color_primary' => '#e4002b', 'color_secondary' => '#ffffff'],
            ['nrl_slug' => 'eels',           'name' => 'Parramatta Eels',           'short_name' => 'Eels',         'color_primary' => '#002b5c', 'color_secondary' => '#ffc72c'],
            ['nrl_slug' => 'knights',        'name' => 'Newcastle Knights',         'short_name' => 'Knights',      'color_primary' => '#003cb4', 'color_secondary' => '#e4002b'],
            ['nrl_slug' => 'panthers',       'name' => 'Penrith Panthers',          'short_name' => 'Panthers',     'color_primary' => '#2a2a2a', 'color_secondary' => '#ff0050'],
            ['nrl_slug' => 'rabbitohs',      'name' => 'South Sydney Rabbitohs',    'short_name' => 'Rabbitohs',    'color_primary' => '#003b28', 'color_secondary' => '#e4002b'],
            ['nrl_slug' => 'roosters',       'name' => 'Sydney Roosters',           'short_name' => 'Roosters',     'color_primary' => '#003cb4', 'color_secondary' => '#e4002b'],
            ['nrl_slug' => 'sea-eagles',     'name' => 'Manly Warringah Sea Eagles', 'short_name' => 'Sea Eagles',  'color_primary' => '#6e0027', 'color_secondary' => '#ffffff'],
            ['nrl_slug' => 'sharks',         'name' => 'Cronulla-Sutherland Sharks', 'short_name' => 'Sharks',      'color_primary' => '#00b3e3', 'color_secondary' => '#2a2a2a'],
            ['nrl_slug' => 'storm',          'name' => 'Melbourne Storm',           'short_name' => 'Storm',        'color_primary' => '#582c83', 'color_secondary' => '#ffc72c'],
            ['nrl_slug' => 'titans',         'name' => 'Gold Coast Titans',         'short_name' => 'Titans',       'color_primary' => '#003e7e', 'color_secondary' => '#ffc72c'],
            ['nrl_slug' => 'warriors',       'name' => 'New Zealand Warriors',      'short_name' => 'Warriors',     'color_primary' => '#2a2a2a', 'color_secondary' => '#8c8c8c'],
            ['nrl_slug' => 'wests-tigers',   'name' => 'Wests Tigers',              'short_name' => 'Wests Tigers', 'color_primary' => '#f47920', 'color_secondary' => '#2a2a2a'],
        ];

        foreach ($teams as $team) {
            Team::updateOrCreate(['nrl_slug' => $team['nrl_slug']], $team);
        }
    }
}
