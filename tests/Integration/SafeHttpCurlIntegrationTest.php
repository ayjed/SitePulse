<?php

use App\Support\SafeHttp\BatchCheckTarget;
use App\Support\SafeHttp\SafeHttpFailureReason;
use Tests\Support\LoopbackSafeHttpFactory;
use Tests\Support\LoopbackTestServer;

test('executes concurrent HTTP probes via real curl_multi respecting maxConcurrency bounds', function () {
    $server = LoopbackTestServer::start();

    try {
        $client = LoopbackSafeHttpFactory::clientForServer($server);

        $targets = [];
        for ($i = 1; $i <= 6; $i++) {
            $targets[] = new BatchCheckTarget(
                key: $i,
                url: "http://slow-{$i}.example.test:{$server->httpPort}/slow?ms=260",
                timeoutSeconds: 10,
            );
        }

        $startedNs = hrtime(true);
        $outcomes = $client->checkBatch(
            targets: $targets,
            maxConcurrency: 3,
            softDeadlineSeconds: 10,
            hardDeadlineSeconds: 12,
        );
        $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;

        expect($outcomes)->toHaveCount(6);
        foreach ($outcomes as $outcome) {
            expect($outcome->cancelled)->toBeFalse()
                ->and($outcome->result)->not->toBeNull()
                ->and($outcome->result->succeeded)->toBeTrue()
                ->and($outcome->result->statusCode)->toBe(200)
                ->and($outcome->result->resolvedIp)->toBe('127.0.0.1');
        }

        $metrics = $server->metrics();
        expect($metrics['totalRequests'])->toBe(6)
            ->and($metrics['peakConcurrentSlow'])->toBeGreaterThanOrEqual(2)
            ->and($metrics['peakConcurrentSlow'])->toBeLessThanOrEqual(3);

        // 6 x 260ms sequential would exceed 1560ms; bounded 3-slot concurrency completes in ~2 waves.
        expect($elapsedMs)->toBeLessThan(1250);
    } finally {
        $server->stop();
    }
});

test('enforces CURLOPT_RESOLVE DNS pinning, verifies connected peer IP, and classifies DNS or connection failures cleanly', function () {
    $server = LoopbackTestServer::start();

    try {
        // Closed ephemeral port obtained safely then closed before probing.
        $tempSocket = stream_socket_server('tcp://127.0.0.1:0');
        $closedAddress = (string) stream_socket_get_name($tempSocket, false);
        fclose($tempSocket);
        $closedPort = (int) substr($closedAddress, strrpos($closedAddress, ':') + 1);

        $client = LoopbackSafeHttpFactory::clientForServer(
            server: $server,
            allowedLoopbackPorts: [...$server->ports(), $closedPort],
            dnsMap: [
                'pinned.example.test' => ['127.0.0.1'],
                'empty-dns.example.test' => [],
                'rebind.example.test' => ['169.254.169.254'],
            ],
        );

        $outcomes = $client->checkBatch([
            new BatchCheckTarget(1, "http://pinned.example.test:{$server->httpPort}/ok"),
            new BatchCheckTarget(2, "http://empty-dns.example.test:{$server->httpPort}/ok"),
            new BatchCheckTarget(3, "http://rebind.example.test:{$server->httpPort}/ok"),
            new BatchCheckTarget(4, "http://pinned.example.test:{$closedPort}/ok", timeoutSeconds: 5),
        ], maxConcurrency: 4);

        expect($outcomes[0]->result->succeeded)->toBeTrue()
            ->and($outcomes[0]->result->resolvedIp)->toBe('127.0.0.1')
            ->and($outcomes[1]->result->succeeded)->toBeFalse()
            ->and($outcomes[1]->result->failureReason)->toBe(SafeHttpFailureReason::DNS_FAILURE)
            ->and($outcomes[2]->result->succeeded)->toBeFalse()
            ->and($outcomes[2]->result->failureReason)->toBe(SafeHttpFailureReason::SSRF_BLOCKED)
            ->and($outcomes[3]->result->succeeded)->toBeFalse()
            ->and($outcomes[3]->result->failureReason)->toBe(SafeHttpFailureReason::CONNECTION_REFUSED);
    } finally {
        $server->stop();
    }
});

