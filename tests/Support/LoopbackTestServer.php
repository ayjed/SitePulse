<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Isolated non-blocking HTTP/HTTPS loopback server running in a child PHP process for real cURL integration tests.
 */
final class LoopbackTestServer
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private function __construct(
        private readonly string $workDir,
        public readonly string $caCertPath,
        public readonly int $httpPort,
        public readonly int $httpsValidPort,
        public readonly int $httpsExpiringPort,
        public readonly int $httpsMismatchPort,
        public readonly int $httpsUntrustedPort,
        private readonly string $metricsPath,
        private readonly string $stopPath,
    ) {}

    public static function start(): self
    {
        $workDir = sys_get_temp_dir().'/sitepulse_loopback_'.bin2hex(random_bytes(6));
        if (! mkdir($workDir, 0777, true) && ! is_dir($workDir)) {
            throw new RuntimeException("Unable to create temporary loopback test directory [{$workDir}].");
        }

        $opensslCnf = $workDir.'/openssl.cnf';
        file_put_contents($opensslCnf, <<<'CNF'
[req]
default_bits = 2048
distinguished_name = dn

[dn]

[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign

[v3_leaf_valid]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:*.example.test,DNS:example.test

[v3_leaf_mismatch]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = DNS:wrong-host.example.test
CNF);

        [$caCertPath] = self::generateCertificates($workDir, $opensslCnf);

        $serverScriptPath = $workDir.'/server.php';
        $metricsPath = $workDir.'/metrics.json';
        $readyPath = $workDir.'/ready.json';
        $stopPath = $workDir.'/stop.flag';

        file_put_contents($serverScriptPath, self::serverWorkerScript());

        $configPath = $workDir.'/config.json';
        file_put_contents($configPath, json_encode([
            'readyPath' => $readyPath,
            'metricsPath' => $metricsPath,
            'stopPath' => $stopPath,
            'caCertPath' => $caCertPath,
            'certs' => [
                'valid' => $workDir.'/leaf-valid.pem',
                'expiring' => $workDir.'/leaf-expiring.pem',
                'mismatch' => $workDir.'/leaf-mismatch.pem',
                'untrusted' => $workDir.'/leaf-untrusted.pem',
            ],
        ], JSON_THROW_ON_ERROR));

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = [PHP_BINARY, $serverScriptPath, $configPath];
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            self::removeDirectory($workDir);
            throw new RuntimeException('Unable to launch loopback test server child process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = hrtime(true) + 5_000_000_000;
        $ready = null;

        while (hrtime(true) < $deadline) {
            if (is_file($readyPath)) {
                $raw = file_get_contents($readyPath);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && isset($decoded['httpPort'])) {
                        $ready = $decoded;
                        break;
                    }
                }
            }

            $status = proc_get_status($process);
            if (! $status['running']) {
                $stderr = stream_get_contents($pipes[2]);
                proc_close($process);
                self::removeDirectory($workDir);
                throw new RuntimeException('Loopback server exited unexpectedly: '.trim((string) $stderr));
            }

            usleep(10_000);
        }

        if ($ready === null) {
            proc_terminate($process);
            proc_close($process);
            self::removeDirectory($workDir);
            throw new RuntimeException('Timed out waiting for loopback test server to listen.');
        }

        $instance = new self(
            workDir: $workDir,
            caCertPath: $caCertPath,
            httpPort: (int) $ready['httpPort'],
            httpsValidPort: (int) $ready['httpsValidPort'],
            httpsExpiringPort: (int) $ready['httpsExpiringPort'],
            httpsMismatchPort: (int) $ready['httpsMismatchPort'],
            httpsUntrustedPort: (int) $ready['httpsUntrustedPort'],
            metricsPath: $metricsPath,
            stopPath: $stopPath,
        );
        $instance->process = $process;
        $instance->pipes = $pipes;

        return $instance;
    }

    /**
     * @return array<int, int>
     */
    public function ports(): array
    {
        return [
            $this->httpPort,
            $this->httpsValidPort,
            $this->httpsExpiringPort,
            $this->httpsMismatchPort,
            $this->httpsUntrustedPort,
        ];
    }

    /**
     * @return array{totalRequests: int, peakConcurrentSlow: int, requests: array<int, array<string, mixed>>}
     */
    public function metrics(): array
    {
        if (! is_file($this->metricsPath)) {
            return ['totalRequests' => 0, 'peakConcurrentSlow' => 0, 'requests' => []];
        }

        $decoded = json_decode((string) file_get_contents($this->metricsPath), true);

        return is_array($decoded) ? $decoded : ['totalRequests' => 0, 'peakConcurrentSlow' => 0, 'requests' => []];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            @file_put_contents($this->stopPath, 'stop');
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            $this->pipes = [];

            $deadline = hrtime(true) + 1_500_000_000;
            while (hrtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if (! $status['running']) {
                    break;
                }
                usleep(10_000);
            }

            $status = proc_get_status($this->process);
            if ($status['running']) {
                @proc_terminate($this->process);
            }
            @proc_close($this->process);
            $this->process = null;
        }

        self::removeDirectory($this->workDir);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @return array{0: string}
     */
    private static function generateCertificates(string $workDir, string $opensslCnf): array
    {
        $baseCfg = [
            'config' => $opensslCnf,
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ];

        $caKey = openssl_pkey_new($baseCfg);
        $caCsr = openssl_csr_new(['commonName' => 'SitePulse Test CA'], $caKey, $baseCfg);
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 365, $baseCfg + ['x509_extensions' => 'v3_ca'], 1);
        if ($caKey === false || $caCert === false) {
            throw new RuntimeException('Failed to generate loopback test CA certificate.');
        }

        openssl_x509_export($caCert, $caPem);
        $caCertPath = $workDir.'/ca.pem';
        file_put_contents($caCertPath, $caPem);

        self::writeLeafBundle($workDir.'/leaf-valid.pem', 'tls.example.test', $caCert, $caKey, $caPem, 90, $baseCfg + ['x509_extensions' => 'v3_leaf_valid']);
        self::writeLeafBundle($workDir.'/leaf-expiring.pem', 'expiring.example.test', $caCert, $caKey, $caPem, 5, $baseCfg + ['x509_extensions' => 'v3_leaf_valid']);
        self::writeLeafBundle($workDir.'/leaf-mismatch.pem', 'wrong-host.example.test', $caCert, $caKey, $caPem, 90, $baseCfg + ['x509_extensions' => 'v3_leaf_mismatch']);

        // Rogue untrusted self-signed certificate.
        $rogueKey = openssl_pkey_new($baseCfg);
        $rogueCsr = openssl_csr_new(['commonName' => 'untrusted.example.test'], $rogueKey, $baseCfg);
        $rogueCert = openssl_csr_sign($rogueCsr, null, $rogueKey, 90, $baseCfg + ['x509_extensions' => 'v3_leaf_valid'], 2);
        if ($rogueKey === false || $rogueCert === false) {
            throw new RuntimeException('Failed to generate untrusted test certificate.');
        }
        openssl_x509_export($rogueCert, $roguePem);
        openssl_pkey_export($rogueKey, $rogueKeyPem, null, $baseCfg);
        file_put_contents($workDir.'/leaf-untrusted.pem', $roguePem.$rogueKeyPem);

        return [$caCertPath];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private static function writeLeafBundle(
        string $path,
        string $commonName,
        mixed $caCert,
        mixed $caKey,
        string $caPem,
        int $days,
        array $cfg,
    ): void {
        $key = openssl_pkey_new($cfg);
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $cfg);
        $cert = openssl_csr_sign($csr, $caCert, $caKey, $days, $cfg, random_int(1000, 999999));

        if ($key === false || $cert === false) {
            throw new RuntimeException("Failed to sign leaf certificate [{$commonName}].");
        }

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $cfg);
        file_put_contents($path, $certPem.$caPem.$keyPem);
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if (is_array($files)) {
            foreach ($files as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $target = $dir.'/'.$item;
                if (is_dir($target)) {
                    self::removeDirectory($target);
                } else {
                    @unlink($target);
                }
            }
        }

        @rmdir($dir);
    }

    private static function serverWorkerScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

