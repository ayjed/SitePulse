<?php

namespace App\Http\Requests;

use App\Models\Monitor;
use App\Rules\SafeMonitorUrl;
use App\Support\SafeHttp\SafeHttpClient;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates monitor creation and update configuration payloads against SitePulse security bounds.
 */
class MonitorConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->isOwner()) {
            return false;
        }

        /** @var Monitor|null $monitor */
        $monitor = $this->route('monitor');

        if ($monitor !== null) {
            return $user->can('update', $monitor);
        }

        return $user->can('create', Monitor::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(SafeHttpClient $safeHttpClient): array
    {
        $strictInt = $this->strictIntegerRule();

        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\x00-\x1F\x7F]+$/'],
            'url' => ['required', 'string', 'max:2048', new SafeMonitorUrl($safeHttpClient)],
            'check_interval_minutes' => ['required', $strictInt, Rule::in(Monitor::ALLOWED_INTERVALS)],
            'timeout_seconds' => [
                'required',
                $strictInt,
                'min:'.Monitor::MIN_TIMEOUT_SECONDS,
                'max:'.Monitor::MAX_TIMEOUT_SECONDS,
            ],
            'expected_status_code_min' => [
                'required',
                $strictInt,
                'min:'.Monitor::MIN_HTTP_STATUS,
                'max:'.Monitor::MAX_HTTP_STATUS,
            ],
            'expected_status_code_max' => [
                'required',
                $strictInt,
                'min:'.Monitor::MIN_HTTP_STATUS,
                'max:'.Monitor::MAX_HTTP_STATUS,
                'gte:expected_status_code_min',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function strictIntegerRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_int($value)) {
                return;
            }

            if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/', $value) === 1) {
                return;
            }

            $fail("The {$attribute} field must be an integer.");
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The monitor name must not contain control characters or line breaks.',
            'check_interval_minutes.in' => 'The check interval must be one of 1, 5, 10, 15, 30, or 60 minutes.',
            'expected_status_code_max.gte' => 'The maximum expected status code must be greater than or equal to the minimum expected status code.',
        ];
    }

    /**
     * Return validated attributes with canonicalized URL ready for Eloquent persistence.
     *
     * @return array<string, mixed>
     */
    public function normalizedConfiguration(SafeHttpClient $safeHttpClient): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'url' => $safeHttpClient->validateUrl($validated['url'])->url,
            'check_interval_minutes' => (int) $validated['check_interval_minutes'],
            'timeout_seconds' => (int) $validated['timeout_seconds'],
            'expected_status_code_min' => (int) $validated['expected_status_code_min'],
            'expected_status_code_max' => (int) $validated['expected_status_code_max'],
        ];
    }

    /**
     * Determine the intended active flag without accepting arbitrary internal monitor states.
     */
    public function desiredActiveState(bool $default = true): bool
    {
        if (! $this->exists('is_active')) {
            return $default;
        }

        return $this->boolean('is_active');
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if (is_string($this->input('name'))) {
            $updates['name'] = trim($this->input('name'));
        }

        if (is_string($this->input('url'))) {
            $updates['url'] = trim($this->input('url'));
        }

        if (! $this->exists('check_interval_minutes')) {
            $updates['check_interval_minutes'] = Monitor::DEFAULT_INTERVAL_MINUTES;
        }

        if (! $this->exists('timeout_seconds')) {
            $updates['timeout_seconds'] = Monitor::DEFAULT_TIMEOUT_SECONDS;
        }

        if (! $this->exists('expected_status_code_min')) {
            $updates['expected_status_code_min'] = Monitor::DEFAULT_EXPECTED_STATUS_MIN;
        }

        if (! $this->exists('expected_status_code_max')) {
            $updates['expected_status_code_max'] = Monitor::DEFAULT_EXPECTED_STATUS_MAX;
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }
}
