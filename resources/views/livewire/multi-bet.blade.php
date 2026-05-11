<div class="space-y-8">
    <section class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <div class="lbl mb-2">Multi Builder</div>
            <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">
                @if (isset($multi['round']))
                    Round {{ $multi['round'] }} Multi
                @else
                    Build Your Multi
                @endif
            </h1>
            <p class="mt-1 text-sm text-bone-400">Signal-driven multi-bet suggestions combining match winners and try scorers</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach (['safe' => 'Safe', 'balanced' => 'Balanced', 'value' => 'Value'] as $key => $label)
                <button wire:click="setRisk('{{ $key }}')"
                        class="rounded-lg px-4 py-2 text-xs font-semibold uppercase tracking-wider transition
                            {{ $risk === $key
                                ? 'bg-gold-500 text-navy-900'
                                : 'border border-ink-600 bg-ink-800 text-bone-300 hover:bg-ink-700' }}">
                    {{ $label }}
                </button>
            @endforeach

            <label class="ml-2 flex items-center gap-2 text-xs text-bone-400">
                Legs:
                <select wire:model.live="legs" class="rounded border border-ink-600 bg-ink-800 px-2 py-1.5 text-sm text-bone-50">
                    @for ($i = 2; $i <= 10; $i++)
                        <option value="{{ $i }}">{{ $i }}</option>
                    @endfor
                </select>
            </label>
        </div>
    </section>

    @if (!empty($multi['summary']['error']))
        <div class="card p-10 text-center text-bone-400">{{ $multi['summary']['error'] }}</div>
    @else
        {{-- Summary card --}}
        <div class="card border-gold-500/30 bg-gold-500/[0.04] p-5">
            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <div class="lbl mb-1">Legs</div>
                    <div class="h-display text-2xl text-bone-50">{{ $multi['summary']['total_legs'] }}</div>
                </div>
                <div>
                    <div class="lbl mb-1">Combined Probability</div>
                    <div class="h-display text-2xl text-gold-400">{{ $multi['summary']['combined_probability_pct'] }}%</div>
                </div>
                <div>
                    <div class="lbl mb-1">Confidence</div>
                    <div class="h-display text-2xl text-bone-50">{{ $multi['summary']['overall_confidence'] }}/100</div>
                </div>
                <div>
                    <div class="lbl mb-1">Risk Profile</div>
                    <div class="h-display text-2xl text-bone-50 capitalize">{{ $multi['risk_profile'] }}</div>
                </div>
            </div>
            <div class="mt-3 text-sm text-bone-300">{{ $multi['summary']['confidence_label'] }}</div>
            <div class="mt-2 text-xs text-bone-400">{{ $multi['summary']['recommendation'] }}</div>
        </div>

        {{-- Legs --}}
        <div class="space-y-3">
            @foreach ($multi['legs'] as $i => $leg)
                <div class="card p-4 sm:p-5 {{ $leg['is_value_pick'] ? 'border-signal-orange/30 bg-signal-orange/[0.04]' : '' }}">
                    <div class="flex items-start gap-4">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                            {{ $leg['type'] === 'match_winner' ? 'bg-gold-500/20 text-gold-400' : 'bg-ink-700 text-bone-300' }}
                            font-mono text-sm font-bold">
                            {{ $i + 1 }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="chip {{ $leg['type'] === 'match_winner' ? 'chip-gold' : 'chip-muted' }}">
                                    {{ $leg['type'] === 'match_winner' ? 'WINNER' : 'TRY SCORER' }}
                                </span>
                                @if ($leg['is_value_pick'])
                                    <span class="chip chip-orange">VALUE PICK</span>
                                @endif
                                <span class="text-xs text-bone-400">{{ $leg['match'] }}</span>
                            </div>

                            <div class="mt-2 flex items-center gap-3">
                                <span class="h-display text-lg text-bone-50">{{ $leg['selection'] }}</span>
                                @if (isset($leg['team']) && $leg['type'] === 'anytime_try_scorer')
                                    <span class="text-xs text-bone-400">{{ $leg['team'] }} · {{ ucfirst($leg['position'] ?? '') }}</span>
                                @endif
                            </div>

                            <div class="mt-2 flex flex-wrap items-center gap-4 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-bone-400">Probability:</span>
                                    <span class="font-mono text-gold-400">{{ $leg['probability'] }}%</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-bone-400">Confidence:</span>
                                    <span class="font-mono text-bone-200">{{ $leg['confidence'] }}/100</span>
                                </div>
                                @if (isset($leg['venue']))
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-bone-400">Venue:</span>
                                        <span class="text-bone-300">{{ $leg['venue'] }}</span>
                                    </div>
                                @endif
                                @if (isset($leg['kickoff_at']))
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-bone-400">Kickoff:</span>
                                        <span class="text-bone-300">{{ \Carbon\Carbon::parse($leg['kickoff_at'])->format('D H:i') }} AEST</span>
                                    </div>
                                @endif
                            </div>

                            <div class="mt-3 text-sm text-bone-300">{{ $leg['reasoning'] }}</div>

                            @if (!empty($leg['signals']))
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($leg['signals'] as $signal)
                                        <div class="rounded border border-ink-600 bg-ink-900 px-2 py-1 text-[11px]">
                                            <span class="text-bone-400">{{ $signal['type'] }}</span>
                                            <span class="ml-1 font-mono {{ $signal['strength'] >= 60 ? 'text-gold-400' : 'text-bone-500' }}">{{ $signal['strength'] }}%</span>
                                            @if ($signal['description'])
                                                <span class="ml-1 text-bone-500">{{ \Illuminate\Support\Str::limit($signal['description'], 40) }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if (!empty($leg['ai_reasoning']))
                                <div class="mt-2 rounded border border-ink-600 bg-ink-950 p-3 text-xs text-bone-400">
                                    <span class="text-bone-500">AI:</span> {{ $leg['ai_reasoning'] }}
                                </div>
                            @endif
                        </div>

                        <div class="hidden shrink-0 text-right sm:block">
                            <div class="font-mono text-2xl {{ $leg['probability'] >= 55 ? 'text-gold-400' : 'text-bone-300' }}">
                                {{ $leg['probability'] }}%
                            </div>
                            <div class="text-[11px] text-bone-500">probability</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if (empty($multi['legs']))
            <div class="card p-10 text-center text-bone-400">
                No suitable legs found for this round. Try a different risk profile or wait for more data.
            </div>
        @endif

        <div class="text-xs text-bone-500">
            Predictions are model-driven and should not be treated as betting advice. Gamble responsibly.
        </div>
    @endif
</div>
