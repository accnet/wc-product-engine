<?php

declare(strict_types=1);

namespace WPE\Support;

/**
 * Small SQL building helpers for values that $wpdb->prepare() cannot take directly.
 */
final class Sql
{
    /**
     * Comma-separated list of integer IDs for an `IN (...)` clause.
     *
     * Every value is cast to int, so the result is safe to interpolate whatever the caller passes.
     * An empty input yields "0", which matches no row instead of producing invalid SQL.
     *
     * @param iterable<mixed> $ids
     */
    public static function intList(iterable $ids): string
    {
        $ints = [];
        foreach ($ids as $id) {
            $ints[] = (int)$id;
        }
        return $ints === [] ? '0' : implode(',', $ints);
    }
}
