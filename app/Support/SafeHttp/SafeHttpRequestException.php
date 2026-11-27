<?php

namespace App\Support\SafeHttp;

use RuntimeException;
use Throwable;

/**
 * Represents a non-SSRF transport, DNS, redirect-limit, or deadline failure during a safe HTTP probe.
 */
class SafeHttpRequestException extends RuntimeException
{
    public function __construct(
        public readonly SafeHttpFailureReason $reason,
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
