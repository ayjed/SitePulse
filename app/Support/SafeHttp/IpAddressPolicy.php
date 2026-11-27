<?php

namespace App\Support\SafeHttp;

use App\Exceptions\BlockedDestinationException;

/**
 * Enforces strict canonical IP parsing and SitePulse's IPv4/IPv6 CIDR and metadata denylist.
 */
class IpAddressPolicy
{
    private const CANONICAL_IPV4_PATTERN = '/^(?:0|[1-9][0-9]{0,2})(?:\.(?:0|[1-9][0-9]{0,2})){3}$/';

    /**
     * Reserved, private, documentation, CGNAT, metadata, multicast, and broadcast IPv4 ranges.
     *
     * @var array<int, string>
     */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * Unspecified, loopback, IPv4-compatible/mapped, discard, documentation, ULA, link-local, and multicast IPv6 ranges.
     *
     * @var array<int, string>
     */
    private const BLOCKED_IPV6_CIDRS = [
        '::/128',
        '::1/128',
        '::/96',
        '::ffff:0:0/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * Explicit cloud metadata IP endpoints documented in ADR-0002.
     *
     * @var array<int, string>
     */
    private const BLOCKED_METADATA_IPS = [
        '169.254.169.254',
        '100.100.100.200',
        'fd00:ec2::254',
    ];

    /**
     * Pre-parsed binary network prefixes and prefix lengths.
     *
     * @var array<int, array{0: string, 1: int}>|null
     */
    private ?array $parsedIpv4Cidrs = null;

    /**
     * Pre-parsed binary IPv6 network prefixes and prefix lengths.
     *
     * @var array<int, array{0: string, 1: int}>|null
     */
    private ?array $parsedIpv6Cidrs = null;

    /**
     * Determine whether a string is strictly formatted as dotted-decimal IPv4 without leading zeros or radix variants.
     */
    public function isCanonicalIpv4(string $value): bool
    {
        if (preg_match(self::CANONICAL_IPV4_PATTERN, $value) !== 1) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Determine whether a string is a valid unbracketed IPv6 address without a zone identifier.
     */
    public function isValidIpv6(string $value): bool
    {
        if ($value === '' || str_contains($value, '%') || str_contains($value, '[') || str_contains($value, ']')) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /**
     * Normalize a canonical IPv4 or valid IPv6 literal; return null for ambiguous, zoned, or malformed inputs.
     */
    public function normalizeIp(string $ip): ?string
    {
        $trimmed = trim($ip);

        if ($trimmed === '' || $trimmed !== $ip) {
            return null;
        }

        if ($this->isCanonicalIpv4($trimmed)) {
            $packed = @inet_pton($trimmed);

            return $packed !== false && strlen($packed) === 4 ? inet_ntop($packed) ?: null : null;
        }

        if ($this->isValidIpv6($trimmed)) {
            $packed = @inet_pton($trimmed);

            return $packed !== false && strlen($packed) === 16 ? inet_ntop($packed) ?: null : null;
        }

        return null;
    }

    /**
     * Determine whether the IP address is prohibited or fails strict validation.
     */
    public function isBlocked(string $ip): bool
    {
        $normalized = $this->normalizeIp($ip);

        if ($normalized === null) {
            return true;
        }

        $packed = @inet_pton($normalized);
        if ($packed === false) {
            return true;
        }

        foreach (self::BLOCKED_METADATA_IPS as $metadataIp) {
            $metadataPacked = @inet_pton($metadataIp);
            if ($metadataPacked !== false && $packed === $metadataPacked) {
                return true;
            }
        }

        if (strlen($packed) === 4) {
            return $this->matchesAnyCidr($packed, $this->ipv4Cidrs());
        }

        if (strlen($packed) === 16) {
            if ($this->matchesAnyCidr($packed, $this->ipv6Cidrs())) {
                return true;
            }

            // Defense-in-depth for any embedded IPv4 bytes in transitional prefixes.
            if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                $embeddedIpv4 = inet_ntop(substr($packed, 12, 4));

                return $embeddedIpv4 === false || $this->isBlocked($embeddedIpv4);
            }

            return false;
        }

        return true;
    }

    /**
     * Validate that an IP address is canonical, routable, and permitted by SSRF policy.
     */
    public function assertAllowed(string $ip): string
    {
        $normalized = $this->normalizeIp($ip);

        if ($normalized === null) {
            throw new BlockedDestinationException('Destination IP format is invalid or ambiguous.');
        }

        if ($this->isBlocked($normalized)) {
            throw new BlockedDestinationException("Destination IP [{$normalized}] is in a restricted network range.");
        }

        return $normalized;
    }

    /**
     * Determine whether two IP strings represent the same binary network address.
     */
    public function sameIp(string $first, string $second): bool
    {
        $firstNormalized = $this->normalizeIp($first);
        $secondNormalized = $this->normalizeIp($second);

        if ($firstNormalized === null || $secondNormalized === null) {
            return false;
        }

        $firstPacked = @inet_pton($firstNormalized);
        $secondPacked = @inet_pton($secondNormalized);

        return $firstPacked !== false && $firstPacked === $secondPacked;
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function ipv4Cidrs(): array
    {
        return $this->parsedIpv4Cidrs ??= $this->parseCidrList(self::BLOCKED_IPV4_CIDRS, 4);
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function ipv6Cidrs(): array
    {
        return $this->parsedIpv6Cidrs ??= $this->parseCidrList(self::BLOCKED_IPV6_CIDRS, 16);
    }

    /**
     * @param  array<int, string>  $cidrs
     * @return array<int, array{0: string, 1: int}>
     */
    private function parseCidrList(array $cidrs, int $expectedBytes): array
    {
        $parsed = [];

        foreach ($cidrs as $cidr) {
            [$subnet, $prefix] = explode('/', $cidr, 2);
            $network = @inet_pton($subnet);

            if ($network === false || strlen($network) !== $expectedBytes) {
                continue;
            }

            $parsed[] = [$network, (int) $prefix];
        }

        return $parsed;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $cidrs
     */
    private function matchesAnyCidr(string $ipBinary, array $cidrs): bool
    {
        foreach ($cidrs as [$networkBinary, $prefixLength]) {
            if ($this->cidrContains($ipBinary, $networkBinary, $prefixLength)) {
                return true;
            }
        }

        return false;
    }

    private function cidrContains(string $ipBinary, string $networkBinary, int $prefixLength): bool
    {
        $length = strlen($networkBinary);

        if (strlen($ipBinary) !== $length || $prefixLength < 0 || $prefixLength > $length * 8) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainderBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr_compare($ipBinary, $networkBinary, 0, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits > 0) {
            $mask = (0xFF << (8 - $remainderBits)) & 0xFF;
            if ((ord($ipBinary[$fullBytes]) & $mask) !== (ord($networkBinary[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
