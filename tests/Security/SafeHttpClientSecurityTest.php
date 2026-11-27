<?php

use App\Exceptions\BlockedDestinationException;
use App\Support\SafeHttp\BoundedBodyBuffer;
use App\Support\SafeHttp\CanonicalUrlValidator;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\CurlHttpTransport;
use App\Support\SafeHttp\NativeDnsResolver;
use App\Support\SafeHttp\SafeHttpClient;
use App\Support\SafeHttp\SafeHttpFailureReason;
use App\Support\SafeHttp\SafeHttpRequestException;
use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;

class RecordingDnsResolver implements DnsResolverInterface
{
    /** @var array<int, string> */
    public array $queries = [];

    /**
     * @param  Closure(string, int): array<int, string>  $resolver
     */
    public function __construct(
        private readonly Closure $resolver,
    ) {}

    public function resolve(string $hostname): array
    {
        $attempt = count($this->queries);
        $this->queries[] = $hostname;

        return ($this->resolver)($hostname, $attempt);
    }
}

class RecordingHttpTransport implements HttpTransportInterface
{
    /** @var array<int, TransportRequest> */
    public array $requests = [];

    /**
     * @param  Closure(TransportRequest, int): TransportResponse  $handler
     */
    public function __construct(
        private readonly Closure $handler,
    ) {}

    public function send(TransportRequest $request): TransportResponse
    {
        $attempt = count($this->requests);
        $this->requests[] = $request;

        return ($this->handler)($request, $attempt);
    }
}

test('container resolves SafeHttpClient with NativeDnsResolver and CurlHttpTransport bindings', function () {
    expect(app(DnsResolverInterface::class))->toBeInstanceOf(NativeDnsResolver::class);
    expect(app(HttpTransportInterface::class))->toBeInstanceOf(CurlHttpTransport::class);
    expect(app(SafeHttpClient::class))->toBeInstanceOf(SafeHttpClient::class);
});

test('SEC-07 blocks DNS resolutions returning private or metadata IP addresses before opening a socket', function (string $resolvedIp) {
    $dns = new RecordingDnsResolver(fn (): array => [$resolvedIp]);
    $transport = new RecordingHttpTransport(function (): TransportResponse {
        throw new RuntimeException('Transport must not execute when DNS resolves to a private IP.');
    });

    $client = new SafeHttpClient(dnsResolver: $dns, transport: $transport);

    expect(fn () => $client->request('http://rebinding.test/status'))
        ->toThrow(BlockedDestinationException::class);

    expect($dns->queries)->toBe(['rebinding.test']);
    expect($transport->requests)->toHaveCount(0);

    $check = $client->check('http://rebinding.test/status');
    expect($check->succeeded)->toBeFalse();
    expect($check->failureReason)->toBe(SafeHttpFailureReason::SSRF_BLOCKED);
})->with([
    'rfc1918 class A' => '10.1.2.3',
    'loopback' => '127.0.0.1',
    'imds ipv4' => '169.254.169.254',
    'imds ipv6' => 'fd00:ec2::254',
    'ipv4 mapped loopback' => '::ffff:127.0.0.1',
]);

test('SEC-08 rejects dual-homed split DNS responses whenever any returned record is restricted', function (array $records) {
    $dns = new RecordingDnsResolver(fn (): array => $records);
    $transport = new RecordingHttpTransport(function (): TransportResponse {
        throw new RuntimeException('Transport must not execute on split private/public DNS.');
    });

    $client = new SafeHttpClient(dnsResolver: $dns, transport: $transport);

    expect(fn () => $client->request('https://dualhomed.test/'))
        ->toThrow(BlockedDestinationException::class);

    expect($transport->requests)->toHaveCount(0);
})->with([
    'public ipv4 plus loopback' => [['93.184.216.34', '127.0.0.1']],
    'loopback ipv6 plus public ipv4' => [['::1', '93.184.216.34']],
    'public ipv6 plus rfc1918' => [['2606:4700:4700::1111', '10.0.0.5']],
    'public ipv4 plus test-net-2' => [['93.184.216.34', '198.51.100.1']],
]);

