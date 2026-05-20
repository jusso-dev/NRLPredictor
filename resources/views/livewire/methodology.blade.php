<div class="space-y-10">
    <section>
        <h1 class="h-display text-3xl text-bone-50">How It Works</h1>
        <p class="mt-2 max-w-3xl text-sm leading-relaxed text-bone-300">
            Every prediction is built from scraped NRL data, weighted signals, and optional AI refinement.
            No gut feel — every pick has a data trail. Here's exactly how confidence scores are calculated.
        </p>
    </section>

    {{-- Data pipeline --}}
    <section>
        <div class="lbl mb-4">Data Pipeline</div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Fixtures & Draw', 'nrl.com/draw/data', 'Match schedules, venues, kickoff times, scores', 'Daily + on sync'],
                ['Team Lists', 'nrl.com team pages', 'Named 1-17 + bench per match', 'Every 30 min'],
                ['Player Stats', 'nrl.com player profiles', 'Career games, tries, try assists, line breaks, season stats', 'Every 2 hours'],
                ['Injuries', 'nrl.com casualty ward', 'Injury type, status (out/doubt/test), player affected', 'Every 30 min'],
                ['Articles', 'nrl.com news feed', 'Team-tagged articles for narrative context (coach quotes, team news)', 'Every 6 hours'],
                ['Live Scores', 'nrl.com live data', 'In-play scores, try events, match status updates', 'Every 5 min (when live)'],
            ] as [$title, $source, $desc, $freq])
                <div class="card p-4">
                    <div class="font-medium text-bone-50">{{ $title }}</div>
                    <div class="mt-1 text-xs text-gold-400">{{ $source }}</div>
                    <div class="mt-2 text-xs text-bone-400">{{ $desc }}</div>
                    <div class="mt-2 text-[11px] text-bone-500">Refresh: {{ $freq }}</div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Try scorer signals --}}
    <section>
        <div class="lbl mb-2">Try Scorer Prediction — 11 Signals</div>
        <p class="mb-4 max-w-3xl text-xs text-bone-400">
            Each player in the team list is scored against {{ $trySignals->count() }} weighted signals.
            The maximum possible raw score is {{ $tryMaxScore }}. Scores are normalized to 0-100 per match
            (the top scorer in each match gets 100, others scale relative).
        </p>
        <div class="card divide-y divide-ink-700">
            @foreach ($trySignals as $signal)
                <div class="px-4 py-4 sm:px-5">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-bone-50">{{ str_replace('_', ' ', $signal['type']) }}</span>
                                <span class="chip chip-gold">{{ $signal['pct_of_total'] }}%</span>
                                <span class="text-xs text-bone-500">weight: {{ $signal['weight'] }}</span>
                            </div>
                            <div class="mt-1 text-sm text-bone-300">{{ $signal['description'] }}</div>
                            <div class="mt-1 text-[11px] text-bone-500">Source: {{ $signal['source'] }}</div>
                        </div>
                        <div class="hidden w-32 sm:block">
                            <div class="score-bar">
                                <div class="score-bar-fill bg-gold-500/70"
                                     style="width: {{ $signal['pct_of_total'] }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Position weights --}}
    <section>
        <div class="lbl mb-2">Position Base Weights</div>
        <p class="mb-4 max-w-3xl text-xs text-bone-400">
            The "position advantage" signal uses these base weights. Wingers and fullbacks are historically
            the most prolific try scorers, while props rarely cross the line.
        </p>
        <div class="card p-4">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($positionWeights as $pos)
                    <div class="flex items-center gap-3">
                        <span class="w-24 text-sm text-bone-200">{{ $pos['position'] }}</span>
                        <div class="score-bar flex-1">
                            <div class="score-bar-fill bg-gold-500/70"
                                 style="width: {{ $pos['pct'] }}%"></div>
                        </div>
                        <span class="w-10 text-right font-mono text-xs text-bone-400">{{ $pos['pct'] }}%</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Win prediction signals --}}
    <section>
        <div class="lbl mb-2">Match Winner Prediction — 7 Signals</div>
        <p class="mb-4 max-w-3xl text-xs text-bone-400">
            Each match gets a home/away win probability from {{ $winSignals->count() }} signals.
            The total weight pool is {{ $winMaxScore }}. Each team's signals are scored independently,
            then converted to a percentage split (e.g. 62% / 38%).
        </p>
        <div class="card divide-y divide-ink-700">
            @foreach ($winSignals as $signal)
                <div class="px-4 py-4 sm:px-5">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-bone-50">{{ str_replace('_', ' ', $signal['type']) }}</span>
                                <span class="chip chip-gold">{{ $signal['pct_of_total'] }}%</span>
                                <span class="text-xs text-bone-500">weight: {{ $signal['weight'] }}</span>
                            </div>
                            <div class="mt-1 text-sm text-bone-300">{{ $signal['description'] }}</div>
                            <div class="mt-1 text-[11px] text-bone-500">Source: {{ $signal['source'] }}</div>
                        </div>
                        <div class="hidden w-32 sm:block">
                            <div class="score-bar">
                                <div class="score-bar-fill bg-gold-500/70"
                                     style="width: {{ $signal['pct_of_total'] }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Confidence explained --}}
    <section>
        <div class="lbl mb-2">Confidence Scores Explained</div>
        <div class="card p-5 space-y-4">
            <div>
                <div class="font-medium text-bone-50">Try Scorer Score (0-100)</div>
                <div class="mt-1 text-sm text-bone-300">
                    Each signal produces a strength (0.0-1.0) multiplied by its weight. The raw total
                    is then normalized so the highest-scoring player in each match = 100. A score of 75
                    means the player's signals are 75% as strong as the top pick in that match.
                </div>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    <span class="rounded bg-signal-red/20 px-2 py-1 text-signal-red">80-100: Elite tier</span>
                    <span class="rounded bg-signal-orange/20 px-2 py-1 text-signal-orange">65-79: Strong pick</span>
                    <span class="rounded bg-signal-yellow/20 px-2 py-1 text-signal-yellow">50-64: Decent chance</span>
                    <span class="rounded bg-white/10 px-2 py-1 text-bone-400">&lt;50: Outside shot</span>
                </div>
            </div>
            <div class="border-t border-ink-700 pt-4">
                <div class="font-medium text-bone-50">Win Probability (%)</div>
                <div class="mt-1 text-sm text-bone-300">
                    Both teams are scored on the same 7 signals. Their raw scores are converted to a
                    percentage split. A 60/40 split means the model gives one team a 50% stronger signal
                    profile. The predicted winner is whoever scores higher.
                </div>
            </div>
            <div class="border-t border-ink-700 pt-4">
                <div class="font-medium text-bone-50">Multi-Bet Confidence (0-100)</div>
                <div class="mt-1 text-sm text-bone-300">
                    Each multi-bet leg gets its own confidence score based on: the win probability or
                    try probability, the number of strong signals supporting it, the player's rank
                    in that match, and the risk profile chosen (safe/balanced/value).
                    Combined probability is the product of all leg probabilities.
                </div>
            </div>
            <div class="border-t border-ink-700 pt-4">
                <div class="font-medium text-bone-50">AI Review (Optional)</div>
                <div class="mt-1 text-sm text-bone-300">
                    When enabled, an AI analyst reviews the statistical predictions using all available context:
                    team lists, injuries, venue history, recent news articles, and coaching commentary.
                    It can adjust scores by up to +/-15 points and adds written reasoning explaining
                    compound advantages or contradictions the model can't capture.
                </div>
            </div>
        </div>
    </section>

    {{-- Advantage tags --}}
    <section>
        <div class="lbl mb-2">Advantage Tags</div>
        <p class="mb-3 text-xs text-bone-400">
            These badges appear on predictions when a signal strength exceeds 60%.
        </p>
        <div class="flex flex-wrap gap-2">
            <div class="card flex items-center gap-2 px-3 py-2">
                <span class="chip chip-red">HOT FORM</span>
                <span class="text-xs text-bone-400">Scoring frequently in recent games</span>
            </div>
            <div class="card flex items-center gap-2 px-3 py-2">
                <span class="chip chip-gold">MILESTONE</span>
                <span class="text-xs text-bone-400">Near 50th/100th/150th/200th game</span>
            </div>
            <div class="card flex items-center gap-2 px-3 py-2">
                <span class="chip chip-green">VENUE</span>
                <span class="text-xs text-bone-400">Strong try record at this ground</span>
            </div>
            <div class="card flex items-center gap-2 px-3 py-2">
                <span class="chip chip-blue">MATCHUP</span>
                <span class="text-xs text-bone-400">Scores well against this opponent</span>
            </div>
            <div class="card flex items-center gap-2 px-3 py-2">
                <span class="chip chip-orange">RETURNING</span>
                <span class="text-xs text-bone-400">Back from injury or omission</span>
            </div>
            <div class="card flex items-center gap-2 px-3 py-2">
                <span class="chip chip-purple">OPP. WEAK</span>
                <span class="text-xs text-bone-400">Opponent missing key defenders</span>
            </div>
        </div>
    </section>

    <section class="pb-8">
        <div class="text-xs text-bone-500">
            All data is scraped from public nrl.com endpoints. Predictions are model-driven and
            should not be treated as betting advice. This is an independent project, not affiliated with the NRL.
        </div>
    </section>
</div>
