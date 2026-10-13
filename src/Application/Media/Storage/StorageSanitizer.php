<?php

declare(strict_types=1);

namespace WPE\Application\Media\Storage;

/**
 * Removes anything that looks like a credential or a signed value from provider messages before they are
 * stored, logged or shown.
 */
final class StorageSanitizer
{
    public static function message(?string $message): string
    {
        $text = wp_strip_all_tags((string) $message);
        $text = preg_replace('/\b(AKIA|ASIA)[0-9A-Z]{16}\b/', '[redacted-key]', $text) ?? $text;
        $text = preg_replace('/\b[A-Za-z0-9\/+=]{40}\b/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/(X-Amz-[A-Za-z-]+|Signature|Credential)=[^&\s]+/i', '$1=[redacted]', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_substr($text, 0, 300);
    }
}
