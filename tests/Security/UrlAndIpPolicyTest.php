<?php

use App\Exceptions\BlockedDestinationException;
use App\Support\SafeHttp\CanonicalUrlValidator;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\Contracts\HttpTransportInterface;
use App\Support\SafeHttp\IpAddressPolicy;
use App\Support\SafeHttp\SafeHttpClient;
use App\Support\SafeHttp\TransportRequest;
use App\Support\SafeHttp\TransportResponse;

function makeZeroPacketClient(): SafeHttpClient
{
    $dns = new class implements DnsResolverInterface
    {
        public function resolve(string $hostname): array
        {
            throw new RuntimeException("Unexpected DNS lookup for [{$hostname}].");
        }
    };

    $transport = new class implements HttpTransportInterface
    {
        public function send(TransportRequest $request): TransportResponse
        {
            throw new RuntimeException("Unexpected outbound transport call to [{$request->url->url}].");
        }
    };

    return new SafeHttpClient(
        urlValidator: new CanonicalUrlValidator,
        ipPolicy: new IpAddressPolicy,
        dnsResolver: $dns,
        transport: $transport,
    );
}

test('SEC-01 blocks direct IPv4 loopback destinations before DNS or transport execution', function (string $url) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request($url))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'standard loopback' => 'http://127.0.0.1/',
    'https loopback port 8443' => 'https://127.0.0.1:8443/health',
    'high loopback host' => 'http://127.255.255.254/',
    'this host zero network' => 'http://0.0.0.0/',
]);

test('SEC-02 blocks direct IPv6 loopback and IPv4-embedded loopback destinations', function (string $url) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request($url))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'compressed loopback' => 'http://[::1]/',
    'expanded loopback' => 'https://[0:0:0:0:0:0:0:1]/',
    'unspecified ipv6' => 'http://[::]/',
    'ipv4-mapped dotted loopback' => 'http://[::ffff:127.0.0.1]/',
    'ipv4-mapped hex loopback' => 'http://[::ffff:7f00:1]/',
    'ipv4-compatible loopback' => 'http://[::127.0.0.1]/',
    'unbracketed ipv6' => 'http://::1/',
    'zoned link local' => 'http://[fe80::1%eth0]/',
]);

test('SEC-03 blocks RFC 1918 private, CGNAT, documentation, benchmark, multicast, and broadcast CIDRs', function (string $ip) {
    $policy = new IpAddressPolicy;
    $client = makeZeroPacketClient();

    expect($policy->isBlocked($ip))->toBeTrue();
    $urlIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;

    expect(fn () => $client->request("http://{$urlIp}/"))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'rfc1918 class A' => '10.0.0.1',
    'rfc1918 class B lower' => '172.16.0.1',
    'rfc1918 class B upper' => '172.31.255.254',
    'rfc1918 class C' => '192.168.1.1',
    'cgnat start' => '100.64.0.1',
    'cgnat end' => '100.127.255.254',
    'ietf protocol' => '192.0.0.1',
    'test net 1' => '192.0.2.1',
    '6to4 anycast' => '192.88.99.1',
    'benchmarking' => '198.18.0.1',
    'test net 2' => '198.51.100.1',
    'test net 3' => '203.0.113.1',
    'multicast' => '224.0.0.1',
    'reserved future' => '240.0.0.1',
    'broadcast' => '255.255.255.255',
    'ipv6 discard' => '100::1',
    'ipv6 documentation' => '2001:db8::1',
    'ipv6 unique local' => 'fc00::1',
    'ipv6 link local' => 'fe80::1',
    'ipv6 site local deprecated' => 'fec0::1',
    'ipv6 multicast' => 'ff02::1',
    'ipv6 nat64 well known loopback' => '64:ff9b::127.0.0.1',
    'ipv6 nat64 local use' => '64:ff9b:1::1',
    'ipv6 siit translated loopback' => '::ffff:0:127.0.0.1',
    'ipv6 teredo' => '2001::1',
    'ipv6 benchmarking' => '2001:2::1',
    'ipv6 orchid' => '2001:10::1',
    'ipv6 orchid v2' => '2001:20::1',
    'ipv6 6to4 loopback' => '2002:7f00:1::',
    'ipv6 doc 3fff' => '3fff::1',
]);

test('allows routable public IPv4 and IPv6 addresses adjacent to private boundaries', function (string $ip) {
    $policy = new IpAddressPolicy;

    expect($policy->isBlocked($ip))->toBeFalse();
    expect($policy->assertAllowed($ip))->toBe($ip);
})->with([
    'below 172.16' => '172.15.255.254',
    'above 172.31' => '172.32.0.1',
    'below cgnat' => '100.63.255.254',
    'above cgnat' => '100.128.0.1',
    'public ipv4' => '93.184.216.34',
    'public ipv6 cloudflare' => '2606:4700:4700::1111',
    'public ipv6 google' => '2001:4860:4860::8888',
]);

test('SEC-04 blocks cloud metadata IPs and internal metadata/localhost hostnames before DNS', function (string $url) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request($url))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'aws gcp azure imds' => 'http://169.254.169.254/latest/meta-data/',
    'aws imds trailing dot' => 'http://169.254.169.254./latest/meta-data/',
    'alibaba imds' => 'http://100.100.100.200/latest/meta-data/',
    'aws ipv6 imds' => 'http://[fd00:ec2::254]/latest/meta-data/',
    'gcp metadata hostname' => 'http://metadata.google.internal/computeMetadata/v1/',
    'gcp metadata trailing dot' => 'http://metadata.google.internal./computeMetadata/v1/',
    'localhost' => 'http://localhost/health',
    'subdomain localhost' => 'http://admin.localhost/health',
    'mdns local hostname' => 'http://printer.local/',
    'single label service' => 'http://redis/',
]);

