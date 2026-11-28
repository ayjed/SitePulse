<?php

namespace App\Support\SafeHttp;

use App\Exceptions\BlockedDestinationException;
use App\Support\SafeHttp\Contracts\DeadlineAwareDnsResolverInterface;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\Contracts\MultiTransportDriverInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Orchestrates SSRF-resistant HTTP/HTTPS checks with DNS pinning, peer IP checks, manual redirects, and resource caps.
 */
class SafeHttpClient
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    public const MIN_TIMEOUT_SECONDS = 2;

    public const MAX_TIMEOUT_SECONDS = 15;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const LOW_SPEED_LIMIT_BYTES_PER_SEC = 1024;

    public const LOW_SPEED_TIME_SECONDS = 5;

    public const MAX_REDIRECTS = 3;

    public const MAX_BODY_BYTES = 512000;

    public const TLS_EXPIRY_WARNING_DAYS = 14;

    public const DEFAULT_USER_AGENT = 'SitePulse/1.0 (+https://github.com/ayjed/SitePulse)';

    /**
     * HTTP status codes handled via manual hop-by-hop redirect validation.
     *
     * @var array<int, int>
     */
    private const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];

    private const CURLE_WRITE_ERROR_CODE = 23;

    private const CURLE_OPERATION_TIMEDOUT_CODE = 28;

    private const CURLE_COULDNT_RESOLVE_PROXY_CODE = 5;

    private const CURLE_COULDNT_RESOLVE_HOST_CODE = 6;

    private const CURLE_COULDNT_CONNECT_CODE = 7;

    /**
     * @var array<int, int>
     */
    private const TLS_ERROR_CODES = [35, 51, 53, 54, 58, 59, 60, 66, 77, 82, 83, 90, 91, 96];

    private readonly DnsResolverInterface $dnsResolver;

    private readonly HttpTransportInterface $transport;

    private readonly MultiTransportDriverInterface $multiTransport;

    /** @var Closure(): int */
    private readonly Closure $monotonicClockNs;

    /**
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public function __construct(
        private readonly CanonicalUrlValidator $urlValidator = new CanonicalUrlValidator,
        private readonly IpAddressPolicy $ipPolicy = new IpAddressPolicy,
        ?DnsResolverInterface $dnsResolver = null,
        ?HttpTransportInterface $transport = null,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
        ?Closure $monotonicClockNs = null,
        ?MultiTransportDriverInterface $multiTransport = null,
    ) {
        if ($userAgent === '' || preg_match('/[\x00-\x1F\x7F]/', $userAgent) === 1) {
            throw new InvalidArgumentException('SafeHttpClient User-Agent cannot be empty or contain control characters.');
        }

        $this->monotonicClockNs = $monotonicClockNs ?? static fn (): int => hrtime(true);
        $this->dnsResolver = $dnsResolver ?? new NativeDnsResolver;
        $this->transport = $transport ?? new CurlHttpTransport($this->ipPolicy);
        $this->multiTransport = $multiTransport ?? (
            $this->transport instanceof CurlHttpTransport
                ? new CurlMultiTransportDriver($this->transport, $this->monotonicClockNs)
                : new SequentialMultiTransportDriver($this->transport, $this->monotonicClockNs)
        );
    }

    /**
     * Validate URL syntax, port rules, and literal IP / restricted hostname policy without DNS or network calls.
     */
    public function validateUrl(string $url): ValidatedUrl
    {
        return $this->urlValidator->validate($url);
    }

    /**
     * Perform a hardened HTTP/HTTPS request with pre-flight DNS pinning, peer IP checks, and manual redirects.
     *
     * Caller-supplied custom request headers are intentionally unsupported in Phase 02 to prevent header
     * injection, host spoofing, and credential/cookie leakage across redirects.
     *
     * @throws BlockedDestinationException
     * @throws SafeHttpRequestException
     */
    public function request(
        string $url,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        string $method = 'GET',
    ): SafeHttpResponse {
        $this->assertValidTimeout($timeoutSeconds);
        $normalizedMethod = $this->normalizeMethod($method);

        $startedAtNs = $this->nowNs();
        $deadlineNs = $startedAtNs + ($timeoutSeconds * 1_000_000_000);
        $visitedKeys = [];
        $redirectsFollowed = 0;
        $currentUrl = $url;
        $currentMethod = $normalizedMethod;

        while (true) {
            $hop = $this->prepareHop($currentUrl, $currentMethod, $visitedKeys, $deadlineNs);
            $transportResponse = $this->transport->send($hop['request']);

            $evaluation = $this->evaluateCompletedHop(
                validated: $hop['validated'],
                pinnedIp: $hop['pinnedIp'],
                currentMethod: $currentMethod,
                redirectsFollowed: $redirectsFollowed,
                deadlineNs: $deadlineNs,
                startedAtNs: $startedAtNs,
                transportResponse: $transportResponse,
            );

            if (isset($evaluation['response'])) {
                return $evaluation['response'];
            }

            $redirectsFollowed = $evaluation['redirectsFollowed'];
            $currentUrl = $evaluation['redirectUrl'];
            $currentMethod = $evaluation['nextMethod'];
        }
    }

    /**
     * Execute a probe and return a sanitized monitor check summary with canonical failure reasons.
     */
    public function check(
        string $url,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $expectedStatusMin = 200,
        int $expectedStatusMax = 299,
        string $method = 'GET',
    ): SafeHttpCheckResult {
        $startedAtNs = $this->nowNs();

        try {
            $response = $this->request($url, $timeoutSeconds, $method);

            return $this->buildCheckResultFromResponse(
                response: $response,
                expectedStatusMin: $expectedStatusMin,
                expectedStatusMax: $expectedStatusMax,
                includeResponse: true,
            );
        } catch (BlockedDestinationException|SafeHttpRequestException $exception) {
            return $this->buildFailureResultFromException($exception, $url, $startedAtNs);
        }
    }

    /**
     * Execute a bounded, concurrently pooled batch of safe probes with soft intake cutoff and hard cancellation.
     *
     * @param  iterable<int, BatchCheckTarget>  $targets
     * @param  (callable(BatchCheckOutcome): void)|null  $onOutcome
     * @return array<int, BatchCheckOutcome>
     */
    public function checkBatch(
        iterable $targets,
        int $maxConcurrency = 5,
        int|float $softDeadlineSeconds = 45,
        int|float $hardDeadlineSeconds = 50,
        ?callable $onOutcome = null,
        ?int $softDeadlineNs = null,
        ?int $hardDeadlineNs = null,
    ): array {
        $concurrency = max(1, min(10, $maxConcurrency));

        if ($softDeadlineSeconds <= 0 || $hardDeadlineSeconds < $softDeadlineSeconds) {
            throw new InvalidArgumentException('Batch soft/hard deadlines must be positive with hardDeadlineSeconds >= softDeadlineSeconds.');
        }

        $queue = array_values(is_array($targets) ? $targets : iterator_to_array($targets, false));
        foreach ($queue as $target) {
            if (! $target instanceof BatchCheckTarget) {
                throw new InvalidArgumentException('Every batch check target must be an instance of BatchCheckTarget.');
            }

            $this->assertValidTimeout($target->timeoutSeconds);
            $this->normalizeMethod($target->method);
        }

        if ($queue === []) {
            return [];
        }

        $batchStartedAtNs = $this->nowNs();
        $resolvedSoftDeadlineNs = $softDeadlineNs ?? ($batchStartedAtNs + (int) round($softDeadlineSeconds * 1_000_000_000));
        $resolvedHardDeadlineNs = $hardDeadlineNs ?? ($batchStartedAtNs + (int) round($hardDeadlineSeconds * 1_000_000_000));

        if ($resolvedHardDeadlineNs < $resolvedSoftDeadlineNs) {
            throw new InvalidArgumentException('Batch hardDeadlineNs must be greater than or equal to softDeadlineNs.');
        }

        $total = count($queue);
        $nextIndex = 0;

        /** @var array<int, array{target: BatchCheckTarget, method: string, startedAtNs: int, deadlineNs: int, visitedKeys: array<string, bool>, redirectsFollowed: int, validated: ValidatedUrl, pinnedIp: string}> $activeStates */
        $activeStates = [];
        /** @var array<int, BatchCheckOutcome> $outcomes */
        $outcomes = [];

        $emitOutcome = static function (int $index, BatchCheckOutcome $outcome) use (&$outcomes, $onOutcome): void {
            $outcomes[$index] = $outcome;

            if ($onOutcome !== null) {
                $onOutcome($outcome);
            }
        };

        $nextJob = function () use (
            &$nextIndex,
            &$activeStates,
            $total,
            $queue,
            $resolvedSoftDeadlineNs,
            $resolvedHardDeadlineNs,
            $emitOutcome,
        ): ?array {
            while ($nextIndex < $total) {
                if ($this->nowNs() >= $resolvedSoftDeadlineNs) {
                    return null;
                }

                $index = $nextIndex;
                $nextIndex++;

                $target = $queue[$index];
                $startedAtNs = $this->nowNs();
                $deadlineNs = $startedAtNs + ($target->timeoutSeconds * 1_000_000_000);
                $dnsDeadlineNs = min($deadlineNs, $resolvedHardDeadlineNs);
                $method = $this->normalizeMethod($target->method);
                $visitedKeys = [];

                try {
                    $hop = $this->prepareHop($target->url, $method, $visitedKeys, $deadlineNs, $dnsDeadlineNs);
                } catch (BlockedDestinationException|SafeHttpRequestException $exception) {
                    if ($this->nowNs() >= $resolvedHardDeadlineNs) {
                        $emitOutcome($index, new BatchCheckOutcome($target, null, true, 'hard_deadline'));

                        return null;
                    }

                    $result = $this->buildFailureResultFromException($exception, $target->url, $startedAtNs);
                    $emitOutcome($index, new BatchCheckOutcome($target, $result, false));

                    continue;
                }

                if ($this->nowNs() >= $resolvedHardDeadlineNs) {
                    $emitOutcome($index, new BatchCheckOutcome($target, null, true, 'hard_deadline'));

                    return null;
                }

                $activeStates[$index] = [
                    'target' => $target,
                    'method' => $method,
                    'startedAtNs' => $startedAtNs,
                    'deadlineNs' => $deadlineNs,
                    'visitedKeys' => $visitedKeys,
                    'redirectsFollowed' => 0,
                    'validated' => $hop['validated'],
                    'pinnedIp' => $hop['pinnedIp'],
                ];

                return [
                    'id' => $index,
                    'request' => $hop['request'],
                ];
            }

            return null;
        };

        $onComplete = function (int|string $id, TransportResponse $transportResponse) use (
            &$activeStates,
            $resolvedHardDeadlineNs,
            $emitOutcome,
        ): ?TransportRequest {
            $index = (int) $id;
            $state = $activeStates[$index] ?? null;

            if ($state === null) {
                return null;
            }

            $target = $state['target'];

            try {
                $evaluation = $this->evaluateCompletedHop(
                    validated: $state['validated'],
                    pinnedIp: $state['pinnedIp'],
                    currentMethod: $state['method'],
                    redirectsFollowed: $state['redirectsFollowed'],
                    deadlineNs: $state['deadlineNs'],
                    startedAtNs: $state['startedAtNs'],
                    transportResponse: $transportResponse,
                );

                if (isset($evaluation['response'])) {
                    unset($activeStates[$index]);

                    $result = $this->buildCheckResultFromResponse(
                        response: $evaluation['response'],
                        expectedStatusMin: $target->expectedStatusMin,
                        expectedStatusMax: $target->expectedStatusMax,
                        includeResponse: false,
                    );

                    $emitOutcome($index, new BatchCheckOutcome($target, $result, false));

                    return null;
                }

                if ($this->nowNs() >= $resolvedHardDeadlineNs) {
                    unset($activeStates[$index]);
                    $emitOutcome($index, new BatchCheckOutcome($target, null, true, 'hard_deadline'));

                    return null;
                }

                $state['redirectsFollowed'] = $evaluation['redirectsFollowed'];
                $state['method'] = $evaluation['nextMethod'];

                $hop = $this->prepareHop(
                    url: $evaluation['redirectUrl'],
                    method: $state['method'],
                    visitedKeys: $state['visitedKeys'],
                    deadlineNs: $state['deadlineNs'],
                    dnsDeadlineNs: min($state['deadlineNs'], $resolvedHardDeadlineNs),
                );

                if ($this->nowNs() >= $resolvedHardDeadlineNs) {
                    unset($activeStates[$index]);
                    $emitOutcome($index, new BatchCheckOutcome($target, null, true, 'hard_deadline'));

                    return null;
                }

                $state['validated'] = $hop['validated'];
                $state['pinnedIp'] = $hop['pinnedIp'];
                $activeStates[$index] = $state;

                return $hop['request'];
            } catch (BlockedDestinationException|SafeHttpRequestException $exception) {
                unset($activeStates[$index]);

                if ($this->nowNs() >= $resolvedHardDeadlineNs) {
                    $emitOutcome($index, new BatchCheckOutcome($target, null, true, 'hard_deadline'));

                    return null;
                }

                $result = $this->buildFailureResultFromException($exception, $target->url, $state['startedAtNs']);
                $emitOutcome($index, new BatchCheckOutcome($target, $result, false));

                return null;
            }
        };

        $onCancelled = static function (array $cancelledIds) use (&$activeStates, $emitOutcome): void {
            foreach ($cancelledIds as $id) {
                $index = (int) $id;
                if (! isset($activeStates[$index])) {
                    continue;
                }

                $target = $activeStates[$index]['target'];
                unset($activeStates[$index]);
                $emitOutcome($index, new BatchCheckOutcome($target, null, true, 'hard_deadline'));
            }
        };

        $this->multiTransport->run(
            maxConcurrency: $concurrency,
            hardDeadlineNs: $resolvedHardDeadlineNs,
            nextJob: $nextJob,
            onComplete: $onComplete,
            onCancelled: $onCancelled,
        );

        foreach ($activeStates as $index => $state) {
            unset($activeStates[$index]);
            $emitOutcome($index, new BatchCheckOutcome($state['target'], null, true, 'hard_deadline'));
        }

        for ($index = $nextIndex; $index < $total; $index++) {
            $emitOutcome($index, new BatchCheckOutcome($queue[$index], null, true, 'soft_deadline'));
        }

        ksort($outcomes);

        return array_values($outcomes);
    }

    /**
     * Validate URL syntax/SSRF, enforce redirect loop tracking, pin DNS, and construct a hop TransportRequest.
     *
     * @param  array<string, bool>  $visitedKeys
     * @return array{validated: ValidatedUrl, pinnedIp: string, request: TransportRequest}
     */
    private function prepareHop(
        string $url,
        string $method,
        array &$visitedKeys,
        int $deadlineNs,
        ?int $dnsDeadlineNs = null,
    ): array {
        $validated = $this->urlValidator->validate($url);
        $visitKey = $validated->canonicalKey();

        if (isset($visitedKeys[$visitKey])) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED,
                'Redirect loop detected during hop-by-hop validation.'
            );
        }
        $visitedKeys[$visitKey] = true;

        $chosenIp = $this->resolveAndSelectPinnedIp($validated, $dnsDeadlineNs ?? $deadlineNs);

        $remainingBudgetMs = $this->remainingBudgetMs($deadlineNs);
        if ($remainingBudgetMs <= 0) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::DEADLINE_EXCEEDED,
                'Total monitor check deadline exceeded before connecting.'
            );
        }

        $request = new TransportRequest(
            url: $validated,
            pinnedIp: $chosenIp,
            method: $method,
            connectTimeoutMs: min(self::CONNECT_TIMEOUT_SECONDS * 1000, $remainingBudgetMs),
            timeoutMs: $remainingBudgetMs,
            lowSpeedLimitBytesPerSec: self::LOW_SPEED_LIMIT_BYTES_PER_SEC,
            lowSpeedTimeSeconds: min(
                self::LOW_SPEED_TIME_SECONDS,
                max(1, (int) ceil($remainingBudgetMs / 1000)),
            ),
            maxBodyBytes: self::MAX_BODY_BYTES,
            userAgent: $this->userAgent,
        );

        return [
            'validated' => $validated,
            'pinnedIp' => $chosenIp,
            'request' => $request,
        ];
    }

    /**
     * Validate post-connect peer IP, handle safe body truncation, follow manual redirects, or extract TLS/response.
     *
     * @return array{response?: SafeHttpResponse, redirectUrl?: string, nextMethod?: string, redirectsFollowed?: int}
     */
    private function evaluateCompletedHop(
        ValidatedUrl $validated,
        string $pinnedIp,
        string $currentMethod,
        int $redirectsFollowed,
        int $deadlineNs,
        int $startedAtNs,
        TransportResponse $transportResponse,
    ): array {
        if ($transportResponse->primaryIp !== null && $transportResponse->primaryIp !== '') {
            $this->assertConnectedPeerValid($transportResponse->primaryIp, $pinnedIp);
        }

        $abortedCleanlyForSizeCap = $transportResponse->curlErrno === self::CURLE_WRITE_ERROR_CODE
            && $transportResponse->bodyTruncated
            && ! $transportResponse->headerLimitExceeded
            && $transportResponse->statusCode > 0;

        if ($transportResponse->curlErrno !== 0 && ! $abortedCleanlyForSizeCap) {
            throw $this->classifyTransportFailure($transportResponse, $redirectsFollowed, $deadlineNs);
        }

        if ($transportResponse->primaryIp === null || $transportResponse->primaryIp === '') {
            throw new BlockedDestinationException('Connected socket peer IP could not be verified.');
        }

        $this->assertConnectedPeerValid($transportResponse->primaryIp, $pinnedIp);

        $location = $this->extractFirstHeaderValue($transportResponse->headers, 'location');
        if ($this->isRedirectStatus($transportResponse->statusCode) && $location !== null) {
            if ($redirectsFollowed >= self::MAX_REDIRECTS) {
                throw new SafeHttpRequestException(
                    SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED,
                    'Maximum redirect hop limit (3) exceeded.'
                );
            }

            $nextRedirects = $redirectsFollowed + 1;
            $nextUrl = $this->urlValidator->resolveRedirectTarget($validated, $location);
            $nextMethod = ($transportResponse->statusCode === 303 && $currentMethod !== 'HEAD')
                ? 'GET'
                : $currentMethod;

            return [
                'redirectUrl' => $nextUrl,
                'nextMethod' => $nextMethod,
                'redirectsFollowed' => $nextRedirects,
            ];
        }

        [$tlsExpiresAt, $tlsIssuer] = $this->extractTlsMetadata($validated, $transportResponse->certInfo);
        $latencyMs = $this->elapsedMs($startedAtNs);

        return [
            'response' => new SafeHttpResponse(
                finalUrl: $validated->url,
                resolvedIp: $transportResponse->primaryIp,
                statusCode: $transportResponse->statusCode,
                headers: $transportResponse->headers,
                body: $transportResponse->body,
                bodyTruncated: $transportResponse->bodyTruncated,
                bytesReceived: $transportResponse->bytesReceived,
                latencyMs: $latencyMs,
                redirectsFollowed: $redirectsFollowed,
                tlsExpiresAt: $tlsExpiresAt,
                tlsIssuer: $tlsIssuer,
            ),
        ];
    }

    private function buildCheckResultFromResponse(
        SafeHttpResponse $response,
        int $expectedStatusMin,
        int $expectedStatusMax,
        bool $includeResponse = true,
    ): SafeHttpCheckResult {
        $tlsExpiringSoon = $response->isTlsExpiringSoon(self::TLS_EXPIRY_WARNING_DAYS);

        if ($response->statusCode < $expectedStatusMin || $response->statusCode > $expectedStatusMax) {
            return SafeHttpCheckResult::failure(
                reason: SafeHttpFailureReason::HTTP_ERROR,
                errorMessage: "Unexpected HTTP status code {$response->statusCode}.",
                finalUrl: $response->finalUrl,
                latencyMs: $response->latencyMs,
                statusCode: $response->statusCode,
                resolvedIp: $response->resolvedIp,
                redirectsFollowed: $response->redirectsFollowed,
                tlsExpiresAt: $response->tlsExpiresAt,
                tlsIssuer: $response->tlsIssuer,
                tlsExpiringSoon: $tlsExpiringSoon,
                bodyTruncated: $response->bodyTruncated,
                bytesReceived: $response->bytesReceived,
            );
        }

        return SafeHttpCheckResult::success($response, $tlsExpiringSoon, $includeResponse);
    }

    private function buildFailureResultFromException(
        BlockedDestinationException|SafeHttpRequestException $exception,
        string $url,
        int $startedAtNs,
    ): SafeHttpCheckResult {
        $reason = $exception instanceof BlockedDestinationException
            ? SafeHttpFailureReason::SSRF_BLOCKED
            : $exception->reason;

        return SafeHttpCheckResult::failure(
            reason: $reason,
            errorMessage: $this->sanitizeErrorMessage($exception),
            finalUrl: $this->sanitizeUrlForReport($url),
            latencyMs: $this->elapsedMs($startedAtNs),
        );
    }

    private function assertValidTimeout(int $timeoutSeconds): void
    {
        if ($timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'Monitor HTTP timeout must be between %d and %d seconds.',
                self::MIN_TIMEOUT_SECONDS,
                self::MAX_TIMEOUT_SECONDS,
            ));
        }
    }

    private function normalizeMethod(string $method): string
    {
        $upper = strtoupper(trim($method));

        if (! in_array($upper, ['GET', 'HEAD'], true)) {
            throw new InvalidArgumentException('Unsupported HTTP check method; only GET and HEAD are permitted.');
        }

        return $upper;
    }

    private function resolveAndSelectPinnedIp(ValidatedUrl $validated, ?int $deadlineNs = null): string
    {
        if ($validated->isIpLiteral) {
            return $this->ipPolicy->assertAllowed($validated->host);
        }

        if ($deadlineNs !== null && $this->nowNs() >= $deadlineNs) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::DEADLINE_EXCEEDED,
                'Remaining deadline exceeded prior to DNS resolution.'
            );
        }

        $resolvedIps = $this->dnsResolver instanceof DeadlineAwareDnsResolverInterface
            ? $this->dnsResolver->resolveWithDeadline($validated->host, $deadlineNs)
            : $this->dnsResolver->resolve($validated->host);

        if ($deadlineNs !== null && $this->nowNs() >= $deadlineNs) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::DEADLINE_EXCEEDED,
                'Remaining deadline elapsed during synchronous DNS resolution.'
            );
        }

        if ($resolvedIps === []) {
            throw new SafeHttpRequestException(
                SafeHttpFailureReason::DNS_FAILURE,
                "DNS lookup failed to return any IP addresses for [{$validated->host}]."
            );
        }

        $validatedIps = [];
        foreach ($resolvedIps as $ip) {
            // Every returned A and AAAA address must be permitted before choosing one.
            $validatedIps[] = $this->ipPolicy->assertAllowed($ip);
        }

        foreach ($validatedIps as $ip) {
            if ($this->ipPolicy->isCanonicalIpv4($ip)) {
                return $ip;
            }
        }

        return $validatedIps[0];
    }

    private function assertConnectedPeerValid(string $primaryIp, string $pinnedIp): void
    {
        $normalizedPeer = $this->ipPolicy->assertAllowed($primaryIp);

        if (! $this->ipPolicy->sameIp($normalizedPeer, $pinnedIp)) {
            throw new BlockedDestinationException(
                "Connected socket peer [{$normalizedPeer}] does not match pinned destination IP [{$pinnedIp}]."
            );
        }
    }

    private function remainingBudgetMs(int $deadlineNs): int
    {
        $remainingNs = $deadlineNs - $this->nowNs();

        if ($remainingNs <= 0) {
            return 0;
        }

        return max(1, intdiv($remainingNs + 999_999, 1_000_000));
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return max(0, (int) round(($this->nowNs() - $startedAtNs) / 1_000_000));
    }

    private function nowNs(): int
    {
        return ($this->monotonicClockNs)();
    }

    private function isRedirectStatus(int $statusCode): bool
    {
        return in_array($statusCode, self::REDIRECT_STATUS_CODES, true);
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function extractFirstHeaderValue(array $headers, string $name): ?string
    {
        $values = $headers[strtolower($name)] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $value = trim((string) $values[0]);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $certInfo
     * @return array{0: ?DateTimeImmutable, 1: ?string}
     */
    private function extractTlsMetadata(ValidatedUrl $validated, array $certInfo): array
    {
        if ($validated->scheme !== 'https' || $certInfo === []) {
            return [null, null];
        }

        $leaf = $certInfo[0] ?? null;
        if (! is_array($leaf)) {
            return [null, null];
        }

        $issuer = is_string($leaf['Issuer'] ?? null) ? trim($leaf['Issuer']) : null;
        $expiresAt = null;

        if (isset($leaf['validTo_time_t']) && is_numeric($leaf['validTo_time_t'])) {
            $expiresAt = DateTimeImmutable::createFromFormat(
                'U',
                (string) ((int) $leaf['validTo_time_t']),
                new DateTimeZone('UTC')
            ) ?: null;
        } elseif (is_string($leaf['Expire date'] ?? null) && trim($leaf['Expire date']) !== '') {
            try {
                $expiresAt = new DateTimeImmutable(trim($leaf['Expire date']), new DateTimeZone('UTC'));
            } catch (Throwable) {
                $expiresAt = null;
            }
        } elseif (is_string($leaf['Cert'] ?? null) && function_exists('openssl_x509_parse')) {
            $parsed = @openssl_x509_parse($leaf['Cert']);
            if (is_array($parsed) && isset($parsed['validTo_time_t']) && is_numeric($parsed['validTo_time_t'])) {
                $expiresAt = DateTimeImmutable::createFromFormat(
                    'U',
                    (string) ((int) $parsed['validTo_time_t']),
                    new DateTimeZone('UTC')
                ) ?: null;
            }
        }

        return [$expiresAt, $issuer];
    }

    private function classifyTransportFailure(
        TransportResponse $response,
        int $redirectsFollowed,
        int $deadlineNs,
    ): SafeHttpRequestException {
        if ($response->headerLimitExceeded) {
            return new SafeHttpRequestException(
                SafeHttpFailureReason::HTTP_ERROR,
                'Response headers exceeded maximum permitted size or header count.'
            );
        }

        $errno = $response->curlErrno;

        if ($errno === self::CURLE_OPERATION_TIMEDOUT_CODE) {
            if ($redirectsFollowed > 0 && $this->nowNs() >= $deadlineNs) {
                return new SafeHttpRequestException(
                    SafeHttpFailureReason::DEADLINE_EXCEEDED,
                    'Total monitor check deadline exceeded across redirect hops.'
                );
            }

            return new SafeHttpRequestException(
                SafeHttpFailureReason::TIMEOUT,
                'HTTP probe timed out before receiving a complete response.'
            );
        }

        if (in_array($errno, self::TLS_ERROR_CODES, true)) {
            return new SafeHttpRequestException(
                SafeHttpFailureReason::TLS_ERROR,
                'TLS handshake or peer certificate verification failed.'
            );
        }

        if ($errno === self::CURLE_COULDNT_CONNECT_CODE) {
            return new SafeHttpRequestException(
                SafeHttpFailureReason::CONNECTION_REFUSED,
                'Target server refused or could not accept the TCP connection.'
            );
        }

        if (in_array($errno, [self::CURLE_COULDNT_RESOLVE_HOST_CODE, self::CURLE_COULDNT_RESOLVE_PROXY_CODE], true)) {
            return new SafeHttpRequestException(
                SafeHttpFailureReason::DNS_FAILURE,
                'Unable to resolve target hostname during transport.'
            );
        }

        return new SafeHttpRequestException(
            SafeHttpFailureReason::HTTP_ERROR,
            'Outbound HTTP transport failed safely.'
        );
    }

    private function sanitizeErrorMessage(Throwable $exception): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $exception->getMessage()) ?? 'HTTP check failed.';
        $message = preg_replace('#(https?://)[^/@\s]+@#i', '$1[REDACTED]@', $message) ?? $message;
        $message = trim($message);

        if ($message === '') {
            return 'HTTP check failed.';
        }

        return strlen($message) > 255 ? substr($message, 0, 252).'...' : $message;
    }

    private function sanitizeUrlForReport(string $url): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?? '';
        $sanitized = preg_replace('#^(https?://)[^/?\#@]+@#i', '$1[REDACTED]@', $sanitized) ?? $sanitized;
        $sanitized = trim($sanitized);

        return strlen($sanitized) > 255 ? substr($sanitized, 0, 252).'...' : $sanitized;
    }
}
