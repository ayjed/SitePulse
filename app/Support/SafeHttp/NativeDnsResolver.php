<?php

namespace App\Support\SafeHttp;

use App\Support\SafeHttp\Contracts\DnsResolverInterface;

/**
 * Resolves A and AAAA records via PHP's native dns_get_record without OS proxy or cURL DNS bypasses.
 */
class NativeDnsResolver implements DnsResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolve(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A + DNS_AAAA);

        if ($records === false) {
            $records = $this->resolveSplitQueries($hostname);
        }

        if (! is_array($records) || $records === []) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::DNS_FAILURE,
                "DNS resolution returned no A or AAAA records for [{$hostname}]."
            );
        }

        $ips = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $candidate = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($candidate) && $candidate !== '') {
                $ips[] = $candidate;
            }
        }

        $ips = array_values(array_unique($ips));

        if ($ips === []) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::DNS_FAILURE,
                "DNS resolution produced no usable IP addresses for [{$hostname}]."
            );
        }

        return $ips;
    }

    /**
     * Retry A and AAAA separately when combined queries fail on stub resolvers.
     *
     * @return array<int, array<string, mixed>>|false
     */
    private function resolveSplitQueries(string $hostname): array|false
    {
        $aRecords = @dns_get_record($hostname, DNS_A);
        $aaaaRecords = @dns_get_record($hostname, DNS_AAAA);

        if ($aRecords === false && $aaaaRecords === false) {
            return false;
        }

        return array_merge(
            is_array($aRecords) ? $aRecords : [],
            is_array($aaaaRecords) ? $aaaaRecords : [],
        );
    }
}
