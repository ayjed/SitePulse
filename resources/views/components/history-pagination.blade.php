@props(['kind', 'action', 'previous', 'next', 'away'])

@if ($previous || $next || $away)
    <nav aria-label="{{ ucfirst($kind) }} history pages" class="flex flex-wrap gap-2 border-t border-stone-200 px-4 py-3 sm:px-6 dark:border-stone-800">
        @if ($away && !$previous && !$next)
            <button type="button" wire:click="{{ $action }}" wire:loading.class="opacity-70 pointer-events-none" class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:hover:bg-stone-800">First {{ $kind }} page</button>
        @else
            <button type="button" wire:click="{{ $action }}('{{ $previous }}')" wire:loading.class="opacity-70 pointer-events-none" @disabled(!$previous) class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 disabled:opacity-50 dark:border-stone-600 dark:hover:bg-stone-800">Previous {{ $kind }} page</button>
            <button type="button" wire:click="{{ $action }}('{{ $next }}')" wire:loading.class="opacity-70 pointer-events-none" @disabled(!$next) class="min-h-[44px] rounded-md border border-stone-400 px-3 text-sm font-medium hover:bg-stone-100 disabled:opacity-50 dark:border-stone-600 dark:hover:bg-stone-800">Next {{ $kind }} page</button>
        @endif
    </nav>
@endif
