<div class="space-y-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="lbl mb-2">Model accuracy</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Top-5 hit rate</h1>
        </div>
        <div class="sm:text-right">
            <div class="lbl">Running</div>
            <div class="h-display text-3xl text-gold-500 dark:text-gold-400 sm:text-4xl">{{ $pct }}%</div>
            <div class="text-xs text-bone-400">{{ $summary['hits'] }} / {{ $summary['total'] }} predictions</div>
        </div>
    </div>

    @forelse ($rows as $row)
        <section class="card p-5">
            <div class="mb-4 flex items-center justify-between">
                <div class="h-display text-xl text-bone-50">Round {{ $row['round']->round_number }}</div>
                <div class="text-sm text-bone-400">
                    {{ $row['hits'] }} of {{ $row['total'] }}
                    @if ($row['total'])
                        · <span class="text-gold-500 dark:text-gold-400">{{ round($row['hits'] / $row['total'] * 100, 1) }}%</span>
                    @endif
                </div>
            </div>

            @php($cal = $row['calibration'] ?? null)
            @if ($cal && $cal->brier_score !== null)
                <div class="mb-4 grid gap-3 rounded border border-ink-600 bg-ink-950/60 p-3 text-xs sm:grid-cols-4">
                    <div>
                        <div class="lbl">Brier</div>
                        <div class="font-mono text-bone-50">{{ number_format($cal->brier_score, 3) }}</div>
                        @if ($cal->market_brier !== null)
                            <div class="text-bone-500">mkt {{ number_format($cal->market_brier, 3) }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="lbl">Log loss</div>
                        <div class="font-mono text-bone-50">{{ $cal->log_loss !== null ? number_format($cal->log_loss, 3) : '—' }}</div>
                        @if ($cal->market_log_loss !== null)
                            <div class="text-bone-500">mkt {{ number_format($cal->market_log_loss, 3) }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="lbl">Value vs market</div>
                        @if ($cal->value_score !== null)
                            <div class="font-mono {{ $cal->value_score >= 0 ? 'text-signal-green' : 'text-signal-red' }}">
                                {{ ($cal->value_score >= 0 ? '+' : '') . number_format($cal->value_score, 3) }}
                            </div>
                        @else
                            <div class="font-mono text-bone-500">—</div>
                        @endif
                    </div>
                    <div>
                        <div class="lbl">Verdict</div>
                        @if ($cal->market_brier !== null)
                            <div class="font-mono {{ $cal->brier_score < $cal->market_brier ? 'text-signal-green' : 'text-bone-400' }}">
                                {{ $cal->brier_score < $cal->market_brier ? 'beats market' : 'trails market' }}
                            </div>
                        @else
                            <div class="font-mono text-bone-500">no market data</div>
                        @endif
                    </div>
                </div>
            @endif

            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($row['items'] as $item)
                    <div class="flex items-center gap-3 rounded border border-ink-600 px-3 py-2
                        {{ $item['hit'] ? 'bg-signal-green/10 border-signal-green/40' : 'bg-ink-950' }}">
                        <span class="w-6 text-center font-mono text-xs {{ $item['hit'] ? 'text-signal-green' : 'text-bone-500' }}">
                            {{ $item['hit'] ? '✓' : '·' }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm text-bone-50">{{ $item['p']->player?->name }}</div>
                            <div class="text-xs text-bone-400">
                                {{ $item['match']->homeTeam->short_name }} v {{ $item['match']->awayTeam->short_name }}
                            </div>
                        </div>
                        <span class="font-mono text-xs text-bone-200">{{ $item['p']->score }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="card p-10 text-center text-bone-400">
            No completed rounds yet. Accuracy tracking begins after the first round finishes.
        </div>
    @endforelse
</div>
