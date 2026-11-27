<?php

namespace App\Support\SafeHttp;

use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use Closure;

/**
 * Deterministic fallback batch driver executing jobs via injected HttpTransportInterface fakes in tests.
 */
final class SequentialMultiTransportDriver implements MultiTransportDriverInterface
{
    /** @var Closure(): int */
    private readonly Closure $monotonicClockNs;

    /**
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public function __construct(
        private readonly HttpTransportInterface $transport,
        ?Closure $monotonicClockNs = null,
    ) {
        $this->monotonicClockNs = $monotonicClockNs ?? static fn (): int => hrtime(true);
    }

    public function run(
        int $maxConcurrency,
        int $hardDeadlineNs,
        callable $nextJob,
        callable $onComplete,
        callable $onCancelled,
    ): bool {
        while (true) {
            if ($this->nowNs() >= $hardDeadlineNs) {
                return true;
            }

            $job = $nextJob();
            if ($job === null) {
                return false;
            }

            $id = $job['id'];
            $request = $job['request'];

            while ($request !== null) {
                if ($this->nowNs() >= $hardDeadlineNs) {
                    $onCancelled([$id]);

                    return true;
                }

                $response = $this->transport->send($request);
                $request = $onComplete($id, $response);
            }
        }
    }

    private function nowNs(): int
    {
        return ($this->monotonicClockNs)();
    }
}