test('verifies HTTPS TLS certificates via test CA, extracts expiry metadata, and rejects untrusted or hostname-mismatched peers', function () {
    $server = LoopbackTestServer::start();

    try {
        $caClient = LoopbackSafeHttpFactory::clientForServer($server, useServerCa: true);
        $systemCaClient = LoopbackSafeHttpFactory::clientForServer($server, useServerCa: false);

        $outcomes = $caClient->checkBatch([
            new BatchCheckTarget(1, "https://tls.example.test:{$server->httpsValidPort}/ok"),
            new BatchCheckTarget(2, "https://expiring.example.test:{$server->httpsExpiringPort}/ok"),
            new BatchCheckTarget(3, "https://badhost.example.test:{$server->httpsMismatchPort}/ok"),
            new BatchCheckTarget(4, "https://rogue.example.test:{$server->httpsUntrustedPort}/ok"),
        ], maxConcurrency: 4);

        expect($outcomes[0]->result->succeeded)->toBeTrue()
            ->and($outcomes[0]->result->tlsExpiresAt)->not->toBeNull()
            ->and($outcomes[0]->result->tlsExpiringSoon)->toBeFalse()
            ->and((string) $outcomes[0]->result->tlsIssuer)->toContain('SitePulse Test CA')
            ->and($outcomes[1]->result->succeeded)->toBeTrue()
            ->and($outcomes[1]->result->tlsExpiresAt)->not->toBeNull()
            ->and($outcomes[1]->result->tlsExpiringSoon)->toBeTrue()
            ->and($outcomes[2]->result->succeeded)->toBeFalse()
            ->and($outcomes[2]->result->failureReason)->toBe(SafeHttpFailureReason::TLS_ERROR)
            ->and($outcomes[3]->result->succeeded)->toBeFalse()
            ->and($outcomes[3]->result->failureReason)->toBe(SafeHttpFailureReason::TLS_ERROR);

        // Verify stock CurlHttpTransport (no custom CA) strictly rejects self-signed/private CA certs too.
        $prodReject = $systemCaClient->check("https://tls.example.test:{$server->httpsValidPort}/ok");
        expect($prodReject->succeeded)->toBeFalse()
            ->and($prodReject->failureReason)->toBe(SafeHttpFailureReason::TLS_ERROR);
    } finally {
        $server->stop();
    }
});

test('handles manual redirects, redirect loops, hop caps, SSRF redirect targets, status ranges, and 500 KB body caps under curl_multi', function () {
    $server = LoopbackTestServer::start();

    try {
        $client = LoopbackSafeHttpFactory::clientForServer($server);

        $outcomes = $client->checkBatch([
            new BatchCheckTarget(1, "http://redir.example.test:{$server->httpPort}/redirect/1"),
            new BatchCheckTarget(2, "http://toomany.example.test:{$server->httpPort}/redirect-too-many/1"),
            new BatchCheckTarget(3, "http://loop.example.test:{$server->httpPort}/redirect-loop"),
            new BatchCheckTarget(4, "http://ssrf.example.test:{$server->httpPort}/redirect-ssrf"),
            new BatchCheckTarget(5, "http://large.example.test:{$server->httpPort}/large"),
            new BatchCheckTarget(6, "http://err.example.test:{$server->httpPort}/status/503"),
        ], maxConcurrency: 5);

        expect($outcomes[0]->result->succeeded)->toBeTrue()
            ->and($outcomes[0]->result->redirectsFollowed)->toBe(2)
            ->and($outcomes[0]->result->statusCode)->toBe(200)
            ->and($outcomes[1]->result->succeeded)->toBeFalse()
            ->and($outcomes[1]->result->failureReason)->toBe(SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED)
            ->and($outcomes[2]->result->succeeded)->toBeFalse()
            ->and($outcomes[2]->result->failureReason)->toBe(SafeHttpFailureReason::MAX_REDIRECTS_EXCEEDED)
            ->and($outcomes[3]->result->succeeded)->toBeFalse()
            ->and($outcomes[3]->result->failureReason)->toBe(SafeHttpFailureReason::SSRF_BLOCKED)
            ->and($outcomes[4]->result->succeeded)->toBeTrue()
            ->and($outcomes[4]->result->bodyTruncated)->toBeTrue()
            ->and($outcomes[4]->result->bytesReceived)->toBe(512000)
            ->and($outcomes[5]->result->succeeded)->toBeFalse()
            ->and($outcomes[5]->result->failureReason)->toBe(SafeHttpFailureReason::HTTP_ERROR)
            ->and($outcomes[5]->result->statusCode)->toBe(503);
    } finally {
        $server->stop();
    }
});

