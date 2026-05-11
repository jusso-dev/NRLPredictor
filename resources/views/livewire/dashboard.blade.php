<div wire:poll.30s class="space-y-10">
    <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <div class="lbl mb-2">Current Round</div>
            <h1 class="h-display text-3xl text-bone-50 sm:text-4xl">
                @if ($round)
                    Round {{ $round->round_number }}
                    <span class="text-xl text-bone-400 sm:text-2xl">— {{ $round->season }}</span>
                @else
                    Awaiting fixtures
                @endif
            </h1>
            @if ($round && $round->start_date)
                <div class="mt-1 text-sm text-bone-400">
                    {{ $round->start_date->format('D j M') }} — {{ optional($round->end_date)->format('D j M') }}
                </div>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="chip chip-muted">{{ $matches->count() }} matches</span>
            <span class="chip chip-gold">{{ $leaderboard->count() }} predictions</span>
            @if ($runningJobs > 0)
                <span class="chip chip-yellow animate-pulse">{{ $runningJobs }} job(s) running</span>
            @endif
            <button wire:click="triggerSync" wire:loading.attr="disabled" class="btn-primary">
                <span wire:loading.remove wire:target="triggerSync">Sync round</span>
                <span wire:loading wire:target="triggerSync">Queuing…</span>
            </button>
        </div>
    </section>

    @if ($syncMessage)
        <div class="card border-gold-500/40 bg-gold-500/[0.06] p-4 text-sm text-bone-100">
            {{ $syncMessage }}
            @if ($lastSync?->completed_at)
                <span class="ml-2 text-xs text-bone-400">Last sync {{ $lastSync->completed_at->diffForHumans() }}.</span>
            @endif
        </div>
    @elseif ($lastSync)
        <div class="text-xs text-bone-400">
            Last round sync:
            @if ($lastSync->completed_at)
                {{ $lastSync->completed_at->diffForHumans() }}.
            @else
                in progress since {{ $lastSync->started_at?->diffForHumans() }}.
            @endif
        </div>
    @endif

    <section>
        <div class="lbl mb-3">Matches</div>
        @if ($matches->isEmpty())
            <div class="card p-10 text-center text-bone-400">
                No matches scheduled for this round. Run <code class="text-gold-400">php artisan nrl:seed-round</code>
                to bootstrap fixtures.
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($matches as $match)
                    @php($top = $match->predictions->first())
                    <a href="{{ route('match.detail', $match) }}"
                       class="card card-hover group flex flex-col gap-4 p-5">
                        <div class="flex items-center justify-between">
                            <span class="chip
                                {{ $match->status === 'live' ? 'chip-red animate-pulse' : '' }}
                                {{ $match->status === 'completed' ? 'chip-muted' : '' }}
                                {{ $match->status === 'upcoming' ? 'chip-gold' : '' }}">
                                {{ $match->statusBadge() }}
                            </span>
                            <span class="text-xs text-bone-400">
                                {{ optional($match->kickoff_at)?->format('D j M · H:i') }} AEST
                            </span>
                        </div>

                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-bone-50">
                                <span class="h-display text-lg {{ $match->predicted_winner_id === $match->home_team_id ? 'text-gold-400' : '' }}">
                                    {{ $match->homeTeam->short_name ?? $match->homeTeam->name }}
                                </span>
                                <div class="flex items-center gap-2">
                                    @if ($match->home_win_pct)
                                        <span class="text-xs font-mono {{ $match->predicted_winner_id === $match->home_team_id ? 'text-gold-400' : 'text-bone-400' }}">{{ $match->home_win_pct }}%</span>
                                    @endif
                                    @if ($match->home_score !== null)
                                        <span class="font-mono text-xl">{{ $match->home_score }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center justify-between text-bone-50">
                                <span class="h-display text-lg {{ $match->predicted_winner_id === $match->away_team_id ? 'text-gold-400' : '' }}">
                                    {{ $match->awayTeam->short_name ?? $match->awayTeam->name }}
                                </span>
                                <div class="flex items-center gap-2">
                                    @if ($match->away_win_pct)
                                        <span class="text-xs font-mono {{ $match->predicted_winner_id === $match->away_team_id ? 'text-gold-400' : 'text-bone-400' }}">{{ $match->away_win_pct }}%</span>
                                    @endif
                                    @if ($match->away_score !== null)
                                        <span class="font-mono text-xl">{{ $match->away_score }}</span>
                                    @endif
                                </div>
                            </div>
                            @if ($match->home_win_pct && $match->away_win_pct)
                                <div class="mt-1 flex h-1.5 overflow-hidden rounded-full">
                                    <div class="bg-gold-500 transition-all" style="width: {{ $match->home_win_pct }}%"></div>
                                    <div class="bg-bone-600 transition-all" style="width: {{ $match->away_win_pct }}%"></div>
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center gap-2 text-xs text-bone-400">
                            <span>{{ $match->venue ?? 'Venue TBA' }}</span>
                            @php($weather = $weatherByMatch[$match->id] ?? null)
                            @if ($weather)
                                <span class="ml-auto flex items-center gap-1 {{ $weather->is_wet ? 'text-blue-400' : ($weather->is_hot ? 'text-red-400' : '') }}">
                                    @if ($weather->is_wet) <span>🌧</span> @elseif ($weather->is_hot) <span>🔥</span> @else <span>☀</span> @endif
                                    {{ round($weather->temp_c) }}° {{ $weather->wind_kph }}kph
                                </span>
                            @endif
                        </div>

                        @php($milestones = $milestonesByMatch[$match->id] ?? collect())
                        @foreach ($milestones->take(2) as $ms)
                            <div class="rounded bg-gold-500/10 px-2 py-1 text-[11px] text-gold-400">
                                {{ $ms->player?->name }} — {{ $ms->type === 'try_milestone' ? "chasing try #{$ms->target_count}" : "game #{$ms->target_count}" }}
                            </div>
                        @endforeach

                        @if ($match->tryEvents->isNotEmpty())
                            <div class="mt-auto rounded border border-ink-600 bg-ink-950 p-3">
                                <div class="lbl mb-1">{{ $match->status === 'live' ? 'Try scorers (live)' : 'Try scorers' }}</div>
                                <div class="space-y-1">
                                    @foreach ($match->tryEvents->groupBy('player_id') as $playerId => $events)
                                        @php($scorer = $events->first()->player)
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="truncate text-bone-100">{{ $scorer?->name }}</span>
                                            <span class="shrink-0 ml-2 text-bone-400">
                                                @if ($events->count() > 1)
                                                    {{ $events->count() }} tries
                                                @else
                                                    {{ $events->first()->minute ? $events->first()->minute . "'" : '' }}
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($top && $top->player)
                            <div class="mt-auto rounded border border-ink-600 bg-ink-950 p-3">
                                <div class="lbl mb-1">Top pick</div>
                                <div class="flex items-center justify-between">
                                    <div class="truncate font-medium text-bone-50">{{ $top->player->name }}</div>
                                    <div class="font-mono text-gold-400">{{ $top->score }}</div>
                                </div>
                                @php($tags = $top->advantageTags())
                                @if (!empty($tags))
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach (array_slice($tags, 0, 3) as $tag)
                                            <span class="chip {{ $tag['class'] }}">{{ $tag['label'] }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="mt-auto text-xs text-bone-500">No predictions yet</div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section>
        <div class="lbl mb-3">Round leaderboard</div>
        <div class="card divide-y divide-ink-700">
            @foreach ($leaderboard as $index => $prediction)
                @php($player = $prediction->player)
                @php($match = $prediction->match)
                <div class="flex flex-col gap-1 px-4 py-3 sm:px-5">
                    <div class="flex items-center gap-3 sm:gap-4">
                        <div class="w-5 shrink-0 text-right font-mono text-sm {{ $index < 3 ? 'text-gold-500' : 'text-bone-400' }} dark:text-gold-400">
                            {{ $index + 1 }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm text-bone-50 sm:text-base">{{ $player?->name }}</div>
                            <div class="truncate text-xs text-bone-400">
                                {{ $player?->team?->short_name ?? $player?->team?->name }} ·
                                {{ $match?->homeTeam?->short_name }} v {{ $match?->awayTeam?->short_name }}
                            </div>
                        </div>
                        <div class="hidden flex-1 max-w-xs sm:block md:max-w-sm">
                            <div class="score-bar">
                                <div class="score-bar-fill {{ $prediction->tierClass() }}"
                                     style="width: {{ $prediction->score }}%"></div>
                            </div>
                        </div>
                        <div class="w-10 shrink-0 text-right font-mono text-bone-100">{{ $prediction->score }}</div>
                        <div class="hidden gap-1 lg:flex">
                            @foreach (array_slice($prediction->advantageTags(), 0, 3) as $tag)
                                <span class="chip {{ $tag['class'] }}">{{ $tag['label'] }}</span>
                            @endforeach
                        </div>
                    </div>
                    @php($topSignals = collect($prediction->signals ?? [])->sortByDesc(fn ($s) => ($s['weight'] ?? 0) * ($s['strength'] ?? 0))->take(3))
                    @if ($topSignals->isNotEmpty())
                        <div class="ml-8 flex flex-wrap gap-x-4 gap-y-0.5 text-[11px] text-bone-500">
                            @foreach ($topSignals as $sig)
                                <span>{{ $sig['description'] ?? str_replace('_', ' ', $sig['type']) }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
            @if ($leaderboard->isEmpty())
                <div class="p-8 text-center text-bone-400">Predictions will populate after the next analysis run.</div>
            @endif
        </div>
    </section>
</div>