test('SEC-09 blocks redirects targeting private IPs, metadata endpoints, forbidden schemes, or DNS-rebound hosts', function (string $redirectLocation, bool $rebindSecondDns = false) {
    $dns = new RecordingDnsResolver(function (string $host, int $attempt) use ($rebindSecondDns): array {
        if ($rebindSecondDns && $attempt >= 1) {
            return ['127.0.0.1'];
        }

        return ['93.184.216.34'];
    });

    $transport = new RecordingHttpTransport(function (TransportRequest $request, int $attempt) use ($redirectLocation): TransportResponse {
        if ($attempt === 0) {
            return new TransportResponse(
                statusCode: 302,
                headers: ['location' => [$redirectLocation]],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: $request->pinnedIp,
            );
        }

        throw new RuntimeException('Second hop must never reach transport after SSRF redirect rejection.');
    });

    $client = new SafeHttpClient(dnsResolver: $dns, transport: $transport);

    expect(fn () => $client->request('https://public.example.test/start'))
        ->toThrow(BlockedDestinationException::class);

    expect($transport->requests)->toHaveCount(1);
})->with([
    'redirect to ipv4 loopback' => ['http://127.0.0.1/', false],
    'redirect to imds metadata' => ['http://169.254.169.254/latest/meta-data/', false],
    'protocol-relative redirect to loopback' => ['//[::1]/secret', false],
    'redirect to file scheme' => ['file:///etc/passwd', false],
    'redirect to gopher scheme' => ['gopher://127.0.0.1:6379/', false],
    'redirect to credential url' => ['https://admin:secret@public.example.test/next', false],
    'dns rebinding on hop 1' => ['/rebound-step', true],
]);

test('SEC-10 enforces redirect loop detection and maximum 3 redirect hops with MAX_REDIRECTS_EXCEEDED', function () {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);

    $loopTransport = new RecordingHttpTransport(function (TransportRequest $request): TransportResponse {
        $next = str_ends_with($request->url->pathAndQuery, '/a') ? '/b' : '/a';

        return new TransportResponse(
            statusCode: 302,
            headers: ['location' => [$next]],
            body: '',
            bodyTruncated: false,
            bytesReceived: 0,
            primaryIp: $request->pinnedIp,
        );
    });

    $loopClient = new SafeHttpClient(dnsResolver: $dns, transport: $loopTransport);

    $caught = null;
    try {
        $loopClient->request('https://loop.example.test/a');
    } catch (SafeHttpRequestException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(SafeHttpRequestException::class);
    expect($caught?->reason)->toBe(SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED);
    expect($loopTransport->requests)->toHaveCount(2);

    $loopCheck = $loopClient->check('https://loop.example.test/a');
    expect($loopCheck->succeeded)->toBeFalse();
    expect($loopCheck->failureReason)->toBe(SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED);

    // 4-hop chain (/1 -> /2 -> /3 -> /4 -> /5) must execute 4 requests (3 followed hops) and abort on 4th redirect.
    $chainTransport = new RecordingHttpTransport(function (TransportRequest $request, int $attempt): TransportResponse {
        return new TransportResponse(
            statusCode: 301,
            headers: ['location' => ['/hop-'.($attempt + 2)]],
            body: '',
            bodyTruncated: false,
            bytesReceived: 0,
            primaryIp: $request->pinnedIp,
        );
    });

    $chainClient = new SafeHttpClient(dnsResolver: $dns, transport: $chainTransport);

    $chainResult = $chainClient->check('https://chain.example.test/hop-1');
    expect($chainResult->succeeded)->toBeFalse();
    expect($chainResult->failureReason)->toBe(SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED);
    expect($chainTransport->requests)->toHaveCount(4);

    // Valid 3-hop chain (/1 -> /2 -> /3 -> /4 returning 200 OK) succeeds.
    $validTransport = new RecordingHttpTransport(function (TransportRequest $request, int $attempt): TransportResponse {
        if ($attempt < 3) {
            return new TransportResponse(
                statusCode: 307,
                headers: ['location' => ['/hop-'.($attempt + 2)]],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: $request->pinnedIp,
            );
        }

        return new TransportResponse(
            statusCode: 200,
            headers: ['content-type' => ['text/plain']],
            body: 'ok',
            bodyTruncated: false,
            bytesReceived: 2,
            primaryIp: $request->pinnedIp,
        );
    });

    $validResponse = (new SafeHttpClient(dnsResolver: $dns, transport: $validTransport))
        ->request('https://chain.example.test/hop-1');

    expect($validResponse->statusCode)->toBe(200);
    expect($validResponse->redirectsFollowed)->toBe(3);
    expect($validResponse->finalUrl)->toBe('https://chain.example.test/hop-4');
});

