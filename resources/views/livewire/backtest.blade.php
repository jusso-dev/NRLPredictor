<div class="space-y-8" @if ($shouldPoll) wire:poll.2s @endif>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="lbl mb-2">Walk-forward replay</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Backtest</h1>
            <p class="mt-1 max-w-2xl text-xs text-bone-400">
                Replay past rounds and only keep tuner weight changes that improve the next round's Brier score. Runs as a background job — submit and the page will poll until it's done.
            </p>
        </div>
    </div>

    <section class="card p-5">
        <form wire:submit.prevent="startRun" class="grid gap-4 sm:grid-cols-5">
            <div>
                <label class="lbl mb-1 block" for="season">Season</label>
                <select id="season" wire:model="season" class="w-full rounded border border-ink-600 bg-ink-950 px-2 py-2 text-sm">
                    @foreach ($availableSeasons as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                    @endforeach
                    @if (empty($availableSeasons))
                        <option value="{{ now()->year }}">{{ now()->year }}</option>
                    @endif
                </select>
            </div>
            <div>
                <label class="lbl mb-1 block" for="from">From round</label>
                <input id="from" type="number" wire:model="fromRound" min="1" max="30"
                    class="w-full rounded border border-ink-600 bg-ink-950 px-2 py-2 text-sm font-mono">
            </div>
            <div>
                <label class="lbl mb-1 block" for="to">To round</label>
                <input id="to" type="number" wire:model="toRound" min="1" max="30"
                    class="w-full rounded border border-ink-600 bg-ink-950 px-2 py-2 text-sm font-mono">
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm text-bone-200">
                    <input type="checkbox" wire:model="apply" class="rounded border-ink-600 bg-ink-950">
                    <span>Apply accepted weights</span>
                </label>
            </div>
            <div class="flex items-end">
                <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="startRun"
                    class="w-full rounded bg-gold-500 px-4 py-2 text-sm font-semibold text-navy-900 hover:bg-gold-400 disabled:opacity-50">
                    <span wire:loading.remove wire:target="startRun">Run backtest</span>
                    <span wire:loading wire:target="startRun">Queueing…</span>
                </button>
            </div>
        </form>
        @error('fromRound') <div class="mt-2 text-xs text-signal-red">{{ $message }}</div> @enderror
        @error('toRound') <div class="mt-2 text-xs text-signal-red">{{ $message }}</div> @enderror
    </section>

    @if ($activeRun)
        @php($status = $activeRun->status)
        @php($tone = match ($status) {
            'completed' => 'border-signal-green/50 bg-signal-green/10',
            'failed' => 'border-signal-red/50 bg-signal-red/10',
            'running' => 'border-gold-500/50 bg-gold-500/10',
            default => 'border-ink-600 bg-ink-950',
        })

        <section class="card border {{ $tone }} p-5">
            <div class="flex items-center justify-between">
                <div>
                    <div class="lbl">Run #{{ $activeRun->id }} · season {{ $activeRun->season }} · R{{ $activeRun->from_round }}..R{{ $activeRun->to_round }}</div>
                    <div class="mt-1 h-display text-lg text-bone-50">
                        @switch($status)
                            @case('pending') Waiting for worker…  @break
                            @case('running')
                                Running… <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-gold-500 align-middle ml-1"></span>
                                @break
                            @case('completed') Completed @break
                            @case('failed') Failed @break
                        @endswitch
                    </div>
                </div>
                <div class="text-right text-xs text-bone-400">
                    Queued {{ $activeRun->created_at?->diffForHumans() }}
                    @if ($activeRun->completed_at)
                        · finished {{ $activeRun->completed_at->diffForHumans() }}
                    @endif
                    @if ($activeRun->apply)
                        <div class="mt-1 text-gold-500">--apply</div>
                    @endif
                </div>
            </div>

            @if ($status === 'failed')
                <div class="mt-4 rounded border border-signal-red/40 bg-ink-950 p-3 font-mono text-xs text-signal-red">
                    {{ $activeRun->error ?? 'Unknown error' }}
                </div>
            @endif

            @if ($status === 'completed')
                @php($summary = $activeRun->summary())
                @php($rounds = $activeRun->rounds())

                <div class="mt-4 grid gap-3 rounded border border-ink-600 bg-ink-950/60 p-3 text-xs sm:grid-cols-4">
                    <div>
                        <div class="lbl">Pairs evaluated</div>
                        <div class="font-mono text-bone-50">{{ $summary['pairs_evaluated'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="lbl">Accepted / Rejected</div>
                        <div class="font-mono text-bone-50">{{ $summary['accepted'] ?? 0 }} / {{ $summary['rejected'] ?? 0 }}</div>
                    </div>
                    <div>
                        <div class="lbl">Baseline → Walked Brier</div>
                        <div class="font-mono text-bone-50">
                            {{ isset($summary['baseline_brier']) ? number_format($summary['baseline_brier'], 4) : '—' }}
                            →
                            {{ isset($summary['walked_brier']) ? number_format($summary['walked_brier'], 4) : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="lbl">Improvement</div>
                        @if (! empty($summary['improvement']))
                            <div class="font-mono {{ $summary['improvement'] > 0 ? 'text-signal-green' : 'text-signal-red' }}">
                                {{ $summary['improvement'] > 0 ? '+' : '' }}{{ number_format($summary['improvement'], 4) }}
                                <span class="text-bone-500">(lower Brier)</span>
                            </div>
                        @else
                            <div class="font-mono text-bone-500">—</div>
                        @endif
                    </div>
                </div>

                @if (! empty($rounds))
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[640px] text-left text-xs">
                            <thead class="text-bone-500">
                                <tr>
                                    <th class="py-2 pr-3 font-medium uppercase tracking-wider">Learn → Test</th>
                                    <th class="py-2 pr-3 font-medium uppercase tracking-wider">Brier (current)</th>
                                    <th class="py-2 pr-3 font-medium uppercase tracking-wider">Brier (proposed)</th>
                                    <th class="py-2 pr-3 font-medium uppercase tracking-wider">Δ</th>
                                    <th class="py-2 pr-3 font-medium uppercase tracking-wider">Changes</th>
                                    <th class="py-2 pr-3 font-medium uppercase tracking-wider">Decision</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono text-bone-200">
                                @foreach ($rounds as $r)
                                    <tr class="border-t border-ink-700">
                                        <td class="py-2 pr-3">R{{ $r['learn_round'] }} → R{{ $r['test_round'] }}</td>
                                        <td class="py-2 pr-3">{{ isset($r['brier_current']) ? number_format($r['brier_current'], 4) : '—' }}</td>
                                        <td class="py-2 pr-3">{{ isset($r['brier_proposed']) ? number_format($r['brier_proposed'], 4) : '—' }}</td>
                                        <td class="py-2 pr-3 {{ ($r['delta'] ?? 0) < 0 ? 'text-signal-green' : (($r['delta'] ?? 0) > 0 ? 'text-signal-red' : '') }}">
                                            {{ isset($r['delta']) ? (($r['delta'] >= 0 ? '+' : '') . number_format($r['delta'], 4)) : '—' }}
                                        </td>
                                        <td class="py-2 pr-3">{{ $r['weight_changes'] ?? 0 }}</td>
                                        <td class="py-2 pr-3">
                                            @php($d = strtoupper($r['decision'] ?? ''))
                                            <span class="rounded px-2 py-0.5 text-[10px] uppercase
                                                {{ $d === 'ACCEPT' ? 'bg-signal-green/20 text-signal-green' : ($d === 'REJECT' ? 'bg-bone-500/20 text-bone-300' : 'bg-ink-700 text-bone-400') }}">
                                                {{ $d }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @php($start = $summary['starting_weights'] ?? [])
                @php($end = $summary['final_weights'] ?? [])
                @php($changed = collect($end)->filter(fn ($v, $k) => ($start[$k] ?? null) !== $v))
                @if ($changed->isNotEmpty())
                    <div class="mt-5">
                        <div class="lbl mb-2">Weight diff</div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($changed as $type => $value)
                                @php($from = $start[$type] ?? 0)
                                <div class="flex items-center justify-between rounded border border-ink-600 bg-ink-950 px-3 py-2 text-xs">
                                    <span class="text-bone-200">{{ str_replace('_', ' ', $type) }}</span>
                                    <span class="font-mono">
                                        {{ $from }} → {{ $value }}
                                        <span class="ml-1 {{ $value - $from > 0 ? 'text-signal-green' : 'text-signal-red' }}">
                                            ({{ $value - $from > 0 ? '+' : '' }}{{ $value - $from }})
                                        </span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        @if ($activeRun->apply)
                            <div class="mt-3 text-xs text-gold-500">
                                Persisted to weight_adjustments — live SignalCalculator will pick these up on its next read.
                            </div>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    @endif

    @if ($history->isNotEmpty())
        <section class="card p-5">
            <div class="lbl mb-3">Recent runs</div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-xs">
                    <thead class="text-bone-500">
                        <tr>
                            <th class="py-2 pr-3 font-medium uppercase tracking-wider">#</th>
                            <th class="py-2 pr-3 font-medium uppercase tracking-wider">Season</th>
                            <th class="py-2 pr-3 font-medium uppercase tracking-wider">Range</th>
                            <th class="py-2 pr-3 font-medium uppercase tracking-wider">Status</th>
                            <th class="py-2 pr-3 font-medium uppercase tracking-wider">Walked Brier</th>
                            <th class="py-2 pr-3 font-medium uppercase tracking-wider">Queued</th>
                            <th class="py-2 pr-3"></th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-bone-200">
                        @foreach ($history as $run)
                            @php($s = $run->summary())
                            <tr class="border-t border-ink-700 {{ $activeRun && $activeRun->id === $run->id ? 'bg-ink-900' : '' }}">
                                <td class="py-2 pr-3">{{ $run->id }}</td>
                                <td class="py-2 pr-3">{{ $run->season }}</td>
                                <td class="py-2 pr-3">R{{ $run->from_round }}..R{{ $run->to_round }}</td>
                                <td class="py-2 pr-3">
                                    <span class="rounded px-2 py-0.5 text-[10px] uppercase
                                        {{ $run->status === 'completed' ? 'bg-signal-green/20 text-signal-green' :
                                           ($run->status === 'failed' ? 'bg-signal-red/20 text-signal-red' :
                                           ($run->status === 'running' ? 'bg-gold-500/20 text-gold-500' : 'bg-ink-700 text-bone-300')) }}">
                                        {{ $run->status }}
                                    </span>
                                </td>
                                <td class="py-2 pr-3">{{ isset($s['walked_brier']) ? number_format($s['walked_brier'], 4) : '—' }}</td>
                                <td class="py-2 pr-3 text-bone-400">{{ $run->created_at?->diffForHumans() }}</td>
                                <td class="py-2 pr-3">
                                    <button type="button" wire:click="$set('activeRunId', {{ $run->id }})"
                                        class="text-gold-500 hover:underline">view</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
