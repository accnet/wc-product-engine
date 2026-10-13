<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

use RuntimeException;

/**
 * A storage operation failed. The reason is a stable machine value; the message is safe to show to an
 * editor (credentials and signed values are removed).
 */
class StorageException extends RuntimeException
{
    public function __construct(string $message, private readonly string $reason = 'upload_failed')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
