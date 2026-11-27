@extends('layouts.app')

@section('title', 'Dashboard | '.config('app.name', 'SitePulse'))

@section('body')
    <div class="min-h-screen">
        @include('partials.owner-header', ['owner' => $owner])

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
                        Authenticated
                    </span>
                </div>

                <p class="mt-3 text-sm leading-relaxed text-stone-600 dark:text-stone-400">
                    Single-owner authentication is active. Manage monitored HTTP/HTTPS endpoints now; automated check scheduling and analytics dashboards arrive in later tasks.
                </p>

                <div class="mt-5 flex flex-wrap gap-3">
                    <a
                        href="{{ route('monitors.index') }}"
                        class="inline-flex min-h-[44px] items-center rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-950 dark:hover:bg-stone-200"
                    >
                        Manage Monitors
                    </a>
                    <a
                        href="{{ route('monitors.create') }}"
                        class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 bg-stone-100 px-4 py-2 text-sm font-medium text-stone-900 hover:bg-stone-200 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700"
                    >
                        Add Monitor
                    </a>
                </div>
            </section>
        </main>
    </div>
@endsection
