<div wire:poll.5s class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="lbl mb-2">Scraping &amp; analysis</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Background jobs</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($justDispatched)
                <span class="chip chip-green">{{ $justDispatched }}</span>
            @endif
            @if ($stuckCount > 0)
                <button wire:click="clearStuck" class="btn-ghost">
                    Clear {{ $stuckCount }} stuck
                </button>
            @endif
            <a href="{{ route('logs') }}" class="btn-ghost">Laravel logs →</a>
        </div>
    </div>

    <section class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
        @foreach ($jobs as $key => $job)
            @php($last = $lastPerClass[$job['class']] ?? null)
            @php($running = $last && $last->completed_at === null)
            <div class="card p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="h-display text-lg text-bone-50">{{ $job['label'] }}</div>
                        <p class="mt-1 text-xs text-bone-400">{{ $job['description'] }}</p>
                    </div>
                    <button wire:click="run('{{ $key }}')"
                            wire:loading.attr="disabled"
                            wire:target="run('{{ $key }}')"
                            class="btn-primary shrink-0">
                        <span wire:loading.remove wire:target="run('{{ $key }}')">Run</span>
                        <span wire:loading wire:target="run('{{ $key }}')">…</span>
                    </button>
                </div>
                <div class="mt-4 flex items-center gap-2 text-xs">
                    @if ($running)
                        <span class="chip chip-yellow animate-pulse">RUNNING</span>
                    @elseif ($last && $last->status === 'success')
                        <span class="chip chip-green">OK</span>
                    @elseif ($last && $last->status === 'failed')
                        <span class="chip chip-red">FAILED</span>
                    @else
                        <span class="chip chip-muted">NEVER RUN</span>
                    @endif
                    @if ($last)
                        <span class="text-bone-400">
                            {{ $last->records_updated }} records ·
                            {{ $last->updated_at?->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>
        @endforeach
    </section>

    <section>
        <div class="lbl mb-3">Recent runs</div>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-bone-400">
                    <tr class="text-left">
                        <th class="px-3 py-3 font-medium uppercase tracking-wider text-[11px] sm:px-4">Job</th>
                        <th class="hidden px-4 py-3 font-medium uppercase tracking-wider text-[11px] sm:table-cell">Source</th>
                        <th class="px-3 py-3 font-medium uppercase tracking-wider text-[11px] sm:px-4">Status</th>
                        <th class="px-3 py-3 font-medium uppercase tracking-wider text-[11px] text-right sm:px-4">Rec</th>
                        <th class="hidden px-4 py-3 font-medium uppercase tracking-wider text-[11px] md:table-cell">Started</th>
                        <th class="hidden px-4 py-3 font-medium uppercase tracking-wider text-[11px] lg:table-cell">Dur</th>
                        <th class="hidden px-4 py-3 font-medium uppercase tracking-wider text-[11px] md:table-cell">Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-700">
                    @forelse ($logs as $log)
                        @php($running = $log->completed_at === null)
                        @php($isOpen = $expanded[$log->id] ?? false)
                        @php($stuck = $running && $log->started_at && $log->started_at->lt(now()->subMinutes(15)))
                        @php($dur = $log->started_at && $log->completed_at
                            ? $log->completed_at->diffInMilliseconds($log->started_at) : null)
                        <tr class="cursor-pointer hover:bg-ink-800" wire:click="toggle({{ $log->id }})">
                            <td class="px-3 py-2 sm:px-4">
                                <div class="flex items-center gap-2">
                                    <svg class="h-3 w-3 text-bone-400 transition-transform {{ $isOpen ? 'rotate-90' : '' }}" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>
                                    </svg>
                                    <span class="text-bone-50">{{ class_basename($log->job_class) }}</span>
                                </div>
                                <div class="mt-0.5 text-[11px] text-bone-400 sm:hidden">{{ $log->started_at?->diffForHumans() }}</div>
                            </td>
                            <td class="hidden px-4 py-2 text-bone-200 sm:table-cell">{{ $log->source }}</td>
                            <td class="px-3 py-2 sm:px-4">
                                @if ($stuck)
                                    <span class="chip chip-red" title="Stuck — worker may have died">STUCK</span>
                                @elseif ($running)
                                    <span class="chip chip-yellow animate-pulse">RUN</span>
                                @elseif ($log->status === 'success')
                                    <span class="chip chip-green">OK</span>
                                @else
                                    <span class="chip chip-red">FAIL</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-mono text-bone-200 sm:px-4">{{ $log->records_updated }}</td>
                            <td class="hidden px-4 py-2 text-bone-400 md:table-cell">{{ $log->started_at?->diffForHumans() }}</td>
                            <td class="hidden px-4 py-2 font-mono text-bone-400 lg:table-cell">
                                {{ $dur !== null ? number_format($dur).' ms' : '—' }}
                            </td>
                            <td class="hidden px-4 py-2 text-bone-400 md:table-cell">
                                @if ($log->error)
                                    <span class="block max-w-md truncate text-signal-red">
                                        {{ \Illuminate\Support\Str::limit($log->error, 80) }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @if ($isOpen)
                            <tr class="bg-ink-950">
                                <td colspan="7" class="px-4 py-4">
                                    <dl class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2 md:grid-cols-4">
                                        <div>
                                            <dt class="lbl">Job class</dt>
                                            <dd class="mt-1 break-all font-mono text-bone-100">{{ $log->job_class }}</dd>
                                        </div>
                                        <div>
                                            <dt class="lbl">Source</dt>
                                            <dd class="mt-1 font-mono text-bone-100">{{ $log->source }}</dd>
                                        </div>
                                        <div>
                                            <dt class="lbl">Started</dt>
                                            <dd class="mt-1 font-mono text-bone-100">{{ $log->started_at?->toDateTimeString() ?? '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="lbl">Completed</dt>
                                            <dd class="mt-1 font-mono text-bone-100">{{ $log->completed_at?->toDateTimeString() ?? ($running ? 'still running' : '—') }}</dd>
                                        </div>
                                        <div>
                                            <dt class="lbl">Records</dt>
                                            <dd class="mt-1 font-mono text-bone-100">{{ $log->records_updated }}</dd>
                                        </div>
                                        <div>
                                            <dt class="lbl">Duration</dt>
                                            <dd class="mt-1 font-mono text-bone-100">
                                                {{ $dur !== null ? number_format($dur).' ms' : ($running && $log->started_at ? now()->diffInSeconds($log->started_at).' s (running)' : '—') }}
                                            </dd>
                                        </div>
                                    </dl>
                                    @if ($log->error)
                                        <div class="mt-4">
                                            <div class="lbl mb-1">Error</div>
                                            <pre class="whitespace-pre-wrap break-words rounded border border-signal-red/30 bg-signal-red/10 p-3 font-mono text-[11px] leading-relaxed text-signal-red">{{ $log->error }}</pre>
                                        </div>
                                    @endif
                                    @if ($stuck)
                                        <div class="mt-4 rounded border border-signal-red/40 bg-signal-red/10 p-3 text-xs text-signal-red">
                                            This job has been running for more than 15 minutes and likely died silently.
                                            Click <b>Clear N stuck</b> at the top of the page, or inspect
                                            <a href="{{ route('logs') }}" class="underline">Laravel logs</a>.
                                        </div>
                                    @endif
                                    <div class="mt-3">
                                        <a href="{{ route('logs', ['class' => class_basename($log->job_class)]) }}" class="btn-ghost">
                                            Open Laravel log for this job →
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-bone-400">
                                No jobs have run yet. Click Run on a card above to kick one off.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
