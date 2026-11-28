<?php

namespace App\Support\SafeHttp\Contracts;

/**
 * Optional extension for DNS resolvers capable of checking monotonic deadlines around lookups.
 */
interface DeadlineAwareDnsResolverInterface extends DnsResolverInterface
{
    /**
     * Resolve A/AAAA records while aborting before or between fallback queries if monotonic deadline elapsed.
     *
     * @return array<int, string>
     */
    public function resolveWithDeadline(string $hostname, ?int $deadlineNs = null): array;
}
