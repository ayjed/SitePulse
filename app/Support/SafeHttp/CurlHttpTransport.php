<?php

namespace App\Support\SafeHttp;

use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use CurlHandle;
use InvalidArgumentException;

/**
 * Executes single-hop, IP-pinned, proxy-isolated HTTP/HTTPS transfers via ext-curl.
 */
class CurlHttpTransport implements HttpTransportInterface
{
    public const MAX_HEADER_BYTES = 65536;

    public const MAX_HEADER_COUNT = 256;

    public function __construct(
        private readonly IpAddressPolicy $ipPolicy = new IpAddressPolicy,
    ) {}

    /**
     * Execute the pinned HTTP/HTTPS transport request.
     */
    public function send(TransportRequest $request): TransportResponse
    {
        $startedAtNs = hrtime(true);
        $bodyBuffer = new BoundedBodyBuffer($request->maxBodyBytes);
        $headers = [];
        $headerBytes = 0;
        $headerCount = 0;
        $headerLimitExceeded = false;

        // Enforce pinned IP and option construction invariants before touching curl_init().
        $options = $this->buildCurlOptions($request);

        $handle = curl_init();
        if (! $handle instanceof CurlHandle) {
            return new TransportResponse(
                statusCode: 0,
                headers: [],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: null,
                certInfo: [],
                curlErrno: defined('CURLE_FAILED_INIT') ? CURLE_FAILED_INIT : 2,
                curlError: 'Unable to initialize cURL transport handle.',
                elapsedMs: 0,
            );
        }

        try {
            $options[CURLOPT_HEADERFUNCTION] = static function ($ch, string $headerLine) use (
                &$headers,
                &$headerBytes,
                &$headerCount,
                &$headerLimitExceeded,
            ): int {
                $length = strlen($headerLine);

                if ($headerBytes + $length > self::MAX_HEADER_BYTES) {
                    $headerLimitExceeded = true;

                    return 0;
                }

                $headerBytes += $length;
                $trimmed = trim($headerLine);

                if ($trimmed === '') {
                    return $length;
                }

                if (str_starts_with(strtoupper($trimmed), 'HTTP/')) {
                    $headers = [];
                    $headerCount = 0;

                    return $length;
                }

                $colonPos = strpos($trimmed, ':');
                if ($colonPos !== false) {
                    if ($headerCount >= self::MAX_HEADER_COUNT) {
                        $headerLimitExceeded = true;

                        return 0;
                    }

                    $name = strtolower(trim(substr($trimmed, 0, $colonPos)));
                    $value = trim(substr($trimmed, $colonPos + 1));

                    if ($name !== '') {
                        $headers[$name][] = $value;
                        $headerCount++;
                    }
                }

                return $length;
            };

            $options[CURLOPT_WRITEFUNCTION] = static fn ($ch, string $chunk): int => $bodyBuffer->append($chunk);

            if (! curl_setopt_array($handle, $options)) {
                return new TransportResponse(
                    statusCode: 0,
                    headers: [],
                    body: '',
                    bodyTruncated: false,
                    bytesReceived: 0,
                    primaryIp: null,
                    certInfo: [],
                    curlErrno: defined('CURLE_BAD_FUNCTION_ARGUMENT') ? CURLE_BAD_FUNCTION_ARGUMENT : 43,
                    curlError: 'Failed to apply mandatory hardened cURL transport options.',
                    elapsedMs: max(0, (int) round((hrtime(true) - $startedAtNs) / 1_000_000)),
                );
            }

            curl_exec($handle);

            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $rawPrimaryIp = curl_getinfo($handle, CURLINFO_PRIMARY_IP);
            $rawCertInfo = curl_getinfo($handle, CURLINFO_CERTINFO);

            $primaryIp = is_string($rawPrimaryIp) && $rawPrimaryIp !== '' ? $rawPrimaryIp : null;
            $certInfo = is_array($rawCertInfo) ? array_values(array_filter($rawCertInfo, 'is_array')) : [];

            $elapsedMs = max(0, (int) round((hrtime(true) - $startedAtNs) / 1_000_000));

            return new TransportResponse(
                statusCode: $statusCode,
                headers: $headers,
                body: $bodyBuffer->contents(),
                bodyTruncated: $bodyBuffer->isTruncated(),
                bytesReceived: $bodyBuffer->storedBytes(),
                primaryIp: $primaryIp,
                certInfo: $certInfo,
                curlErrno: $errno,
                curlError: $error,
                elapsedMs: $elapsedMs,
                headerLimitExceeded: $headerLimitExceeded,
            );
        } finally {
            curl_close($handle);
        }
    }

    /**
     * Construct hardened cURL options without executing network I/O.
     *
     * @return array<int, mixed>
     */
    public function buildCurlOptions(TransportRequest $request): array
    {
        $normalizedPinnedIp = $this->ipPolicy->assertAllowed($request->pinnedIp);

        if ($request->url->isIpLiteral) {
            $this->ipPolicy->assertAllowed($request->url->host);
        }

        if ($request->userAgent === '' || preg_match('/[\x00-\x1F\x7F]/', $request->userAgent) === 1) {
            throw new InvalidArgumentException('Transport User-Agent contains invalid control characters.');
        }

        $options = [
            CURLOPT_URL => $request->url->url,
            CURLOPT_PORT => $request->url->port,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
            CURLOPT_DNS_CACHE_TIMEOUT => 0,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_LOW_SPEED_LIMIT => max(1, $request->lowSpeedLimitBytesPerSec),
            CURLOPT_LOW_SPEED_TIME => max(1, $request->lowSpeedTimeSeconds),
            CURLOPT_USERAGENT => $request->userAgent,
        ];

        if (defined('CURLOPT_UNRESTRICTED_AUTH')) {
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;
        }

        if (defined('CURLOPT_NOSIGNAL')) {
            $options[CURLOPT_NOSIGNAL] = true;
        }

        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            $options[CURLOPT_CONNECTTIMEOUT_MS] = max(1, $request->connectTimeoutMs);
        } else {
            $options[CURLOPT_CONNECTTIMEOUT] = max(1, (int) ceil($request->connectTimeoutMs / 1000));
        }

        if (defined('CURLOPT_TIMEOUT_MS')) {
            $options[CURLOPT_TIMEOUT_MS] = max(1, $request->timeoutMs);
        } else {
            $options[CURLOPT_TIMEOUT] = max(1, (int) ceil($request->timeoutMs / 1000));
        }

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4') && defined('CURL_IPRESOLVE_V6')) {
            $options[CURLOPT_IPRESOLVE] = $this->ipPolicy->isCanonicalIpv4($normalizedPinnedIp)
                ? CURL_IPRESOLVE_V4
                : CURL_IPRESOLVE_V6;
        }

        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        if (! $request->url->isIpLiteral) {
            $options[CURLOPT_RESOLVE] = [
                "{$request->url->host}:{$request->url->port}:{$normalizedPinnedIp}",
            ];
        }

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = 0;
        }

        return $options;
    }
}
