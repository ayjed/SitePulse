<?php

namespace Tests\Support;

use App\Exceptions\BlockedDestinationException;
use App\Support\SafeHttp\CanonicalUrlValidator;
use App\Support\SafeHttp\Contracts\DnsResolverInterface;
use App\Support\SafeHttp\CurlHttpTransport;
use App\Support\SafeHttp\IpAddressPolicy;
use App\Support\SafeHttp\SafeHttpClient;
use Closure;

/**
 * Constructs test-only SafeHttpClient instances scoped strictly to isolated ephemeral loopback ports and test CA.
 * Production code in app/ contains no loopback or certificate bypasses.
 */
final class LoopbackSafeHttpFactory
{
    /**
     * @param  array<int, int>  $allowedLoopbackPorts
     * @param  array<string, array<int, string>>|null  $dnsMap
     * @param  (Closure(): int)|null  $monotonicClockNs
     */
    public static function clientForServer(
        LoopbackTestServer $server,
        array $allowedLoopbackPorts = [],
        ?array $dnsMap = null,
        bool $useServerCa = true,
        ?Closure $monotonicClockNs = null,
    ): SafeHttpClient {
        $ports = $allowedLoopbackPorts === [] ? $server->ports() : $allowedLoopbackPorts;

        $ipPolicy = new class extends IpAddressPolicy
        {
            public function assertAllowed(string $ip): string
            {
                $normalized = $this->normalizeIp($ip);

                if ($normalized === '127.0.0.1') {
                    return $normalized;
                }

                return parent::assertAllowed($ip);
            }
        };

        $urlValidator = new class($ipPolicy, $ports) extends CanonicalUrlValidator
        {
            /**
             * @param  array<int, int>  $loopbackPorts
             */
            public function __construct(
                IpAddressPolicy $ipPolicy,
                private readonly array $loopbackPorts,
            ) {
                parent::__construct($ipPolicy);
            }

            protected function assertPortAllowed(int $port): void
            {
                if (in_array($port, $this->loopbackPorts, true) && $port > 1024) {
                    return;
                }

                parent::assertPortAllowed($port);
            }
        };

        $dnsResolver = new class($dnsMap ?? ['example.test' => ['127.0.0.1']]) implements DnsResolverInterface
        {
            /**
             * @param  array<string, array<int, string>>  $map
             */
            public function __construct(private readonly array $map) {}

            public function resolve(string $hostname): array
            {
                $lower = strtolower($hostname);
                if (isset($this->map[$lower])) {
                    return $this->map[$lower];
                }

                if (str_ends_with($lower, '.example.test')) {
                    return ['127.0.0.1'];
                }

                throw new BlockedDestinationException("Unexpected DNS lookup for non-test hostname [{$hostname}].");
            }
        };

        $caCertPath = $server->caCertPath;
        $transport = $useServerCa
            ? new class($ipPolicy, $caCertPath) extends CurlHttpTransport
            {
                public function __construct(
                    IpAddressPolicy $ipPolicy,
                    private readonly string $testCaPath,
                ) {
                    parent::__construct($ipPolicy);
                }

                protected function caInfoPath(): ?string
                {
                    return $this->testCaPath;
                }
            }
        : new CurlHttpTransport($ipPolicy);

        return new SafeHttpClient(
            urlValidator: $urlValidator,
            ipPolicy: $ipPolicy,
            dnsResolver: $dnsResolver,
            transport: $transport,
            monotonicClockNs: $monotonicClockNs,
        );
    }
}
