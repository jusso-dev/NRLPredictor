<?php

namespace App\Services;

use App\Models\Matchup;
use App\Models\Prediction;
use Illuminate\Support\Facades\DB;

class PredictionScorer
{
    public function __construct(protected SignalCalculator $signals) {}

    /**
     * Score every player in a match, return the persisted top-15 predictions ordered by rank.
     */
    public function score(int $matchId): array
    {
        /** @var Matchup $match */
        $match = Matchup::with([
            'round',
            'homeTeam',
            'awayTeam',
            'teamLists.player.team',
            'teamLists.player.activeInjury',
            'teamLists.player.venueStats',
            'teamLists.player.opponentStats.opponent',
        ])->findOrFail($matchId);

        $rows = [];
        foreach ($match->teamLists as $listEntry) {
            $player = $listEntry->player;
            if (! $player) continue;

            $signals = $this->signals->calculate($player, $match);
            $raw = 0.0;
            $activeSignalCount = 0;
            foreach ($signals as $s) {
                $contrib = $s['weight'] * $s['strength'];
                $raw += $contrib;
                if ($contrib > 0) {
                    $activeSignalCount++;
                }
            }

            // Add a small tiebreaker based on number of active signals and season try rate
            // This prevents multiple players from scoring identically
            $tiebreaker = ($activeSignalCount * 0.01) + (($player->current_season_try_rate ?? 0) * 0.1);

            $rows[] = [
                'player_id' => $player->id,
                'raw' => $raw + $tiebreaker,
                'signals' => $signals,
            ];
        }

        if (empty($rows)) {
            return [];
        }

        $max = max(array_column($rows, 'raw')) ?: 1;
        usort($rows, fn ($a, $b) => $b['raw'] <=> $a['raw']);

        $top = array_slice($rows, 0, 15);
        $version = (Prediction::where('match_id', $match->id)->max('version') ?? 0) + 1;

        DB::transaction(function () use ($top, $match, $max, $version) {
            // Only delete predictions for matches that haven't completed yet
            if ($match->status !== 'completed') {
                Prediction::where('match_id', $match->id)->delete();
            } else {
                // For completed matches, skip if predictions already exist
                if (Prediction::where('match_id', $match->id)->exists()) {
                    return;
                }
            }
            $rank = 1;
            foreach ($top as $row) {
                $score = (int) round(($row['raw'] / $max) * 100);
                Prediction::create([
                    'match_id' => $match->id,
                    'player_id' => $row['player_id'],
                    'score' => max(0, min(100, $score)),
                    'rank_in_match' => $rank++,
                    'signals' => $row['signals'],
                    'version' => $version,
                ]);
            }
        });

        return Prediction::with('player.team')
            ->where('match_id', $match->id)
            ->orderBy('rank_in_match')
            ->get()
            ->all();
    }
}
