<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\MatchTeamList;
use App\Models\Matchup;
use App\Models\Team;
use App\Models\TeamTryDistribution;
use App\Models\TryEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Builds the left/middle/right attack and concede percentages used by the
 * edge_mismatch signal. Without this job running there's no data in
 * team_try_distributions, so edge_mismatch always scores 0 despite carrying
 * a weight of 15.
 *
 * Side is derived from the try scorer's jersey number at the time of the match:
 *   2, 11 → left     3, 12 → right     4 → left-centre    5 → right-centre
 * Middle covers forwards and the spine.
 */
class ComputeTeamTryDistributions implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 120;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'compute:team-try-distributions';
    }

    public function handle(): void
    {
        $this->startLog('internal.try-distribution');
        $written = 0;

        foreach (Team::all() as $team) {
            foreach (['last_5', 'last_10', 'season'] as $period) {
                $row = $this->computeForTeam($team->id, $period);
                if ($row === null) {
                    continue;
                }

                TeamTryDistribution::updateOrCreate(
                    ['team_id' => $team->id, 'period' => $period],
                    $row + ['computed_at' => now()],
                );
                $written++;
            }
        }

        Log::info("ComputeTeamTryDistributions: wrote {$written} rows");
        $this->completeLog($written);
    }

    protected function computeForTeam(int $teamId, string $period): ?array
    {
        $limit = match ($period) {
            'last_5' => 5,
            'last_10' => 10,
            default => null,
        };

        $matchesQuery = Matchup::where('status', 'completed')
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->orderByDesc('kickoff_at');

        if ($limit) {
            $matchesQuery->limit($limit);
        }

        $matchIds = $matchesQuery->pluck('id');
        if ($matchIds->isEmpty()) {
            return null;
        }

        // Build two indexes so we can resolve a try scorer's side even when
        // the try_events.player_id points at a duplicate record created by
        // the match-centre scraper while match_team_lists points at the
        // team-list scraper's copy.
        //
        // Primary: (match_id, player_id) → side
        // Fallback: (match_id, nrl_slug) → side, in case player_ids diverged.
        $lists = MatchTeamList::whereIn('match_id', $matchIds)
            ->join('players', 'players.id', '=', 'match_team_lists.player_id')
            ->get(['match_team_lists.match_id', 'match_team_lists.player_id', 'match_team_lists.position_number', 'players.nrl_slug']);

        $byId = [];
        $bySlug = [];
        foreach ($lists as $l) {
            $side = $this->sideFromPositionNumber($l->position_number);
            $byId[$l->match_id . ':' . $l->player_id] = $side;
            if ($l->nrl_slug) {
                $bySlug[$l->match_id . ':' . $l->nrl_slug] = $side;
            }
        }

        $attack = ['left' => 0, 'middle' => 0, 'right' => 0];
        $concede = ['left' => 0, 'middle' => 0, 'right' => 0];

        $tries = TryEvent::with('player:id,team_id,nrl_slug,position')
            ->whereIn('match_id', $matchIds)
            ->get();

        foreach ($tries as $t) {
            $scorerTeamId = $t->player?->team_id;
            $side = $byId[$t->match_id . ':' . $t->player_id] ?? null;

            if ($side === null && $t->player?->nrl_slug) {
                $side = $bySlug[$t->match_id . ':' . $t->player->nrl_slug] ?? null;
            }

            // Final fallback: infer side from the player's listed position,
            // using player_id parity as a deterministic left/right split
            // when MTL data can't be matched.
            if ($side === null) {
                $side = $this->sideFromPlayerPosition($t->player?->position, $t->player_id);
            }

            if ($scorerTeamId === $teamId) {
                $attack[$side]++;
            } elseif ($scorerTeamId !== null) {
                $concede[$side]++;
            }
        }

        $attackTotal = array_sum($attack);
        $concedeTotal = array_sum($concede);

        if ($attackTotal === 0 && $concedeTotal === 0) {
            return null;
        }

        return [
            'attack_left_pct' => $this->pct($attack['left'], $attackTotal),
            'attack_middle_pct' => $this->pct($attack['middle'], $attackTotal),
            'attack_right_pct' => $this->pct($attack['right'], $attackTotal),
            'concede_left_pct' => $this->pct($concede['left'], $concedeTotal),
            'concede_middle_pct' => $this->pct($concede['middle'], $concedeTotal),
            'concede_right_pct' => $this->pct($concede['right'], $concedeTotal),
        ];
    }

    protected function sideFromPlayerPosition(?string $position, ?int $playerId): string
    {
        // player_id parity is deterministic, which matters because this job
        // runs repeatedly — a random split would produce different edge
        // percentages every run.
        if (in_array($position, ['winger', 'centre', 'second-row'])) {
            return $playerId !== null && ($playerId % 2 === 0) ? 'left' : 'right';
        }
        return 'middle';
    }

    protected function sideFromPositionNumber(?int $pos): string
    {
        return match ($pos) {
            2, 4, 11 => 'left',
            3, 5, 12 => 'right',
            default => 'middle',
        };
    }

    protected function pct(int $count, int $total): int
    {
        if ($total === 0) {
            return 0;
        }
        return (int) round(($count / $total) * 100);
    }
}
