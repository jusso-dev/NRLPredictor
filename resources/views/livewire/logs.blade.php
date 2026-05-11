<div wire:poll.5s class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="lbl mb-2">Backend telemetry</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Laravel logs</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="chip chip-muted">{{ $totalAll }} entries</span>
            @if ($totalMatched !== $totalAll)
                <span class="chip chip-gold">{{ $totalMatched }} matched</span>
            @endif
            <button wire:click="clearLog"
                    wire:confirm="Wipe storage/logs/laravel.log? This cannot be undone."
                    class="btn-ghost">Wipe log</button>
        </div>
    </div>

    <div class="card p-4">
        <div class="flex flex-wrap items-end gap-3">
            <label class="flex flex-col gap-1">
                <span class="lbl">Search</span>
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="message or context…"
                       class="input w-full sm:w-72">
            </label>

            <div class="flex flex-col gap-1">
                <span class="lbl">Level</span>
                <div class="flex flex-wrap gap-1">
                    <button wire:click="$set('level', '')"
                            class="chip {{ $level === '' ? 'chip-gold' : 'chip-muted' }} cursor-pointer">ALL</button>
                    @foreach ($levels as $lvl)
                        @if (($counts[$lvl] ?? 0) > 0)
                            <button wire:click="$set('level', '{{ $lvl }}')"
                                    class="chip cursor-pointer {{ $level === $lvl
                                        ? match($lvl) {
                                            'ERROR','CRITICAL','ALERT','EMERGENCY' => 'chip-red',
                                            'WARNING' => 'chip-orange',
                                            'NOTICE','INFO' => 'chip-blue',
                                            default => 'chip-muted',
                                        }
                                        : 'chip-muted'
                                    }}">
                                {{ $lvl }}
                                <span class="opacity-70">{{ $counts[$lvl] }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>

            @if ($classFilter !== '' || $level !== '' || $search !== '')
                <button wire:click="clearFilters" class="btn-ghost">Clear</button>
            @endif
            @if ($classFilter !== '')
                <span class="chip chip-blue">class:{{ $classFilter }}</span>
            @endif
        </div>
    </div>

    <div class="card overflow-hidden">
        @forelse ($entries as $i => $entry)
            @php($isOpen = $expanded[$i] ?? false)
            @php($levelClass = match($entry['level']) {
                'ERROR','CRITICAL','ALERT','EMERGENCY' => 'chip-red',
                'WARNING' => 'chip-orange',
                'NOTICE','INFO' => 'chip-blue',
                'DEBUG' => 'chip-muted',
                default => 'chip-muted',
            })
            <div class="cursor-pointer border-b border-ink-700 last:border-b-0 hover:bg-ink-800"
                 wire:key="log-{{ $i }}"
                 wire:click="toggle({{ $i }})">
                <div class="flex items-start gap-3 px-4 py-2.5 text-sm">
                    <svg class="mt-0.5 h-3 w-3 shrink-0 text-bone-400 transition-transform {{ $isOpen ? 'rotate-90' : '' }}" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>
                    </svg>
                    <span class="chip {{ $levelClass }} shrink-0">{{ $entry['level'] }}</span>
                    <span class="hidden shrink-0 font-mono text-[11px] text-bone-400 sm:inline">{{ $entry['timestamp'] }}</span>
                    <span class="min-w-0 flex-1 truncate text-bone-100">{{ $entry['message'] ?: '(no message)' }}</span>
                </div>
                @if ($isOpen)
                    <div class="space-y-2 border-t border-ink-700 bg-ink-950 px-4 py-3 text-[12px]">
                        <div class="flex flex-wrap gap-x-6 gap-y-1 font-mono text-bone-400">
                            <span>{{ $entry['timestamp'] }}</span>
                            <span>env: {{ $entry['env'] }}</span>
                            <span>level: {{ $entry['level'] }}</span>
                        </div>
                        <pre class="whitespace-pre-wrap break-words rounded bg-ink-900 p-3 font-mono leading-relaxed text-bone-100">{{ $entry['message'] }}</pre>
                        @if (! empty($entry['context']))
                            <details class="rounded border border-ink-600 bg-ink-900">
                                <summary class="cursor-pointer px-3 py-2 text-bone-400 hover:text-bone-100">Context / stack trace</summary>
                                <pre class="whitespace-pre-wrap break-words px-3 pb-3 font-mono text-[11px] leading-relaxed text-bone-200">{{ $entry['context'] }}</pre>
                            </details>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-10 text-center text-bone-400">
                @if ($totalAll === 0)
                    No log entries yet. Run a job or hit an error, then come back.
                @else
                    No entries match the current filters.
                @endif
            </div>
        @endforelse
    </div>

    @if ($totalMatched > $totalShown)
        <div class="text-center text-xs text-bone-400">
            Showing the {{ $totalShown }} most recent of {{ $totalMatched }} matching entries.
        </div>
    @endif
</div>
