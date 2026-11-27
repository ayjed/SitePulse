<?php

namespace App\Support\SafeHttp;

/**
 * Target specification passed into SafeHttpClient::checkBatch().
 */
final readonly class BatchCheckTarget
{
    public function __construct(
        public int|string $key,
        public string $url,
        public int $timeoutSeconds = SafeHttpClient::DEFAULT_TIMEOUT_SECONDS,
        public int $expectedStatusMin = 200,
        public int $expectedStatusMax = 299,
        public string $method = 'GET',
        public mixed $context = null,
    ) {}
}
