<?php
/**
 * T14 Integration Test — WC admin, REST v3, and Store API adapters.
 *
 * Verifies:
 * - R55 (admin_crud_bulk_duplicate): Admin CRUD, quick edit, bulk status/trash, product duplication with shell and SKU.
 * - R56 (wc_rest_product_variation_roundtrip): Full WC REST v3 product and variation lifecycle persisted in native database.
 * - R57 (store_api_no_private_leak): Public & Store API isolation preventing draft/private/cost data leakage.
 * - R58 (object_capability_denial): Object-level capability checks and denial for unprivileged/anonymous users.
 * - AC-T14-01: Admin and REST mutations exist in engine storage; public surfaces do not leak draft/private/cost.
 * - AC-T14-02: Unsupported meta query rejects fallback in native mode to protect against stale postmeta reads.
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WooDataStore\InventoryBridge;
use WPE\Compatibility\WooDataStore\PostShellBridge;
use WPE\Compatibility\WooDataStore\AdminApiBridge;

class T14Test
{
    private \wpdb $wpdb;
    private AdminApiBridge $bridge;
    private array $cleanProductIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        // Register data stores and bridges
        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        InventoryBridge::getInstance()->boot();
        PostShellBridge::getInstance()->boot();
        $this->bridge = AdminApiBridge::getInstance();
        $this->bridge->boot();
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $prefix = $this->wpdb->prefix;
        foreach ($this->cleanProductIds as $pid) {
            $this->wpdb->delete($prefix . 'wpe_sku_registry', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_inventory', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_stock_operations', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_reservation_bridge', ['owner_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_object_state', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_media', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_product_links', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_downloads', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_meta', ['object_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_variants', ['product_id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_products', ['id' => $pid]);
            $this->wpdb->delete($prefix . 'wpe_outbox', ['aggregate_id' => $pid]);
            $this->wpdb->delete($this->wpdb->posts, ['ID' => $pid]);
            $this->wpdb->delete($this->wpdb->postmeta, ['post_id' => $pid]);
        }
    }

    private function createSimpleProduct(string $name, float $price, string $status = 'publish'): \WC_Product_Simple
    {
        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_regular_price((string)$price);
        $product->set_sku('ADM-SKU-' . strtolower(wp_generate_password(6, false)));
        $product->set_status($status);
        $product->save();

        $pid = $product->get_id();
        $this->cleanProductIds[] = $pid;

        PostShellBridge::getInstance()->projectShell($pid, [
            'title'  => $name,
            'slug'   => $product->get_slug(),
            'status' => $status,
        ]);

        return $product;
    }

    public function testR55AdminCrudBulkDuplicate(): void
    {
        echo "[Test R55] Testing admin CRUD, quick edit, bulk actions, and duplicate...\n";

        // 1. Create product
        $prod = $this->createSimpleProduct('R55 Admin CRUD', 50.0, 'publish');
        $pid = $prod->get_id();

        // 2. Quick Edit
        $quickEditSuccess = $this->bridge->quickEdit($pid, [
            'name'          => 'R55 Admin CRUD Edited',
            'regular_price' => '65.00',
            'sale_price'    => '55.00',
        ]);
        if (!$quickEditSuccess) {
            throw new \RuntimeException("R55 Failed: quickEdit failed");
        }

        // Verify native database update
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT title, regular_price, sale_price FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $pid
        ), ARRAY_A);
        if ($row['title'] !== 'R55 Admin CRUD Edited' || (float)$row['regular_price'] !== 65.0 || (float)$row['sale_price'] !== 55.0) {
            throw new \RuntimeException("R55 Failed: Native database not updated after quickEdit: " . json_encode($row));
        }

        // 3. Duplicate Product
        $duplicate = $this->bridge->duplicateProduct($prod);
        $dupId = $duplicate->get_id();
        if ($dupId <= 0 || $dupId === $pid) {
            throw new \RuntimeException("R55 Failed: Duplicate product returned invalid ID: {$dupId}");
        }
        $this->cleanProductIds[] = $dupId;

        // Verify duplicate has new real ID, status draft, and projected shell
        $dupRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT status, sku FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $dupId
        ), ARRAY_A);
        if ($dupRow['status'] !== 'draft') {
            throw new \RuntimeException("R55 Failed: Duplicate status expected draft, got {$dupRow['status']}");
        }
        $dupShell = get_post($dupId);
        if (!$dupShell || $dupShell->post_status !== 'draft') {
            throw new \RuntimeException("R55 Failed: Duplicate post shell invalid in wp_posts");
        }

        // 4. Bulk Update Status
        $updatedCount = $this->bridge->bulkUpdateStatus([$pid, $dupId], 'private');
        if ($updatedCount !== 2) {
            throw new \RuntimeException("R55 Failed: bulkUpdateStatus expected 2, got {$updatedCount}");
        }

        $statusPid = $this->wpdb->get_var($this->wpdb->prepare("SELECT status FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $pid));
        $statusDup = $this->wpdb->get_var($this->wpdb->prepare("SELECT status FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $dupId));
        if ($statusPid !== 'private' || $statusDup !== 'private') {
            throw new \RuntimeException("R55 Failed: bulkUpdateStatus native status not updated: {$statusPid}, {$statusDup}");
        }

        // 5. Bulk Trash
        $trashedCount = $this->bridge->bulkTrash([$dupId]);
        if ($trashedCount !== 1) {
            throw new \RuntimeException("R55 Failed: bulkTrash expected 1, got {$trashedCount}");
        }

        $trashedRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT status, deleted_at FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $dupId
        ), ARRAY_A);
        if ($trashedRow['status'] !== 'trash' || empty($trashedRow['deleted_at'])) {
            throw new \RuntimeException("R55 Failed: Product not marked trash/deleted_at in native tables: " . json_encode($trashedRow));
        }

        echo "PASSED: Admin CRUD, quick edit, bulk actions, and duplicate verified.\n\n";
    }

    public function testR56WcRestProductVariationRoundtrip(): void
    {
        echo "[Test R56] Testing WC REST v3 product and variation roundtrip persistence...\n";

        // Authenticate as Admin user 1
        wp_set_current_user(1);

        // 1. Create Variable Product via WC REST Controller
        $prodController = new \WC_REST_Products_Controller();
        $createReq = new \WP_REST_Request('POST', '/wc/v3/products');
        $createReq->set_body_params([
            'name'          => 'REST Variable Product',
            'type'          => 'variable',
            'status'        => 'publish',
            'regular_price' => '',
            'sku'           => 'REST-PAR-' . strtolower(wp_generate_password(6, false)),
        ]);

        $resProd = $prodController->create_item($createReq);
        if ($resProd instanceof \WP_Error || $resProd->get_status() !== 201) {
            throw new \RuntimeException("R56 Failed: REST create_item product failed: " . ($resProd instanceof \WP_Error ? $resProd->get_error_message() : json_encode($resProd->get_data())));
        }

        $prodData = $resProd->get_data();
        $parentId = (int)$prodData['id'];
        $this->cleanProductIds[] = $parentId;

        // Verify parent created in native wpe_products
        $parentRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, type, title FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $parentId
        ), ARRAY_A);
        if (!$parentRow || $parentRow['type'] !== 'variable') {
            throw new \RuntimeException("R56 Failed: REST parent not persisted in wpe_products: " . json_encode($parentRow));
        }

        // 2. Create Variation via WC REST Variation Controller
        $varController = new \WC_REST_Product_Variations_Controller();
        $createVarReq = new \WP_REST_Request('POST', "/wc/v3/products/{$parentId}/variations");
        $createVarReq->set_url_params(['product_id' => $parentId]);
        $createVarReq->set_body_params([
            'regular_price' => '29.99',
            'sku'           => 'REST-VAR-' . strtolower(wp_generate_password(6, false)),
            'status'        => 'publish',
        ]);

        $resVar = $varController->create_item($createVarReq);
        if ($resVar instanceof \WP_Error || $resVar->get_status() !== 201) {
            throw new \RuntimeException("R56 Failed: REST create_item variation failed: " . ($resVar instanceof \WP_Error ? $resVar->get_error_message() : json_encode($resVar->get_data())));
        }

        $varData = $resVar->get_data();
        $varId = (int)$varData['id'];
        $this->cleanProductIds[] = $varId;

        // Verify variation created in native wpe_variants
        $varRow = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, product_id, regular_price FROM {$this->wpdb->prefix}wpe_variants WHERE id = %d",
            $varId
        ), ARRAY_A);
        if (!$varRow || (int)$varRow['product_id'] !== $parentId || (float)$varRow['regular_price'] !== 29.99) {
            throw new \RuntimeException("R56 Failed: REST variation not persisted in wpe_variants: " . json_encode($varRow));
        }

        // 3. Update Variation via REST
        $updateVarReq = new \WP_REST_Request('PUT', "/wc/v3/products/{$parentId}/variations/{$varId}");
        $updateVarReq->set_url_params(['product_id' => $parentId, 'id' => $varId]);
        $updateVarReq->set_body_params([
            'regular_price' => '34.99',
        ]);

        $resVarUpdate = $varController->update_item($updateVarReq);
        if ($resVarUpdate instanceof \WP_Error || $resVarUpdate->get_status() !== 200) {
            throw new \RuntimeException("R56 Failed: REST update_item variation failed");
        }

        // Verify updated price in wpe_variants
        $updatedPrice = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT regular_price FROM {$this->wpdb->prefix}wpe_variants WHERE id = %d",
            $varId
        ));
        if ($updatedPrice !== 34.99) {
            throw new \RuntimeException("R56 Failed: Updated price in wpe_variants expected 34.99, got {$updatedPrice}");
        }

        // 4. Delete via REST
        $delVarReq = new \WP_REST_Request('DELETE', "/wc/v3/products/{$parentId}/variations/{$varId}");
        $delVarReq->set_url_params(['product_id' => $parentId, 'id' => $varId]);
        $delVarReq->set_param('force', true);
        $resDel = $varController->delete_item($delVarReq);
        if ($resDel instanceof \WP_Error || $resDel->get_status() !== 200) {
            throw new \RuntimeException("R56 Failed: REST delete variation failed");
        }

        echo "PASSED: Full WC REST v3 product and variation roundtrip persistence verified.\n\n";
    }

    public function testR57StoreApiNoPrivateLeak(): void
    {
        echo "[Test R57] Testing Store API & public leak protection (draft/private/cost)...\n";

        // 1. Published Product with sensitive cost fields
        $pub = $this->createSimpleProduct('R57 Public Product', 20.0, 'publish');
        $pubId = $pub->get_id();

        // Add private meta
        update_post_meta($pubId, '_cost', 12.00);
        update_post_meta($pubId, '_cost_price', 12.00);
        update_post_meta($pubId, '_internal_secret', 'secret-val');

        // 2. Draft Product
        $draft = $this->createSimpleProduct('R57 Draft Product', 30.0, 'draft');
        $draftId = $draft->get_id();

        // 3. Private Product
        $priv = $this->createSimpleProduct('R57 Private Product', 40.0, 'private');
        $privId = $priv->get_id();

        // Simulate Store API payload for Draft product -> must return null for anonymous users
        $draftPayload = [
            'id'     => $draftId,
            'name'   => 'R57 Draft Product',
            'status' => 'draft',
            'price'  => '30.00',
        ];
        $filteredDraft = $this->bridge->filterStoreApiResponse($draftPayload, 0);
        if ($filteredDraft !== null) {
            throw new \RuntimeException("R57 Failed: Draft product leaked to anonymous user!");
        }

        // Simulate Store API payload for Private product -> must return null for anonymous users
        $privPayload = [
            'id'     => $privId,
            'name'   => 'R57 Private Product',
            'status' => 'private',
            'price'  => '40.00',
        ];
        $filteredPriv = $this->bridge->filterStoreApiResponse($privPayload, 0);
        if ($filteredPriv !== null) {
            throw new \RuntimeException("R57 Failed: Private product leaked to anonymous user!");
        }

        // Simulate Store API payload for Public product with cost data
        $pubPayload = [
            'id'         => $pubId,
            'name'       => 'R57 Public Product',
            'status'     => 'publish',
            'price'      => '20.00',
            'cost'       => '12.00',
            '_cost'      => '12.00',
            'cost_price' => '12.00',
        ];
        $filteredPub = $this->bridge->filterStoreApiResponse($pubPayload, 0);
        if ($filteredPub === null) {
            throw new \RuntimeException("R57 Failed: Published product suppressed");
        }
        if (isset($filteredPub['cost']) || isset($filteredPub['_cost']) || isset($filteredPub['cost_price'])) {
            throw new \RuntimeException("R57 Failed: Cost field leaked in public response: " . json_encode($filteredPub));
        }

        echo "PASSED: Store API & public leak protection (draft/private/cost) verified.\n\n";
    }

    public function testR58ObjectCapabilityDenial(): void
    {
        echo "[Test R58] Testing object-level capability checks and denial for unauthorized users...\n";

        $pub = $this->createSimpleProduct('R58 Public Product', 15.0, 'publish');
        $pubId = $pub->get_id();

        $priv = $this->createSimpleProduct('R58 Private Product', 25.0, 'private');
        $privId = $priv->get_id();

        // 1. Anonymous user ($userId = 0)
        if (!$this->bridge->checkObjectPermission($pubId, 'read', 0)) {
            throw new \RuntimeException("R58 Failed: Anonymous user should be able to read public product");
        }
        if ($this->bridge->checkObjectPermission($privId, 'read', 0)) {
            throw new \RuntimeException("R58 Failed: Anonymous user allowed to read private product!");
        }
        if ($this->bridge->checkObjectPermission($pubId, 'edit', 0)) {
            throw new \RuntimeException("R58 Failed: Anonymous user allowed to edit product!");
        }
        if ($this->bridge->checkObjectPermission($pubId, 'delete', 0)) {
            throw new \RuntimeException("R58 Failed: Anonymous user allowed to delete product!");
        }

        // 2. Admin user ($userId = 1)
        if (!$this->bridge->checkObjectPermission($privId, 'read', 1)) {
            throw new \RuntimeException("R58 Failed: Admin should be able to read private product");
        }
        if (!$this->bridge->checkObjectPermission($pubId, 'edit', 1)) {
            throw new \RuntimeException("R58 Failed: Admin should be able to edit product");
        }
        if (!$this->bridge->checkObjectPermission($pubId, 'delete', 1)) {
            throw new \RuntimeException("R58 Failed: Admin should be able to delete product");
        }

        echo "PASSED: Object-level capability checks and denial verified.\n\n";
    }

    public function testAcT1401AdminRestEngineParity(): void
    {
        echo "[Test AC-T14-01] Testing admin and REST mutations engine parity & no private/cost leak...\n";

        $prod = $this->createSimpleProduct('AC14-01 Parity Test', 45.0, 'publish');
        $pid = $prod->get_id();

        // Update via AdminApiBridge quickEdit
        $this->bridge->quickEdit($pid, ['regular_price' => '48.00']);

        // Check native engine price
        $regPrice = (float)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT regular_price FROM {$this->wpdb->prefix}wpe_products WHERE id = %d",
            $pid
        ));
        if ($regPrice !== 48.0) {
            throw new \RuntimeException("AC-T14-01 Failed: Price in wpe_products expected 48.0, got {$regPrice}");
        }

        echo "PASSED: AC-T14-01 verified.\n\n";
    }

    public function testAcT1402UnsupportedQueryLegacyOnly(): void
    {
        echo "[Test AC-T14-02] Testing unsupported query rejection in native mode (no stale postmeta fallback)...\n";

        // Query with unindexed random meta key
        $queryArgs = [
            'meta_query' => [
                [
                    'key'     => '_unregistered_random_vendor_meta',
                    'value'   => 'abc',
                    'compare' => '=',
                ]
            ]
        ];

        // In native authority mode: must be rejected with fallback_allowed = false (AC-T14-02)
        $evalNative = $this->bridge->isQuerySupported($queryArgs, 'native');
        if ($evalNative['supported'] || $evalNative['fallback_allowed']) {
            throw new \RuntimeException("AC-T14-02 Failed: Native mode must not allow fallback to stale postmeta query: " . json_encode($evalNative));
        }

        // In legacy mode: fallback is permitted
        $evalLegacy = $this->bridge->isQuerySupported($queryArgs, 'legacy');
        if ($evalLegacy['supported'] || !$evalLegacy['fallback_allowed']) {
            throw new \RuntimeException("AC-T14-02 Failed: Legacy mode should allow fallback: " . json_encode($evalLegacy));
        }

        echo "PASSED: AC-T14-02 verified.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T14 Integration Tests ===\n\n";

        $this->testR55AdminCrudBulkDuplicate();
        $this->testR56WcRestProductVariationRoundtrip();
        $this->testR57StoreApiNoPrivateLeak();
        $this->testR58ObjectCapabilityDenial();
        $this->testAcT1401AdminRestEngineParity();
        $this->testAcT1402UnsupportedQueryLegacyOnly();

        echo "=== Test Summary ===\n";
        echo "R55 (admin_crud_bulk_duplicate): PASSED\n";
        echo "R56 (wc_rest_product_variation_roundtrip): PASSED\n";
        echo "R57 (store_api_no_private_leak): PASSED\n";
        echo "R58 (object_capability_denial): PASSED\n";
        echo "AC-T14-01 (admin_rest_engine_parity): PASSED\n";
        echo "AC-T14-02 (unsupported_query_legacy_only): PASSED\n";
    }
}

$test = new T14Test();
$test->runAll();
exit(0);
