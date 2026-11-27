@extends('layouts.app')

@section('title', 'Owner Sign In | '.config('app.name', 'SitePulse'))

@section('body')
    <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
        <header class="mb-8">
            <p class="text-sm font-semibold tracking-wide text-stone-600 uppercase dark:text-stone-400">
                <a href="{{ url('/') }}" class="hover:underline">{{ config('app.name', 'SitePulse') }}</a>
            </p>
        </header>

        <main class="rounded-lg border border-stone-200 bg-white p-6 shadow-xs sm:p-8 dark:border-stone-800 dark:bg-stone-900">
            <h1 id="main-content" tabindex="-1" class="text-2xl font-semibold tracking-tight text-stone-950 dark:text-stone-50">
                Owner Sign In
            </h1>
            <p class="mt-2 text-sm text-stone-600 dark:text-stone-400">
                Sign in with your administrator credentials to manage SitePulse.
            </p>

            @if (session('status'))
                <div role="status" aria-live="polite" class="mt-6 rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            @php
                $authErrorMessage = $throttleMessage ?? ($errors->first('email') ?: $errors->first('password'));
            @endphp

            @if ($authErrorMessage)
                <div id="login-error" role="alert" aria-live="assertive" class="mt-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-900 dark:border-red-800 dark:bg-red-950/60 dark:text-red-200">
                    {{ $authErrorMessage }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" aria-labelledby="main-content" class="mt-6 space-y-5">
                @csrf

                <div class="flex flex-col gap-1.5">
                    <label for="email" class="text-sm font-medium text-stone-900 dark:text-stone-100">
                        Email address <span aria-hidden="true">*</span>
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email', $prefilledEmail ?? '') }}"
                        autocomplete="username"
                        spellcheck="false"
                        autocapitalize="none"
                        autocorrect="off"
                        enterkeyhint="next"
                        required
                        autofocus
                        @if ($authErrorMessage)
                            aria-invalid="true"
                            aria-describedby="login-error"
                        @endif
                        class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-600 dark:bg-stone-950 dark:text-stone-50"
                    >
                </div>

                <div class="flex flex-col gap-1.5">
                    <label for="password" class="text-sm font-medium text-stone-900 dark:text-stone-100">
                        Password <span aria-hidden="true">*</span>
                    </label>
                    <p id="password-hint" class="text-xs text-stone-600 dark:text-stone-400">
                        Enter the owner password created via CLI setup.
                    </p>
                    <div class="flex gap-2">
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            enterkeyhint="done"
                            required
                            @if ($authErrorMessage)
                                aria-invalid="true"
                            @endif
                            aria-describedby="password-hint{{ $authErrorMessage ? ' login-error' : '' }}"
                            class="min-h-[48px] flex-1 rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-600 dark:bg-stone-950 dark:text-stone-50"
                        >
                        <button
                            type="button"
                            data-password-toggle="password"
                            aria-pressed="false"
                            aria-controls="password"
                            aria-label="Show password"
                            class="min-h-[48px] min-w-[64px] rounded-md border border-stone-400 bg-stone-100 px-3 text-sm font-medium text-stone-900 hover:bg-stone-200 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100 dark:hover:bg-stone-700"
                        >
                            Show
                        </button>
                    </div>
                </div>

                <button
                    type="submit"
                    class="min-h-[48px] w-full rounded-md bg-stone-900 px-4 py-2.5 text-base font-medium text-white hover:bg-stone-800 dark:bg-stone-100 dark:text-stone-950 dark:hover:bg-white"
                >
                    Sign In
                </button>
            </form>
        </main>

        <footer class="mt-6 text-xs text-stone-600 dark:text-stone-400">
            <p>Single-owner instance. No public registration is available; provision an owner via <code>php artisan sitepulse:create-owner</code>.</p>
        </footer>
    </div>
@endsection