test('SEC-13 forces cURL proxy isolation, DNS pinning, disabled auto-redirects, and TLS verification even with proxy env vars set', function () {
    $originalHttpProxy = getenv('http_proxy');
    $originalUpperHttpsProxy = getenv('HTTPS_PROXY');
    putenv('http_proxy=http://internal-proxy.local:8080');
    putenv('HTTPS_PROXY=http://internal-proxy.local:8443');

    try {
        $validator = new CanonicalUrlValidator;
        $validated = $validator->validate('https://example.com/health');

        $request = new TransportRequest(
            url: $validated,
            pinnedIp: '93.184.216.34',
        );

        $curlTransport = new CurlHttpTransport;
        $options = $curlTransport->buildCurlOptions($request);

        expect($options[CURLOPT_PROXY])->toBe('');
        expect($options[CURLOPT_NOPROXY])->toBe('*');
        expect($options[CURLOPT_FOLLOWLOCATION])->toBeFalse();
        expect($options[CURLOPT_MAXREDIRS])->toBe(0);
        expect($options[CURLOPT_SSL_VERIFYPEER])->toBeTrue();
        expect($options[CURLOPT_SSL_VERIFYHOST])->toBe(2);
        expect($options[CURLOPT_CERTINFO])->toBeTrue();
        expect($options[CURLOPT_DNS_CACHE_TIMEOUT])->toBe(0);
        expect($options[CURLOPT_FORBID_REUSE])->toBeTrue();
        expect($options[CURLOPT_FRESH_CONNECT])->toBeTrue();
        expect($options[CURLOPT_LOW_SPEED_LIMIT])->toBe(1024);
        expect($options[CURLOPT_LOW_SPEED_TIME])->toBe(5);
        expect($options[CURLOPT_USERAGENT])->toBe(SafeHttpClient::DEFAULT_USER_AGENT);
        expect($options[CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.216.34']);
        expect($options)->not->toHaveKey(CURLOPT_HTTPHEADER);
        expect($options)->not->toHaveKey(CURLOPT_COOKIE);
        expect($options)->not->toHaveKey(CURLOPT_COOKIEFILE);
        expect($options)->not->toHaveKey(CURLOPT_COOKIEJAR);

        if (defined('CURLOPT_UNRESTRICTED_AUTH')) {
            expect($options[CURLOPT_UNRESTRICTED_AUTH])->toBeFalse();
        }

        if (defined('CURLOPT_NOSIGNAL')) {
            expect($options[CURLOPT_NOSIGNAL])->toBeTrue();
        }

        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            expect($options[CURLOPT_CONNECTTIMEOUT_MS])->toBe(5000);
        } else {
            expect($options[CURLOPT_CONNECTTIMEOUT])->toBe(5);
        }

        if (defined('CURLOPT_TIMEOUT_MS')) {
            expect($options[CURLOPT_TIMEOUT_MS])->toBe(10000);
        } else {
            expect($options[CURLOPT_TIMEOUT])->toBe(10);
        }

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            expect($options[CURLOPT_IPRESOLVE])->toBe(CURL_IPRESOLVE_V4);
        }

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            expect($options[CURLOPT_PROTOCOLS_STR])->toBe('http,https');
        }

        // Verify libcurl accepts the hardened option array cleanly on a real CurlHandle.
        $handle = curl_init();
        expect($handle)->not->toBeFalse();
        expect(curl_setopt_array($handle, $options))->toBeTrue();
        curl_close($handle);

        // Also verify IPv6 pinning option compatibility on a real CurlHandle.
        $ipv6Options = $curlTransport->buildCurlOptions(new TransportRequest(
            url: $validator->validate('https://ipv6.example.test/health'),
            pinnedIp: '2606:4700:4700::1111',
        ));
        expect($ipv6Options[CURLOPT_RESOLVE])->toBe(['ipv6.example.test:443:2606:4700:4700::1111']);
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V6')) {
            expect($ipv6Options[CURLOPT_IPRESOLVE])->toBe(CURL_IPRESOLVE_V6);
        }
        $ipv6Handle = curl_init();
        expect($ipv6Handle)->not->toBeFalse();
        expect(curl_setopt_array($ipv6Handle, $ipv6Options))->toBeTrue();
        curl_close($ipv6Handle);
    } finally {
        putenv($originalHttpProxy === false ? 'http_proxy' : "http_proxy={$originalHttpProxy}");
        putenv($originalUpperHttpsProxy === false ? 'HTTPS_PROXY' : "HTTPS_PROXY={$originalUpperHttpsProxy}");
    }
});

