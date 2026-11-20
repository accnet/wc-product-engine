<?php

declare(strict_types=1);

namespace WPE\Support;

/**
 * Plugin capability names and the access rules shared by several screens and REST routes.
 */
final class Capabilities
{
    public const IMPORT_PRODUCTS = 'wpe_import_products';
    public const EXPORT_PRODUCTS = 'wpe_export_products';
    public const MANAGE_OPERATIONS = 'wpe_manage_operations';

    /** Capabilities granted to the administrator role. */
    public const ALL = [
        self::IMPORT_PRODUCTS,
        self::EXPORT_PRODUCTS,
        self::MANAGE_OPERATIONS,
    ];

    /**
     * Read-only operations views (health report, status): the dedicated capability, or a
     * WooCommerce shop manager.
     */
    public static function canViewOperations(): bool
    {
        return current_user_can(self::MANAGE_OPERATIONS) || current_user_can('manage_woocommerce');
    }

    /**
     * Mutating rollout actions (backfill, rebuild, audit, rollback, mode, outbox, gates, cutover,
     * hygiene, export, resume): the dedicated capability only, granted to administrators.
     * Shop managers are not trusted with authority changes (audit SEC-02).
     */
    public static function canRunOperations(): bool
    {
        return current_user_can(self::MANAGE_OPERATIONS);
    }

    /**
     * Same check for a given user, for actors passed explicitly (REST dispatcher, workers).
     */
    public static function userCanRunOperations(int $userId): bool
    {
        return $userId > 0 && user_can($userId, self::MANAGE_OPERATIONS);
    }

    /**
     * @deprecated 1.1.1 Use canViewOperations() for read access or canRunOperations() for actions.
     */
    public static function canManageOperations(): bool
    {
        _deprecated_function(__METHOD__, '1.1.1', __CLASS__ . '::canViewOperations() or ::canRunOperations()');
        return self::canViewOperations();
    }
}
