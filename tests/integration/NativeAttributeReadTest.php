<?php

/**
 * Regression tests: product attributes under the native data store.
 *
 * Under native_bridge, variable products lost their attributes: ProductDataStore::read() never
 * populated them, variations only read extra_props, and read_variation_attributes() was not routed,
 * so WooCommerce's variations form rendered an empty table (no swatches, no selects).
 *
 * For every published native variable product:
 * - get_attributes() matches legacy _product_attributes (name, options, variation flag);
 * - each variation's attributes match its legacy attribute_* postmeta;
 * - get_variation_attributes() lists every variation option with values;
 * - each variation's name matches WooCommerce's generated title, get_title() is the parent title
 *   (parent data is loaded) and the attribute summary is set;
 * - find_matching_product_variation() with posted attribute_* keys returns the variation itself.
 *
 * Read-only: creates nothing.
 *
 * Run: wp eval-file tests/integration/NativeAttributeReadTest.php
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

class NativeAttributeReadTest
{
    private int $checks = 0;

    public function run(): void
    {
        echo "Running native attribute read tests...\n";
        try {
            $this->testVariableProductsExposeAttributes();
            echo "All native attribute read tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            exit(1);
        }
    }

    private function testVariableProductsExposeAttributes(): void
    {
        echo ' native variable products expose attributes like legacy...';
        if (!PluginBootstrap::instance()->isNativeRuntimeActive()) {
            echo " skipped (native runtime not active, products are read by the CPT store)\n";
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT p.id FROM {$wpdb->prefix}wpe_products p
             WHERE p.deleted_at IS NULL AND p.status = 'publish'
               AND EXISTS (SELECT 1 FROM {$wpdb->prefix}wpe_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL)
             ORDER BY p.id"
        );
        $store = \WC_Data_Store::load('product-variable');
        // WooCommerce's own title generator: reads the parent post title of the shell post.
        $cptVariationStore = new \WC_Product_Variation_Data_Store_CPT();
        $generateTitle = new \ReflectionMethod($cptVariationStore, 'generate_product_title');
        $generateTitle->setAccessible(true);
        $products = 0;
        $variations = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            $product = wc_get_product($id);
            if (!$product || !$product->is_type('variable')) {
                continue;
            }
            $products++;

            $attributes = $product->get_attributes();
            $this->check($attributes !== [], "Product {$id}: get_attributes() must not be empty");

            $legacy = get_post_meta($id, '_product_attributes', true);
            if (is_array($legacy) && $legacy !== []) {
                foreach ($legacy as $key => $legacyAttr) {
                    if (!empty($legacyAttr['is_taxonomy'])) {
                        continue;
                    }
                    $this->check(isset($attributes[$key]), "Product {$id}: attribute '{$key}' missing (have " . implode(',', array_keys($attributes)) . ')');
                    $this->check(
                        $attributes[$key]->get_options() === wc_get_text_attributes((string)$legacyAttr['value']),
                        "Product {$id}: attribute '{$key}' options differ from legacy"
                    );
                    $this->check(
                        $attributes[$key]->get_variation() === !empty($legacyAttr['is_variation']),
                        "Product {$id}: attribute '{$key}' variation flag differs from legacy"
                    );
                }
            }

            $variationAttributes = $product->get_variation_attributes();
            foreach ($attributes as $attribute) {
                if ($attribute->get_variation()) {
                    $this->check(
                        !empty($variationAttributes[$attribute->get_name()]),
                        "Product {$id}: get_variation_attributes() has no values for '{$attribute->get_name()}'"
                    );
                }
            }

            foreach ($product->get_visible_children() as $childId) {
                $variation = wc_get_product($childId);
                if (!$variation) {
                    continue;
                }
                $variations++;
                $own = $variation->get_attributes();
                $this->check($own !== [], "Variation {$childId}: get_attributes() must not be empty");

                $expectedName = $generateTitle->invoke($cptVariationStore, $variation);
                $this->check($variation->get_name() !== '', "Variation {$childId}: get_name() must not be empty");
                $this->check($variation->get_name() === $expectedName, "Variation {$childId}: name '{$variation->get_name()}' must match WooCommerce '{$expectedName}'");
                $this->check($variation->get_title() === $product->get_name(), "Variation {$childId}: get_title() must be the parent title");
                $this->check(
                    $variation->get_attribute_summary() === wc_get_formatted_variation($variation, true, true),
                    "Variation {$childId}: attribute summary mismatch"
                );

                foreach ($own as $key => $value) {
                    $legacyValue = get_post_meta($childId, 'attribute_' . $key, true);
                    if ($legacyValue !== '' && metadata_exists('post', $childId, 'attribute_' . $key)) {
                        $this->check($legacyValue === $value, "Variation {$childId}: '{$key}' is '{$value}', legacy '{$legacyValue}'");
                    }
                }

                if (!in_array('', $own, true)) {
                    $posted = [];
                    foreach ($own as $key => $value) {
                        $posted['attribute_' . $key] = $value;
                    }
                    $match = $store->find_matching_product_variation($product, $posted);
                    $this->check($match === (int)$childId, "Variation {$childId}: find_matching_product_variation returned {$match}");
                }
            }
        }
        $this->check($products > 0, 'At least one native variable product must exist to test');
        echo " ok ({$products} products, {$variations} variations)\n";
    }

    private function check(bool $condition, string $message): void
    {
        $this->checks++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

(new NativeAttributeReadTest())->run();
