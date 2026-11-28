<?php

namespace App\Support\SafeHttp;

use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use Closure;
use CurlHandle;
use CurlMultiHandle;
use RuntimeException;

/**
 * Executes bounded concurrent single-hop cURL transfers via curl_multi with deterministic handle cancellation.
 */
final class CurlMultiTransportDriver implements MultiTransportDriverInterface
{
    /** @var Closure(): int */
    private readonly Closure $monotonicClockNs;

    /**
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public function __construct(
        private readonly CurlHttpTransport $transport = new CurlHttpTransport,
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
        $concurrency = max(1, $maxConcurrency);
        $multiHandle = curl_multi_init();

        if (! $multiHandle instanceof CurlMultiHandle) {
            throw new RuntimeException('Unable to initialize curl_multi handle.');
        }

        /** @var array<int, array{id: int|string, prepared: CurlPreparedHandle}> $active */
        $active = [];
        $hardDeadlineHit = false;

        try {
            $fillSlots = function () use (
                &$active,
                &$hardDeadlineHit,
                $concurrency,
                $hardDeadlineNs,
                $multiHandle,
                $nextJob,
                $onComplete,
                $onCancelled,
            ): void {
                while (count($active) < $concurrency) {
                    if ($this->nowNs() >= $hardDeadlineNs) {
                        $hardDeadlineHit = true;

                        return;
                    }

                    $job = $nextJob();
                    if ($job === null) {
                        return;
                    }

                    $this->enqueueJob(
                        id: $job['id'],
                        request: $job['request'],
                        multiHandle: $multiHandle,
                        active: $active,
                        hardDeadlineNs: $hardDeadlineNs,
                        hardDeadlineHit: $hardDeadlineHit,
                        onComplete: $onComplete,
                        onCancelled: $onCancelled,
                    );

                    if ($hardDeadlineHit) {
                        return;
                    }
                }
            };

            $fillSlots();

            while ($active !== [] && ! $hardDeadlineHit) {
                if ($this->nowNs() >= $hardDeadlineNs) {
                    $hardDeadlineHit = true;
                    break;
                }

                $running = 0;
                do {
                    $execStatus = curl_multi_exec($multiHandle, $running);
                } while ($execStatus === CURLM_CALL_MULTI_PERFORM);

                if ($execStatus !== CURLM_OK) {
                    $hardDeadlineHit = true;
                    break;
                }

                while (($info = curl_multi_info_read($multiHandle)) !== false) {
                    $handle = $info['handle'] ?? null;
                    if (! $handle instanceof CurlHandle) {
                        continue;
                    }

                    $objectId = spl_object_id($handle);
                    $entry = $active[$objectId] ?? null;

                    if ($entry === null) {
                        continue;
                    }

                    unset($active[$objectId]);

                    $response = $entry['prepared']->toTransportResponse(
                        multiResultErrno: is_int($info['result'] ?? null) ? $info['result'] : null,
                    );

                    curl_multi_remove_handle($multiHandle, $handle);
                    $entry['prepared']->close();

                    $continuation = $onComplete($entry['id'], $response);
                    if ($continuation !== null) {
                        if ($this->nowNs() >= $hardDeadlineNs) {
                            $hardDeadlineHit = true;
                            $onCancelled([$entry['id']]);
                            break;
                        }

                        $this->enqueueJob(
                            id: $entry['id'],
                            request: $continuation,
                            multiHandle: $multiHandle,
                            active: $active,
                            hardDeadlineNs: $hardDeadlineNs,
                            hardDeadlineHit: $hardDeadlineHit,
                            onComplete: $onComplete,
                            onCancelled: $onCancelled,
                        );
                    }
                }

                if ($hardDeadlineHit) {
                    break;
                }

                $fillSlots();

                if ($hardDeadlineHit || $active === []) {
                    break;
                }

                if ($running > 0) {
                    $remainingNs = $hardDeadlineNs - $this->nowNs();
                    if ($remainingNs <= 0) {
                        $hardDeadlineHit = true;
                        break;
                    }

                    $selectSeconds = min(0.05, max(0.001, $remainingNs / 1_000_000_000));
                    $selected = curl_multi_select($multiHandle, $selectSeconds);

                    if ($selected === -1) {
                        usleep(min(1000, max(1, (int) ($remainingNs / 1000))));
                    }
                }
            }

            if ($active !== []) {
                $cancelledIds = $this->cancelAllActiveHandles($multiHandle, $active);
                $onCancelled($cancelledIds);
            }

            return $hardDeadlineHit;
        } finally {
            if ($active !== []) {
                $this->cancelAllActiveHandles($multiHandle, $active);
            }

            curl_multi_close($multiHandle);
        }
    }

    /**
     * Prepare and enqueue one single-hop request into curl_multi, or short-circuit setup errors cleanly.
     *
     * @param  array<int, array{id: int|string, prepared: CurlPreparedHandle}>  $active
     * @param  callable(int|string, TransportResponse): ?TransportRequest  $onComplete
     * @param  callable(array<int, int|string>): void  $onCancelled
     */
    private function enqueueJob(
        int|string $id,
        TransportRequest $request,
        CurlMultiHandle $multiHandle,
        array &$active,
        int $hardDeadlineNs,
        bool &$hardDeadlineHit,
        callable $onComplete,
        callable $onCancelled,
    ): void {
        $prepared = $this->transport->prepareHandle($request);

        if ($prepared->setupFailure !== null || ! $prepared->handle instanceof CurlHandle) {
            $failure = $prepared->toTransportResponse();
            $prepared->close();

            $continuation = $onComplete($id, $failure);
            if ($continuation !== null) {
                if ($this->nowNs() >= $hardDeadlineNs) {
                    $hardDeadlineHit = true;
                    $onCancelled([$id]);

                    return;
                }

                $this->enqueueJob(
                    id: $id,
                    request: $continuation,
                    multiHandle: $multiHandle,
                    active: $active,
                    hardDeadlineNs: $hardDeadlineNs,
                    hardDeadlineHit: $hardDeadlineHit,
                    onComplete: $onComplete,
                    onCancelled: $onCancelled,
                );
            }

            return;
        }

        $addStatus = curl_multi_add_handle($multiHandle, $prepared->handle);
        if ($addStatus !== CURLM_OK) {
            $prepared->close();

            $failure = new TransportResponse(
                statusCode: 0,
                headers: [],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: null,
                certInfo: [],
                curlErrno: defined('CURLE_FAILED_INIT') ? CURLE_FAILED_INIT : 2,
                curlError: 'Unable to attach cURL handle to curl_multi pool.',
                elapsedMs: 0,
            );
            $onComplete($id, $failure);

            return;
        }

        $active[spl_object_id($prepared->handle)] = [
            'id' => $id,
            'prepared' => $prepared,
        ];
    }

    /**
     * Deterministically remove and close every active handle when the batch hard deadline expires.
     *
     * @param  array<int, array{id: int|string, prepared: CurlPreparedHandle}>  $active
     * @return array<int, int|string>
     */
    private function cancelAllActiveHandles(CurlMultiHandle $multiHandle, array &$active): array
    {
        $cancelledIds = [];

        foreach ($active as $objectId => $entry) {
            $cancelledIds[] = $entry['id'];
            if ($entry['prepared']->handle instanceof CurlHandle) {
                curl_multi_remove_handle($multiHandle, $entry['prepared']->handle);
            }
            $entry['prepared']->close();
            unset($active[$objectId]);
        }

        return $cancelledIds;
    }

    private function nowNs(): int
    {
        return ($this->monotonicClockNs)();
    }
}
