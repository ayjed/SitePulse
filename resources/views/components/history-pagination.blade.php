@props(['kind', 'action', 'previous', 'next', 'away'])

@if ($previous || $next || $away)
    <nav aria-label="{{ ucfirst($kind) }} history pages" class="flex flex-wrap gap-2 border-t border-stone-200 px-4 py-3 sm:px-6 dark:border-stone-800">
        @if ($away && !$previous && !$next)
            <button type="button" x-on:click="runHistoryAction('{{ $action }}')" x-bind:aria-disabled="historyPending.toString()" wire:loading.class="opacity-70" class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:hover:bg-stone-800">First {{ $kind }} page</button>
        @else
            @foreach (['Previous' => $previous, 'Next' => $next] as $direction => $token)
                <button type="button" data-cursor="{{ $token }}"
                    x-on:click="if ($el.dataset.cursor) runHistoryAction('{{ $action }}', $el.dataset.cursor)"
                    x-bind:aria-disabled="(historyPending || !$el.dataset.cursor).toString()"
                    aria-disabled="{{ $token ? 'false' : 'true' }}"
                    wire:loading.class="opacity-70"
                    class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 aria-disabled:opacity-50 dark:border-stone-600 dark:hover:bg-stone-800">{{ $direction }} {{ $kind }} page</button>
            @endforeach
        @endif
    </nav>
@endif
