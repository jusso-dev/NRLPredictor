<div class="space-y-8">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <a href="{{ route('dashboard') }}" class="lbl mb-3 inline-block text-bone-400 hover:text-gold-400">
                ← Back to round
            </a>
            <div class="flex items-center gap-3">
                <span class="chip
                    {{ $match->status === 'live' ? 'chip-red animate-pulse' : '' }}
                    {{ $match->status === 'completed' ? 'chip-muted' : '' }}
                    {{ $match->status === 'upcoming' ? 'chip-gold' : '' }}">
                    {{ $match->statusBadge() }}
                </span>
                <span class="text-sm text-bone-400">
                    {{ optional($match->kickoff_at)?->format('l j F · H:i') }} AEST · {{ $match->venue ?? 'Venue TBA' }}
                </span>
            </div>
            <h1 class="h-display mt-2 text-2xl text-bone-50 sm:text-3xl md:text-4xl">
                {{ $match->homeTeam->name }}
                <span class="text-bone-400">v</span>
                {{ $match->awayTeam->name }}
            </h1>
            @if ($match->home_score !== null && $match->away_score !== null)
                <div class="mt-2 font-mono text-xl text-gold-500 dark:text-gold-400 sm:text-2xl">
                    {{ $match->home_score }} — {{ $match->away_score }}
                </div>
            @endif
        </div>
        <button wire:click="reanalyse" wire:loading.attr="disabled" class="btn-primary w-full sm:w-auto">
            <span wire:loading.remove wire:target="reanalyse">Re-analyse with AI</span>
            <span wire:loading wire:target="reanalyse">Analysing…</span>
        </button>
    </div>

    @if ($tryEvents->isNotEmpty())
        <div class="card p-5">
            <div class="lbl mb-3">{{ $match->status === 'live' ? 'Try scorers (live)' : 'Try scorers' }}</div>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach (['home' => $match->homeTeam, 'away' => $match->awayTeam] as $side => $team)
                    @php($teamId = $side === 'home' ? $match->home_team_id : $match->away_team_id)
                    @php($teamTries = $tryEvents->filter(fn ($e) => $e->player?->team_id === $teamId))
                    <div>
                        <div class="mb-2 text-sm font-medium text-bone-200">{{ $team->short_name ?? $team->name }}</div>
                        @if ($teamTries->isEmpty())
                            <div class="text-xs text-bone-500">No tries</div>
                        @else
                            <div class="space-y-1.5">
                                @foreach ($teamTries->groupBy('player_id') as $playerId => $events)
                                    @php($scorer = $events->first()->player)
                                    <div class="flex items-center justify-between rounded bg-ink-800 px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm text-bone-50">{{ $scorer?->name }}</span>
                                            @if ($events->count() > 1)
                                                <span class="chip chip-gold text-[10px]">{{ $events->count() }}x</span>
                                            @endif
                                        </div>
                                        <div class="flex gap-1.5 text-xs text-bone-400">
                                            @foreach ($events->sortBy('minute') as $event)
                                                @if ($event->minute)
                                                    <span>{{ $event->minute }}'</span>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($match->home_win_pct && $match->away_win_pct)
        <div class="card p-5">
            <div class="lbl mb-4">Win prediction</div>
            <div class="flex items-center gap-4">
                <div class="text-right flex-1">
                    <div class="h-display text-lg {{ $match->predicted_winner_id === $match->home_team_id ? 'text-gold-400' : 'text-bone-200' }}">
                        {{ $match->homeTeam->short_name ?? $match->homeTeam->name }}
                    </div>
                    <div class="font-mono text-2xl {{ $match->predicted_winner_id === $match->home_team_id ? 'text-gold-400' : 'text-bone-400' }}">
                        {{ $match->home_win_pct }}%
                    </div>
                </div>
                <div class="w-full max-w-md">
                    <div class="flex h-3 overflow-hidden rounded-full">
                        <div class="bg-gold-500 transition-all" style="width: {{ $match->home_win_pct }}%"></div>
                        <div class="bg-bone-600 transition-all" style="width: {{ $match->away_win_pct }}%"></div>
                    </div>
                </div>
                <div class="flex-1">
                    <div class="h-display text-lg {{ $match->predicted_winner_id === $match->away_team_id ? 'text-gold-400' : 'text-bone-200' }}">
                        {{ $match->awayTeam->short_name ?? $match->awayTeam->name }}
                    </div>
                    <div class="font-mono text-2xl {{ $match->predicted_winner_id === $match->away_team_id ? 'text-gold-400' : 'text-bone-400' }}">
                        {{ $match->away_win_pct }}%
                    </div>
                </div>
            </div>

            @if ($match->win_signals)
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    @foreach (['home' => $match->homeTeam, 'away' => $match->awayTeam] as $side => $team)
                        <div>
                            <div class="lbl mb-2 {{ $match->predicted_winner_id === $team->id ? 'text-gold-400' : '' }}">
                                {{ $team->short_name ?? $team->name }} signals
                            </div>
                            <div class="space-y-1.5">
                                @foreach (collect($match->win_signals)->where('side', $side) as $signal)
                                    <div class="flex items-center gap-3 text-xs">
                                        <span class="w-32 truncate text-bone-400">{{ str_replace('_', ' ', $signal['type']) }}</span>
                                        <div class="score-bar flex-1">
                                            <div class="score-bar-fill {{ $signal['strength'] >= 0.6 ? 'bg-gold-500/70' : 'bg-bone-500/50' }}"
                                                 style="width: {{ round(($signal['strength'] ?? 0) * 100) }}%"></div>
                                        </div>
                                    </div>
                                    <div class="ml-32 pl-3 text-[11px] text-bone-500">{{ $signal['description'] ?? '' }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach (['home' => $match->homeTeam, 'away' => $match->awayTeam] as $side => $team)
            <div class="card border-signal-red/30 bg-signal-red/[0.06] p-5">
                <div class="lbl mb-3 text-signal-red/80">{{ $team->name }} — injuries</div>
                @forelse ($injuries[$side] as $player)
                    <div class="flex items-center justify-between py-1.5 text-sm">
                        <span class="text-bone-100">{{ $player->name }}</span>
                        <span class="text-xs text-bone-400">
                            {{ $player->activeInjury?->injury_type }}
                            <span class="chip chip-red ml-2">{{ strtoupper($player->activeInjury?->status ?? '') }}</span>
                        </span>
                    </div>
                @empty
                    <div class="text-sm text-bone-400">No open injury reports.</div>
                @endforelse
            </div>
        @endforeach
    </div>

    @if ($milestones->isNotEmpty())
        <div class="card border-gold-500/40 bg-gold-500/[0.06] p-5">
            <div class="lbl mb-3 text-gold-400">Milestone games</div>
            <div class="flex flex-wrap gap-3">
                @foreach ($milestones as $p)
                    <div class="rounded-lg bg-gold-500/10 px-3 py-2 text-sm">
                        <span class="text-bone-50">{{ $p->player?->name }}</span>
                        <span class="ml-2 text-gold-400">
                            {{ data_get(collect($p->signals)->firstWhere('type', 'milestone_game'), 'description', '') }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <section>
        <div class="lbl mb-3">Predicted try scorers</div>
        <div class="space-y-2">
            @forelse ($predictions as $prediction)
                @php($player = $prediction->player)
                @php($isOpen = $expanded[$prediction->id] ?? false)
                <div class="card p-3 sm:p-4">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <div class="w-6 shrink-0 text-center font-mono text-lg sm:w-8
                            {{ $prediction->rank_in_match <= 3 ? 'text-gold-500 dark:text-gold-400' : 'text-bone-400' }}">
                            {{ $prediction->rank_in_match }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                                <span class="font-medium text-bone-50">{{ $player?->name }}</span>
                                <span class="text-xs text-bone-400">
                                    {{ $player?->team?->short_name ?? $player?->team?->name }} · {{ ucfirst($player?->position ?? '') }}
                                </span>
                                @foreach (array_slice($prediction->advantageTags(), 0, 3) as $tag)
                                    <span class="chip {{ $tag['class'] }}">{{ $tag['label'] }}</span>
                                @endforeach
                            </div>
                            <div class="mt-2 flex items-center gap-3">
                                <div class="score-bar flex-1">
                                    <div class="score-bar-fill {{ $prediction->tierClass() }}"
                                         style="width: {{ $prediction->score }}%"></div>
                                </div>
                                <div class="w-10 text-right font-mono text-bone-100">{{ $prediction->score }}</div>
                            </div>
                            @php($keySignals = collect($prediction->signals ?? [])->filter(fn ($s) => ($s['strength'] ?? 0) >= 0.4)->sortByDesc(fn ($s) => ($s['weight'] ?? 0) * ($s['strength'] ?? 0))->take(3))
                            @if ($keySignals->isNotEmpty())
                                <div class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-bone-400">
                                    @foreach ($keySignals as $sig)
                                        <span class="inline-flex items-center gap-1">
                                            <span class="inline-block h-1.5 w-1.5 rounded-full {{ ($sig['strength'] ?? 0) >= 0.7 ? 'bg-gold-500' : 'bg-bone-500' }}"></span>
                                            {{ $sig['description'] ?? str_replace('_', ' ', $sig['type']) }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <button type="button" wire:click="toggle({{ $prediction->id }})"
                                class="btn-ghost shrink-0 px-2 sm:px-3">
                            <span class="hidden sm:inline">{{ $isOpen ? 'Hide' : 'Detail' }}</span>
                            <svg class="h-4 w-4 sm:hidden" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </div>

                    @if ($isOpen)
                        <div class="mt-4 grid gap-4 border-t border-ink-700 pt-4 md:grid-cols-2">
                            <div>
                                <div class="lbl mb-2">AI reasoning</div>
                                <p class="text-sm leading-relaxed text-bone-200">
                                    {{ $prediction->ai_reasoning ?: 'Awaiting AI review — showing statistical signals only.' }}
                                </p>
                            </div>
                            <div>
                                <div class="lbl mb-2">Signals</div>
                                <div class="space-y-1.5">
                                    @foreach ($prediction->signals ?? [] as $signal)
                                        <div class="flex items-center gap-3 text-xs">
                                            <span class="w-40 truncate text-bone-400">{{ str_replace('_', ' ', $signal['type']) }}</span>
                                            <div class="score-bar flex-1">
                                                <div class="score-bar-fill bg-gold-500/70"
                                                     style="width: {{ round(($signal['strength'] ?? 0) * 100) }}%"></div>
                                            </div>
                                            <span class="w-10 text-right font-mono text-bone-200">×{{ $signal['weight'] }}</span>
                                        </div>
                                        <div class="ml-40 pl-3 text-[11px] text-bone-500">{{ $signal['description'] ?? '' }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="card p-8 text-center text-bone-400">
                    No predictions yet. Run <code class="text-gold-400">php artisan nrl:predict</code>
                    or click Re-analyse with AI.
                </div>
            @endforelse
        </div>
    </section>
</div>
