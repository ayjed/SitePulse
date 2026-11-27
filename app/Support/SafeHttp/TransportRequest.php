<?php

namespace App\Support\SafeHttp;

/**
 * Immutable transport request pinned to a pre-validated destination IP.
 */
final readonly class TransportRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public ValidatedUrl $url,
        public string $pinnedIp,
        public string $method = 'GET',
        public int $connectTimeoutSeconds = 5,
        public int $timeoutSeconds = 10,
        public int $lowSpeedLimitBytesPerSec = 1024,
        public int $lowSpeedTimeSeconds = 5,
        public int $maxBodyBytes = 512000,
        public string $userAgent = 'SitePulse/1.0 (+https://github.com/ayjed/SitePulse)',
        public array $headers = [],
    ) {}
}
