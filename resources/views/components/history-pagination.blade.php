@props(['kind', 'action', 'previous', 'next', 'away'])

@if ($previous || $next || $away)
    <nav aria-label="{{ ucfirst($kind) }} history pages" class="flex flex-wrap gap-2 border-t border-stone-200 px-4 py-3 sm:px-6 dark:border-stone-800">
        @if ($away && !$previous && !$next)
            <button type="button" x-on:click="runHistoryAction('{{ $action }}')" x-bind:aria-disabled="historyPending" wire:loading.class="opacity-70" class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:hover:bg-stone-800">First {{ $kind }} page</button>
        @else
            <button type="button" x-on:click="runHistoryAction('{{ $action }}', '{{ $previous }}')" x-bind:aria-disabled="historyPending || $el.disabled" wire:loading.class="opacity-70" @disabled(!$previous) class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 disabled:opacity-50 dark:border-stone-600 dark:hover:bg-stone-800">Previous {{ $kind }} page</button>
            <button type="button" x-on:click="runHistoryAction('{{ $action }}', '{{ $next }}')" x-bind:aria-disabled="historyPending || $el.disabled" wire:loading.class="opacity-70" @disabled(!$next) class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 disabled:opacity-50 dark:border-stone-600 dark:hover:bg-stone-800">Next {{ $kind }} page</button>
        @endif
    </nav>
@endif
