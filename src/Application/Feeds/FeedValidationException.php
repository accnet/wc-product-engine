<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

use InvalidArgumentException;

/**
 * A feed definition was rejected (HTTP 422, error code `validation_failed`).
 *
 * `details` use the contract shape: a list of `{pointer, reason}` entries, one per invalid field.
 */
final class FeedValidationException extends InvalidArgumentException
{
    /** @param array<int,array{pointer:string,reason:string}> $details */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message, 422);
    }

    /** @return array<int,array{pointer:string,reason:string}> */
    public function details(): array
    {
        return $this->details;
    }
}
