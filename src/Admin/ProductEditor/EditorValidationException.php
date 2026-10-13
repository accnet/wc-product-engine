<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use InvalidArgumentException;

/**
 * A rule of the product editor rejected the submitted data (HTTP 422, error code `validation_failed`).
 *
 * It extends InvalidArgumentException so that callers which only know the older exception keep returning a client
 * error. `details` use the contract shape: a list of `{pointer, reason}` entries.
 */
final class EditorValidationException extends InvalidArgumentException
{
    /** @param array<int,array{pointer:string,reason:string}> $details */
    public function __construct(
        private readonly string $reason,
        string $message,
        private readonly array $details = [],
        private readonly array $context = []
    ) {
        parent::__construct($message, 422);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** @return array<int,array{pointer:string,reason:string}> */
    public function details(): array
    {
        return $this->details;
    }

    /** Extra non-secret data a client may need, for example the affected variations of a preview. */
    public function context(): array
    {
        return $this->context;
    }
}
