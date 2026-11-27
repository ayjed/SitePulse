<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an outbound HTTP destination violates SitePulse URL, IP, DNS, redirect, or peer SSRF policy.
 */
class BlockedDestinationException extends RuntimeException
{
    public const FAILURE_REASON = 'SSRF_BLOCKED';
}