test('SEC-05 rejects hex, octal, dword, shorthand, mixed-radix, and bracketed-IPv4 literals', function (string $url) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request($url))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'decimal dword loopback' => 'http://2130706433/',
    'decimal dword metadata' => 'http://2852039166/',
    'hex dword' => 'http://0x7f000001/',
    'hex dotted' => 'http://0x7f.0x00.0x00.0x01/',
    'mixed hex and decimal' => 'http://0x7f.0.0.1/',
    'octal dotted' => 'http://0177.0.0.1/',
    'octal single dword' => 'http://017700000001/',
    'two-part shorthand' => 'http://127.1/',
    'three-part shorthand' => 'http://127.0.1/',
    'bracketed ipv4' => 'http://[1.1.1.1]/',
]);

test('SEC-06 rejects IPv4 addresses containing leading zeros in any octet', function (string $url) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request($url))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'first octet leading zero' => 'http://0127.0.0.1/',
    'zero-padded octets' => 'http://127.000.000.001/',
    'public IP first octet zero' => 'http://093.184.216.34/',
    'public IP last octet zero' => 'http://93.184.216.034/',
]);

test('SEC-11 blocks sensitive database, admin, mail, and unallowlisted ports', function (int $port) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request("http://example.com:{$port}/"))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'ftp 21' => 21,
    'ssh 22' => 22,
    'telnet 23' => 23,
    'smtp 25' => 25,
    'dns 53' => 53,
    'pop3 110' => 110,
    'imap 143' => 143,
    'ldap 389' => 389,
    'smb 445' => 445,
    'ldaps 636' => 636,
    'mssql 1433' => 1433,
    'oracle 1521' => 1521,
    'nfs 2049' => 2049,
    'docker 2375' => 2375,
    'docker tls 2376' => 2376,
    'mysql 3306' => 3306,
    'postgres 5432' => 5432,
    'redis 6379' => 6379,
    'alt 8000' => 8000,
    'elastic 9200' => 9200,
    'memcached 11211' => 11211,
    'mongo 27017' => 27017,
    'unallowlisted 9090' => 9090,
]);

test('allows only canonical ports 80, 443, 8080, and 8443 on valid FQDNs', function (string $url, int $expectedPort) {
    $validator = new CanonicalUrlValidator;
    $validated = $validator->validate($url);

    expect($validated->port)->toBe($expectedPort);
    expect($validated->isIpLiteral)->toBeFalse();
})->with([
    'default http' => ['http://example.com/status', 80],
    'default https' => ['https://api.example.com/v1/health?ok=1', 443],
    'http 8080' => ['http://example.com:8080/health', 8080],
    'https 8443' => ['https://example.com:8443/health', 8443],
]);

test('SEC-12 rejects non-HTTP schemes, uppercase schemes, credentials, and URL parser differentials', function (string $url) {
    $client = makeZeroPacketClient();

    expect(fn () => $client->request($url))
        ->toThrow(BlockedDestinationException::class);
})->with([
    'file scheme' => 'file:///etc/passwd',
    'gopher scheme' => 'gopher://127.0.0.1:6379/_INFO',
    'ftp scheme' => 'ftp://example.com/pub',
    'dict scheme' => 'dict://127.0.0.1:6379/info',
    'php wrapper' => 'php://filter/resource=/etc/passwd',
    'uppercase HTTP' => 'HTTP://example.com/',
    'mixed-case Https' => 'Https://example.com/',
    'userinfo credentials' => 'https://user:pass@example.com/',
    'username differential' => 'http://public.example.com@127.0.0.1/',
    'empty userinfo' => 'http://:@example.com/',
    'backslash in authority' => 'http://evil.com\@127.0.0.1/',
    'percent encoded host' => 'http://%31%32%37.0.0.1/',
    'whitespace inside url' => 'http://example.com/ test',
    'leading zero port' => 'http://example.com:080/',
    'semicolon in authority' => 'http://example.com;127.0.0.1/',
    'comma in authority' => 'http://example.com,127.0.0.1/',
    'oversized url' => 'https://example.com/'.str_repeat('a', 2048),
]);

test('canonical redirect resolver handles scheme-relative, root-relative, relative segments, and blocks empty or backslash Location', function () {
    $validator = new CanonicalUrlValidator;
    $base = $validator->validate('https://example.com:8443/v1/status?check=1');

    expect($validator->resolveRedirectTarget($base, '/next?a=2'))
        ->toBe('https://example.com:8443/next?a=2');

    expect($validator->resolveRedirectTarget($base, '../health'))
        ->toBe('https://example.com:8443/health');

    expect($validator->resolveRedirectTarget($base, '//127.0.0.1/secret'))
        ->toBe('https://127.0.0.1/secret');

    expect($validator->resolveRedirectTarget($base, '?retry=true'))
        ->toBe('https://example.com:8443/v1/status?retry=true');

    expect(fn () => $validator->resolveRedirectTarget($base, '   '))
        ->toThrow(BlockedDestinationException::class);

    expect(fn () => $validator->resolveRedirectTarget($base, '\\\\127.0.0.1/secret'))
        ->toThrow(BlockedDestinationException::class);

    expect(fn () => $validator->resolveRedirectTarget($base, '/\\evil.example.test'))
        ->toThrow(BlockedDestinationException::class);

    expect(fn () => $validator->resolveRedirectTarget($base, '/'.str_repeat('a', 2050)))
        ->toThrow(BlockedDestinationException::class);
});
