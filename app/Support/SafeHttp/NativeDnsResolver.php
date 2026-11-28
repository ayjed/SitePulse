<?php

namespace App\Support\SafeHttp;

use App\Support\SafeHttp\Contracts\DeadlineAwareDnsResolverInterface;
use Closure;

/**
 * Resolves A and AAAA records via PHP's native dns_get_record without OS proxy or cURL DNS bypasses.
 *
 * Standard PHP dns_get_record() executes synchronously inside the host C stub resolver without a
 * per-call timeout parameter; monotonic deadlines are checked before and between queries.
 */
class NativeDnsResolver implements DeadlineAwareDnsResolverInterface
{
    /** @var Closure(): int */
    private readonly Closure $monotonicClockNs;

    /** @var Closure(string, int): (array<int, array<string, mixed>>|false) */
    private readonly Closure $recordLookup;

    /**
     * @param  (Closure(): int)|null  $monotonicClockNs
     * @param  (Closure(string, int): (array<int, array<string, mixed>>|false))|null  $recordLookup
     */
    public function __construct(
        ?Closure $monotonicClockNs = null,
        ?Closure $recordLookup = null,
    ) {
        $this->monotonicClockNs = $monotonicClockNs ?? static fn (): int => hrtime(true);
        $this->recordLookup = $recordLookup ?? static fn (string $host, int $type): array|false => @dns_get_record($host, $type);
    }

    /**
     * {@inheritDoc}
     */
    public function resolve(string $hostname): array
    {
        return $this->resolveWithDeadline($hostname, null);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveWithDeadline(string $hostname, ?int $deadlineNs = null): array
    {
        $this->assertDeadlineRemaining($deadlineNs, 'Remaining deadline exceeded prior to DNS resolution.');

        $records = ($this->recordLookup)($hostname, DNS_A + DNS_AAAA);

        if ($records === false) {
            $records = $this->resolveSplitQueries($hostname, $deadlineNs);
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
     * Retry A and AAAA separately when combined queries fail on stub resolvers, aborting if deadline elapses.
     *
     * Both A and AAAA must still be queried cleanly unless deadline fails the entire probe closed, preserving
     * dual-homed split-DNS SSRF enforcement across IPv4 and IPv6 records.
     *
     * @return array<int, array<string, mixed>>|false
     */
    private function resolveSplitQueries(string $hostname, ?int $deadlineNs): array|false
    {
        $this->assertDeadlineRemaining($deadlineNs, 'Remaining deadline exceeded before split A/AAAA DNS fallback.');
        $aRecords = ($this->recordLookup)($hostname, DNS_A);

        $this->assertDeadlineRemaining($deadlineNs, 'Remaining deadline exceeded before split AAAA DNS fallback.');
        $aaaaRecords = ($this->recordLookup)($hostname, DNS_AAAA);

        if ($aRecords === false && $aaaaRecords === false) {
            return false;
        }

        return array_merge(
            is_array($aRecords) ? $aRecords : [],
            is_array($aaaaRecords) ? $aaaaRecords : [],
        );
    }

    private function assertDeadlineRemaining(?int $deadlineNs, string $message): void
    {
        if ($deadlineNs !== null && ($this->monotonicClockNs)() >= $deadlineNs) {
            throw new SafeHttpRequestException(SafeHttpFailureReason::DEADLINE_EXCEEDED, $message);
        }
    }
}
