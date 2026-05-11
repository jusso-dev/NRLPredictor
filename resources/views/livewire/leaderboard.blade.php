<div class="space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <div class="lbl mb-2">Season</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Try scorer leaderboard</h1>
        </div>
        <label class="flex w-full items-center gap-2 text-sm md:w-auto">
            <span class="lbl hidden sm:inline">Search</span>
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="Player name…"
                   class="input w-full md:w-64">
        </label>
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-bone-400">
                <tr class="text-left">
                    <th class="px-3 py-3 font-medium uppercase tracking-wider text-[11px] sm:px-4">#</th>
                    <th class="px-3 py-3 font-medium sm:px-4">
                        <button wire:click="sortBy('name')" class="uppercase tracking-wider text-[11px]">
                            Player {!! $sort === 'name' ? ($direction === 'asc' ? '↑' : '↓') : '' !!}
                        </button>
                    </th>
                    <th class="hidden px-4 py-3 font-medium uppercase tracking-wider text-[11px] sm:table-cell">Team</th>
                    <th class="px-3 py-3 font-medium text-right sm:px-4">
                        <button wire:click="sortBy('current_season_tries')" class="uppercase tracking-wider text-[11px]">
                            Tries {!! $sort === 'current_season_tries' ? ($direction === 'asc' ? '↑' : '↓') : '' !!}
                        </button>
                    </th>
                    <th class="hidden px-4 py-3 font-medium text-right md:table-cell">
                        <button wire:click="sortBy('current_season_games')" class="uppercase tracking-wider text-[11px]">
                            Games {!! $sort === 'current_season_games' ? ($direction === 'asc' ? '↑' : '↓') : '' !!}
                        </button>
                    </th>
                    <th class="hidden px-4 py-3 font-medium text-right md:table-cell">
                        <button wire:click="sortBy('current_season_try_rate')" class="uppercase tracking-wider text-[11px]">
                            Rate {!! $sort === 'current_season_try_rate' ? ($direction === 'asc' ? '↑' : '↓') : '' !!}
                        </button>
                    </th>
                    <th class="hidden px-4 py-3 font-medium uppercase tracking-wider text-[11px] text-right lg:table-cell">L3 form</th>
                    <th class="px-3 py-3 font-medium uppercase tracking-wider text-[11px] text-right sm:px-4">Next</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-ink-700">
                @foreach ($players as $i => $player)
                    @php($next = $nextScores[$player->id] ?? null)
                    <tr class="hover:bg-ink-800">
                        <td class="px-3 py-3 font-mono text-bone-400 sm:px-4">{{ $i + 1 }}</td>
                        <td class="px-3 py-3 sm:px-4">
                            <div class="text-bone-50">{{ $player->name }}</div>
                            <div class="text-[11px] text-bone-400 sm:hidden">{{ $player->team?->short_name ?? $player->team?->name ?? '—' }}</div>
                        </td>
                        <td class="hidden px-4 py-3 text-bone-200 sm:table-cell">{{ $player->team?->short_name ?? $player->team?->name ?? '—' }}</td>
                        <td class="px-3 py-3 text-right font-mono text-gold-500 dark:text-gold-400 sm:px-4">{{ $player->current_season_tries }}</td>
                        <td class="hidden px-4 py-3 text-right font-mono text-bone-200 md:table-cell">{{ $player->current_season_games }}</td>
                        <td class="hidden px-4 py-3 text-right font-mono text-bone-200 md:table-cell">{{ number_format($player->current_season_try_rate, 2) }}</td>
                        <td class="hidden px-4 py-3 text-right lg:table-cell">
                            <div class="inline-flex gap-0.5">
                                @for ($g = 0; $g < 3; $g++)
                                    <span class="h-2 w-2 rounded-full {{ $g < min(3, intdiv($player->current_season_tries, max(1, $player->current_season_games))) ? 'bg-signal-red' : 'bg-ink-700' }}"></span>
                                @endfor
                            </div>
                        </td>
                        <td class="px-3 py-3 text-right sm:px-4">
                            @if ($next)
                                <span class="font-mono text-bone-50">{{ $next->score }}</span>
                            @else
                                <span class="text-bone-500">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                @if ($players->isEmpty())
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-bone-400">No players match that filter.</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
