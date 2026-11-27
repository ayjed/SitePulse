<?php

namespace App\Support\SafeHttp\Contracts;

use App\Support\SafeHttp\SafeHttpRequestException;

/**
 * Resolves a validated hostname into its raw A/AAAA IP address records before connecting.
 */
interface DnsResolverInterface
{
    /**
     * Resolve all A and AAAA IP addresses associated with the hostname.
     *
     * @return array<int, string>
     *
     * @throws SafeHttpRequestException When DNS lookup fails or yields no usable address records.
     */
    public function resolve(string $hostname): array;
}
