@extends('layouts.app')

@section('title', 'Monitors | '.config('app.name', 'SitePulse'))

@section('body')
    <div class="min-h-screen">
        @include('partials.owner-header', ['owner' => $owner])

        <main class="mx-auto max-w-5xl px-6 py-10">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 id="main-content" tabindex="-1" class="text-2xl font-semibold tracking-tight text-stone-950 dark:text-stone-50">
                        Monitors
                    </h1>
                    <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">
                        Configure HTTP and HTTPS endpoints scoped strictly to your owner account.
                    </p>
                </div>

                <a
                    href="{{ route('monitors.create') }}"
                    class="inline-flex min-h-[44px] items-center rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-950 dark:hover:bg-stone-200"
                >
                    Add Monitor
                </a>
            </div>

            @if (session('status'))
                <div role="status" aria-live="polite" class="mt-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            @if ($monitors->isEmpty())
                <section class="mt-6 rounded-lg border border-stone-200 bg-white p-8 text-center shadow-xs dark:border-stone-800 dark:bg-stone-900">
                    <h2 class="text-base font-medium text-stone-900 dark:text-stone-100">
                        No monitors configured yet
                    </h2>
                    <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">
                        Create your first monitor to persist a validated target URL, check interval, timeout, and status expectation.
                    </p>
                    <a
                        href="{{ route('monitors.create') }}"
                        class="mt-5 inline-flex min-h-[44px] items-center rounded-md bg-stone-900 px-4 py-2 text-sm font-medium text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-950 dark:hover:bg-stone-200"
                    >
                        Create Monitor
                    </a>
                </section>
            @else
                <section class="mt-6 overflow-x-auto rounded-lg border border-stone-200 bg-white shadow-xs dark:border-stone-800 dark:bg-stone-900">
                    <table class="w-full border-collapse text-left text-sm">
                        <caption class="sr-only">Configured HTTP and HTTPS monitors</caption>
                        <thead class="border-b border-stone-200 bg-stone-50 text-xs font-semibold uppercase tracking-wide text-stone-700 dark:border-stone-800 dark:bg-stone-950 dark:text-stone-300">
                            <tr>
                                <th scope="col" class="px-4 py-3">Name &amp; URL</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Interval / Timeout</th>
                                <th scope="col" class="px-4 py-3">Expected Codes</th>
                                <th scope="col" class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200 dark:divide-stone-800">
                            @foreach ($monitors as $monitor)
                                <tr>
                                    <td class="px-4 py-4">
                                        <a href="{{ route('monitors.show', $monitor) }}" class="font-semibold text-stone-950 hover:underline dark:text-stone-50">
                                            {{ $monitor->name }}
                                        </a>
                                        <div class="mt-1 break-all font-mono text-xs text-stone-600 dark:text-stone-400">
                                            {{ $monitor->url }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center rounded-full border border-stone-300 bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-900 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100">
                                            {{ $monitor->status->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-4 text-stone-700 dark:text-stone-300">
                                        {{ $monitor->check_interval_minutes }}m / {{ $monitor->timeout_seconds }}s
                                    </td>
                                    <td class="px-4 py-4 text-stone-700 dark:text-stone-300">
                                        {{ $monitor->expected_status_code_min }}–{{ $monitor->expected_status_code_max }}
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a
                                                href="{{ route('monitors.show', $monitor) }}"
                                                class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 px-3 py-1.5 text-xs font-medium text-stone-900 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-100 dark:hover:bg-stone-800"
                                            >
                                                View
                                            </a>
                                            <a
                                                href="{{ route('monitors.edit', $monitor) }}"
                                                class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 px-3 py-1.5 text-xs font-medium text-stone-900 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-100 dark:hover:bg-stone-800"
                                            >
                                                Edit
                                            </a>
                                            @if ($monitor->isPaused())
                                                <form method="POST" action="{{ route('monitors.resume', $monitor) }}">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="min-h-[44px] rounded-md border border-emerald-500 px-3 py-1.5 text-xs font-medium text-emerald-900 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-200 dark:hover:bg-emerald-950/60"
                                                    >
                                                        Resume
                                                    </button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('monitors.pause', $monitor) }}">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="min-h-[44px] rounded-md border border-amber-500 px-3 py-1.5 text-xs font-medium text-amber-950 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-200 dark:hover:bg-amber-950/60"
                                                    >
                                                        Pause
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        </main>
    </div>
@endsection
