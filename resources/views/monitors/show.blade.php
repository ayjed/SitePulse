@extends('layouts.app')

@section('title', $monitor->name.' | Monitors | '.config('app.name', 'SitePulse'))

@section('body')
    @include('partials.owner-header', ['owner' => $owner])

    <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
        <a href="{{ route('monitors.index') }}" class="inline-flex min-h-[44px] items-center gap-2 rounded-sm text-sm font-medium text-stone-700 hover:underline dark:text-stone-300">
            <span aria-hidden="true">←</span> All monitors
        </a>

        @if (session('status'))
            <div role="status" class="mb-4 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        <livewire:monitor-detail :monitor="$monitor" />
    </main>
@endsection
