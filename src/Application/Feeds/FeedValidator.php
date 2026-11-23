<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * Item rules shared by Google and Microsoft (10 §12). Errors make an item ineligible (not exported);
 * warnings are reported but the item is exported; exclusions are items left out on purpose by the
 * feed's filters.
 */
final class FeedValidator
{
    public const ERRORS = [
        'missing_id', 'missing_title', 'missing_link', 'missing_image', 'missing_price', 'invalid_price',
        'missing_shell', 'unsupported_type', 'invalid_link', 'invalid_image',
    ];

    public const WARNINGS = [
        'missing_brand', 'missing_identifier', 'identifier_exists_no', 'missing_google_category',
        'title_truncated', 'description_truncated', 'description_from_title', 'id_fallback',
    ];

    public const EXCLUSIONS = [
        'excluded_status', 'excluded_visibility', 'excluded_category', 'excluded_out_of_stock',
        'excluded_no_image', 'excluded_variation_status',
    ];

    public const MAX_ID = 50;
    public const MAX_TITLE = 150;
    public const MAX_DESCRIPTION = 5000;
    public const MAX_URL = 2000;

    public static function severity(string $code): string
    {
        if (in_array($code, self::ERRORS, true)) {
            return 'error';
        }
        if (in_array($code, self::EXCLUSIONS, true)) {
            return 'excluded';
        }
        return 'warning';
    }

    /**
     * Validate a mapped payload; returns the issue codes it adds.
     *
     * @param array<string,mixed> $payload
     * @return string[]
     */
    public static function validate(array $payload): array
    {
        $issues = [];
        if (($payload['id'] ?? '') === '') {
            $issues[] = 'missing_id';
        }
        if (($payload['title'] ?? '') === '') {
            $issues[] = 'missing_title';
        }
        $link = (string)($payload['link'] ?? '');
        if ($link === '') {
            $issues[] = 'missing_link';
        } elseif (strlen($link) > self::MAX_URL || !preg_match('#^https?://#i', $link)) {
            $issues[] = 'invalid_link';
        }
        $image = (string)($payload['image_link'] ?? '');
        if ($image === '') {
            $issues[] = 'missing_image';
        } elseif (strlen($image) > self::MAX_URL) {
            $issues[] = 'invalid_image';
        }
        $price = (string)($payload['price'] ?? '');
        if ($price === '') {
            $issues[] = 'missing_price';
        } elseif (!preg_match('/^(\d+(?:\.\d+)?) [A-Z]{3}$/', $price, $m) || (float)$m[1] <= 0) {
            $issues[] = 'invalid_price';
        }
        if (($payload['brand'] ?? '') === '') {
            $issues[] = 'missing_brand';
        }
        if (($payload['google_product_category'] ?? '') === '' && ($payload['product_type'] ?? '') === '') {
            $issues[] = 'missing_google_category';
        }
        return $issues;
    }

    /** @param string[] $issues */
    public static function isEligible(array $issues): bool
    {
        foreach ($issues as $code) {
            if (self::severity($code) !== 'warning') {
                return false;
            }
        }
        return true;
    }
}
