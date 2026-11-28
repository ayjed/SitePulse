<?php

namespace App\Support\SafeHttp;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Bounded observations of a leaf whose transport and peer checks have succeeded.
 */
final readonly class VerifiedTlsMetadata
{
    private function __construct(public ?DateTimeImmutable $expiresAt, public ?string $issuer) {}

    /** @param array<int, array<string, mixed>> $certInfo */
    public static function fromCertificateInfo(array $certInfo): self
    {
        $leaf = $certInfo[0] ?? null;
        if (! is_array($leaf)) {
            return new self(null, null);
        }

        $expiresAt = self::fromTimestamp($leaf['validTo_time_t'] ?? null)
            ?? self::fromCurlExpiry($leaf['Expire date'] ?? null);
        $issuer = self::issuerText($leaf['Issuer'] ?? null);

        $pem = $leaf['Cert'] ?? null;
        if (($expiresAt === null || $issuer === null) && is_string($pem) && strlen($pem) <= 65_536
            && str_starts_with($pem, '-----BEGIN CERTIFICATE-----') && function_exists('openssl_x509_parse')) {
            $parsed = @openssl_x509_parse($pem);
            if (is_array($parsed)) {
                $expiresAt ??= self::fromTimestamp($parsed['validTo_time_t'] ?? null);
                if ($issuer === null && is_array($parsed['issuer'] ?? null)) {
                    $parts = [];
                    foreach ($parsed['issuer'] as $name => $value) {
                        if (is_string($value) && $value !== '') {
                            $parts[] = $name.'='.$value;
                        }
                    }
                    $issuer = self::issuerText(implode(', ', $parts));
                }
            }
        }

        return new self($expiresAt, $issuer);
    }

    private static function fromTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/\A[0-9]{1,12}\z/', $value) !== 1)) {
            return null;
        }

        $timestamp = (int) $value;
        if ($timestamp <= 0 || $timestamp > 253402300799) {
            return null;
        }

        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    private static function fromCurlExpiry(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || strlen($value) > 40
            || preg_match('/\A[A-Z][a-z]{2} {1,2}[0-9]{1,2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4} (?:GMT|UTC)\z/', trim($value)) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!M j H:i:s Y T', str_replace('  ', ' ', trim($value)));
        if ($date === false || DateTimeImmutable::getLastErrors() !== false || $date->getTimestamp() <= 0) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function issuerText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $bounded = mb_strcut($value, 0, 1024, 'UTF-8');
        if (! mb_check_encoding($bounded, 'UTF-8')) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $bounded) ?? '');

        return $clean === '' ? null : $clean;
    }
}
