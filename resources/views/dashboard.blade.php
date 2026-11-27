@extends('layouts.app')

@section('title', 'Dashboard | '.config('app.name', 'SitePulse'))

@section('body')
    <div class="min-h-screen">
        <header class="border-b border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900">
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-6 py-4">
                <nav aria-label="Primary" class="flex items-center gap-6">
                    <a href="{{ route('dashboard') }}" aria-current="page" class="text-base font-semibold text-stone-950 dark:text-stone-50">
                        {{ config('app.name', 'SitePulse') }}
                    </a>
                </nav>

                <div class="flex items-center gap-4">
                    <span class="text-sm text-stone-700 dark:text-stone-300">
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

        <main class="mx-auto max-w-5xl px-6 py-10">
            <h1 id="main-content" tabindex="-1" class="text-2xl font-semibold tracking-tight text-stone-950 dark:text-stone-50">
                Dashboard
            </h1>

            <section class="mt-6 rounded-lg border border-stone-200 bg-white p-6 shadow-xs dark:border-stone-800 dark:bg-stone-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-medium text-stone-900 dark:text-stone-100">
                        Owner Session Active
                    </h2>
                    <span class="inline-flex items-center rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200">
                        Authenticated (Phase 01 Placeholder)
                    </span>
                </div>

                <p class="mt-3 text-sm leading-relaxed text-stone-600 dark:text-stone-400">
                    Single-owner authentication is active. Website monitoring setup and health-check views will be introduced in Phases 02 and 03.
                </p>
            </section>
        </main>
    </div>
@endsection
