@extends('layouts.app')

@section('title', 'Add Monitor | '.config('app.name', 'SitePulse'))

@section('body')
    <div class="min-h-screen">
        @include('partials.owner-header', ['owner' => $owner])

        <main class="mx-auto max-w-3xl px-6 py-10">
            <h1 id="main-content" tabindex="-1" class="text-2xl font-semibold tracking-tight text-stone-950 dark:text-stone-50">
                Add Monitor
            </h1>
            <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">
                Validate and store monitor settings safely without issuing immediate outbound network requests.
            </p>

            <section class="mt-6 rounded-lg border border-stone-200 bg-white p-6 shadow-xs dark:border-stone-800 dark:bg-stone-900">
                <form method="POST" action="{{ route('monitors.store') }}" aria-labelledby="main-content">
                    @csrf

                    @include('monitors._form', [
                        'monitor' => $monitor,
                        'allowedIntervals' => $allowedIntervals,
                    ])

                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="min-h-[44px] rounded-md bg-stone-900 px-5 py-2 text-sm font-medium text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-950 dark:hover:bg-stone-200"
                        >
                            Save Monitor
                        </button>
                        <a
                            href="{{ route('monitors.index') }}"
                            class="inline-flex min-h-[44px] items-center rounded-md border border-stone-400 px-4 py-2 text-sm font-medium text-stone-900 hover:bg-stone-100 dark:border-stone-700 dark:text-stone-100 dark:hover:bg-stone-800"
                        >
                            Cancel
                        </a>
                    </div>
                </form>
            </section>
        </main>
    </div>
@endsection
