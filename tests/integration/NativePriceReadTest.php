<?php

/**
 * LOG-09 regression tests: WooCommerce price props under the native data store.
 *
 * - priceProps(): `price` is the effective price (sale only inside its window and not above the
 *   regular price), and the window is exposed as date_on_sale_from/to.
 * - A variable parent's get_price() is the lowest active price of its published variations,
 *   matching legacy `_price` for every published product of the live catalog.
 * - When the native runtime is active, a simple product saved through the WooCommerce CRUD with a
 *   sale window keeps the window, prices by it, and clearing the window persists.
 *
 * The CRUD case creates one throw-away product and deletes it.
 *
 * Run: wp eval-file tests/integration/NativePriceReadTest.php
 */

if (!defined('ABSPATH')) {
    $wp_load = (getenv('WPE_WP_PATH') ?: '/var/www/site1.localhost') . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\WooDataStore\ProductDataStore;

final class NativePriceProbe extends ProductDataStore
{
    public static function props(array $row): array
    {
        return self::priceProps($row);
    }
}

class NativePriceReadTest
{
    private int $checks = 0;

    public function run(): void
    {
        echo "Running native price read tests (LOG-09)...\n";
        try {
            $this->testPricePropsFollowSaleWindow();
            $this->testVariableParentsMatchLegacyPrice();
            $this->testCrudSaleWindowRoundTrip();
            echo "All native price read tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            exit(1);
        }
    }

    private function testPricePropsFollowSaleWindow(): void
    {
        echo ' price props follow the sale window...';
        $past = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        $yesterday = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $tomorrow = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $base = ['regular_price' => '50.000000', 'sale_price' => '40.000000', 'sale_from' => null, 'sale_to' => null, 'extra_props' => ''];

        $cases = [
            'no window'        => [$base, 40.0],
            'inside window'    => [['sale_from' => $yesterday, 'sale_to' => $tomorrow] + $base, 40.0],
            'expired window'   => [['sale_from' => $past, 'sale_to' => $yesterday] + $base, 50.0],
            'future window'    => [['sale_from' => $tomorrow] + $base, 50.0],
            'sale above reg'   => [['sale_price' => '60.000000'] + $base, 50.0],
            'no sale'          => [['sale_price' => null] + $base, 50.0],
            'free sale'        => [['sale_price' => '0.000000'] + $base, 0.0],
        ];
        foreach ($cases as $label => [$row, $expected]) {
            $props = NativePriceProbe::props($row);
            $this->check($props['price'] !== '' && abs((float)$props['price'] - $expected) < 0.000001, "{$label}: price must be {$expected}, got '{$props['price']}'");
        }
        $unpriced = NativePriceProbe::props(['regular_price' => null, 'sale_price' => null, 'extra_props' => '']);
        $this->check($unpriced['price'] === '', 'Unpriced row must give an empty price, not 0');

        $windowed = NativePriceProbe::props(['sale_from' => $yesterday, 'sale_to' => $tomorrow] + $base);
        $this->check($windowed['date_on_sale_from'] === strtotime($yesterday . ' UTC'), 'date_on_sale_from must be the stored UTC instant');
        $this->check($windowed['date_on_sale_to'] === strtotime($tomorrow . ' UTC'), 'date_on_sale_to must be the stored UTC instant');
        echo " ok\n";
    }

    private function testVariableParentsMatchLegacyPrice(): void
    {
        echo ' published products price like legacy _price...';
        if (!PluginBootstrap::instance()->isNativeRuntimeActive()) {
            echo " skipped (native runtime not active, products are read by the CPT store)\n";
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}wpe_products WHERE deleted_at IS NULL AND status = 'publish' ORDER BY id");
        $variable = 0;
        foreach ($ids as $id) {
            $product = wc_get_product((int)$id);
            if (!$product) {
                continue;
            }
            $legacy = (string)$wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price' AND meta_value <> '' ORDER BY meta_value + 0 ASC LIMIT 1",
                $id
            ));
            $native = (string)$product->get_price();
            $this->check(
                ($legacy === '' && $native === '') || ($legacy !== '' && $native !== '' && abs((float)$legacy - (float)$native) < 0.0001),
                "Product {$id} ({$product->get_type()}): native price '{$native}' must match legacy _price '{$legacy}'"
            );
            if ($product->is_type('variable')) {
                $variable++;
                $childPrices = array_filter(array_map(static fn($c) => wc_get_product($c)?->get_price(), $product->get_children()), static fn($p) => $p !== '' && $p !== null);
                if ($childPrices !== []) {
                    $this->check(abs((float)$native - (float)min(array_map('floatval', $childPrices))) < 0.0001, "Product {$id}: parent price must be the lowest variation price");
                }
            }
        }
        echo " ok (" . count($ids) . " products, {$variable} variable)\n";
    }

    private function testCrudSaleWindowRoundTrip(): void
    {
        echo ' a sale window saved through WooCommerce CRUD persists and prices...';
        if (!PluginBootstrap::instance()->isNativeRuntimeActive()) {
            echo " skipped (native runtime not active)\n";
            return;
        }
        global $wpdb;
        $product = new \WC_Product_Simple();
        $product->set_name('WPE LOG-09 sale window ' . wp_generate_password(6, false));
        $product->set_status('draft');
        $product->set_regular_price('30');
        $product->set_sale_price('20');
        $product->set_date_on_sale_from(time() - 2 * DAY_IN_SECONDS);
        $product->set_date_on_sale_to(time() - DAY_IN_SECONDS);
        $id = $product->save();
        try {
            $this->check($id > 0, 'Test product must be created');
            $row = $wpdb->get_row($wpdb->prepare("SELECT sale_from, sale_to FROM {$wpdb->prefix}wpe_products WHERE id = %d", $id), ARRAY_A);
            $this->check(!empty($row['sale_from']) && !empty($row['sale_to']), 'create() must store the sale window: ' . wp_json_encode($row));

            $read = wc_get_product($id);
            $this->check(abs((float)$read->get_price() - 30.0) < 0.0001, 'An expired sale must price at the regular price, got ' . $read->get_price());
            $this->check(!$read->is_on_sale(), 'An expired sale must not report is_on_sale()');

            $read->set_date_on_sale_to(time() + DAY_IN_SECONDS);
            $read->save();
            $read = wc_get_product($id);
            $this->check(abs((float)$read->get_price() - 20.0) < 0.0001, 'An open sale must price at the sale price, got ' . $read->get_price());
            $this->check($read->is_on_sale(), 'An open sale must report is_on_sale()');

            $read->set_date_on_sale_from(null);
            $read->set_date_on_sale_to(null);
            $read->save();
            $row = $wpdb->get_row($wpdb->prepare("SELECT sale_from, sale_to FROM {$wpdb->prefix}wpe_products WHERE id = %d", $id), ARRAY_A);
            $this->check($row['sale_from'] === null && $row['sale_to'] === null, 'Clearing the window must persist: ' . wp_json_encode($row));
        } finally {
            if ($id > 0 && ($p = wc_get_product($id))) {
                $p->delete(true);
            }
        }
        echo " ok\n";
    }

    private function check(bool $condition, string $message): void
    {
        $this->checks++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

(new NativePriceReadTest())->run();
