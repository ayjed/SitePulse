<?php

use App\Support\SafeHttp\VerifiedTlsMetadata;

test('extracts only absolute, valid certificate dates at second precision', function (array $leaf, ?string $utc) {
    $certificate = VerifiedTlsMetadata::fromCertificateInfo([$leaf]);
    expect($certificate->expiresAt?->format('Y-m-d H:i:s e'))->toBe($utc);
})->with([
    'numeric epoch' => [['validTo_time_t' => 1796209200], gmdate('Y-m-d H:i:s', 1796209200).' UTC'],
    'decimal epoch string' => [['validTo_time_t' => '1796209200'], gmdate('Y-m-d H:i:s', 1796209200).' UTC'],
    'OpenSSL textual spacing' => [['Expire date' => 'Dec  2 06:00:00 2026 GMT'], '2026-12-02 06:00:00 UTC'],
    'text after invalid numeric value' => [['validTo_time_t' => '1e9', 'Expire date' => 'Dec 02 06:00:00 2026 UTC'], '2026-12-02 06:00:00 UTC'],
    'impossible calendar day' => [['Expire date' => 'Feb 30 06:00:00 2027 GMT'], null],
    'relative date' => [['Expire date' => 'tomorrow'], null],
    'malformed PEM' => [['Cert' => "-----BEGIN CERTIFICATE-----\ninvalid\n-----END CERTIFICATE-----"], null],
    'empty fields' => [['Expire date' => '', 'validTo_time_t' => '', 'Cert' => ''], null],
    'missing fields' => [[], null],
]);

test('bounds transient issuer text and ignores empty certificate info', function () {
    $metadata = VerifiedTlsMetadata::fromCertificateInfo([['Issuer' => "  CN=Synthetic\r\n".str_repeat('é', 800)]]);
    expect($metadata->issuer)->toStartWith('CN=Synthetic ')
        ->and(strlen($metadata->issuer))->toBeLessThanOrEqual(1024)
        ->and(mb_check_encoding($metadata->issuer, 'UTF-8'))->toBeTrue()
        ->and(VerifiedTlsMetadata::fromCertificateInfo([])->issuer)->toBeNull()
        ->and(VerifiedTlsMetadata::fromCertificateInfo([['Issuer' => " \r\n"]])->issuer)->toBeNull();
});
