<?php

namespace App\Support\SafeHttp;

use CurlHandle;

/**
 * Encapsulates a single prepared cURL easy handle and its bounded header/body capture state.
 */
final class CurlPreparedHandle
{
    /** @var array<string, array<int, string>> */
    public array $headers = [];

    public int $headerBytes = 0;

    public int $headerCount = 0;

    public bool $headerLimitExceeded = false;

    public readonly BoundedBodyBuffer $bodyBuffer;

    public function __construct(
        public readonly ?CurlHandle $handle,
        int $maxBodyBytes,
        public readonly int $startedAtNs,
        public readonly ?TransportResponse $setupFailure = null,
    ) {
        $this->bodyBuffer = new BoundedBodyBuffer($maxBodyBytes);
    }

    /**
     * Extract a sanitized TransportResponse from the completed cURL handle.
     */
    public function toTransportResponse(?int $multiResultErrno = null): TransportResponse
    {
        if ($this->setupFailure !== null || ! $this->handle instanceof CurlHandle) {
            return $this->setupFailure ?? new TransportResponse(
                statusCode: 0,
                headers: [],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: null,
                certInfo: [],
                curlErrno: defined('CURLE_FAILED_INIT') ? CURLE_FAILED_INIT : 2,
                curlError: 'cURL handle was not initialized.',
                elapsedMs: 0,
            );
        }

        $handleErrno = curl_errno($this->handle);
        $errno = ($multiResultErrno !== null && $multiResultErrno !== CURLE_OK)
            ? $multiResultErrno
            : $handleErrno;
        $error = curl_error($this->handle);
        $statusCode = (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
        $rawPrimaryIp = curl_getinfo($this->handle, CURLINFO_PRIMARY_IP);
        $rawCertInfo = curl_getinfo($this->handle, CURLINFO_CERTINFO);

        $primaryIp = is_string($rawPrimaryIp) && $rawPrimaryIp !== '' ? $rawPrimaryIp : null;
        $certInfo = is_array($rawCertInfo) ? array_values(array_filter($rawCertInfo, 'is_array')) : [];
        $elapsedMs = max(0, (int) round((hrtime(true) - $this->startedAtNs) / 1_000_000));

        return new TransportResponse(
            statusCode: $statusCode,
            headers: $this->headers,
            body: $this->bodyBuffer->contents(),
            bodyTruncated: $this->bodyBuffer->isTruncated(),
            bytesReceived: $this->bodyBuffer->storedBytes(),
            primaryIp: $primaryIp,
            certInfo: $certInfo,
            curlErrno: $errno,
            curlError: $error,
            elapsedMs: $elapsedMs,
            headerLimitExceeded: $this->headerLimitExceeded,
        );
    }

    /**
     * Close the underlying cURL handle if still open.
     */
    public function close(): void
    {
        if ($this->handle instanceof CurlHandle) {
            curl_close($this->handle);
        }
    }
}