$config = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$metricsPath = $config['metricsPath'];
$readyPath = $config['readyPath'];
$stopPath = $config['stopPath'];

function createTcpListener(): mixed {
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    if (! is_resource($sock)) {
        fwrite(STDERR, "Listen failed: {$errstr}\n");
        exit(1);
    }
    stream_set_blocking($sock, false);
    return $sock;
}

function createTlsListener(string $pemPath, string $caCertPath): mixed {
    $ctx = stream_context_create([
        'ssl' => [
            'local_cert' => $pemPath,
            'cafile' => $caCertPath,
            'verify_peer' => false,
            'allow_self_signed' => true,
        ],
    ]);
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
    if (! is_resource($sock)) {
        fwrite(STDERR, "TLS listen failed ({$pemPath}): {$errstr}\n");
        exit(1);
    }
    stream_set_blocking($sock, false);
    return $sock;
}

function listenerPort(mixed $sock): int {
    $name = (string) stream_socket_get_name($sock, false);
    return (int) substr($name, strrpos($name, ':') + 1);
}

$caCertPath = $config['caCertPath'];
$listeners = [
    'http' => ['sock' => createTcpListener(), 'tls' => false],
    'httpsValid' => ['sock' => createTlsListener($config['certs']['valid'], $caCertPath), 'tls' => true],
    'httpsExpiring' => ['sock' => createTlsListener($config['certs']['expiring'], $caCertPath), 'tls' => true],
    'httpsMismatch' => ['sock' => createTlsListener($config['certs']['mismatch'], $caCertPath), 'tls' => true],
    'httpsUntrusted' => ['sock' => createTlsListener($config['certs']['untrusted'], $caCertPath), 'tls' => true],
];

