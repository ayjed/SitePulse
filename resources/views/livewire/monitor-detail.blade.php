<div class="space-y-5" x-data="{
    historyPending: false,
    async runHistoryAction(action, ...parameters) {
        if (this.historyPending) return;
        this.historyPending = true;
        try {
            await this.$wire[action](...parameters);
        } finally {
            this.historyPending = false;
        }
    },
}">
    <section aria-labelledby="main-content" class="rounded-xl border border-stone-200 bg-white shadow-xs dark:border-stone-800 dark:bg-stone-900">
        <div class="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-6">
            <div class="min-w-0">
                <h1 id="main-content" tabindex="-1" class="wrap-anywhere text-2xl font-semibold tracking-tight sm:text-3xl">{{ $monitor->name }}</h1>
                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <x-monitor-status-badge :status="$monitor->status" />
                    <p class="text-sm text-stone-700 dark:text-stone-300">
                        Last recorded check
                        @if ($monitor->last_checked_at)
                            <time datetime="{{ $monitor->last_checked_at->utc()->toIso8601String() }}">{{ $monitor->last_checked_at->utc()->format('M j, Y H:i:s') }} UTC</time>
                        @else
                            <span class="font-medium">Never checked yet</span>
                        @endif
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('monitors.edit', $monitor) }}" class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:hover:bg-stone-800">Edit</a>
                <form method="POST" action="{{ route($monitor->isPaused() ? 'monitors.resume' : 'monitors.pause', $monitor) }}">
                    @csrf
                    <button type="submit" class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:hover:bg-stone-800">{{ $monitor->isPaused() ? 'Resume Monitor' : 'Pause Monitor' }}</button>
                </form>
                <details class="group max-w-full">
                    <summary class="inline-flex min-h-[44px] cursor-pointer list-none items-center rounded-md px-3 text-sm font-medium text-rose-800 hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:text-rose-300 dark:hover:bg-rose-950">
                        <span class="group-open:hidden">Delete Monitor</span>
                        <span class="hidden group-open:inline">Cancel deletion</span>
                    </summary>
                    <form
                        method="POST"
                        action="{{ route('monitors.destroy', $monitor) }}"
                        class="mt-2 flex max-w-xs flex-col items-start gap-3 rounded-md border border-rose-300 bg-rose-50 p-3 dark:border-rose-800 dark:bg-rose-950/40"
                        x-data="{ deletionPending: false }"
                        x-on:submit="if (deletionPending) { $event.preventDefault() } else { deletionPending = true }"
                    >
                        @csrf
                        @method('DELETE')
                        <p id="monitor-delete-warning" class="wrap-anywhere text-sm text-rose-950 dark:text-rose-100">
                            Permanently delete {{ $monitor->name }} and its check and incident history.
                        </p>
                        <button type="submit" aria-describedby="monitor-delete-warning" x-bind:disabled="deletionPending" class="min-h-[44px] rounded-md bg-rose-800 px-3 text-sm font-medium text-white hover:bg-rose-900 disabled:opacity-60">
                            Permanently delete monitor
                        </button>
                    </form>
                </details>
            </div>
        </div>
        <dl aria-label="Monitor configuration" class="grid grid-cols-2 gap-x-6 gap-y-3 rounded-b-xl border-t border-stone-200 bg-stone-50 p-4 text-sm sm:grid-cols-3 sm:p-6 dark:border-stone-800 dark:bg-stone-950/40">
            <div class="col-span-full">
                <dt class="text-stone-600 dark:text-stone-400">Target URL</dt>
                <dd class="wrap-anywhere font-mono">{{ $monitor->url }}</dd>
            </div>
            <div><dt class="text-stone-600 dark:text-stone-400">Check interval</dt><dd>Every {{ $monitor->check_interval_minutes }} {{ \Illuminate\Support\Str::plural('minute', $monitor->check_interval_minutes) }}</dd></div>
            <div><dt class="text-stone-600 dark:text-stone-400">Request timeout</dt><dd>{{ $monitor->timeout_seconds }} seconds</dd></div>
            <div><dt class="text-stone-600 dark:text-stone-400">Expected status range</dt><dd>{{ $monitor->expected_status_code_min }}–{{ $monitor->expected_status_code_max }}</dd></div>
        </dl>
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-stone-200 bg-white px-4 py-2 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-sm text-stone-700 dark:text-stone-300">
            History cutoff <time id="history-reference" class="font-medium tabular-nums" datetime="{{ $reference->toIso8601String() }}">{{ $reference->format('M j, Y H:i:s') }} UTC</time>
        </p>
        <div class="flex flex-wrap items-center gap-3">
            <span wire:loading.delay role="status" class="text-sm text-stone-700 dark:text-stone-300">Loading history…</span>
            <button type="button" x-on:click="runHistoryAction('refreshSnapshot')" x-bind:aria-disabled="historyPending" wire:loading.class="opacity-70" class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:hover:bg-stone-800">Refresh history</button>
        </div>
    </div>

    @if ($history === null)
        <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100">
            Monitor history is temporarily unavailable. Use Refresh history to try again.
        </div>
    @else
        <section aria-labelledby="uptime-heading" class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs sm:p-6 dark:border-stone-800 dark:bg-stone-900">
            <h2 id="uptime-heading" class="text-lg font-semibold">Observed-check uptime</h2>
            <p class="mt-1 max-w-3xl text-sm text-stone-600 dark:text-stone-300">Share of completed checks that succeeded. Paused periods without checks and other gaps contribute no samples.</p>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach (['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'] as $window => $label)
                    @php
                        $sample = $history['uptime'][$window];
                        $rate = $sample['percentage'];
                        $display = match (true) {
                            $rate === null => 'No data',
                            $sample['successfulCount'] === $sample['sampleCount'] => '100%',
                            round($rate, 2) >= 100 => '>99.99%',
                            $rate > 0 && round($rate, 2) === 0.0 => '<0.01%',
                            default => rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%',
                        };
                    @endphp
                    <div class="rounded-lg border border-stone-200 bg-stone-50 p-4 dark:border-stone-800 dark:bg-stone-950/50">
                        <dt class="text-sm font-medium text-stone-700 dark:text-stone-300">{{ $label }}</dt>
                        <dd class="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{{ $display }}</dd>
                        <dd class="mt-1 text-sm text-stone-600 tabular-nums dark:text-stone-300">{{ number_format($sample['sampleCount']) }} {{ \Illuminate\Support\Str::plural('completed check', $sample['sampleCount']) }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="mt-4 text-sm text-stone-600 dark:text-stone-300">Only retained checks are included. History remains with this monitor after a target URL change.</p>
        </section>

        <x-monitor-latency-chart :samples="$history['latencies']" />

        <section aria-labelledby="checks-heading" class="rounded-xl border border-stone-200 bg-white shadow-xs dark:border-stone-800 dark:bg-stone-900">
            <h2 id="checks-heading" class="px-4 py-4 text-lg font-semibold sm:px-6">Check history</h2>
            @if ($history['checks']->isEmpty())
                <p class="border-t border-stone-200 px-4 py-5 text-sm text-stone-700 sm:px-6 dark:border-stone-800 dark:text-stone-300">{{ $checkCursor === '' ? 'No recorded checks yet.' : 'No checks on this page.' }}</p>
            @else
                <table class="w-full table-fixed border-t border-stone-200 text-left text-sm dark:border-stone-800">
                    <thead class="bg-stone-50 text-stone-700 dark:bg-stone-950/50 dark:text-stone-300">
                        <tr><th scope="col" class="w-[36%] py-2 pl-4 pr-1 sm:pl-6">Check (UTC)</th><th scope="col" class="py-2 pr-1">Outcome</th><th scope="col" class="w-[16%] py-2 pr-1">HTTP</th><th scope="col" class="w-[21%] py-2 pr-3">Latency</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                        @foreach ($history['checks'] as $check)
                            <tr wire:key="detail-check-{{ $check->id }}">
                                <td class="py-3 pl-4 pr-1 tabular-nums sm:pl-6"><time datetime="{{ $check->checked_at->utc()->toIso8601String() }}"><span class="whitespace-nowrap">{{ $check->checked_at->utc()->format('M j, Y') }}</span> <span class="whitespace-nowrap">{{ $check->checked_at->utc()->format('H:i:s') }}</span></time></td>
                                <td class="py-3 pr-1 font-medium {{ $check->status === \App\Enums\CheckResultStatus::SUCCESS ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300' }}">{{ $check->status->label() }}</td>
                                <td class="py-3 pr-1 tabular-nums">{{ $check->status_code ?? '—' }}</td>
                                <td class="py-3 pr-3 tabular-nums">{{ $check->latency_ms !== null ? number_format($check->latency_ms).' ms' : 'No data' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <x-history-pagination kind="check" action="showChecks" :previous="$history['checkPrevious']" :next="$history['checkNext']" :away="$checkCursor !== ''" />
        </section>

        <section aria-labelledby="incidents-heading" class="rounded-xl border border-stone-200 bg-white shadow-xs dark:border-stone-800 dark:bg-stone-900">
            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <h2 id="incidents-heading" class="text-lg font-semibold">Incident history</h2>
                <label class="flex flex-wrap items-center gap-2 text-sm font-medium" for="incident-filter">
                    Status
                    <select id="incident-filter" x-on:change="runHistoryAction('filterIncidents', $el.value).finally(() => { $el.value = $wire.incidentFilter })" x-bind:aria-disabled="historyPending" wire:loading.class="opacity-70" class="min-h-[44px] rounded-md border border-stone-400 bg-white px-2 dark:border-stone-600 dark:bg-stone-900">
                        @foreach (['all' => 'All incidents', 'open' => 'Open', 'resolved' => 'Resolved'] as $value => $label)
                            <option value="{{ $value }}" @selected($incidentFilter === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="px-4 pb-4 text-sm text-stone-600 sm:px-6 dark:text-stone-300">The cutoff applies to incident start times. Status and resolution show the latest saved record.</p>
            @if ($history['incidents']->isEmpty())
                <p class="border-t border-stone-200 px-4 py-5 text-sm text-stone-700 sm:px-6 dark:border-stone-800 dark:text-stone-300">{{ $incidentCursor === '' ? 'No incidents match this filter.' : 'No incidents on this page.' }}</p>
            @else
                <ul role="list" class="divide-y divide-stone-200 border-t border-stone-200 dark:divide-stone-800 dark:border-stone-800">
                    @foreach ($history['incidents'] as $incident)
                        @php
                            $isOpen = $incident->status === \App\Enums\IncidentStatus::OPEN;
                            $seconds = $isOpen ? max(0, $reference->getTimestamp() - $incident->started_at->getTimestamp()) : $incident->duration_seconds;
                            $duration = match ($seconds) {
                                null => 'Not recorded',
                                0 => '0 s',
                                default => \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true]),
                            };
                        @endphp
                        <li wire:key="detail-incident-{{ $incident->id }}" class="space-y-3 px-4 py-4 sm:px-6">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="rounded-full border px-2.5 py-1 text-xs font-semibold {{ $isOpen ? 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200' : 'border-stone-300 bg-stone-100 text-stone-800 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-200' }}">{{ $incident->status->label() }}</span>
                                <span class="min-w-0 wrap-anywhere text-sm font-medium">{{ $incident->root_cause ?: 'Cause not recorded' }}</span>
                            </div>
                            <dl class="grid gap-2 text-sm sm:grid-cols-3">
                                <div><dt class="text-stone-600 dark:text-stone-400">Started (UTC)</dt><dd><time datetime="{{ $incident->started_at->utc()->toIso8601String() }}">{{ $incident->started_at->utc()->format('M j, Y H:i:s') }}</time></dd></div>
                                <div>
                                    <dt class="text-stone-600 dark:text-stone-400">Resolved (UTC)</dt>
                                    <dd>
                                        @if ($incident->resolved_at)
                                            <time datetime="{{ $incident->resolved_at->utc()->toIso8601String() }}">{{ $incident->resolved_at->utc()->format('M j, Y H:i:s') }}</time>
                                            @if ($incident->resolved_at->greaterThan($reference))
                                                <span class="block text-xs text-stone-600 dark:text-stone-300">Resolved after the cutoff</span>
                                            @endif
                                        @else
                                            {{ $isOpen ? 'Still open' : 'Not recorded' }}
                                        @endif
                                    </dd>
                                </div>
                                <div><dt class="text-stone-600 dark:text-stone-400">{{ $isOpen ? 'Ongoing duration' : 'Recorded duration' }}</dt><dd>{{ $duration }}</dd></div>
                            </dl>
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-history-pagination kind="incident" action="showIncidents" :previous="$history['incidentPrevious']" :next="$history['incidentNext']" :away="$incidentCursor !== ''" />
        </section>
    @endif
</div>
