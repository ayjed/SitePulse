@props(['samples'])

@php($chart = \App\Support\Dashboard\LatencyChart::from($samples))

<section aria-labelledby="latency-heading" class="rounded-xl border border-stone-200 bg-white p-4 shadow-xs sm:p-6 dark:border-stone-800 dark:bg-stone-900">
    <h2 id="latency-heading" class="text-lg font-semibold">Recent latency</h2>
    @if ($chart['samples']->isEmpty())
        <p class="mt-2 text-sm text-stone-700 dark:text-stone-300">No check samples yet.</p>
    @else
        <figure class="mt-2" aria-labelledby="latency-heading latency-range" aria-describedby="latency-summary">
            <figcaption class="space-y-1 text-sm text-stone-700 dark:text-stone-300">
                <p id="latency-summary">
                    {{ count($chart['points']) }} of {{ $chart['samples']->count() }} recorded checks reported latency.
                    @if ($chart['lowest'] !== null)
                        Measured range {{ number_format($chart['lowest']) }}–{{ number_format($chart['highest']) }} ms.
                    @else
                        No latency measurements in this range.
                    @endif
                </p>
                <p id="latency-range" class="tabular-nums">
                    <time datetime="{{ $chart['samples']->first()->checked_at->utc()->toIso8601String() }}">{{ $chart['samples']->first()->checked_at->utc()->format('M j, Y H:i:s') }}</time>
                    @unless ($chart['samples']->first()->checked_at->equalTo($chart['samples']->last()->checked_at))
                        <span>to</span> <time datetime="{{ $chart['samples']->last()->checked_at->utc()->toIso8601String() }}">{{ $chart['samples']->last()->checked_at->utc()->format('M j, Y H:i:s') }}</time>
                    @endunless
                    UTC
                </p>
            </figcaption>
            @if ($chart['points'] !== [])
                <div class="mt-4 flex gap-2" aria-hidden="true">
                    <div class="flex shrink-0 flex-col justify-between py-1 text-right text-xs text-stone-600 tabular-nums dark:text-stone-300">
                        <span>{{ number_format($chart['maximum']) }} ms</span>
                        <span>{{ number_format($chart['maximum'] / 2) }} ms</span>
                        <span>0 ms</span>
                    </div>
                    <svg data-latency-plot viewBox="0 0 720 160" preserveAspectRatio="none" class="h-36 min-w-0 flex-1 overflow-visible rounded-md bg-sky-50/60 sm:h-48 dark:bg-sky-950/25">
                        <path d="M 8 8 H 712 M 8 80 H 712 M 8 152 H 712" fill="none" class="stroke-sky-200 dark:stroke-sky-900" vector-effect="non-scaling-stroke" />
                        @foreach ($chart['points'] as $point)
                            <path d="M {{ $point['x'] }} {{ $point['y'] }} h 0" class="stroke-sky-700 dark:stroke-sky-300" stroke-width="7" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        @endforeach
                    </svg>
                </div>
            @endif
        </figure>

        <details wire:key="latency-sample-table" class="mt-4 rounded-md border border-stone-200 dark:border-stone-800">
            <summary class="min-h-[44px] cursor-pointer rounded-md px-3 py-3 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Latency data table</summary>
            <table class="w-full border-t border-stone-200 text-left text-sm dark:border-stone-800">
                <caption class="sr-only">Latency samples in chronological order</caption>
                <thead class="bg-stone-50 dark:bg-stone-950/50"><tr><th scope="col" class="p-2 pl-3">Check (UTC)</th><th scope="col" class="p-2">Latency</th></tr></thead>
                <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                    @foreach ($chart['samples'] as $sample)
                        <tr wire:key="latency-sample-{{ $sample->id }}">
                            <td class="p-2 pl-3 tabular-nums"><time datetime="{{ $sample->checked_at->utc()->toIso8601String() }}">{{ $sample->checked_at->utc()->format('M j, Y H:i:s') }}</time></td>
                            <td class="p-2 tabular-nums">{{ $sample->latency_ms !== null ? number_format($sample->latency_ms).' ms' : 'No data' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</section>
