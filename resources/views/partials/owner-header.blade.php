<header class="border-b border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-4">
        <nav aria-label="Primary" class="flex flex-wrap items-center gap-x-6">
            <a
                href="{{ route('dashboard') }}"
                @if (request()->routeIs('dashboard')) aria-current="page" @endif
                class="min-h-[44px] inline-flex items-center text-base font-semibold text-stone-950 hover:underline dark:text-stone-50"
            >
                {{ config('app.name', 'SitePulse') }}
            </a>

            <a
                href="{{ route('monitors.index') }}"
                @if (request()->routeIs('monitors.*')) aria-current="page" @endif
                class="min-h-[44px] inline-flex items-center text-sm font-medium {{ request()->routeIs('monitors.*') ? 'text-stone-950 underline underline-offset-4 dark:text-stone-50' : 'text-stone-700 hover:text-stone-950 hover:underline dark:text-stone-300 dark:hover:text-stone-50' }}"
            >
                Monitors
            </a>
        </nav>

        <div class="flex min-w-0 flex-wrap items-center gap-4">
            <span class="wrap-anywhere text-sm text-stone-700 dark:text-stone-300">
                {{ $owner->name }} ({{ $owner->email }})
            </span>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="min-h-[44px] rounded-md border border-stone-400 bg-stone-100 px-4 py-2 text-sm font-medium text-stone-900 hover:bg-stone-200 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700"
                >
                    Sign Out
                </button>
            </form>
        </div>
    </div>
</header>
