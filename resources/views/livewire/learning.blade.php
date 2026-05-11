<div class="space-y-8">
    <div>
        <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Self-Tuning Engine</h1>
        <p class="mt-1 text-sm text-bone-400">The model automatically grades its predictions after each round and adjusts signal weights based on what's actually working.</p>
    </div>

    {{-- Current weights --}}
    <section>
        <div class="lbl mb-3">Current Signal Weights</div>
        <div class="card p-4">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (collect($currentWeights)->sortDesc() as $type => $weight)
                    <div class="flex items-center gap-3">
                        <span class="w-40 truncate text-sm text-bone-200">{{ str_replace('_', ' ', $type) }}</span>
                        <div class="score-bar flex-1">
                            <div class="score-bar-fill bg-gold-500/70"
                                 style="width: {{ round($weight / max(1, max($currentWeights)) * 100) }}%"></div>
                        </div>
                        <span class="w-8 text-right font-mono text-xs text-bone-400">{{ $weight }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Signal performance trends --}}
    <section>
        <div class="lbl mb-3">Signal Performance by Round</div>
        <p class="mb-3 text-xs text-bone-400">Delta = avg strength in hits minus avg strength in misses. Positive = signal is predictive.</p>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-bone-400">
                    <tr>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-medium">Signal</th>
                        @foreach ($signalTrends->first() ?? [] as $point)
                            <th class="px-3 py-3 text-center text-[11px] uppercase tracking-wider font-medium">R{{ $point['round'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-700">
                    @foreach ($signalTrends as $type => $points)
                        <tr>
                            <td class="px-4 py-2 text-bone-200">{{ str_replace('_', ' ', $type) }}</td>
                            @foreach ($points as $point)
                                <td class="px-3 py-2 text-center font-mono text-xs {{ $point['delta'] > 0.02 ? 'text-gold-400' : ($point['delta'] < -0.02 ? 'text-signal-red' : 'text-bone-500') }}">
                                    {{ $point['delta'] > 0 ? '+' : '' }}{{ round($point['delta'], 3) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($signalTrends->isEmpty())
            <div class="card p-8 text-center text-bone-400">No signal performance data yet. Tuning begins after the first completed round with predictions.</div>
        @endif
    </section>

    {{-- Weight adjustment history --}}
    <section>
        <div class="lbl mb-3">Weight Adjustment History</div>
        <div class="space-y-3">
            @forelse ($adjustments as $adj)
                <div class="card p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="h-display text-lg text-bone-50">After Round {{ $adj->after_round }}</div>
                        <div class="flex items-center gap-3">
                            @if ($adj->accuracy_before)
                                <span class="chip chip-gold">{{ $adj->accuracy_before }}% accuracy</span>
                            @endif
                            <span class="text-xs text-bone-400">{{ $adj->created_at->format('D j M H:i') }}</span>
                        </div>
                    </div>

                    @if ($adj->reasoning)
                        <pre class="text-xs text-bone-300 whitespace-pre-wrap font-mono bg-ink-900 rounded p-3 mb-3">{{ $adj->reasoning }}</pre>
                    @endif

                    @php($changes = collect($adj->new_weights)->filter(fn ($v, $k) => ($adj->old_weights[$k] ?? 0) !== $v))
                    @if ($changes->isNotEmpty())
                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($changes as $type => $newVal)
                                @php($oldVal = $adj->old_weights[$type] ?? 0)
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="text-bone-400">{{ str_replace('_', ' ', $type) }}</span>
                                    <span class="font-mono text-bone-500">{{ $oldVal }}</span>
                                    <span class="text-bone-500">→</span>
                                    <span class="font-mono {{ $newVal > $oldVal ? 'text-gold-400' : 'text-signal-red' }}">{{ $newVal }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-xs text-bone-500">No weight changes this round.</div>
                    @endif
                </div>
            @empty
                <div class="card p-8 text-center text-bone-400">No weight adjustments yet. The tuner runs automatically after each completed round.</div>
            @endforelse
        </div>
    </section>
</div>