test('SEC-14 aborts and discards response when connected peer IP is missing, restricted, or mismatches pinned IP', function (?string $connectedPeerIp) {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $transport = new RecordingHttpTransport(fn (): TransportResponse => new TransportResponse(
        statusCode: 200,
        headers: [],
        body: 'SENSITIVE_INTERNAL_DATA',
        bodyTruncated: false,
        bytesReceived: 23,
        primaryIp: $connectedPeerIp,
    ));

    $client = new SafeHttpClient(dnsResolver: $dns, transport: $transport);

    expect(fn () => $client->request('https://public.example.test/'))
        ->toThrow(BlockedDestinationException::class);

    $check = $client->check('https://public.example.test/');
    expect($check->succeeded)->toBeFalse();
    expect($check->failureReason)->toBe(SafeHttpFailureReason::SSRF_BLOCKED);
    expect($check->response)->toBeNull();
})->with([
    'private peer IP' => ['10.0.0.1'],
    'loopback peer IP' => ['127.0.0.1'],
    'different unpinned public IP' => ['1.1.1.1'],
    'missing peer IP' => [null],
    'empty peer IP' => [''],
]);

test('recognizes compressed and expanded IPv6 peer IP equivalence during peer verification', function () {
    $dns = new RecordingDnsResolver(fn (): array => ['2606:4700:4700:0000:0000:0000:0000:1111']);
    $transport = new RecordingHttpTransport(fn (): TransportResponse => new TransportResponse(
        statusCode: 200,
        headers: [],
        body: 'ok',
        bodyTruncated: false,
        bytesReceived: 2,
        primaryIp: '2606:4700:4700::1111',
    ));

    $response = (new SafeHttpClient(dnsResolver: $dns, transport: $transport))
        ->request('https://ipv6.example.test/');

    expect($response->statusCode)->toBe(200);
    expect($response->resolvedIp)->toBe('2606:4700:4700::1111');
});

