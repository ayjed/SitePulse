<?php

namespace App\Support\SafeHttp;

use InvalidArgumentException;

/**
 * Buffers streamed HTTP response bodies up to a strict byte limit before aborting transfer.
 */
class BoundedBodyBuffer
{
    private string $buffer = '';

    private int $storedBytes = 0;

    private int $totalBytesSeen = 0;

    private bool $truncated = false;

    public function __construct(
        public readonly int $maxBytes = 512000,
    ) {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum body buffer size must be non-negative.');
        }
    }

    /**
     * Accept a streamed body chunk and return bytes consumed (0 signals libcurl to abort).
     */
    public function append(string $chunk): int
    {
        $chunkLength = strlen($chunk);
        $this->totalBytesSeen += $chunkLength;

        if ($chunkLength === 0) {
            return 0;
        }

        if ($this->truncated) {
            return 0;
        }

        $remaining = $this->maxBytes - $this->storedBytes;

        if ($chunkLength > $remaining) {
            if ($remaining > 0) {
                $this->buffer .= substr($chunk, 0, $remaining);
                $this->storedBytes += $remaining;
            }

            $this->truncated = true;

            return 0;
        }

        $this->buffer .= $chunk;
        $this->storedBytes += $chunkLength;

        return $chunkLength;
    }

    public function contents(): string
    {
        return $this->buffer;
    }

    public function storedBytes(): int
    {
        return $this->storedBytes;
    }

    public function totalBytesSeen(): int
    {
        return $this->totalBytesSeen;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}
