<section class="mt-8 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-xs dark:border-stone-800 dark:bg-stone-900" aria-labelledby="monitor-overview-heading"
    x-data="{
        overviewPending: false,
        async loadOverview(cursor) {
            if (this.overviewPending) return;
            this.overviewPending = true;
            try {
                if (cursor === undefined) await this.$wire.$refresh();
                else await this.$wire.showPage(cursor);
            } finally {
                this.overviewPending = false;
            }
        },
    }">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-4 py-3 sm:px-6 dark:border-stone-800">
        <h2 id="monitor-overview-heading" class="text-lg font-semibold">Monitor status</h2>
        <div class="flex flex-wrap items-center gap-3">
            <span wire:loading.delay role="status" class="text-sm text-stone-700 dark:text-stone-300">Loading monitors…</span>
            <button type="button" x-on:click="loadOverview()" x-bind:aria-disabled="overviewPending.toString()" class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 aria-disabled:opacity-60 dark:border-stone-600 dark:hover:bg-stone-800">
                Refresh
            </button>
        </div>
    </div>

    @if ($monitors === null)
        <div role="alert" class="p-4 text-sm text-stone-700 sm:p-6 dark:text-stone-300">
            Monitor status is temporarily unavailable. Use Refresh to try again.
        </div>
    @elseif ($monitors->isEmpty())
        <div class="p-4 text-sm text-stone-700 sm:p-6 dark:text-stone-300">
            @if ($cursor !== '')
                <p>No monitors on this page.</p>
                <button type="button" wire:click="showPage" wire:loading.attr="disabled" class="mt-2 min-h-[44px] rounded-md px-2 font-semibold underline underline-offset-4">
                    Back to first page
                </button>
            @else
                <p class="font-semibold text-stone-900 dark:text-stone-100">No monitors configured yet</p>
                <p class="mt-1">Add a monitor to see its status here after a scheduled or CLI check completes.</p>
            @endif
        </div>
    @else
        <div class="hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,.55fr)] gap-4 border-b border-stone-200 bg-stone-50 px-6 py-3 text-sm font-semibold text-stone-700 sm:grid dark:border-stone-800 dark:bg-stone-950/50 dark:text-stone-300" aria-hidden="true">
            <span>Monitor</span>
            <span>Status</span>
            <span>Last check (UTC)</span>
            <span>Latency</span>
        </div>

        <ul role="list" aria-label="Monitors" class="divide-y divide-stone-200 dark:divide-stone-800">
            @foreach ($monitors as $monitor)
                <li wire:key="monitor-overview-{{ $monitor->id }}" class="grid grid-cols-1 items-center gap-2 px-4 py-4 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,.55fr)] sm:gap-4 sm:px-6 sm:py-2">
                    <div class="min-w-0">
                        <a href="{{ route('monitors.show', $monitor) }}" class="inline-flex min-h-[44px] max-w-full items-center rounded-sm font-semibold underline decoration-stone-400 underline-offset-4 hover:decoration-current">
                            <span class="wrap-anywhere">{{ $monitor->name }}</span>
                        </a>
                    </div>
                    <div><span class="sr-only">Status:</span> <x-monitor-status-badge :status="$monitor->status" /></div>
                    <div class="text-sm text-stone-700 dark:text-stone-300">
                        <span class="font-medium sm:sr-only">Last check (UTC):</span>
                        @if ($monitor->last_checked_at)
                            <time datetime="{{ $monitor->last_checked_at->utc()->toIso8601String() }}">{{ $monitor->last_checked_at->utc()->format('Y-m-d H:i:s') }}</time>
                        @else
                            No check recorded
                        @endif
                    </div>
                    <div class="text-sm text-stone-700 dark:text-stone-300">
                        <span class="font-medium sm:sr-only">Latency:</span>
                        @if ($monitor->last_checked_at && $monitor->last_latency_ms !== null)
                            {{ $monitor->last_latency_ms }} ms
                        @else
                            No data
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($monitors->previousCursor() || $monitors->nextCursor())
            <nav aria-label="Monitor pages" class="flex flex-wrap gap-2 border-t border-stone-200 px-4 py-3 sm:px-6 dark:border-stone-800">
                @foreach (['Previous' => $monitors->previousCursor(), 'Next' => $monitors->nextCursor()] as $direction => $pageCursor)
                    <button type="button" data-cursor="{{ $pageCursor?->encode() }}"
                        x-on:click="if ($el.dataset.cursor) loadOverview($el.dataset.cursor)"
                        x-bind:aria-disabled="(overviewPending || !$el.dataset.cursor).toString()"
                        aria-disabled="{{ $pageCursor ? 'false' : 'true' }}"
                        class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 aria-disabled:opacity-60 dark:border-stone-600 dark:hover:bg-stone-800">
                        {{ $direction }} page
                    </button>
                @endforeach
            </nav>
        @endif
    @endif
</section>