test('SEC-15 classifies slowloris timeouts, TLS failures, connection refused, and DNS failures safely', function (int $curlErrno, SafeHttpFailureReason $expectedReason) {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $transport = new RecordingHttpTransport(fn (): TransportResponse => new TransportResponse(
        statusCode: 0,
        headers: [],
        body: '',
        bodyTruncated: false,
        bytesReceived: 0,
        primaryIp: $curlErrno === 28 || $curlErrno === 35 ? '93.184.216.34' : null,
        curlErrno: $curlErrno,
        curlError: 'Simulated curl transport failure',
    ));

    $client = new SafeHttpClient(dnsResolver: $dns, transport: $transport);
    $result = $client->check('https://slow.example.test/', timeoutSeconds: 10);

    expect($result->succeeded)->toBeFalse();
    expect($result->failureReason)->toBe($expectedReason);
    expect($result->statusCode)->toBeNull();
})->with([
    'timeout / slowloris (CURLE_OPERATION_TIMEDOUT)' => [28, SafeHttpFailureReason::TIMEOUT],
    'tls handshake error (CURLE_SSL_CONNECT_ERROR)' => [35, SafeHttpFailureReason::TLS_ERROR],
    'tls cert verification failed (CURLE_PEER_FAILED_VERIFICATION)' => [60, SafeHttpFailureReason::TLS_ERROR],
    'connection refused (CURLE_COULDNT_CONNECT)' => [7, SafeHttpFailureReason::CONNECTION_REFUSED],
]);

test('rejects invalid timeout bounds outside 2 to 15 seconds', function (int $timeout) {
    $client = new SafeHttpClient(
        dnsResolver: new RecordingDnsResolver(fn (): array => ['93.184.216.34']),
        transport: new RecordingHttpTransport(fn (): TransportResponse => throw new RuntimeException('Should not send')),
    );

    expect(fn () => $client->request('https://example.com/', timeoutSeconds: $timeout))
        ->toThrow(InvalidArgumentException::class);
})->with([0, 1, 16, 60]);

test('SEC-16 strictly caps streamed response bodies at 500 KB and aborts oversized transfers cleanly', function () {
    $buffer = new BoundedBodyBuffer(SafeHttpClient::MAX_BODY_BYTES);
    $chunk = str_repeat('0', 65536); // 64 KB chunks -> 8th chunk crosses 512000 bytes (500 KB)

    for ($i = 0; $i < 7; $i++) {
        expect($buffer->append($chunk))->toBe(65536);
        expect($buffer->isTruncated())->toBeFalse();
    }

    // 8th chunk reaches 512000 bytes limit cleanly and returns 0 so libcurl aborts transfer immediately.
    expect($buffer->append($chunk))->toBe(0);
    expect($buffer->isTruncated())->toBeTrue();
    expect($buffer->storedBytes())->toBe(512000);
    expect(strlen($buffer->contents()))->toBe(512000);

    // Subsequent chunks continue aborting with zero growth.
    expect($buffer->append($chunk))->toBe(0);
    expect($buffer->storedBytes())->toBe(512000);

    // Integration with SafeHttpClient accepting clean size-limit abort (CURLE_WRITE_ERROR + bodyTruncated).
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $transport = new RecordingHttpTransport(fn (TransportRequest $request): TransportResponse => new TransportResponse(
        statusCode: 200,
        headers: ['content-type' => ['application/octet-stream']],
        body: $buffer->contents(),
        bodyTruncated: true,
        bytesReceived: $buffer->storedBytes(),
        primaryIp: $request->pinnedIp,
        curlErrno: 23,
        curlError: 'Failed writing received data to disk/application',
    ));

    $client = new SafeHttpClient(dnsResolver: $dns, transport: $transport);
    $check = $client->check('https://giant.example.test/stream');

    expect($check->succeeded)->toBeTrue();
    expect($check->statusCode)->toBe(200);
    expect($check->bodyTruncated)->toBeTrue();
    expect($check->bytesReceived)->toBe(512000);
    expect(strlen((string) $check->response?->body))->toBe(512000);
});

