<?php

/**
 * P006.1 — Mirror worker epoch fallback.
 *
 * A product with no wpe_object_state row must be mirrored with the authority epoch the mirror itself reads
 * (docs/02: every command carries the epoch it read), not a literal 1 and not the unrelated outbox counter
 * `current_epoch`. Before the fix, a site whose global authority epoch is above 1 answered
 * `rejected_stale_epoch` and the WooCommerce variation shells stayed auto-draft with parent 0.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

// assert() is compiled out when zend.assertions=-1; fail fast instead of passing vacuously.
if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Application\Migration\LegacyMirror;
use WPE\Infrastructure\Database\OutboxWorker;

class MirrorWorkerEpochTest
{
    private wpdb $wpdb;
    private array $productIds = [];
    private ?array $savedEpochRow = null;
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function run(): void
    {
        echo "Running mirror worker epoch tests...\n";
        try {
            $this->ensureGlobalEpochAboveOne();
            $this->testEditorSaveIsMirroredWithoutObjectState();
            $this->testFenceStillRejectsStaleWorker();
            $this->testNoLiteralEpochFallbackRemains();
            $this->cleanup();
            echo "All mirror worker epoch tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function check(bool $cond, string $message): void
    {
        $this->checks++;
        if (!$cond) {
            throw new \AssertionError($message);
        }
    }

    /**
     * The regression needs a global authority epoch above both the old literal 1 and the outbox counter
     * `current_epoch`, so a fallback taken from either of them would be rejected. The original control row is
     * saved and restored verbatim in cleanup().
     */
    private function ensureGlobalEpochAboveOne(): void
    {
        $control = $this->wpdb->prefix . 'wpe_control';
        $this->savedEpochRow = $this->wpdb->get_row("SELECT * FROM {$control} WHERE `key` = 'authority_epoch'", ARRAY_A) ?: ['__absent' => true];

        $mirror = LegacyMirror::getInstance();
        $outbox = (new OutboxWorker($this->wpdb))->getCurrentEpoch();
        $target = max($mirror->getGlobalEpoch(), $outbox, 1) + 1;
        $mirror->setGlobalEpoch($target);

        $this->check($mirror->getGlobalEpoch() === $target, 'Test needs control of the global epoch');
        $this->check($mirror->getGlobalEpoch() > $outbox && $mirror->getGlobalEpoch() > 1, 'Global epoch must exceed both the literal 1 and current_epoch');
    }

    private function createVariableProduct(): int
    {
        $id = wp_insert_post([
            'post_title'  => 'Epoch Test Product ' . uniqid(),
            'post_status' => 'publish',
            'post_type'   => 'product',
        ]);
        if (is_wp_error($id) || !$id) {
            throw new \RuntimeException('Failed to create product');
        }
        wp_set_object_terms($id, 'variable', 'product_type');
        wp_cache_flush();
        $this->productIds[] = (int) $id;
        return (int) $id;
    }

    private function testEditorSaveIsMirroredWithoutObjectState(): void
    {
        echo ' - editor save of a product without object state is mirrored...';
        $service = ProductEditorService::getInstance();
        $productId = $this->createVariableProduct();

        $state = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_object_state WHERE object_id = %d",
            $productId
        ));
        $this->check((int) $state === 0, 'Precondition: product must have no object state row');

        $version = $service->getProductEditorData($productId)['product']['version'];
        $result = $service->saveProductVariants($productId, [
            'expected_version'       => $version,
            'descriptive_attributes' => [],
            'variation_options'      => [['name' => 'Size', 'position' => 0, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]]],
            'variations'             => [
                ['combination' => ['Size' => 'S'], 'sku' => 'EPOCH-S-' . uniqid(), 'regular_price' => 10, 'status' => 'publish', 'manage_stock' => 0, 'stock_quantity' => null],
                ['combination' => ['Size' => 'M'], 'sku' => 'EPOCH-M-' . uniqid(), 'regular_price' => 12, 'status' => 'publish', 'manage_stock' => 0, 'stock_quantity' => null],
            ],
        ]);

        $this->check($result['success'] === true, 'Save must succeed');
        $this->check(($result['projection']['legacy_mirror'] ?? '') === 'mirrored', 'Legacy mirror must be accepted, got ' . var_export($result['projection']['legacy_mirror'] ?? null, true));

        $variantIds = array_map(fn($v) => (int) $v['id'], $service->getProductEditorData($productId)['variations']);
        $this->check(count($variantIds) === 2, 'Fixture must have 2 variations');
        foreach ($variantIds as $vid) {
            $post = get_post($vid);
            $this->check($post && $post->post_type === 'product_variation', "Variation shell $vid must be a product_variation post");
            $this->check((int) $post->post_parent === $productId, "Variation shell $vid must have parent $productId, got " . (int) $post->post_parent);
            $this->check($post->post_status !== 'auto-draft', "Variation shell $vid must not stay auto-draft");
        }
        echo " OK\n";
    }

    private function testFenceStillRejectsStaleWorker(): void
    {
        echo ' - the epoch fence still rejects a stale worker...';
        $productId = $this->productIds[0];
        $state = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT epoch FROM {$this->wpdb->prefix}wpe_object_state WHERE object_id = %d",
            $productId
        ), ARRAY_A);
        $stateEpoch = $state ? (int) $state['epoch'] : LegacyMirror::getInstance()->getGlobalEpoch();
        $this->check($stateEpoch >= 2, 'Fence reference epoch must be above 1');

        $res = LegacyMirror::getInstance()->mirrorAggregate($productId, 9999, $stateEpoch - 1);
        $this->check(($res['status'] ?? '') === 'rejected_stale_epoch', 'A worker below the fence epoch must still be rejected, got ' . ($res['status'] ?? 'null'));
        echo " OK\n";
    }

    /** Guard for the importers, whose mirror outcome is swallowed and not otherwise observable here. */
    private function testNoLiteralEpochFallbackRemains(): void
    {
        echo ' - no literal worker epoch fallback remains...';
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/Admin/ProductEditor/ProductEditorService.php',
            'src/Compatibility/Csv/Shopify/ShopifyBatchImporter.php',
            'src/Compatibility/Csv/Shopify/ShopifyImporter.php',
        ] as $file) {
            $src = file_get_contents($root . '/' . $file);
            $this->check(!preg_match('/\$workerEpoch\s*=\s*\$state\s*\?\s*\(int\)\s*\$state\[\'epoch\'\]\s*:\s*1\s*;/', $src), "$file still falls back to a literal worker epoch of 1");
            $this->check(strpos($src, 'getAggregateEpoch(') !== false, "$file must take the worker epoch from LegacyMirror::getAggregateEpoch()");
            $this->check(strpos($src, 'getCurrentEpoch()') === false, "$file must not take the worker epoch from the outbox counter");
        }
        echo " OK\n";
    }

    private function cleanup(): void
    {
        $pf = $this->wpdb->prefix;
        foreach ($this->productIds as $id) {
            $variantIds = array_map('intval', (array) $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$pf}wpe_variants WHERE product_id = %d", $id)));
            if ($variantIds) {
                $in = implode(',', $variantIds);
                $this->wpdb->query("DELETE FROM {$pf}wpe_inventory WHERE owner_id IN ($in)");
                foreach ($variantIds as $vid) {
                    wp_delete_post($vid, true);
                }
            }
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$pf}wpe_inventory WHERE owner_id = %d", $id));
            $this->wpdb->delete("{$pf}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$pf}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$pf}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
        $this->productIds = [];
        if ($this->savedEpochRow !== null) {
            $control = $pf . 'wpe_control';
            if (isset($this->savedEpochRow['__absent'])) {
                $this->wpdb->delete($control, ['key' => 'authority_epoch']);
            } else {
                $this->wpdb->replace($control, $this->savedEpochRow);
            }
            $this->savedEpochRow = null;
        }
    }
}

(new MirrorWorkerEpochTest())->run();
