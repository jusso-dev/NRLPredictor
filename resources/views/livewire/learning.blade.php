<div class="space-y-8">
    <div>
        <h1 class="h-display text-2xl text-bone-50 sm:text-3xl">Self-Tuning Engine</h1>
        <p class="mt-1 text-sm text-bone-400">Round outcomes update future signal weights, test compound patterns, and pass proven context into the next AI review.</p>
    </div>

    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <div class="lbl">Observed Outcome Trend</div>
                <p class="mt-1 text-xs text-bone-400">Direct top-five results from fully completed rounds. The rolling comparison uses stable four-round windows.</p>
            </div>
            @if ($outcomeTrend['completed_rounds'] > 0)
                <span class="chip {{ $outcomeTrend['state'] === 'improving' ? 'chip-green' : ($outcomeTrend['state'] === 'declining' ? 'chip-red' : 'chip-gold') }}">
                    {{ $outcomeTrend['state'] }}
                </span>
            @endif
        </div>

        @if ($outcomeTrend['completed_rounds'] > 0)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Latest completed round</div>
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($outcomeTrend['latest_accuracy'], 1) }}%</div>
                    <div class="mt-1 text-xs text-bone-400">Observed top-five hit rate</div>
                </div>
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Rolling four rounds</div>
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($outcomeTrend['rolling_accuracy'], 1) }}%</div>
                    <div class="mt-1 text-xs text-bone-400">Weighted by graded picks</div>
                </div>
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Observed change</div>
                    @if ($outcomeTrend['rolling_change'] !== null)
                        <div class="mt-2 font-mono text-2xl {{ $outcomeTrend['rolling_change'] > 0 ? 'text-gold-400' : ($outcomeTrend['rolling_change'] < 0 ? 'text-signal-red' : 'text-bone-50') }}">
                            {{ $outcomeTrend['rolling_change'] > 0 ? '+' : '' }}{{ number_format($outcomeTrend['rolling_change'], 1) }} pts
                        </div>
                        <div class="mt-1 text-xs text-bone-400">vs prior four-round window</div>
                    @else
                        <div class="mt-2 text-sm text-bone-500">Needs five completed rounds</div>
                    @endif
                </div>
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Result coverage</div>
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($outcomeTrend['coverage_pct'], 1) }}%</div>
                    <div class="mt-1 text-xs text-bone-400">{{ $outcomeTrend['graded_matches'] }}/{{ $outcomeTrend['completed_matches'] }} completed matches graded</div>
                </div>
            </div>

            <div class="card mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-bone-400">
                        <tr>
                            <th class="px-4 py-3 text-left text-[11px] font-medium uppercase tracking-wider">Round</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Hits</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Observed</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Rolling</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Coverage</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-700">
                        @foreach (array_slice($outcomeTrend['rounds'], -8) as $roundTrend)
                            <tr>
                                <td class="px-4 py-3 text-bone-200">R{{ $roundTrend['round'] }}</td>
                                <td class="px-4 py-3 text-right font-mono text-bone-300">{{ $roundTrend['hits'] }}/{{ $roundTrend['picks'] }}</td>
                                <td class="px-4 py-3 text-right font-mono text-bone-100">{{ number_format($roundTrend['accuracy'], 1) }}%</td>
                                <td class="px-4 py-3 text-right font-mono text-gold-400">{{ number_format($roundTrend['rolling_accuracy'], 1) }}%</td>
                                <td class="px-4 py-3 text-right text-bone-400">{{ $roundTrend['graded_matches'] }}/{{ $roundTrend['completed_matches'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="card p-6 text-sm text-bone-400">No fully completed round evidence yet.</div>
        @endif
    </section>

    {{-- Candidate validation proof --}}
    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <div class="lbl">Update Validation</div>
                <p class="mt-1 text-xs text-bone-400">Rolling validation is reranked from saved pre-match signals. Unsafe updates are rejected.</p>
            </div>
            @if ($latest)
                <span class="chip {{ $learningSummary['state'] === 'improved' ? 'chip-green' : ($learningSummary['state'] === 'regressed' ? 'chip-red' : 'chip-gold') }}">
                    {{ str_replace('_', ' ', $learningSummary['state']) }} after R{{ $latest->after_round }}
                </span>
            @endif
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Validation top-5</div>
                @if ($latest && $latest->accuracy_after !== null)
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($latest->accuracy_after, 2) }}%</div>
                    <div class="mt-1 text-xs {{ ($learningSummary['top5_round_change'] ?? 0) > 0 ? 'text-gold-400' : 'text-bone-400' }}">
                        {{ ($learningSummary['top5_round_change'] ?? 0) > 0 ? '+' : '' }}{{ number_format($learningSummary['top5_round_change'] ?? 0, 2) }} points this update
                    </div>
                @else
                    <div class="mt-2 text-sm text-bone-500">Waiting for graded round</div>
                @endif
            </div>
            <div class="card p-4">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Ranking quality (NDCG)</div>
                @if ($latest && $latest->ndcg_after !== null)
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($latest->ndcg_after, 4) }}</div>
                    <div class="mt-1 text-xs {{ ($learningSummary['ndcg_round_change'] ?? 0) > 0 ? 'text-gold-400' : 'text-bone-400' }}">
                        {{ ($learningSummary['ndcg_round_change'] ?? 0) > 0 ? '+' : '' }}{{ number_format($learningSummary['ndcg_round_change'] ?? 0, 4) }} this update
                    </div>
                @else
                    <div class="mt-2 text-sm text-bone-500">Recorded after next tuning run</div>
                @endif
            </div>
            <div class="card p-4">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Learning runs</div>
                <div class="mt-2 font-mono text-2xl text-bone-50">{{ $learningSummary['accepted_runs'] }}/{{ $learningSummary['runs'] }}</div>
                <div class="mt-1 text-xs text-bone-400">candidate updates accepted</div>
            </div>
            <div class="card p-4">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Update guardrail</div>
                @if ($latest)
                    <div class="mt-2 text-lg font-semibold {{ $latest->candidate_accepted === false ? 'text-signal-orange' : 'text-gold-400' }}">
                        {{ $latest->candidate_accepted === null ? 'Legacy run' : ($latest->candidate_accepted === false ? 'Candidate rejected' : 'Non-regressive update') }}
                    </div>
                    <div class="mt-1 text-xs text-bone-400">
                        {{ $latest->candidate_accepted === null ? 'Detailed guardrail evidence starts next run' : (($latest->validation_matches ?? 0).' matches checked') }}
                        @if ($latest->learning_step !== null)
                            · {{ number_format($latest->learning_step * 100, 0) }}% learning step
                        @endif
                    </div>
                @else
                    <div class="mt-2 text-sm text-bone-500">No tuning runs yet</div>
                @endif
            </div>
        </div>
    </section>

    <section class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
            <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">AI review layer</div>
            <div class="mt-1 text-sm text-bone-100">
                @if ($aiSummary['state'] === 'success')
                    Available — AI context review is enriching the statistical ranking.
                @elseif ($aiSummary['state'] === 'running')
                    Running — a match review is in progress.
                @elseif ($aiSummary['state'] === 'failed')
                    Unavailable — deterministic rankings continue, but AI enrichment needs attention.
                @else
                    Waiting for the first AI match review.
                @endif
            </div>
            @if ($aiSummary['last_run'])
                <div class="mt-1 text-xs text-bone-400">
                    Last attempt {{ $aiSummary['last_run']->diffForHumans() }} · {{ $aiSummary['failures_24h'] }} failed in 24 hours
                </div>
            @endif
        </div>
        <span class="chip {{ $aiSummary['state'] === 'success' ? 'chip-green' : ($aiSummary['state'] === 'failed' ? 'chip-red' : 'chip-gold') }}">
            {{ $aiSummary['state'] === 'success' ? 'healthy' : $aiSummary['state'] }}
        </span>
    </section>

    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <div class="lbl">AI Outcome Lift</div>
                <p class="mt-1 text-xs text-bone-400">Completed matches compare the untouched statistical top five with the final AI-reviewed top five.</p>
            </div>
            @if ($aiImpact['reviewed_matches'] > 0)
                <span class="chip {{ $aiImpact['lift'] > 0 ? 'chip-green' : ($aiImpact['lift'] < 0 ? 'chip-red' : 'chip-gold') }}">
                    {{ $aiImpact['lift'] > 0 ? '+' : '' }}{{ number_format($aiImpact['lift'], 2) }} pts AI lift
                </span>
            @endif
        </div>

        @if ($aiImpact['reviewed_matches'] > 0)
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Statistical top-5</div>
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($aiImpact['statistical_top5_accuracy'], 2) }}%</div>
                    <div class="mt-1 text-xs text-bone-400">Saved before AI review</div>
                </div>
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">AI-reviewed top-5</div>
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ number_format($aiImpact['ai_top5_accuracy'], 2) }}%</div>
                    <div class="mt-1 text-xs text-bone-400">Final recommendation order</div>
                </div>
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Match impact</div>
                    <div class="mt-2 font-mono text-lg text-bone-50">{{ $aiImpact['helped_matches'] }} helped · {{ $aiImpact['hurt_matches'] }} hurt</div>
                    <div class="mt-1 text-xs text-bone-400">{{ $aiImpact['held_matches'] }} unchanged</div>
                </div>
                <div class="card p-4">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-bone-400">Evidence coverage</div>
                    <div class="mt-2 font-mono text-2xl text-bone-50">{{ $aiImpact['reviewed_matches'] }}</div>
                    <div class="mt-1 text-xs text-bone-400">matches · {{ $aiImpact['adjusted_predictions'] }} player reviews</div>
                </div>
            </div>

            <div class="card mt-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-bone-400">
                        <tr>
                            <th class="px-4 py-3 text-left text-[11px] font-medium uppercase tracking-wider">Round</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Statistical</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">AI reviewed</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Lift</th>
                            <th class="px-4 py-3 text-right text-[11px] font-medium uppercase tracking-wider">Matches</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-700">
                        @foreach ($aiImpact['rounds'] as $roundImpact)
                            <tr>
                                <td class="px-4 py-3 text-bone-200">R{{ $roundImpact['round'] }}</td>
                                <td class="px-4 py-3 text-right font-mono text-bone-300">{{ number_format($roundImpact['statistical_top5_accuracy'], 2) }}%</td>
                                <td class="px-4 py-3 text-right font-mono text-bone-100">{{ number_format($roundImpact['ai_top5_accuracy'], 2) }}%</td>
                                <td class="px-4 py-3 text-right font-mono {{ $roundImpact['lift'] > 0 ? 'text-gold-400' : ($roundImpact['lift'] < 0 ? 'text-signal-red' : 'text-bone-400') }}">
                                    {{ $roundImpact['lift'] > 0 ? '+' : '' }}{{ number_format($roundImpact['lift'], 2) }}
                                </td>
                                <td class="px-4 py-3 text-right text-bone-400">{{ $roundImpact['reviewed_matches'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="card p-6 text-sm text-bone-400">
                Tracking starts after the next AI-reviewed match is completed. Older matches are excluded because their original statistical scores were overwritten and cannot be reconstructed honestly.
            </div>
        @endif
    </section>

    {{-- Discovered compound patterns --}}
    <section>
        <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
            <div>
                <div class="lbl">Patterns Found in Results</div>
                <p class="mt-1 text-xs text-bone-400">Combinations compare their scorer hit rate with all graded picks. Small samples stay labelled early evidence.</p>
            </div>
            @if ($patternEvidence['through_round'])
                <span class="chip chip-gold">{{ $patternEvidence['source'] }} through R{{ $patternEvidence['through_round'] }}</span>
            @endif
        </div>
        @if ($patternEvidence['matches'] !== null)
            <div class="mb-3 text-xs text-bone-400">
                {{ $patternEvidence['predictions'] }} predictions across {{ $patternEvidence['matches'] }} matches · {{ number_format($patternEvidence['baseline'], 1) }}% scorer baseline
            </div>
        @endif
        <div class="grid gap-3 md:grid-cols-2">
            @forelse ($latestPatterns as $pattern)
                <div class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-semibold text-bone-100">{{ $pattern['label'] }}</div>
                                <span class="chip {{ ($pattern['is_actionable'] ?? false) ? 'chip-green' : 'chip-gold' }}">
                                    {{ ($pattern['is_actionable'] ?? false) ? 'ranking active' : 'observing' }}
                                </span>
                            </div>
                            <div class="mt-1 text-xs text-bone-400">
                                {{ $pattern['hit_count'] }}/{{ $pattern['sample_size'] }} hit · {{ $pattern['rounds'] }} round{{ $pattern['rounds'] === 1 ? '' : 's' }} · {{ $pattern['confidence'] }} evidence
                                @if (isset($pattern['direction_consistency']))
                                    · {{ number_format($pattern['direction_consistency'] * 100, 0) }}% direction consistency
                                @endif
                            </div>
                        </div>
                        <span class="font-mono text-sm {{ $pattern['lift'] > 0 ? 'text-gold-400' : 'text-signal-red' }}">
                            {{ $pattern['lift'] > 0 ? '+' : '' }}{{ number_format($pattern['lift'], 1) }} pts
                        </span>
                    </div>
                    <div class="mt-3 flex items-center gap-2 text-xs text-bone-400">
                        <span>Pattern {{ number_format($pattern['hit_rate'], 1) }}%</span>
                        <span>vs</span>
                        <span>baseline {{ number_format($pattern['baseline_hit_rate'], 1) }}%</span>
                    </div>
                </div>
            @empty
                <div class="card p-6 text-sm text-bone-400 md:col-span-2">No compound pattern has enough completed-game evidence yet. Milestone combinations will appear as early evidence from their first observed result.</div>
            @endforelse
        </div>
    </section>

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
                            @if ($adj->accuracy_before !== null)
                                <span class="chip chip-gold">
                                    validation {{ $adj->accuracy_before }}%
                                    @if ($adj->accuracy_after !== null)
                                        → {{ $adj->accuracy_after }}%
                                    @endif
                                </span>
                            @endif
                            @if ($adj->candidate_accepted !== null)
                                <span class="chip {{ $adj->candidate_accepted ? 'chip-green' : 'chip-red' }}">
                                    {{ $adj->candidate_accepted ? 'accepted' : 'rejected' }}
                                </span>
                            @endif
                            <span class="text-xs text-bone-400">{{ $adj->created_at->format('D j M H:i') }}</span>
                        </div>
                    </div>

                    @if ($adj->reasoning)
                        <pre class="text-xs text-bone-300 whitespace-pre-wrap font-mono bg-ink-900 rounded p-3 mb-3">{{ $adj->reasoning }}</pre>
                    @endif

                    @if ($adj->ndcg_before !== null && $adj->ndcg_after !== null)
                        <div class="mb-3 text-xs text-bone-400">
                            NDCG <span class="font-mono text-bone-200">{{ number_format($adj->ndcg_before, 4) }} → {{ number_format($adj->ndcg_after, 4) }}</span>
                            · {{ $adj->validation_matches }} validation matches
                            @if ($adj->learning_step !== null)
                                · {{ number_format($adj->learning_step * 100, 0) }}% step
                            @endif
                        </div>
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
