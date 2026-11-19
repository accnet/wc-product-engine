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
     * Operations screens and routes: the dedicated capability, or a WooCommerce shop manager.
     */
    public static function canManageOperations(): bool
    {
        return current_user_can(self::MANAGE_OPERATIONS) || current_user_can('manage_woocommerce');
    }
}
