<?php

namespace App\Support\SafeHttp\Contracts;

use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;

/**
 * Bounded event-loop driver executing single-hop HTTP/HTTPS TransportRequests concurrently.
 */
interface MultiTransportDriverInterface
{
    /**
     * Execute pull-based concurrent transport jobs while enforcing strict concurrency and hard deadlines.
     *
     * @param  callable(): ?array{id: int|string, request: TransportRequest}  $nextJob
     * @param  callable(int|string, TransportResponse): ?TransportRequest  $onComplete
     * @param  callable(array<int, int|string>): void  $onCancelled
     */
    public function run(
        int $maxConcurrency,
        int $hardDeadlineNs,
        callable $nextJob,
        callable $onComplete,
        callable $onCancelled,
    ): bool;
}
