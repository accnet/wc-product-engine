<?php
/**
 * Plugin Name: WC Product Engine
 * Plugin URI: https://site1.localhost
 * Description: High performance native product catalog engine for WooCommerce.
 * Version: 1.1.0
 * Author: WC Product Engine Team
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * WC requires at least: 11.0
 * License: GPLv2 or later
 * Text Domain: wc-product-engine
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPE_VERSION', '1.1.0');
define('WPE_PLUGIN_FILE', __FILE__);
define('WPE_PLUGIN_DIR', plugin_dir_path(__FILE__));

// PSR-4 Autoloader for WPE namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'WPE\\';
    $base_dir = WPE_PLUGIN_DIR . 'src/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Initialize plugin bootstrap
\WPE\Bootstrap\PluginBootstrap::instance()->init();
