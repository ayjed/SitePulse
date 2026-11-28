<?php

namespace App\Support\SafeHttp;

use App\Exceptions\BlockedDestinationException;

/**
 * Validates and canonicalizes outbound HTTP/HTTPS URLs and resolves redirect Location headers safely.
 */
class CanonicalUrlValidator
{
    /**
     * Strictly permitted lowercase URL schemes.
     *
     * @var array<int, string>
     */
    public const ALLOWED_SCHEMES = ['http', 'https'];

    public const MAX_URL_LENGTH = 2048;

    /**
     * Standard web destination ports permitted for monitoring.
     *
     * @var array<int, int>
     */
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];

    /**
     * Administrative, mail, directory, database, cache, and internal service ports explicitly denied.
     *
     * @var array<int, int>
     */
    public const BLOCKED_SENSITIVE_PORTS = [
        21, 22, 23, 25, 53, 110, 143, 389, 445, 636, 1433, 1521, 2049, 2375, 2376, 3306, 5432, 6379, 8000, 9200, 11211, 27017,
    ];

    /**
     * Explicit loopback and cloud metadata hostnames blocked prior to DNS lookup.
     *
     * @var array<int, string>
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
        'metadata',
        'metadata.google.internal',
    ];

    /**
     * Reserved local and internal hostname suffixes blocked prior to DNS lookup.
     *
     * @var array<int, string>
     */
    private const BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.localdomain',
        '.internal',
    ];

    public function __construct(
        private readonly IpAddressPolicy $ipPolicy = new IpAddressPolicy,
    ) {}

    /**
     * Strictly validate URL scheme, authority, port, host/IP syntax, and return canonical components.
     */
    public function validate(string $rawUrl): ValidatedUrl
    {
        if ($rawUrl === '' || strlen($rawUrl) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7F]/', $rawUrl) === 1) {
            throw new BlockedDestinationException('Destination URL is empty, too long, or contains invalid control characters.');
        }

        if (str_starts_with($rawUrl, 'http://')) {
            $scheme = 'http';
            $afterScheme = substr($rawUrl, 7);
        } elseif (str_starts_with($rawUrl, 'https://')) {
            $scheme = 'https';
            $afterScheme = substr($rawUrl, 8);
        } else {
            throw new BlockedDestinationException('Only lowercase http:// and https:// URLs are permitted.');
        }

        [$authority, $pathAndQuery] = $this->splitAuthorityAndPath($afterScheme);

        if ($authority === '') {
            throw new BlockedDestinationException('Destination URL is missing a target host.');
        }

        // Block credentials, backslashes, percent-encoded host tricks, and non-ASCII parser differentials.
        if (preg_match('/[@\\\\%\x80-\xFF]/', $authority) === 1) {
            throw new BlockedDestinationException('Destination URL authority contains forbidden credentials or unsafe characters.');
        }

        if (preg_match('/^[a-zA-Z0-9.:\[\]-]+$/', $authority) !== 1) {
            throw new BlockedDestinationException('Destination URL authority contains unsupported characters.');
        }

        [$rawHost, $port] = $this->parseAuthorityHostAndPort($authority, $scheme);
        $this->assertPortAllowed($port);

        $parsedByPhp = @parse_url($rawUrl);
        if (! is_array($parsedByPhp)
            || ($parsedByPhp['scheme'] ?? null) !== $scheme
            || isset($parsedByPhp['user'])
            || isset($parsedByPhp['pass'])
        ) {
            throw new BlockedDestinationException('Destination URL failed canonical structure verification.');
        }

        [$host, $isIpLiteral, $ipVersion] = $this->validateHost($rawHost);

        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $authorityHost = $ipVersion === 6 ? "[{$host}]" : $host;
        $normalizedUrl = $isDefaultPort
            ? "{$scheme}://{$authorityHost}{$pathAndQuery}"
            : "{$scheme}://{$authorityHost}:{$port}{$pathAndQuery}";

        return new ValidatedUrl(
            url: $normalizedUrl,
            scheme: $scheme,
            host: $host,
            port: $port,
            isIpLiteral: $isIpLiteral,
            ipVersion: $ipVersion,
            pathAndQuery: $pathAndQuery,
        );
    }

    /**
     * Resolve a raw HTTP Location redirect header relative to the current validated URL before re-validation.
     */
    public function resolveRedirectTarget(ValidatedUrl $currentUrl, string $location): string
    {
        $trimmed = trim($location);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x1F\x7F\s]/', $trimmed) === 1) {
            throw new BlockedDestinationException('Redirect Location header is empty, too long, or contains invalid characters.');
        }

        $beforeQueryOrFragment = strpbrk($trimmed, '?#') !== false
            ? substr($trimmed, 0, strcspn($trimmed, '?#'))
            : $trimmed;

        if (str_contains($beforeQueryOrFragment, '\\')) {
            throw new BlockedDestinationException('Redirect Location header contains unsafe backslash characters.');
        }

        // Explicit scheme redirects (including blocked schemes like file:// or gopher://) must hit validate().
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $trimmed) === 1) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '//')) {
            return "{$currentUrl->scheme}:{$trimmed}";
        }

        // Strip fragment from Location if any without losing path/query semantics.
        $fragmentPos = strpos($trimmed, '#');
        $withoutFragment = $fragmentPos === false ? $trimmed : substr($trimmed, 0, $fragmentPos);

        if ($withoutFragment === '') {
            return $currentUrl->url;
        }

        [$basePath] = $this->splitPathAndQuery($currentUrl->pathAndQuery);

        if (str_starts_with($withoutFragment, '?')) {
            return "{$currentUrl->origin()}{$basePath}{$withoutFragment}";
        }

        if (str_starts_with($withoutFragment, '/')) {
            return "{$currentUrl->origin()}{$withoutFragment}";
        }

        [$relativePath, $relativeQuery] = $this->splitPathAndQuery($withoutFragment);
        $mergedPath = $this->mergeAndNormalizeRelativePath($basePath, $relativePath);

        return "{$currentUrl->origin()}{$mergedPath}{$relativeQuery}";
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitAuthorityAndPath(string $afterScheme): array
    {
        $splitOffset = strcspn($afterScheme, '/?#');
        $authority = substr($afterScheme, 0, $splitOffset);
        $rest = substr($afterScheme, $splitOffset);

        // Strip optional client-side #fragment from HTTP request path.
        $fragmentPos = strpos($rest, '#');
        if ($fragmentPos !== false) {
            $rest = substr($rest, 0, $fragmentPos);
        }

        if ($rest === '') {
            $pathAndQuery = '/';
        } elseif (str_starts_with($rest, '?')) {
            $pathAndQuery = '/'.$rest;
        } else {
            $pathAndQuery = $rest;
        }

        return [$authority, $pathAndQuery];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseAuthorityHostAndPort(string $authority, string $scheme): array
    {
        $defaultPort = $scheme === 'https' ? 443 : 80;

        if (str_starts_with($authority, '[')) {
            $closingBracket = strpos($authority, ']');
            if ($closingBracket === false) {
                throw new BlockedDestinationException('Destination URL has an unclosed IPv6 bracket.');
            }

            $hostPart = substr($authority, 0, $closingBracket + 1);
            $remainder = substr($authority, $closingBracket + 1);

            if ($remainder === '') {
                return [$hostPart, $defaultPort];
            }

            if (! str_starts_with($remainder, ':')) {
                throw new BlockedDestinationException('Destination URL has trailing characters after IPv6 literal.');
            }

            return [$hostPart, $this->parsePort(substr($remainder, 1))];
        }

        $firstColon = strpos($authority, ':');
        if ($firstColon === false) {
            return [$authority, $defaultPort];
        }

        // Unbracketed colons > 1 indicate bare IPv6 (disallowed in URLs) or malformed authority.
        if (substr_count($authority, ':') > 1) {
            throw new BlockedDestinationException('IPv6 hosts in URLs must be enclosed in brackets.');
        }

        $hostPart = substr($authority, 0, $firstColon);
        $portPart = substr($authority, $firstColon + 1);

        return [$hostPart, $this->parsePort($portPart)];
    }

    private function parsePort(string $portPart): int
    {
        if (preg_match('/^[1-9][0-9]{0,4}$/', $portPart) !== 1) {
            throw new BlockedDestinationException('Destination URL port is malformed or contains leading zeros.');
        }

        $port = (int) $portPart;
        if ($port < 1 || $port > 65535) {
            throw new BlockedDestinationException('Destination URL port is outside valid TCP range.');
        }

        return $port;
    }

    protected function assertPortAllowed(int $port): void
    {
        if (in_array($port, self::BLOCKED_SENSITIVE_PORTS, true)) {
            throw new BlockedDestinationException("Destination port {$port} is blocked by security policy.");
        }

        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new BlockedDestinationException("Destination port {$port} is not in the permitted web port allowlist.");
        }
    }

    /**
     * @return array{0: string, 1: bool, 2: ?int}
     */
    private function validateHost(string $rawHost): array
    {
        $host = strtolower($rawHost);

        if ($host === '' || strlen($host) > 255) {
            throw new BlockedDestinationException('Destination hostname is empty or exceeds maximum length.');
        }

        if (str_starts_with($host, '[')) {
            $inner = substr($host, 1, -1);

            if (! $this->ipPolicy->isValidIpv6($inner)) {
                throw new BlockedDestinationException('Destination bracketed host must be a valid IPv6 literal.');
            }

            $normalizedIpv6 = $this->ipPolicy->assertAllowed($inner);

            return [$normalizedIpv6, true, 6];
        }

        if ($this->looksLikeNumericOrAmbiguousIpv4($host)) {
            if (! $this->ipPolicy->isCanonicalIpv4($host)) {
                throw new BlockedDestinationException("Destination host [{$host}] uses an ambiguous or non-canonical IP representation.");
            }

            $normalizedIpv4 = $this->ipPolicy->assertAllowed($host);

            return [$normalizedIpv4, true, 4];
        }

        $this->assertValidRfc1123Fqdn($host);

        return [$host, false, null];
    }

    private function looksLikeNumericOrAmbiguousIpv4(string $host): bool
    {
        $trimmed = trim($host, '.');

        if ($trimmed === '') {
            return true;
        }

        $labels = explode('.', $trimmed);

        foreach ($labels as $label) {
            if (preg_match('/^(?:0x[0-9a-f]+|0[0-7]+|[0-9]+)$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function assertValidRfc1123Fqdn(string $host): void
    {
        if (strlen($host) > 253 || str_starts_with($host, '.') || str_ends_with($host, '.')) {
            throw new BlockedDestinationException("Destination hostname [{$host}] is not a valid canonical FQDN.");
        }

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new BlockedDestinationException("Destination hostname [{$host}] is blocked by SSRF policy.");
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new BlockedDestinationException("Destination hostname [{$host}] uses a reserved internal suffix.");
            }
        }

        $labels = explode('.', $host);

        if (count($labels) < 2) {
            throw new BlockedDestinationException("Destination hostname [{$host}] must be a fully qualified domain name.");
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                throw new BlockedDestinationException("Destination hostname [{$host}] violates RFC 1123 label syntax.");
            }
        }

        $tld = $labels[count($labels) - 1];
        if (preg_match('/[a-z]/', $tld) !== 1 || str_starts_with($tld, '0x')) {
            throw new BlockedDestinationException("Destination hostname [{$host}] has an invalid top-level label.");
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPathAndQuery(string $pathAndQuery): array
    {
        $queryPos = strpos($pathAndQuery, '?');

        if ($queryPos === false) {
            return [$pathAndQuery, ''];
        }

        return [substr($pathAndQuery, 0, $queryPos), substr($pathAndQuery, $queryPos)];
    }

    private function mergeAndNormalizeRelativePath(string $basePath, string $relativePath): string
    {
        $lastSlash = strrpos($basePath, '/');
        $directory = $lastSlash === false ? '/' : substr($basePath, 0, $lastSlash + 1);
        $combined = $directory.$relativePath;

        $segments = explode('/', $combined);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        $suffix = str_ends_with($combined, '/') && count($resolved) > 0 ? '/' : '';

        return '/'.implode('/', $resolved).$suffix;
    }
}
