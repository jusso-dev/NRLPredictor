<div class="space-y-8">
    <div>
        <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Model Calibration</h1>
        <p class="mt-1 text-sm text-bone-400">How well do predicted probabilities match actual outcomes?</p>
    </div>

    @if ($brierScore !== null)
        <div class="card p-5">
            <div class="lbl mb-2">Brier Score</div>
            <div class="h-display text-3xl {{ $brierScore < 0.2 ? 'text-gold-400' : ($brierScore < 0.3 ? 'text-bone-200' : 'text-signal-red') }}">
                {{ $brierScore }}
            </div>
            <div class="text-xs text-bone-400 mt-1">Lower is better. 0 = perfect, 0.25 = random guessing.</div>
        </div>
    @endif

    {{-- Calibration buckets --}}
    <section>
        <div class="lbl mb-3">Predicted vs Actual by Score Bucket</div>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-bone-400">
                    <tr>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-medium">Bucket</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Predicted</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Actual</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Delta</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Count</th>
                        <th class="px-4 py-3 font-medium" style="width: 200px"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-700">
                    @foreach ($calibrationData as $row)
                        @php($delta = round($row['predicted_pct'] - $row['actual_pct'], 1))
                        <tr>
                            <td class="px-4 py-3 font-mono text-bone-200">{{ $row['bucket'] }}</td>
                            <td class="px-4 py-3 text-right font-mono text-bone-300">{{ $row['predicted_pct'] }}%</td>
                            <td class="px-4 py-3 text-right font-mono {{ $row['actual_pct'] > $row['predicted_pct'] ? 'text-gold-400' : 'text-bone-300' }}">{{ $row['actual_pct'] }}%</td>
                            <td class="px-4 py-3 text-right font-mono {{ abs($delta) > 10 ? 'text-signal-red' : 'text-bone-400' }}">{{ $delta > 0 ? '+' : '' }}{{ $delta }}pp</td>
                            <td class="px-4 py-3 text-right font-mono text-bone-400">{{ $row['count'] }}</td>
                            <td class="px-4 py-3">
                                <div class="flex h-3 gap-0.5 items-end">
                                    <div class="bg-gold-500/60 rounded" style="width: {{ $row['predicted_pct'] }}%; height: 100%"></div>
                                    <div class="bg-bone-400 rounded" style="width: {{ $row['actual_pct'] }}%; height: 100%"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if (empty($calibrationData))
            <div class="card p-8 text-center text-bone-400">No calibration data yet. Predictions need completed matches to compare against.</div>
        @endif
    </section>

    {{-- Per-round accuracy --}}
    <section>
        <div class="lbl mb-3">Per-Round Accuracy</div>
        <div class="card divide-y divide-ink-700">
            @foreach ($perRound as $row)
                <div class="flex items-center justify-between px-4 py-3">
                    <span class="text-bone-200">Round {{ $row['round'] }}</span>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-bone-400">{{ $row['hits'] }}/{{ $row['total'] }}</span>
                        <div class="w-20">
                            <div class="score-bar">
                                <div class="score-bar-fill {{ $row['pct'] >= 35 ? 'bg-gold-500/70' : 'bg-bone-500/50' }}"
                                     style="width: {{ $row['pct'] }}%"></div>
                            </div>
                        </div>
                        <span class="w-12 text-right font-mono {{ $row['pct'] >= 35 ? 'text-gold-400' : 'text-bone-300' }}">{{ $row['pct'] }}%</span>
                    </div>
                </div>
            @endforeach
            @if (empty($perRound))
                <div class="p-8 text-center text-bone-400">No completed rounds with predictions yet.</div>
            @endif
        </div>
    </section>

    {{-- Signal effectiveness --}}
    <section>
        <div class="lbl mb-3">Signal Effectiveness</div>
        <p class="mb-3 text-xs text-bone-400">Which signals are strongest when predictions hit vs miss? Positive delta = signal is predictive.</p>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-bone-400">
                    <tr>
                        <th class="px-4 py-3 text-left text-[11px] uppercase tracking-wider font-medium">Signal</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Avg (hits)</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Avg (misses)</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Delta</th>
                        <th class="px-4 py-3 text-right text-[11px] uppercase tracking-wider font-medium">Hit rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-700">
                    @foreach ($signalEffectiveness as $sig)
                        <tr>
                            <td class="px-4 py-3 text-bone-200">{{ str_replace('_', ' ', $sig['type']) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-bone-300">{{ $sig['avg_hit_strength'] }}</td>
                            <td class="px-4 py-3 text-right font-mono text-bone-300">{{ $sig['avg_miss_strength'] }}</td>
                            <td class="px-4 py-3 text-right font-mono {{ $sig['delta'] > 0 ? 'text-gold-400' : ($sig['delta'] < 0 ? 'text-signal-red' : 'text-bone-400') }}">
                                {{ $sig['delta'] > 0 ? '+' : '' }}{{ $sig['delta'] }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-bone-300">{{ $sig['hit_rate'] }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
