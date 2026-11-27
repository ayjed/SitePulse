<?php

namespace App\Support\SafeHttp;

use InvalidArgumentException;

/**
 * Immutable transport request pinned to a pre-validated destination IP without caller-controlled headers.
 */
final readonly class TransportRequest
{
    public function __construct(
        public ValidatedUrl $url,
        public string $pinnedIp,
        public string $method = 'GET',
        public int $connectTimeoutMs = 5000,
        public int $timeoutMs = 10000,
        public int $lowSpeedLimitBytesPerSec = 1024,
        public int $lowSpeedTimeSeconds = 5,
        public int $maxBodyBytes = 512000,
        public string $userAgent = 'SitePulse/1.0 (+https://github.com/ayjed/SitePulse)',
    ) {
        if (! in_array($method, ['GET', 'HEAD'], true)) {
            throw new InvalidArgumentException('Unsupported HTTP transport method.');
        }

        if ($connectTimeoutMs < 1 || $timeoutMs < 1) {
            throw new InvalidArgumentException('HTTP transport timeouts must be at least 1 millisecond.');
        }

        if ($userAgent === '' || preg_match('/[\x00-\x1F\x7F]/', $userAgent) === 1) {
            throw new InvalidArgumentException('HTTP transport User-Agent is empty or contains unsafe control characters.');
        }
    }
}
