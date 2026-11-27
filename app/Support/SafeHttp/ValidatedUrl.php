<?php

namespace App\Support\SafeHttp;

/**
 * Immutable canonical representation of a validated HTTP or HTTPS destination URL.
 */
final readonly class ValidatedUrl
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public bool $isIpLiteral,
        public ?int $ipVersion,
        public string $pathAndQuery,
    ) {}

    /**
     * Format host suitable for URI authorities, bracketing IPv6 literals.
     */
    public function authorityHost(): string
    {
        return $this->ipVersion === 6 ? "[{$this->host}]" : $this->host;
    }

    /**
     * Canonical scheme + authority excluding default ports for HTTP headers/requests.
     */
    public function origin(): string
    {
        $isDefaultPort = ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);

        $authority = $this->authorityHost();

        return $isDefaultPort
            ? "{$this->scheme}://{$authority}"
            : "{$this->scheme}://{$authority}:{$this->port}";
    }

    /**
     * Normalized cache and loop-detection key including explicit port.
     */
    public function canonicalKey(): string
    {
        return "{$this->scheme}://{$this->authorityHost()}:{$this->port}{$this->pathAndQuery}";
    }
}