test('extracts TLS leaf certificate expiry metadata and flags certificates expiring within 14 days', function () {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $expiringSoonTimestamp = time() + (7 * 86400);

    $transport = new RecordingHttpTransport(fn (TransportRequest $request): TransportResponse => new TransportResponse(
        statusCode: 200,
        headers: [],
        body: 'ok',
        bodyTruncated: false,
        bytesReceived: 2,
        primaryIp: $request->pinnedIp,
        certInfo: [
            [
                'Issuer' => 'CN=Test Issuing CA',
                'validTo_time_t' => $expiringSoonTimestamp,
            ],
        ],
    ));

    $result = (new SafeHttpClient(dnsResolver: $dns, transport: $transport))
        ->check('https://cert.example.test/');

    expect($result->succeeded)->toBeTrue();
    expect($result->tlsIssuer)->toBe('CN=Test Issuing CA');
    expect($result->tlsExpiresAt)->not->toBeNull();
    expect($result->tlsExpiresAt?->getTimestamp())->toBe($expiringSoonTimestamp);
    expect($result->tlsExpiringSoon)->toBeTrue();
});

test('records DNS_FAILURE and HTTP_ERROR accurately in check() summaries', function () {
    $dnsFailingClient = new SafeHttpClient(
        dnsResolver: new RecordingDnsResolver(fn (): array => []),
        transport: new RecordingHttpTransport(fn (): TransportResponse => throw new RuntimeException('Should not call transport')),
    );

    $dnsResult = $dnsFailingClient->check('https://missing.example.test/');
    expect($dnsResult->succeeded)->toBeFalse();
    expect($dnsResult->failureReason)->toBe(SafeHttpFailureReason::DNS_FAILURE);

    $httpErrorClient = new SafeHttpClient(
        dnsResolver: new RecordingDnsResolver(fn (): array => ['93.184.216.34']),
        transport: new RecordingHttpTransport(fn (TransportRequest $request): TransportResponse => new TransportResponse(
            statusCode: 503,
            headers: [],
            body: 'Service Unavailable',
            bodyTruncated: false,
            bytesReceived: 19,
            primaryIp: $request->pinnedIp,
        )),
    );

    $httpResult = $httpErrorClient->check('https://error.example.test/');
    expect($httpResult->succeeded)->toBeFalse();
    expect($httpResult->statusCode)->toBe(503);
    expect($httpResult->failureReason)->toBe(SafeHttpFailureReason::HTTP_ERROR);
});

test('aborts redirect hops with DEADLINE_EXCEEDED when cumulative wall-clock budget expires', function () {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $nowNs = 1_000_000_000;

    $transport = new RecordingHttpTransport(function (TransportRequest $request) use (&$nowNs): TransportResponse {
        // Consume 6 seconds during hop 0 on a 5-second total budget.
        $nowNs += 6_000_000_000;

        return new TransportResponse(
            statusCode: 302,
            headers: ['location' => ['/next']],
            body: '',
            bodyTruncated: false,
            bytesReceived: 0,
            primaryIp: $request->pinnedIp,
        );
    });

    $client = new SafeHttpClient(
        dnsResolver: $dns,
        transport: $transport,
        monotonicClockNs: static function () use (&$nowNs): int {
            return $nowNs;
        },
    );

    $result = $client->check('https://deadline.example.test/start', timeoutSeconds: 5);

    expect($result->succeeded)->toBeFalse();
    expect($result->failureReason)->toBe(SafeHttpFailureReason::DEADLINE_EXCEEDED);
    expect($transport->requests)->toHaveCount(1);
});

test('parses libcurl Expire date strings and distinguishes healthy vs expiring certificates', function () {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $healthyExpiry = (new DateTimeImmutable('+60 days', new DateTimeZone('UTC')))->format('M d H:i:s Y \G\M\T');

    $transport = new RecordingHttpTransport(fn (TransportRequest $request): TransportResponse => new TransportResponse(
        statusCode: 200,
        headers: [],
        body: 'ok',
        bodyTruncated: false,
        bytesReceived: 2,
        primaryIp: $request->pinnedIp,
        certInfo: [
            [
                'Issuer' => 'CN=Public Root CA',
                'Expire date' => $healthyExpiry,
            ],
        ],
    ));

    $result = (new SafeHttpClient(dnsResolver: $dns, transport: $transport))
        ->check('https://healthy-cert.example.test/', method: 'HEAD');

    expect($result->succeeded)->toBeTrue();
    expect($result->tlsIssuer)->toBe('CN=Public Root CA');
    expect($result->tlsExpiresAt)->not->toBeNull();
    expect($result->tlsExpiringSoon)->toBeFalse();
});

