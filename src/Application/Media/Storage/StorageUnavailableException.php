<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

/**
 * The default storage cannot be used. Maps to the dependency error with reason storage_unavailable and is
 * retryable. Never answered by switching to another provider.
 */
final class StorageUnavailableException extends StorageException
{
    public function __construct(string $message, string $reason = 'storage_unavailable')
    {
        parent::__construct($message, $reason);
    }
}
