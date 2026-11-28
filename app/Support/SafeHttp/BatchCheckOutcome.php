<?php

namespace App\Support\SafeHttp;

/**
 * Per-target completion or deadline-cancellation outcome produced by SafeHttpClient::checkBatch().
 */
final readonly class BatchCheckOutcome
{
    public function __construct(
        public BatchCheckTarget $target,
        public ?SafeHttpCheckResult $result,
        public bool $cancelled,
        public ?string $cancellationReason = null,
    ) {}
}