test('Finding 1 regression: disallows custom request headers, validates User-Agent, and never leaks auth or cookies across redirects', function () {
    // Public API methods and TransportRequest must not accept caller-controlled custom header bags.
    $requestParams = array_map(
        static fn (ReflectionParameter $param): string => $param->getName(),
        (new ReflectionMethod(SafeHttpClient::class, 'request'))->getParameters()
    );
    $checkParams = array_map(
        static fn (ReflectionParameter $param): string => $param->getName(),
        (new ReflectionMethod(SafeHttpClient::class, 'check'))->getParameters()
    );
    $transportProps = array_map(
        static fn (ReflectionProperty $prop): string => $prop->getName(),
        (new ReflectionClass(TransportRequest::class))->getProperties()
    );

    expect($requestParams)->not->toContain('headers');
    expect($checkParams)->not->toContain('headers');
    expect($transportProps)->not->toContain('headers');

    // Reject CR/LF/NUL injection in User-Agent before any DNS or cURL execution.
    expect(fn () => new SafeHttpClient(userAgent: "SitePulse/1.0\r\nAuthorization: Bearer secret"))
        ->toThrow(InvalidArgumentException::class);

    $validUrl = (new CanonicalUrlValidator)->validate('https://example.com/');
    expect(fn () => new TransportRequest(url: $validUrl, pinnedIp: '93.184.216.34', userAgent: "SitePulse/1.0\nCookie: sid=1"))
        ->toThrow(InvalidArgumentException::class);

    // Reject malformed/comma-injected or restricted pinned IP directly inside CurlHttpTransport before curl_init().
    $curlTransport = new CurlHttpTransport;
    expect(fn () => $curlTransport->buildCurlOptions(new TransportRequest(url: $validUrl, pinnedIp: '93.184.216.34,127.0.0.1')))
        ->toThrow(BlockedDestinationException::class);
    expect(fn () => $curlTransport->buildCurlOptions(new TransportRequest(url: $validUrl, pinnedIp: '127.0.0.1')))
        ->toThrow(BlockedDestinationException::class);

    // Cross-origin + HTTPS-to-HTTP redirects must emit zero CURLOPT_HTTPHEADER/cookie entries on any hop.
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $transport = new RecordingHttpTransport(function (TransportRequest $request, int $attempt): TransportResponse {
        if ($attempt === 0) {
            return new TransportResponse(
                statusCode: 302,
                headers: [
                    'location' => ['https://origin-b.example.test/step2'],
                    'set-cookie' => ['session=topsecret; HttpOnly; Secure'],
                    'www-authenticate' => ['Bearer realm="origin-a"'],
                ],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: $request->pinnedIp,
            );
        }

        if ($attempt === 1) {
            return new TransportResponse(
                statusCode: 302,
                headers: ['location' => ['http://origin-c.example.test/final']],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: $request->pinnedIp,
            );
        }

        return new TransportResponse(
            statusCode: 200,
            headers: [],
            body: 'ok',
            bodyTruncated: false,
            bytesReceived: 2,
            primaryIp: $request->pinnedIp,
        );
    });

    $response = (new SafeHttpClient(dnsResolver: $dns, transport: $transport))
        ->request('https://origin-a.example.test/start');

    expect($response->statusCode)->toBe(200);
    expect($response->finalUrl)->toBe('http://origin-c.example.test/final');
    expect($transport->requests)->toHaveCount(3);

    foreach ($transport->requests as $hopRequest) {
        $curlOptions = $curlTransport->buildCurlOptions($hopRequest);
        expect($curlOptions)->not->toHaveKey(CURLOPT_HTTPHEADER);
        expect($curlOptions)->not->toHaveKey(CURLOPT_COOKIE);
        expect($curlOptions)->not->toHaveKey(CURLOPT_COOKIEFILE);
        expect($curlOptions)->not->toHaveKey(CURLOPT_COOKIEJAR);
    }
});

