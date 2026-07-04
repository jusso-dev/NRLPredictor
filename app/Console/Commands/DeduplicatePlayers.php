<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\TryEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Find and merge duplicate player records that have different nrl_slug values
 * but refer to the same person (e.g. "lehi-hopoate" vs "lehi-hopoate-2nd").
 */
class DeduplicatePlayers extends Command
{
    protected $signature = 'nrl:dedup-players {--dry-run : Show duplicates without merging}';
    protected $description = 'Find and merge duplicate player records.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $this->info($dryRun ? 'Dry run — showing duplicates:' : 'Merging duplicates...');

        // Find players with very similar names
        $players = Player::orderBy('name')->get();
        $merged = 0;

        $seen = [];
        foreach ($players as $player) {
            // Normalize name for comparison
            $normalized = $this->normalizeName($player->name);
            if (! $normalized) {
                continue;
            }

            if (isset($seen[$normalized])) {
                $canonical = $seen[$normalized];

                if ($dryRun) {
                    $this->line("  DUPE: #{$player->id} '{$player->name}' ({$player->nrl_slug}) → merge into #{$canonical->id} '{$canonical->name}'");
                } else {
                    $this->mergePlayers($canonical, $player);
                    $this->line("  Merged #{$player->id} '{$player->name}' → #{$canonical->id} '{$canonical->name}'");
                }
                $merged++;
            } else {
                $seen[$normalized] = $player;
            }
        }

        $this->info("Found {$merged} duplicates" . ($dryRun ? ' (dry run, no changes)' : ' and merged'));
        return self::SUCCESS;
    }

    protected function normalizeName(string $name): ?string
    {
        $name = Str::lower(trim($name));
        // Remove common suffixes that create false duplicates
        $name = preg_replace('/\s+\d*(st|nd|rd|th)?\s*$/', '', $name);
        $name = preg_replace('/\s+(jr|sr|ii|iii)\.?\s*$/i', '', $name);
        // Normalize whitespace and hyphens
        $name = preg_replace('/[\s\-]+/', ' ', $name);
        return $name ?: null;
    }

    protected function mergePlayers(Player $keep, Player $remove): void
    {
        DB::transaction(function () use ($keep, $remove) {
            // Tables without unique(player_id, x) constraints: straight move.
            TryEvent::where('player_id', $remove->id)->update(['player_id' => $keep->id]);
            DB::table('injuries')->where('player_id', $remove->id)->update(['player_id' => $keep->id]);
            DB::table('suspensions')->where('player_id', $remove->id)->update(['player_id' => $keep->id]);
            DB::table('odds_snapshots')->where('player_id', $remove->id)->update(['player_id' => $keep->id]);
            DB::table('milestone_events')->where('player_id', $remove->id)->update(['player_id' => $keep->id]);

            // Tables with a unique key on (player_id, x): only move rows that
            // won't collide with one the keeper already has. Collisions stay on
            // $remove and cascade-delete with it.
            $this->moveUnlessDuplicate('match_team_lists', $remove->id, $keep->id, 'match_id');
            $this->moveUnlessDuplicate('predictions', $remove->id, $keep->id, 'match_id');
            $this->moveUnlessDuplicate('player_venue_stats', $remove->id, $keep->id, 'venue');
            $this->moveUnlessDuplicate('player_opponent_stats', $remove->id, $keep->id, 'opponent_team_id');
            $this->moveUnlessDuplicate('player_club_histories', $remove->id, $keep->id, 'team_id');

            // Copy any useful data from $remove to $keep
            if (! $keep->position && $remove->position) {
                $keep->position = $remove->position;
            }
            if (! $keep->team_id && $remove->team_id) {
                $keep->team_id = $remove->team_id;
            }
            if (! $keep->nrl_player_id && $remove->nrl_player_id) {
                $nrlId = $remove->nrl_player_id;
                $remove->update(['nrl_player_id' => null]); // unique index
                $keep->nrl_player_id = $nrlId;
            }
            $keep->save();

            $remove->delete();
        });
    }

    protected function moveUnlessDuplicate(string $table, int $fromId, int $toId, string $scopeColumn): void
    {
        // Materialised first: MySQL can't subquery the table being updated.
        $taken = DB::table($table)->where('player_id', $toId)->pluck($scopeColumn)->all();

        DB::table($table)
            ->where('player_id', $fromId)
            ->when($taken !== [], fn ($q) => $q->whereNotIn($scopeColumn, $taken))
            ->update(['player_id' => $toId]);
    }
}
