<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsDataFetch;
use App\Models\Injury;
use App\Models\MatchTeamList;
use App\Models\Player;
use App\Models\Team;
use App\Support\HttpScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class FetchInjuryUpdates implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsDataFetch;

    public int $timeout = 180;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'fetch:injury-updates';
    }

    public function handle(HttpScraper $http): void
    {
        $this->startLog('nrl.com/casualty-ward');
        $records = 0;

        try {
            $response = $http->get('https://www.nrl.com/casualty-ward/data');
            if (! $response->successful()) {
                $this->completeLog(0);
                return;
            }

            $casualties = data_get($response->json(), 'casualties', []);

            // Track which players we've seen so we can resolve recovered ones
            $seenPlayerIds = [];

            foreach ($casualties as $entry) {
                $firstName = $entry['firstName'] ?? '';
                $lastName = $entry['lastName'] ?? '';
                $name = trim("{$firstName} {$lastName}");
                if ($name === '') {
                    continue;
                }

                $teamNickname = $entry['teamNickname'] ?? '';
                $injuryType = $entry['injury'] ?? 'Unknown';
                $expectedReturn = $entry['expectedReturn'] ?? '';

                // Determine status
                $status = 'out';
                $injuryLower = Str::lower($injuryType);
                if (str_contains($injuryLower, 'suspension')) {
                    $status = 'out';
                } elseif (str_contains($expectedReturn, 'TBC') || str_contains($expectedReturn, 'Indefinite')) {
                    $status = 'doubt';
                }

                // Resolve team
                $team = $this->resolveTeam($teamNickname);

                // Resolve or create player
                $slug = Str::slug($name);
                $player = Player::firstOrCreate(
                    ['nrl_slug' => $slug],
                    ['name' => $name, 'team_id' => $team?->id],
                );

                // Update team if player was orphaned
                if (! $player->team_id && $team) {
                    $player->update(['team_id' => $team->id]);
                }

                Injury::updateOrCreate(
                    ['player_id' => $player->id, 'resolved' => false],
                    [
                        'injury_type' => Str::limit($injuryType, 120, ''),
                        'status' => $status,
                        'notes' => $expectedReturn ? "Expected return: {$expectedReturn}" : $injuryType,
                        'fetched_at' => now(),
                    ],
                );

                $seenPlayerIds[] = $player->id;
                $records++;
            }

            // Resolve injuries for players no longer on the casualty list
            if (! empty($seenPlayerIds)) {
                Injury::where('resolved', false)
                    ->whereNotIn('player_id', $seenPlayerIds)
                    ->where('fetched_at', '<', now()->subHours(6))
                    ->update(['resolved' => true]);
            }

            $this->resolveRecoveredPlayers();
            $this->completeLog($records);
        } catch (Throwable $e) {
            $this->failLog($e);
            throw $e;
        }
    }

    protected function resolveTeam(?string $nickname): ?Team
    {
        if (! $nickname) {
            return null;
        }

        $slug = Str::slug($nickname);
        $aliases = [
            'tigers' => 'wests-tigers',
            'eagles' => 'sea-eagles',
        ];
        $slug = $aliases[$slug] ?? $slug;

        return Team::where('nrl_slug', $slug)->first()
            ?? Team::where('name', 'LIKE', "%{$nickname}%")->first()
            ?? Team::where('short_name', 'LIKE', "%{$nickname}%")->first();
    }

    protected function resolveRecoveredPlayers(): void
    {
        $activePlayerIds = MatchTeamList::whereIn('role', ['starting', 'interchange'])
            ->pluck('player_id')
            ->unique();

        Injury::whereIn('player_id', $activePlayerIds)
            ->where('resolved', false)
            ->update(['resolved' => true]);
    }
}
