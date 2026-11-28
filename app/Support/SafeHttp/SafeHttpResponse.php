<?php

namespace App\Support\SafeHttp;

use DateTimeImmutable;

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
}
