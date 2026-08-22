<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\MilestoneEvent;

class MilestoneDetector
{
    protected const AUTOMATIC_TYPES = ['game_milestone', 'try_milestone'];

    public function syncForMatch(Matchup $match): int
    {
        $match->loadMissing('teamLists.player');
        $entries = $match->teamLists
            ->whereIn('role', ['starting', 'interchange'])
            ->filter(fn ($entry) => $entry->player);

        // Team lists can arrive after metadata jobs. Keep prior detections until
        // a real 1-17 list exists, then reconcile it on the next scheduled run.
        if ($entries->isEmpty()) {
            return MilestoneEvent::where('match_id', $match->id)
                ->whereIn('type', self::AUTOMATIC_TYPES)
                ->count();
        }

        $activePlayerIds = $entries->pluck('player_id');
        MilestoneEvent::where('match_id', $match->id)
            ->whereIn('type', self::AUTOMATIC_TYPES)
            ->whereNotIn('player_id', $activePlayerIds)
            ->delete();

        foreach ($entries as $entry) {
            $player = $entry->player;
            $expectedTypes = [];
            $nextGame = (int) $player->career_games + 1;

            if (in_array($nextGame, config('nrl-weights.game_milestones', [50, 100, 150, 200, 250, 300, 350]), true)) {
                $expectedTypes[] = 'game_milestone';
                $this->store($match, $player->id, 'game_milestone', (int) $player->career_games, $nextGame, 1);
            }

            $careerTries = (int) $player->career_tries;
            $tryDistance = (int) config('nrl-weights.milestone_try_distance', 3);
            $nextTryTarget = collect(config('nrl-weights.try_milestones', [50, 100, 150, 200, 212, 250]))
                ->first(fn (int $target) => $target > $careerTries && $target - $careerTries <= $tryDistance);
            if ($nextTryTarget) {
                $expectedTypes[] = 'try_milestone';
                $this->store(
                    $match,
                    $player->id,
                    'try_milestone',
                    $careerTries,
                    $nextTryTarget,
                    $nextTryTarget - $careerTries,
                );
            }

            $staleEvents = MilestoneEvent::where('match_id', $match->id)
                ->where('player_id', $player->id)
                ->whereIn('type', self::AUTOMATIC_TYPES);
            if ($expectedTypes) {
                $staleEvents->whereNotIn('type', $expectedTypes);
            }
            $staleEvents->delete();
        }

        return MilestoneEvent::where('match_id', $match->id)
            ->whereIn('type', self::AUTOMATIC_TYPES)
            ->count();
    }

    protected function store(
        Matchup $match,
        int $playerId,
        string $type,
        int $current,
        int $target,
        int $distance,
    ): void {
        $event = MilestoneEvent::updateOrCreate(
            ['match_id' => $match->id, 'player_id' => $playerId, 'type' => $type],
            ['current_count' => $current, 'target_count' => $target, 'distance' => $distance],
        );

        MilestoneEvent::where('match_id', $match->id)
            ->where('player_id', $playerId)
            ->where('type', $type)
            ->whereKeyNot($event->id)
            ->delete();
    }
}
