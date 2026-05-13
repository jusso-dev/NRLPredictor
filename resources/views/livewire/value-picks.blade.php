<div class="space-y-8">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="lbl mb-2">Model vs market</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Value picks</h1>
            <p class="mt-1 max-w-xl text-xs text-bone-400">
                Predictions where our calibrated probability meaningfully exceeds the bookmaker market. Edge = model % − implied market %.
            </p>
        </div>

        @php($confTone = match ($confidence['tier']) {
            'high' => 'border-signal-green/50 bg-signal-green/10 text-signal-green',
            'moderate' => 'border-gold-500/50 bg-gold-500/10 text-gold-500',
            'low' => 'border-signal-red/50 bg-signal-red/10 text-signal-red',
            default => 'border-ink-600 bg-ink-950 text-bone-400',
        })
        <div class="rounded border {{ $confTone }} px-4 py-3 text-xs sm:text-right">
            <div class="lbl">{{ $confidence['label'] }}</div>
            <div class="mt-1 max-w-xs text-[11px] leading-snug opacity-90">{{ $confidence['note'] }}</div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="lbl mr-2">Minimum edge</span>
        @foreach ([0.02 => '+2pp', 0.05 => '+5pp', 0.08 => '+8pp', 0.12 => '+12pp'] as $value => $label)
            @php($active = abs($threshold - $value) < 0.001)
            <button type="button"
                    wire:click="setThreshold({{ $value }})"
                    class="rounded border px-3 py-1 font-mono uppercase tracking-wide transition
                           {{ $active ? 'border-gold-500 bg-gold-500/20 text-gold-500' : 'border-ink-600 bg-ink-950 text-bone-400 hover:text-bone-100' }}">
                {{ $label }}
            </button>
        @endforeach
        <span class="ml-auto text-bone-500">{{ $picks->count() }} pick{{ $picks->count() === 1 ? '' : 's' }}</span>
    </div>

    @forelse ($picks as $pick)
        @php($pred = $pick['prediction'])
        @php($match = $pick['match'])
        <section class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <a href="{{ $match ? route('match.detail', $match->id) : '#' }}"
                       class="truncate text-base text-bone-50 hover:text-gold-500">
                        {{ $pred->player?->name ?? 'Unknown' }}
                    </a>
                    @foreach ($pred->advantageTags() as $tag)
                        <span class="rounded px-2 py-0.5 font-mono text-[10px] uppercase tracking-wide {{ $tag['class'] }}">
                            {{ $tag['label'] }}
                        </span>
                    @endforeach
                </div>
                <div class="text-xs text-bone-400">
                    @if ($match)
                        {{ $match->homeTeam->short_name }} v {{ $match->awayTeam->short_name }}
                        · {{ optional($match->kickoff_at)->format('D j M, H:i') ?: 'TBC' }}
                        · R{{ $match->round?->round_number ?? '—' }}
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 text-right sm:gap-6">
                <div>
                    <div class="lbl">Model</div>
                    <div class="font-mono text-sm text-bone-50">{{ number_format($pick['model_pct'], 1) }}%</div>
                    @if ($pick['fair_decimal'])
                        <div class="text-[10px] text-bone-500">fair {{ number_format($pick['fair_decimal'], 2) }}</div>
                    @endif
                </div>
                <div>
                    <div class="lbl">Market</div>
                    <div class="font-mono text-sm text-bone-200">{{ number_format($pick['market_pct'], 1) }}%</div>
                    @if ($pick['best_decimal'])
                        <div class="text-[10px] text-gold-500">best {{ number_format($pick['best_decimal'], 2) }}</div>
                    @endif
                </div>
                <div>
                    <div class="lbl">Edge</div>
                    <div class="font-mono text-sm text-signal-green">
                        +{{ number_format($pick['edge'] * 100, 1) }}pp
                    </div>
                </div>
            </div>
        </section>
    @empty
        <div class="card p-10 text-center text-bone-400">
            <div class="h-display text-base text-bone-200">No value picks at this threshold</div>
            <p class="mt-2 text-xs">
                Either no upcoming matches have bookmaker odds yet, or the model agrees with the market across the board. Lower the edge filter or check back closer to kickoff.
            </p>
        </div>
    @endforelse
</div>
