<?php

declare(strict_types=1);

namespace WPE\Support;

/**
 * Keeps internal error details (SQL errors, file paths, class names) out of REST responses.
 *
 * Client errors carry messages written for the user and are returned as they are: an
 * InvalidArgumentException (or subclass), or a RuntimeException whose code is an HTTP 4xx status.
 * Any other exception is written to the PHP error log with a reference id, and the client
 * receives a generic message that quotes the same id.
 */
final class RestErrors
{
    public static function isClientError(\Throwable $e): bool
    {
        if ($e instanceof \InvalidArgumentException) {
            return true;
        }
        $code = (int)$e->getCode();
        return $e instanceof \RuntimeException && $code >= 400 && $code < 500;
    }

    public static function safeMessage(\Throwable $e, string $context, string $publicMessage): string
    {
        if (self::isClientError($e)) {
            return $e->getMessage();
        }

        $ref = substr(str_replace('-', '', wp_generate_uuid4()), 0, 12);
        error_log(sprintf(
            '[wc-product-engine] %s ref=%s %s: %s in %s:%d',
            $context,
            $ref,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        /* translators: 1: short description of the failure, 2: error reference id */
        return sprintf(__('%1$s See the server error log (ref %2$s).', 'wc-product-engine'), $publicMessage, $ref);
    }
}