test('propagates millisecond redirect budgets without ceiling inflation and treats header floods as HTTP_ERROR', function () {
    $dns = new RecordingDnsResolver(fn (): array => ['93.184.216.34']);
    $nowNs = 1_000_000_000;

    // 2s total budget -> Hop 0 takes 1200ms -> Hop 1 must receive exact 800ms timeout (not 1000ms or 2000ms).
    $transport = new RecordingHttpTransport(function (TransportRequest $request, int $attempt) use (&$nowNs): TransportResponse {
        if ($attempt === 0) {
            $nowNs += 1_200_000_000;

            return new TransportResponse(
                statusCode: 302,
                headers: ['location' => ['/hop-2']],
                body: '',
                bodyTruncated: false,
                bytesReceived: 0,
                primaryIp: $request->pinnedIp,
            );
        }

        $nowNs += 900_000_000; // pushes past 2000ms total deadline

        return new TransportResponse(
            statusCode: 0,
            headers: [],
            body: '',
            bodyTruncated: false,
            bytesReceived: 0,
            primaryIp: $request->pinnedIp,
            curlErrno: 28,
            curlError: 'Operation timed out',
        );
    });

    $client = new SafeHttpClient(
        dnsResolver: $dns,
        transport: $transport,
        monotonicClockNs: static function () use (&$nowNs): int {
            return $nowNs;
        },
    );

    $result = $client->check('https://budget.example.test/hop-1', timeoutSeconds: 2);

    expect($transport->requests)->toHaveCount(2);
    expect($transport->requests[0]->timeoutMs)->toBe(2000);
    expect($transport->requests[1]->timeoutMs)->toBe(800);
    expect($result->succeeded)->toBeFalse();
    expect($result->failureReason)->toBe(SafeHttpFailureReason::DEADLINE_EXCEEDED);

    // Header floods must never masquerade as successful truncated body transfers.
    $headerFloodClient = new SafeHttpClient(
        dnsResolver: $dns,
        transport: new RecordingHttpTransport(fn (TransportRequest $request): TransportResponse => new TransportResponse(
            statusCode: 200,
            headers: [],
            body: '',
            bodyTruncated: true,
            bytesReceived: 0,
            primaryIp: $request->pinnedIp,
            curlErrno: 23,
            curlError: 'Header limit exceeded',
            headerLimitExceeded: true,
        )),
    );

    $floodResult = $headerFloodClient->check('https://flood.example.test/');
    expect($floodResult->succeeded)->toBeFalse();
    expect($floodResult->failureReason)->toBe(SafeHttpFailureReason::HTTP_ERROR);

    expect(fn () => new BoundedBodyBuffer(-1))->toThrow(InvalidArgumentException::class);
});

test('redacts credentials and strips control characters from reported check URLs and error messages', function () {
    $client = new SafeHttpClient(
        dnsResolver: new RecordingDnsResolver(fn (): array => ['93.184.216.34']),
        transport: new RecordingHttpTransport(fn (): TransportResponse => throw new RuntimeException('Should not send')),
    );

    $result = $client->check("https://alice:SuperSecretPassword@example.com/health\r\n");

    expect($result->succeeded)->toBeFalse();
    expect($result->failureReason)->toBe(SafeHttpFailureReason::SSRF_BLOCKED);
    expect($result->finalUrl)->not->toContain('SuperSecretPassword');
    expect($result->finalUrl)->not->toContain("\r");
    expect($result->finalUrl)->not->toContain("\n");
    expect((string) $result->errorMessage)->not->toContain('SuperSecretPassword');
});