$metrics = [
    'totalRequests' => 0,
    'peakConcurrentSlow' => 0,
    'requests' => [],
];

function flushMetrics(string $path, array $metrics): void {
    file_put_contents($path, json_encode($metrics));
}

flushMetrics($metricsPath, $metrics);
file_put_contents($readyPath, json_encode([
    'httpPort' => listenerPort($listeners['http']['sock']),
    'httpsValidPort' => listenerPort($listeners['httpsValid']['sock']),
    'httpsExpiringPort' => listenerPort($listeners['httpsExpiring']['sock']),
    'httpsMismatchPort' => listenerPort($listeners['httpsMismatch']['sock']),
    'httpsUntrustedPort' => listenerPort($listeners['httpsUntrusted']['sock']),
], JSON_THROW_ON_ERROR));

$clients = [];
$activeSlow = 0;

function closeClient(int $id, array &$clients, int &$activeSlow): void {
    if (! isset($clients[$id])) {
        return;
    }
    if ($clients[$id]['isSlow']) {
        $activeSlow = max(0, $activeSlow - 1);
    }
    @fclose($clients[$id]['sock']);
    unset($clients[$id]);
}

function buildRouteResponse(string $method, string $target, int &$delayMs, bool &$isSlow, bool &$hang): string {
    $parts = parse_url($target);
    $path = $parts['path'] ?? '/';
    parse_str($parts['query'] ?? '', $query);

    if ($path === '/hang') {
        $hang = true;
        return '';
    }

    if ($path === '/slow') {
        $delayMs = max(1, (int) ($query['ms'] ?? 300));
        $isSlow = true;
        return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 4\r\nConnection: close\r\n\r\nslow";
    }

    if ($path === '/large') {
        $body = str_repeat('A', 600_000);
        return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    }

    if ($path === '/redirect/1') {
        return "HTTP/1.1 302 Found\r\nLocation: /redirect/2\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }

    if ($path === '/redirect/2') {
        return "HTTP/1.1 302 Found\r\nLocation: /ok\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }

    if ($path === '/redirect-too-many/1') {
        return "HTTP/1.1 302 Found\r\nLocation: /redirect-too-many/2\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }
    if ($path === '/redirect-too-many/2') {
        return "HTTP/1.1 302 Found\r\nLocation: /redirect-too-many/3\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }
    if ($path === '/redirect-too-many/3') {
        return "HTTP/1.1 302 Found\r\nLocation: /redirect-too-many/4\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }
    if ($path === '/redirect-too-many/4') {
        return "HTTP/1.1 302 Found\r\nLocation: /ok\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }

    if ($path === '/redirect-loop') {
        return "HTTP/1.1 302 Found\r\nLocation: /redirect-loop\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }

    if ($path === '/redirect-ssrf') {
        return "HTTP/1.1 302 Found\r\nLocation: http://169.254.169.254/latest/meta-data/\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
    }

    if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
        $code = (int) $m[1];
        $body = "status {$code}";
        return "HTTP/1.1 {$code} Status\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . ($method === 'HEAD' ? '' : $body);
    }

    $body = 'ok';
    return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . ($method === 'HEAD' ? '' : $body);
}

while (true) {
    clearstatcache(true, $stopPath);
    if (is_file($stopPath)) {
        break;
    }

    $nowNs = hrtime(true);

    $read = [];
    $write = [];
    $except = null;

    foreach ($listeners as $meta) {
        $read[] = $meta['sock'];
    }

    foreach ($clients as $id => $client) {
        if ($client['stage'] === 'tls_handshake' || $client['stage'] === 'read_request') {
            $read[] = $client['sock'];
        } elseif ($client['stage'] === 'delay_wait' && $nowNs >= $client['sendAtNs']) {
            $clients[$id]['stage'] = 'write_response';
            $write[] = $client['sock'];
        } elseif ($client['stage'] === 'write_response') {
            $write[] = $client['sock'];
        } elseif ($client['stage'] === 'hang') {
            $read[] = $client['sock'];
        }
    }

    if ($read === [] && $write === []) {
        usleep(5000);
        continue;
    }

    @stream_select($read, $write, $except, 0, 10_000);
    $nowNs = hrtime(true);

    foreach ($listeners as $listenerMeta) {
        if (in_array($listenerMeta['sock'], $read, true)) {
            while (($conn = @stream_socket_accept($listenerMeta['sock'], 0)) !== false) {
                stream_set_blocking($conn, false);
                $id = (int) $conn;
                $clients[$id] = [
                    'sock' => $conn,
                    'stage' => $listenerMeta['tls'] ? 'tls_handshake' : 'read_request',
                    'buffer' => '',
                    'out' => '',
                    'outOffset' => 0,
                    'sendAtNs' => 0,
                    'isSlow' => false,
                ];
            }
        }
    }

    foreach ($clients as $id => $client) {
        if ($client['stage'] === 'delay_wait' && $nowNs >= $client['sendAtNs']) {
            $clients[$id]['stage'] = 'write_response';
            if (! in_array($client['sock'], $write, true)) {
                $write[] = $client['sock'];
            }
        }

        if ($client['stage'] === 'tls_handshake') {
            $crypto = @stream_socket_enable_crypto($client['sock'], true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER);
            if ($crypto === true) {
                $clients[$id]['stage'] = 'read_request';
            } elseif ($crypto === false) {
                closeClient($id, $clients, $activeSlow);
                continue;
            }
        }

        if (isset($clients[$id]) && $clients[$id]['stage'] === 'hang' && in_array($client['sock'], $read, true)) {
            $peek = @fread($client['sock'], 1);
            if ($peek === '' || $peek === false) {
                if (feof($client['sock'])) {
                    closeClient($id, $clients, $activeSlow);
                }
            }
            continue;
        }

        if (isset($clients[$id]) && $clients[$id]['stage'] === 'read_request' && in_array($client['sock'], $read, true)) {
            $chunk = @fread($client['sock'], 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($client['sock'])) {
                    closeClient($id, $clients, $activeSlow);
                }
                continue;
            }

            $clients[$id]['buffer'] .= $chunk;
            $headerEnd = strpos($clients[$id]['buffer'], "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }

            $rawHead = substr($clients[$id]['buffer'], 0, $headerEnd);
            $lines = explode("\r\n", $rawHead);
            $requestLine = explode(' ', $lines[0] ?? 'GET / HTTP/1.1');
            $method = strtoupper($requestLine[0] ?? 'GET');
            $target = $requestLine[1] ?? '/';

            $headers = [];
            foreach (array_slice($lines, 1) as $line) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
            }

            $metrics['totalRequests']++;
            $metrics['requests'][] = [
                'method' => $method,
                'target' => $target,
                'host' => $headers['host'] ?? null,
            ];

            $delayMs = 0;
            $isSlow = false;
            $hang = false;
            $response = buildRouteResponse($method, $target, $delayMs, $isSlow, $hang);

            if ($isSlow) {
                $activeSlow++;
                if ($activeSlow > $metrics['peakConcurrentSlow']) {
                    $metrics['peakConcurrentSlow'] = $activeSlow;
                }
            }
            flushMetrics($metricsPath, $metrics);

            if ($hang) {
                $clients[$id]['stage'] = 'hang';
                continue;
            }

            $clients[$id]['isSlow'] = $isSlow;
            $clients[$id]['out'] = $response;
            $clients[$id]['outOffset'] = 0;

            if ($delayMs > 0) {
                $clients[$id]['sendAtNs'] = hrtime(true) + ($delayMs * 1_000_000);
                $clients[$id]['stage'] = 'delay_wait';
            } else {
                $clients[$id]['stage'] = 'write_response';
            }
        }

        if (isset($clients[$id]) && $clients[$id]['stage'] === 'write_response' && in_array($client['sock'], $write, true)) {
            $remaining = substr($clients[$id]['out'], $clients[$id]['outOffset']);
            if ($remaining === '') {
                closeClient($id, $clients, $activeSlow);
                continue;
            }

            $written = @fwrite($client['sock'], substr($remaining, 0, 16384));
            if ($written === false || $written === 0) {
                closeClient($id, $clients, $activeSlow);
                continue;
            }

            $clients[$id]['outOffset'] += $written;
            if ($clients[$id]['outOffset'] >= strlen($clients[$id]['out'])) {
                closeClient($id, $clients, $activeSlow);
            }
        }
    }
}

foreach ($clients as $client) {
    @fclose($client['sock']);
}
foreach ($listeners as $listenerMeta) {
    @fclose($listenerMeta['sock']);
}
PHP;
    }
}
