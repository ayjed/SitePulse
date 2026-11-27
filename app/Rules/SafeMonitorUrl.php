<?php

namespace App\Rules;

use App\Exceptions\BlockedDestinationException;
use App\Support\SafeHttp\SafeHttpClient;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates monitor target URLs strictly via SafeHttpClient::validateUrl() without DNS or network I/O.
 */
class SafeMonitorUrl implements ValidationRule
{
    public function __construct(
        private readonly SafeHttpClient $safeHttpClient,
    ) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The monitor URL must be a canonical http:// or https:// web address.');

            return;
        }

        try {
            $this->safeHttpClient->validateUrl($value);
        } catch (BlockedDestinationException) {
            $fail('The monitor URL is not permitted. Only lowercase http:// or https:// public hosts on ports 80, 443, 8080, or 8443 without credentials or restricted IP addresses are allowed.');
        }
    }
}
