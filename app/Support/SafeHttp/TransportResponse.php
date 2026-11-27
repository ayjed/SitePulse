<?php

namespace App\Support\SafeHttp;

/**
 * Raw low-level outcome returned by HttpTransportInterface before post-connect validation.
 */
final readonly class TransportResponse
{
    /**
     * @param  array<string, array<int, string>>  $headers
     * @param  array<int, array<string, mixed>>  $certInfo
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public bool $bodyTruncated,
        public int $bytesReceived,
        public ?string $primaryIp,
        public array $certInfo = [],
        public int $curlErrno = 0,
        public string $curlError = '',
        public int $elapsedMs = 0,
        public bool $headerLimitExceeded = false,
    ) {}
}