test('enforces per-target timeout cleanly and cancels active curl_multi handles at hard deadline without false failure marks', function () {
    $server = LoopbackTestServer::start();

    try {
        $client = LoopbackSafeHttpFactory::clientForServer($server);

        // 1) Genuine per-monitor timeout (2s timeout vs 3s slow response) inside generous batch deadline.
        $timeoutOutcomes = $client->checkBatch([
            new BatchCheckTarget(1, "http://fast.example.test:{$server->httpPort}/ok", timeoutSeconds: 5),
            new BatchCheckTarget(2, "http://timeout.example.test:{$server->httpPort}/slow?ms=3200", timeoutSeconds: 2),
        ], maxConcurrency: 2, softDeadlineSeconds: 5, hardDeadlineSeconds: 8);

        expect($timeoutOutcomes[0]->cancelled)->toBeFalse()
            ->and($timeoutOutcomes[0]->result->succeeded)->toBeTrue()
            ->and($timeoutOutcomes[1]->cancelled)->toBeFalse()
            ->and($timeoutOutcomes[1]->result->succeeded)->toBeFalse()
            ->and($timeoutOutcomes[1]->result->failureReason)->toBe(SafeHttpFailureReason::TIMEOUT);

        // 2) Batch hard deadline interrupts hanging sockets cleanly via curl_multi_remove_handle + curl_close.
        $startedNs = hrtime(true);
        $cancelOutcomes = $client->checkBatch([
            new BatchCheckTarget(10, "http://fast.example.test:{$server->httpPort}/ok", timeoutSeconds: 10),
            new BatchCheckTarget(11, "http://hang1.example.test:{$server->httpPort}/hang", timeoutSeconds: 10),
            new BatchCheckTarget(12, "http://hang2.example.test:{$server->httpPort}/hang", timeoutSeconds: 10),
            new BatchCheckTarget(13, "http://deferred.example.test:{$server->httpPort}/ok", timeoutSeconds: 10),
        ], maxConcurrency: 2, softDeadlineSeconds: 0.35, hardDeadlineSeconds: 0.6);
        $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;

        expect($elapsedMs)->toBeLessThan(1500)
            ->and($cancelOutcomes[0]->cancelled)->toBeFalse()
            ->and($cancelOutcomes[0]->result->succeeded)->toBeTrue()
            ->and($cancelOutcomes[1]->cancelled)->toBeTrue()
            ->and($cancelOutcomes[1]->cancellationReason)->toBe('hard_deadline')
            ->and($cancelOutcomes[1]->result)->toBeNull()
            ->and($cancelOutcomes[2]->cancelled)->toBeTrue()
            ->and($cancelOutcomes[2]->cancellationReason)->toBe('hard_deadline')
            ->and($cancelOutcomes[3]->cancelled)->toBeTrue()
            ->and($cancelOutcomes[3]->cancellationReason)->toBe('soft_deadline');
    } finally {
        $server->stop();
    }
});
