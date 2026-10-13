<?php

declare(strict_types=1);

namespace WPE\Application\Media;

use RuntimeException;

/**
 * A media request was refused by a rule (permission, file type, size, membership, key reuse).
 * The reason is the stable machine value that goes into the response details; status follows the registered
 * error codes of docs/04.
 */
final class UploadRejectedException extends RuntimeException
{
    /** @param array<int,array<string,mixed>> $details */
    public function __construct(
        private readonly string $reason,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly bool $retryable = false,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /** @return array<int,array<string,mixed>> */
    public function details(): array
    {
        return $this->details;
    }
}
