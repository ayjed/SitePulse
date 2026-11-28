@extends('layouts.app')

@section('title', 'Dashboard | '.config('app.name', 'SitePulse'))

@section('body')
    @include('partials.owner-header', ['owner' => $owner])

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1 id="main-content" tabindex="-1" class="text-3xl font-semibold tracking-tight">
                Dashboard
            </h1>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('monitors.index') }}" class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 bg-white px-4 text-sm font-medium hover:bg-stone-100 dark:border-stone-600 dark:bg-stone-900 dark:hover:bg-stone-800">
                    Manage Monitors
                </a>
                <a href="{{ route('monitors.create') }}" class="inline-flex min-h-[44px] items-center rounded-md bg-stone-900 px-4 text-sm font-medium text-white hover:bg-stone-700 dark:bg-stone-100 dark:text-stone-950 dark:hover:bg-white">
                    Add Monitor
                </a>
            </div>
        </div>

        <livewire:monitor-overview />
    </main>
@endsection
