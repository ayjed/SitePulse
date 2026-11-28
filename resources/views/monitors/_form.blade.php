@php
    $isEdit = $monitor->exists;
    $oldString = static function (string $key, string $fallback): string {
        $old = old($key);

        return is_scalar($old) ? (string) $old : $fallback;
    };

    $nameValue = $oldString('name', (string) ($monitor->name ?? ''));
    $urlValue = $oldString('url', (string) ($monitor->url ?? ''));
    $intervalValue = $oldString('check_interval_minutes', (string) ($monitor->check_interval_minutes ?? 5));
    $timeoutValue = $oldString('timeout_seconds', (string) ($monitor->timeout_seconds ?? 10));
    $minStatusValue = $oldString('expected_status_code_min', (string) ($monitor->expected_status_code_min ?? 200));
    $maxStatusValue = $oldString('expected_status_code_max', (string) ($monitor->expected_status_code_max ?? 299));
    $activeDefault = $isEdit ? (bool) $monitor->is_active : true;
    $activeValue = old('is_active') !== null
        ? in_array(old('is_active'), ['1', 1, true, 'true', 'on'], true)
        : $activeDefault;
@endphp

@if ($errors->any())
    <div id="monitor-form-errors" tabindex="-1" data-focus-error role="alert" aria-live="assertive" class="mb-6 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/60 dark:text-red-200">
        <p class="font-semibold">Please correct the highlighted configuration errors:</p>
        <ul class="mt-2 list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="space-y-5">
    <div class="flex flex-col gap-1.5">
        <label for="name" class="text-sm font-medium text-stone-900 dark:text-stone-100">
            Monitor Name <span aria-hidden="true">*</span>
        </label>
        <input
            id="name"
            name="name"
            type="text"
            maxlength="120"
            value="{{ $nameValue }}"
            required
            @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif
            class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-500 dark:bg-stone-950 dark:text-stone-50"
        >
        @error('name')
            <p id="name-error" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="url" class="text-sm font-medium text-stone-900 dark:text-stone-100">
            Target URL <span aria-hidden="true">*</span>
        </label>
        <p id="url-hint" class="text-xs text-stone-600 dark:text-stone-400">
            Must be lowercase http:// or https:// without credentials on ports 80, 443, 8080, or 8443. Private/internal destinations are blocked.
        </p>
        <input
            id="url"
            name="url"
            type="url"
            maxlength="2048"
            value="{{ $urlValue }}"
            placeholder="https://example.com/health"
            spellcheck="false"
            required
            @if ($errors->has('url')) aria-invalid="true" @endif
            aria-describedby="url-hint{{ $errors->has('url') ? ' url-error' : '' }}"
            class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-500 dark:bg-stone-950 dark:text-stone-50"
        >
        @error('url')
            <p id="url-error" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
        @enderror
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div class="flex flex-col gap-1.5">
            <label for="check_interval_minutes" class="text-sm font-medium text-stone-900 dark:text-stone-100">
                Check Interval (minutes) <span aria-hidden="true">*</span>
            </label>
            <select
                id="check_interval_minutes"
                name="check_interval_minutes"
                required
                @if ($errors->has('check_interval_minutes')) aria-invalid="true" aria-describedby="interval-error" @endif
                class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-500 dark:bg-stone-950 dark:text-stone-50"
            >
                @foreach ($allowedIntervals as $minutes)
                    <option value="{{ $minutes }}" @selected($intervalValue === (string) $minutes)>
                        Every {{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}
                    </option>
                @endforeach
            </select>
            @error('check_interval_minutes')
                <p id="interval-error" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="timeout_seconds" class="text-sm font-medium text-stone-900 dark:text-stone-100">
                Timeout (seconds, 2–15) <span aria-hidden="true">*</span>
            </label>
            <input
                id="timeout_seconds"
                name="timeout_seconds"
                type="number"
                min="2"
                max="15"
                value="{{ $timeoutValue }}"
                required
                @if ($errors->has('timeout_seconds')) aria-invalid="true" aria-describedby="timeout-error" @endif
                class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-500 dark:bg-stone-950 dark:text-stone-50"
            >
            @error('timeout_seconds')
                <p id="timeout-error" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div class="flex flex-col gap-1.5">
            <label for="expected_status_code_min" class="text-sm font-medium text-stone-900 dark:text-stone-100">
                Expected Status Code Min <span aria-hidden="true">*</span>
            </label>
            <input
                id="expected_status_code_min"
                name="expected_status_code_min"
                type="number"
                min="100"
                max="599"
                value="{{ $minStatusValue }}"
                required
                @if ($errors->has('expected_status_code_min')) aria-invalid="true" aria-describedby="min-status-error" @endif
                class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-500 dark:bg-stone-950 dark:text-stone-50"
            >
            @error('expected_status_code_min')
                <p id="min-status-error" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="expected_status_code_max" class="text-sm font-medium text-stone-900 dark:text-stone-100">
                Expected Status Code Max <span aria-hidden="true">*</span>
            </label>
            <input
                id="expected_status_code_max"
                name="expected_status_code_max"
                type="number"
                min="100"
                max="599"
                value="{{ $maxStatusValue }}"
                required
                @if ($errors->has('expected_status_code_max')) aria-invalid="true" aria-describedby="max-status-error" @endif
                class="min-h-[48px] w-full rounded-md border border-stone-500 bg-white px-3.5 py-2.5 text-base text-stone-950 dark:border-stone-500 dark:bg-stone-950 dark:text-stone-50"
            >
            @error('expected_status_code_max')
                <p id="max-status-error" class="text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="flex items-start gap-3 pt-1">
        <input type="hidden" name="is_active" value="0">
        <input
            id="is_active"
            name="is_active"
            type="checkbox"
            value="1"
            @checked($activeValue)
            class="mt-1 h-5 w-5 rounded border-stone-500 text-stone-900"
        >
        <div class="text-sm">
            <label for="is_active" class="font-medium text-stone-900 dark:text-stone-100">
                Monitor is active (eligible for future scheduled checks)
            </label>
            <p class="text-xs text-stone-600 dark:text-stone-400">
                Uncheck to save in a paused state without scheduling outbound probes.
            </p>
        </div>
    </div>
</div>
