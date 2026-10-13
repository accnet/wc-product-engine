<?php

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

// These tests rely on assert(). WP-CLI on some hosts runs with zend.assertions=-1, which compiles every
// assert() out and makes the file pass without checking anything. Fail fast instead of passing vacuously.
if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Admin\ProductEditor\ConcurrencyConflictException;
use WPE\Compatibility\Gallery\UrlValidator;

class ProductEditorServiceTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->service = ProductEditorService::getInstance();
    }

    public function run(): void
    {
        echo "Running ProductEditorService Integration Tests...\n";

        try {
            $this->setUp();
            $this->testCreateAndGetProductEditorData();
            $this->testSaveProductVariantsAndOptionSeparation();
            $this->testSelectiveCombinationsPreview();
            $this->testPreviewBulkEdit();
            $this->testConcurrencyConflictDetection();
            $this->testParentStockGuard();
            $this->testLegacyMirrorProjection();
            $this->tearDown();

            echo "All ProductEditorService tests passed successfully!\n";
        } catch (\Throwable $e) {
            echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
    }

    private function setUp(): void
    {
        $this->cleanProductIds = [];
        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14'];
        });
    }

    private function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanProductIds as $id) {
            $variantIds = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d",
                $id
            ));
            if (!empty($variantIds)) {
                $idList = implode(',', array_map('intval', $variantIds));
                $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$idList})");
            }
            $this->wpdb->delete("{$prefix}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$prefix}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
    }

    private function createTestProduct(): int
    {
        $postId = wp_insert_post([
            'post_title'   => 'Test Variable Product ' . uniqid(),
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ]);

        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create test product post: " . ($postId instanceof \WP_Error ? $postId->get_error_message() : 'Unknown error'));
        }

        wp_set_object_terms($postId, 'variable', 'product_type');
        $this->cleanProductIds[] = $postId;
        return $postId;
    }

    public function testCreateAndGetProductEditorData(): void
    {
        echo " - testCreateAndGetProductEditorData...";
        $productId = $this->createTestProduct();

        $data = $this->service->getProductEditorData($productId);

        assert(!empty($data['product']), 'Product payload should exist');
        assert($data['product']['id'] === $productId, 'Product ID should match');
        assert(isset($data['product']['version']), 'Version should exist');
        assert(is_array($data['descriptive_attributes']), 'descriptive_attributes must be array');
        assert(is_array($data['variation_options']), 'variation_options must be array');
        assert(is_array($data['variations']), 'variations must be array');
        assert(is_array($data['taxonomies']), 'taxonomies must be array');

        echo " OK\n";
    }

    public function testSaveProductVariantsAndOptionSeparation(): void
    {
        echo " - testSaveProductVariantsAndOptionSeparation...";
        $productId = $this->createTestProduct();

        $initialData = $this->service->getProductEditorData($productId);
        $version = $initialData['product']['version'];

        $payload = [
            'expected_version' => $version,
            'descriptive_attributes' => [
                [
                    'name'                => 'Material',
                    'taxonomy'            => null,
                    'is_visible'          => 1,
                    'position'            => 0,
                    'values'              => ['Cotton', 'Polyester'],
                ],
                [
                    'name'                => 'Brand',
                    'taxonomy'            => null,
                    'is_visible'          => 1,
                    'position'            => 1,
                    'values'              => ['AcceNet'],
                ]
            ],
            'variation_options' => [
                [
                    'name'     => 'Size',
                    'position' => 0,
                    'values'   => [
                        ['label' => 'S', 'position' => 0],
                        ['label' => 'M', 'position' => 1],
                    ],
                ],
                [
                    'name'     => 'Color',
                    'position' => 1,
                    'values'   => [
                        ['label' => 'Red', 'position' => 0],
                        ['label' => 'Blue', 'position' => 1],
                    ],
                ]
            ],
            'variations' => [
                [
                    'combination'   => ['Size' => 'S', 'Color' => 'Red'],
                    'sku'           => 'TEST-S-RED-' . uniqid(),
                    'regular_price' => 20.00,
                    'sale_price'    => null,
                    'status'        => 'publish',
                    'manage_stock'  => 1,
                    'stock_quantity'=> 10,
                ],
                [
                    'combination'   => ['Size' => 'S', 'Color' => 'Blue'],
                    'sku'           => 'TEST-S-BLU-' . uniqid(),
                    'regular_price' => 20.00,
                    'sale_price'    => 18.00,
                    'status'        => 'publish',
                    'manage_stock'  => 1,
                    'stock_quantity'=> 15,
                ],
                [
                    'combination'   => ['Size' => 'M', 'Color' => 'Red'],
                    'sku'           => 'TEST-M-RED-' . uniqid(),
                    'regular_price' => 25.00,
                    'sale_price'    => null,
                    'status'        => 'publish',
                    'manage_stock'  => 1,
                    'stock_quantity'=> 5,
                ],
                [
                    'combination'   => ['Size' => 'M', 'Color' => 'Blue'],
                    'sku'           => 'TEST-M-BLU-' . uniqid(),
                    'regular_price' => 25.00,
                    'sale_price'    => null,
                    'status'        => 'publish',
                    'manage_stock'  => 1,
                    'stock_quantity'=> 8,
                ],
            ]
        ];

        $saveResult = $this->service->saveProductVariants($productId, $payload);
        assert($saveResult['success'] === true, 'Save should succeed');
        assert($saveResult['new_version'] > $version, 'Version should increment');

        $reloaded = $this->service->getProductEditorData($productId);

        // Verify descriptive attributes
        assert(count($reloaded['descriptive_attributes']) === 2, 'Should have 2 descriptive attributes');
        $descNames = array_column($reloaded['descriptive_attributes'], 'name');
        assert(in_array('Material', $descNames), 'Material must be descriptive');
        assert(in_array('Brand', $descNames), 'Brand must be descriptive');

        // Verify variation options
        assert(count($reloaded['variation_options']) === 2, 'Should have 2 variation options');
        $optNames = array_column($reloaded['variation_options'], 'name');
        assert(in_array('Size', $optNames), 'Size must be variation option');
        assert(in_array('Color', $optNames), 'Color must be variation option');

        // Verify variations
        assert(count($reloaded['variations']) === 4, 'Should have exactly 4 variations');

        echo " OK\n";
    }

    public function testSelectiveCombinationsPreview(): void
    {
        echo " - testSelectiveCombinationsPreview...";
        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];

        $options = [
            [
                'name'   => 'Size',
                'values' => [
                    ['label' => 'S'],
                    ['label' => 'M'],
                    ['label' => 'L'],
                ],
            ],
            [
                'name'   => 'Color',
                'values' => [
                    ['label' => 'Red'],
                    ['label' => 'Blue'],
                ],
            ],
        ];

        $preview = $this->service->previewCombinations($productId, $options);
        assert($preview['total_possible'] === 6, 'Total combinations should be 3 * 2 = 6');
        assert($preview['existing_count'] === 4, 'Existing count should be 4');
        assert($preview['new_count'] === 2, 'New combinations count should be 2');

        $newCombos = array_filter($preview['combinations'], fn($c) => !$c['exists']);
        assert(count($newCombos) === 2, 'Should have 2 non-existing combos');
        foreach ($newCombos as $nc) {
            assert($nc['combination']['Size'] === 'L', 'New combo should be for Size L');
        }

        echo " OK\n";
    }

    public function testPreviewBulkEdit(): void
    {
        echo " - testPreviewBulkEdit...";
        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $data = $this->service->getProductEditorData($productId);

        $variationIds = array_column($data['variations'], 'id');
        assert(count($variationIds) >= 2, 'Need at least 2 variations');
        $targetIds = array_slice($variationIds, 0, 2);

        $actions = [
            'regular_price' => [
                'type'  => 'fixed',
                'value' => 99.50,
            ],
            'status' => 'private',
        ];

        $preview = $this->service->previewBulkEdit($productId, $targetIds, $actions);
        assert($preview['affected_count'] === 2, 'Affected count should be 2');
        assert(count($preview['diffs']) === 2, 'Should return 2 diffs');

        foreach ($preview['diffs'] as $diff) {
            assert(in_array($diff['variant_id'], $targetIds), 'Variant ID must be in targets');
            assert($diff['changes']['regular_price']['after'] === 99.50, 'Regular price after should be 99.50');
            assert($diff['changes']['status']['after'] === 'private', 'Status after should be private');
        }

        echo " OK\n";
    }

    public function testConcurrencyConflictDetection(): void
    {
        echo " - testConcurrencyConflictDetection...";
        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $data = $this->service->getProductEditorData($productId);
        $currentVersion = $data['product']['version'];

        $staleVersion = $currentVersion - 1;
        $conflictCaught = false;

        try {
            $this->service->saveProductVariants($productId, [
                'expected_version' => $staleVersion,
                'descriptive_attributes' => [],
                'variation_options' => [],
                'variations' => [],
            ]);
        } catch (ConcurrencyConflictException $e) {
            $conflictCaught = true;
            assert($e->getCode() === 409, 'Conflict code should be 409');
            $serverData = $e->getServerData();
            assert(!empty($serverData), 'Server data should be attached');
            assert($serverData['product']['version'] === $currentVersion, 'Server version must match current');
        }

        assert($conflictCaught, 'Should catch ConcurrencyConflictException when version is stale');

        echo " OK\n";
    }

    public function testParentStockGuard(): void
    {
        echo " - testParentStockGuard...";
        $productId = $this->createTestProduct();
        $initialData = $this->service->getProductEditorData($productId);
        $version = $initialData['product']['version'];

        // Parent manage_stock = 0 (default in WPE)
        $payload = [
            'expected_version' => $version,
            'descriptive_attributes' => [],
            'variation_options' => [
                [
                    'name'     => 'Size',
                    'position' => 0,
                    'values'   => [['label' => 'Standard', 'position' => 0]],
                ]
            ],
            'variations' => [
                [
                    'combination'   => ['Size' => 'Standard'],
                    'sku'           => 'TEST-PAR-STK-' . uniqid(),
                    'regular_price' => 10.00,
                    'manage_stock'  => 0, // Uses parent stock
                ]
            ]
        ];

        $this->service->saveProductVariants($productId, $payload);
        $data = $this->service->getProductEditorData($productId);

        $var = $data['variations'][0];
        assert($var['inventory']['manage_stock'] == 0, 'Variation manage_stock should be 0');
        assert((int)$var['inventory']['stock_owner_id'] === $productId, 'Stock owner should be parent product');

        echo " OK\n";
    }

    public function testLegacyMirrorProjection(): void
    {
        echo " - testLegacyMirrorProjection...";
        $productId = $this->cleanProductIds[count($this->cleanProductIds) - 1];
        $data = $this->service->getProductEditorData($productId);
        $variantId = $data['variations'][0]['id'];

        // Check that WP post of type product_variation exists
        $varPost = get_post($variantId);
        assert($varPost !== null, 'Variation WP post should exist');
        assert($varPost->post_type === 'product_variation', 'Post type must be product_variation');
        assert((int)$varPost->post_parent === $productId, 'Post parent must be product ID');

        $price = get_post_meta($variantId, '_regular_price', true);
        assert($price !== '', 'Regular price meta should exist in WP postmeta');

        echo " OK\n";
    }
}

$test = new ProductEditorServiceTest();
$test->run();
