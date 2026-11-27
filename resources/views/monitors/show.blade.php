@extends('layouts.app')

@section('title', $monitor->name.' | Monitors | '.config('app.name', 'SitePulse'))

@section('body')
    <div class="min-h-screen">
        @include('partials.owner-header', ['owner' => $owner])

        <main class="mx-auto max-w-4xl px-6 py-10">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm text-stone-600 dark:text-stone-400">
                        <a href="{{ route('monitors.index') }}" class="hover:underline">Monitors</a>
                    </p>
                    <h1 id="main-content" tabindex="-1" class="mt-1 text-2xl font-semibold tracking-tight text-stone-950 dark:text-stone-50">
                        {{ $monitor->name }}
                    </h1>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        href="{{ route('monitors.edit', $monitor) }}"
                        class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 bg-stone-100 px-4 py-2 text-sm font-medium text-stone-900 hover:bg-stone-200 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700"
                    >
                        Edit
                    </a>

                    @if ($monitor->isPaused())
                        <form method="POST" action="{{ route('monitors.resume', $monitor) }}">
                            @csrf
                            <button
                                type="submit"
                                class="min-h-[44px] rounded-md border border-emerald-500 px-4 py-2 text-sm font-medium text-emerald-900 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-200 dark:hover:bg-emerald-950/60"
                            >
                                Resume Monitor
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('monitors.pause', $monitor) }}">
                            @csrf
                            <button
                                type="submit"
                                class="min-h-[44px] rounded-md border border-amber-500 px-4 py-2 text-sm font-medium text-amber-950 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-950/60"
                            >
                                Pause Monitor
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('monitors.destroy', $monitor) }}">
                        @csrf
                        @method('DELETE')
                        <button
                            type="submit"
                            class="min-h-[44px] rounded-md border border-red-400 bg-red-50 px-4 py-2 text-sm font-medium text-red-900 hover:bg-red-100 dark:border-red-800 dark:bg-red-950/60 dark:text-red-200 dark:hover:bg-red-950"
                        >
                            Delete Monitor
                        </button>
                    </form>
                </div>
            </div>

            @if (session('status'))
                <div role="status" aria-live="polite" class="mt-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            <section class="mt-6 rounded-lg border border-stone-200 bg-white p-6 shadow-xs dark:border-stone-800 dark:bg-stone-900">
                <h2 class="text-lg font-medium text-stone-900 dark:text-stone-100">Configuration</h2>

                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">Target URL</dt>
                        <dd class="mt-1 break-all font-mono text-sm text-stone-950 dark:text-stone-50">{{ $monitor->url }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">Monitoring Status</dt>
                        <dd class="mt-1 text-sm font-semibold text-stone-950 dark:text-stone-50">
                            {{ $monitor->status->label() }} ({{ $monitor->is_active ? 'Active' : 'Paused' }})
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">Check Interval</dt>
                        <dd class="mt-1 text-sm text-stone-900 dark:text-stone-100">Every {{ $monitor->check_interval_minutes }} minutes</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">Request Timeout</dt>
                        <dd class="mt-1 text-sm text-stone-900 dark:text-stone-100">{{ $monitor->timeout_seconds }} seconds</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">Expected Status Range</dt>
                        <dd class="mt-1 text-sm text-stone-900 dark:text-stone-100">
                            {{ $monitor->expected_status_code_min }}–{{ $monitor->expected_status_code_max }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-600 dark:text-stone-400">Last Checked</dt>
                        <dd class="mt-1 text-sm text-stone-900 dark:text-stone-100">
                            {{ $monitor->last_checked_at?->toIso8601String() ?? 'Never checked yet' }}
                        </dd>
                    </div>
                </dl>
            </section>
        </main>
    </div>
@endsection
