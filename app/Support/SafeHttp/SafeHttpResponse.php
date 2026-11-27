<?php

namespace App\Support\SafeHttp;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Validated HTTP/HTTPS response produced by SafeHttpClient after SSRF, redirect, peer, and TLS checks.
 */
final readonly class SafeHttpResponse
{
    /**
     * @param  array<string, array<int, string>>  $headers
     */
    public function __construct(
        public string $finalUrl,
        public string $resolvedIp,
        public int $statusCode,
        public array $headers,
        public string $body,
        public bool $bodyTruncated,
        public int $bytesReceived,
        public int $latencyMs,
        public int $redirectsFollowed,
        public ?DateTimeImmutable $tlsExpiresAt = null,
        public ?string $tlsIssuer = null,
    ) {}

    /**
     * Determine whether the inspected TLS leaf certificate expires within the warning window.
     */
    public function isTlsExpiringSoon(int $warningDays = 14, ?DateTimeImmutable $now = null): bool
    {
        if ($this->tlsExpiresAt === null) {
            return false;
        }

        $clock = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $threshold = $clock->modify("+{$warningDays} days");

        return $this->tlsExpiresAt <= $threshold;
    }
}
