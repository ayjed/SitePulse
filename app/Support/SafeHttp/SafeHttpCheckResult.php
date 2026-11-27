<?php

namespace App\Support\SafeHttp;

use DateTimeImmutable;

/**
 * Structured, sanitized monitor probe summary produced by SafeHttpClient::check().
 */
final readonly class SafeHttpCheckResult
{
    public function __construct(
        public bool $succeeded,
        public ?int $statusCode,
        public int $latencyMs,
        public ?SafeHttpFailureReason $failureReason,
        public ?string $errorMessage,
        public string $finalUrl,
        public ?string $resolvedIp,
        public int $redirectsFollowed,
        public ?DateTimeImmutable $tlsExpiresAt,
        public ?string $tlsIssuer,
        public bool $tlsExpiringSoon,
        public bool $bodyTruncated,
        public int $bytesReceived,
        public ?SafeHttpResponse $response = null,
    ) {}

    public static function success(SafeHttpResponse $response, bool $tlsExpiringSoon): self
    {
        return new self(
            succeeded: true,
            statusCode: $response->statusCode,
            latencyMs: $response->latencyMs,
            failureReason: null,
            errorMessage: null,
            finalUrl: $response->finalUrl,
            resolvedIp: $response->resolvedIp,
            redirectsFollowed: $response->redirectsFollowed,
            tlsExpiresAt: $response->tlsExpiresAt,
            tlsIssuer: $response->tlsIssuer,
            tlsExpiringSoon: $tlsExpiringSoon,
            bodyTruncated: $response->bodyTruncated,
            bytesReceived: $response->bytesReceived,
            response: $response,
        );
    }

    public static function failure(
        SafeHttpFailureReason $reason,
        string $errorMessage,
        string $finalUrl,
        int $latencyMs,
        ?int $statusCode = null,
        ?string $resolvedIp = null,
        int $redirectsFollowed = 0,
        ?DateTimeImmutable $tlsExpiresAt = null,
        ?string $tlsIssuer = null,
        bool $tlsExpiringSoon = false,
        bool $bodyTruncated = false,
        int $bytesReceived = 0,
    ): self {
        return new self(
            succeeded: false,
            statusCode: $statusCode,
            latencyMs: $latencyMs,
            failureReason: $reason,
            errorMessage: $errorMessage,
            finalUrl: $finalUrl,
            resolvedIp: $resolvedIp,
            redirectsFollowed: $redirectsFollowed,
            tlsExpiresAt: $tlsExpiresAt,
            tlsIssuer: $tlsIssuer,
            tlsExpiringSoon: $tlsExpiringSoon,
            bodyTruncated: $bodyTruncated,
            bytesReceived: $bytesReceived,
            response: null,
        );
    }
}
