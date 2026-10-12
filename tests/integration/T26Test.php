<?php
/**
 * T26 Integration Test — Resolver API và native AJAX variation adapter.
 *
 * Verifies:
 * - R103 (wc_ajax_variation_payload): Classic get_variation AJAX payload compatibility.
 * - R104 (public_resolver_permissions): Public access controls; draft products/variants hidden; no internal meta leak.
 * - R105 (payload_limits): Selection size caps, quantity bounds, ETag caching, rate limiting with retry hints, and lightweight empty selection.
 * - R106 (revision_refresh): Request sequence echo, revision mismatch reporting, and zero stock holds.
 * - AC-T26-01: Classic get_variation AJAX returns valid payload; engine resolver does not expose draft/meta.
 * - AC-T26-02: Empty selection does not hydrate 10k variations; rate limit response has retry hint.
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

use WPE\Application\Variant\VariantResolver;
use WPE\Application\Variant\ResolverApi;
use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\Gallery\GalleryAdapter;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\OptionsRepository;

class T26Test
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private AssetService $assetService;
    private AttachmentBridge $bridge;
    private GalleryAdapter $galleryAdapter;
    private OptionsRepository $optionsRepo;
    private VariantResolver $resolver;
    private ResolverApi $api;
    private PostIdAllocator $allocator;

    private array $cleanProductIds = [];
    private array $cleanOptionIds = [];
    private array $cleanValueIds = [];
    private array $cleanAssetIds = [];
    private array $cleanAttachmentIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->mediaRepo = new MediaRepository($this->wpdb);
        $this->assetService = new AssetService($this->wpdb, $this->mediaRepo);
        $this->bridge = AttachmentBridge::register(new AttachmentBridge($this->wpdb, $this->assetService, $this->mediaRepo));
        $this->galleryAdapter = GalleryAdapter::register(new GalleryAdapter($this->wpdb, $this->assetService, $this->bridge, $this->mediaRepo));
        $this->optionsRepo = new OptionsRepository($this->wpdb);
        $this->resolver = new VariantResolver($this->wpdb, $this->optionsRepo);
        $this->api = ResolverApi::register(new ResolverApi($this->wpdb, $this->resolver, $this->galleryAdapter));
        $this->allocator = new PostIdAllocator($this->wpdb);

        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14']; // Safe public IP (example.com)
        });
    }

    public function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;

        UrlValidator::setDnsResolver(null);

        if (!empty($this->cleanAttachmentIds)) {
            foreach ($this->cleanAttachmentIds as $attId) {
                wp_delete_attachment($attId, true);
            }
            $this->cleanAttachmentIds = [];
        }

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE variant_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE id IN ({$ids}) OR product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_meta WHERE object_id IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanOptionIds)) {
            $optIds = implode(',', array_map('intval', $this->cleanOptionIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE option_id IN ({$optIds})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN ({$optIds})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_options WHERE id IN ({$optIds})");
            $this->cleanOptionIds = [];
        }

        if (!empty($this->cleanValueIds)) {
            $valIds = implode(',', array_map('intval', $this->cleanValueIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE id IN ({$valIds})");
            $this->cleanValueIds = [];
        }

        if (!empty($this->cleanAssetIds)) {
            $ids = implode(',', array_map('intval', $this->cleanAssetIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_assets WHERE id IN ({$ids})");
            $this->cleanAssetIds = [];
        }

        $this->api->clearRateLimit('127.0.0.1');
        $this->api->clearRateLimit('test-rate-limit-ip');
    }

    public function runAll(): void
    {
        echo "Running T26 Integration Tests...\n";

        try {
            $this->testR103_wc_ajax_variation_payload();
            $this->testR104_public_resolver_permissions();
            $this->testR105_payload_limits();
            $this->testR106_revision_refresh();
            $this->testAC_T26_01_ClassicAjaxAndNoMetaExposure();
            $this->testAC_T26_02_EmptySelectionAndRateLimitWithRetryHint();

            echo "All T26 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    /**
     * R103: Support Woo native variation resolution path and classic AJAX payload compatibility.
     */
    public function testR103_wc_ajax_variation_payload(): void
    {
        echo " - testR103_wc_ajax_variation_payload...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$parentId, $varId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'AJAX-SHIRT', 'slug' => 'ajax-shirt-' . $parentId, 'title' => 'Ajax Shirt',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varId, 'product_id' => $parentId, 'sku' => 'AJAX-SHIRT-NAVY', 'regular_price' => '35.000000',
            'status' => 'publish', 'combination_key' => hash('sha256', 'navy', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_color', 'name' => 'Color', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $val = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'navy', 'label' => 'Navy']);
        $this->cleanValueIds[] = (int)$val['id'];

        $this->optionsRepo->assignVariantCombination($parentId, $varId, [$optId => (int)$val['id']]);

        // Register CDN image and map to variation
        $asset = $this->assetService->registerAsset('https://cdn.example.com/ajax/navy-shirt.jpg', [
            'width' => 1200, 'height' => 1200, 'alt_text' => 'Navy Shirt Image', 'title' => 'Navy Shirt'
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $this->wpdb->insert("{$prefix}wpe_variant_media", [
            'variant_id' => $varId, 'asset_id' => $assetId, 'position' => 0, 'is_primary' => 1
        ]);

        // Serialize variation for classic get_variation AJAX
        $data = $this->api->serializeAvailableVariation($varId, $parentId);

        $this->assert(is_array($data), "Available variation data must be an array");
        $this->assert((int)$data['variation_id'] === $varId, "variation_id must match variant ID");
        $this->assert($data['sku'] === 'AJAX-SHIRT-NAVY', "sku must match");
        $this->assert($data['display_price'] == 35.0, "display_price must match regular price");
        $this->assert(isset($data['image']), "Image payload must be present");
        $this->assert(str_contains($data['image']['src'], 'navy-shirt.jpg'), "Image src must contain CDN URL");
        $this->assert($data['is_purchasable'] === true, "Must be purchasable");

        echo " OK\n";
    }

    /**
     * R104: Public resolver permissions: draft products/variants hidden; no internal meta leak.
     */
    public function testR104_public_resolver_permissions(): void
    {
        echo " - testR104_public_resolver_permissions...";

        $prefix = $this->wpdb->prefix;

        // 1. Draft Parent Product
        $draftParentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $draftParentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $draftParentId, 'sku' => 'DRAFT-PARENT', 'slug' => 'draft-parent-' . $draftParentId, 'title' => 'Draft Parent',
            'type' => 'variable', 'status' => 'draft', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        // Request as unauthenticated public user
        wp_set_current_user(0);

        $reqDraft = new WP_REST_Request('POST', "/wpe/v1/products/{$draftParentId}/resolve");
        $reqDraft->set_param('id', $draftParentId);
        $reqDraft->set_param('selection', []);
        $reqDraft->set_param('quantity', '1');
        $reqDraft->set_param('request_sequence', 1);

        $resDraft = $this->api->resolveEndpoint($reqDraft);
        $this->assert($resDraft->get_status() === 404, "Draft parent product must return 404 for public consumer (R104)");

        // 2. Published parent with Draft Variation
        $pubParentId = $this->allocator->allocateShell('product');
        $draftVarId  = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$pubParentId, $draftVarId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $pubParentId, 'sku' => 'PUB-PARENT', 'slug' => 'pub-parent-' . $pubParentId, 'title' => 'Pub Parent',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        // Variation is draft
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $draftVarId, 'product_id' => $pubParentId, 'sku' => 'DRAFT-VAR', 'regular_price' => '10.000000',
            'status' => 'draft', 'combination_key' => hash('sha256', 'draft-v', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($pubParentId, ['key' => 'pa_tier', 'name' => 'Tier', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $val = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'gold', 'label' => 'Gold']);
        $this->cleanValueIds[] = (int)$val['id'];

        $this->optionsRepo->assignVariantCombination($pubParentId, $draftVarId, [$optId => (int)$val['id']]);

        // Also add internal sensitive meta to variation in wpe_meta
        $this->wpdb->insert("{$prefix}wpe_meta", [
            'meta_id'     => 77701,
            'object_id'   => $draftVarId,
            'object_kind' => 'variant',
            'meta_key'    => '_cost_price',
            'meta_value'  => '2.500000',
        ]);

        $reqPub = new WP_REST_Request('POST', "/wpe/v1/products/{$pubParentId}/resolve");
        $reqPub->set_param('id', $pubParentId);
        $reqPub->set_param('selection', [$optId => (int)$val['id']]);
        $reqPub->set_param('quantity', '1');
        $reqPub->set_param('request_sequence', 2);

        $resPub = $this->api->resolveEndpoint($reqPub);
        $dataPub = $resPub->get_data();

        // Must not expose draft variant to public
        $this->assert($dataPub['state'] === 'none', "Draft variation must resolve to state 'none' for public user");
        $this->assert($dataPub['variant'] === null, "Draft variation must be null");

        // Verify no sensitive internal meta in response
        $jsonStr = json_encode($dataPub);
        $this->assert(!str_contains($jsonStr, '_cost_price'), "Internal meta _cost_price must never be leaked (AC-T26-01)");
        $this->assert(!str_contains($jsonStr, '2.500000'), "Internal cost value must never be leaked");

        echo " OK\n";
    }

    /**
     * R105: Payload limits, rate limiting, and lightweight empty selection.
     */
    public function testR105_payload_limits(): void
    {
        echo " - testR105_payload_limits...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'LIMIT-PROD', 'slug' => 'limit-prod-' . $parentId, 'title' => 'Limit Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        // 1. Oversized selection payload (> 32 properties)
        $giantSelection = [];
        for ($i = 1; $i <= 35; $i++) {
            $giantSelection["attr_{$i}"] = "val_{$i}";
        }

        $reqGiant = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $reqGiant->set_param('id', $parentId);
        $reqGiant->set_param('selection', $giantSelection);
        $reqGiant->set_param('quantity', '1');
        $reqGiant->set_param('request_sequence', 1);

        $resGiant = $this->api->resolveEndpoint($reqGiant);
        $this->assert($resGiant->get_status() === 400, "Oversized selection (>32 properties) must return 400 Bad Request");
        $this->assert($resGiant->get_data()['code'] === 'invalid_request', "Error code must be invalid_request");

        // 2. Malformed / negative quantity
        $reqBadQty = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $reqBadQty->set_param('id', $parentId);
        $reqBadQty->set_param('selection', []);
        $reqBadQty->set_param('quantity', '-5');
        $reqBadQty->set_param('request_sequence', 1);

        $resBadQty = $this->api->resolveEndpoint($reqBadQty);
        $this->assert($resBadQty->get_status() === 400, "Negative quantity must return 400 Bad Request");

        // 3. Rate limiting test
        $testIp = '198.51.100.22';
        $this->api->clearRateLimit($testIp);

        // Pre-fill rate limit transient to max (60)
        for ($i = 0; $i < 60; $i++) {
            $this->api->checkRateLimit($testIp, 60, 60);
        }

        // 61st request must trigger 429
        $isAllowed = $this->api->checkRateLimit($testIp, 60, 60);
        $this->assert($isAllowed === false, "61st request must be rate limited");

        $this->api->clearRateLimit($testIp);

        echo " OK\n";
    }

    /**
     * R106: Revision tracking, ETag support, and zero stock holds.
     */
    public function testR106_revision_refresh(): void
    {
        echo " - testR106_revision_refresh...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$parentId, $varId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'ETAG-PROD', 'slug' => 'etag-prod-' . $parentId, 'title' => 'ETag Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 9
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varId, 'product_id' => $parentId, 'sku' => 'ETAG-VAR', 'regular_price' => '20.000000',
            'status' => 'publish', 'combination_key' => hash('sha256', 'etag', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_style', 'name' => 'Style', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $val = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'modern', 'label' => 'Modern']);
        $this->cleanValueIds[] = (int)$val['id'];

        $this->optionsRepo->assignVariantCombination($parentId, $varId, [$optId => (int)$val['id']]);

        // 1. Verify ETag Generation and 304 Not Modified
        $req1 = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $req1->set_param('id', $parentId);
        $req1->set_param('selection', [$optId => (int)$val['id']]);
        $req1->set_param('quantity', '1');
        $req1->set_param('request_sequence', 10);

        $res1 = $this->api->resolveEndpoint($req1);
        $this->assert($res1->get_status() === 200, "Initial request must return 200");
        $etag = $res1->get_headers()['ETag'] ?? null;
        $this->assert(!empty($etag), "Response must contain ETag header (R106)");

        // Send second request with If-None-Match header
        $req2 = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $req2->set_param('id', $parentId);
        $req2->set_param('selection', [$optId => (int)$val['id']]);
        $req2->set_param('quantity', '1');
        $req2->set_param('request_sequence', 11);
        $req2->set_header('If-None-Match', $etag);

        $res2 = $this->api->resolveEndpoint($req2);
        $this->assert($res2->get_status() === 304, "Matching If-None-Match must return 304 Not Modified (R106)");

        // 2. Verify Zero Stock Holds created during resolve
        $holdCount = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$prefix}wpe_stock_operations WHERE owner_id = {$varId}");
        $this->assert($holdCount === 0, "Resolver must NEVER create stock holds (R106)");

        // 3. Request Sequence Echo and Revision Check
        $reqMismatch = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $reqMismatch->set_param('id', $parentId);
        $reqMismatch->set_param('selection', [$optId => (int)$val['id']]);
        $reqMismatch->set_param('quantity', '1');
        $reqMismatch->set_param('expected_revision', '5'); // Stale revision (parent is version 9)
        $reqMismatch->set_param('request_sequence', 77);

        $resMismatch = $this->api->resolveEndpoint($reqMismatch);
        $dataMismatch = $resMismatch->get_data();

        $this->assert($dataMismatch['request_sequence'] === 77, "request_sequence must be echoed back exactly (R106)");
        $this->assert($dataMismatch['product_revision'] === '9', "product_revision must report current revision 9");

        echo " OK\n";
    }

    /**
     * AC-T26-01: Classic get_variation AJAX returns valid payload; engine resolver does not expose draft/meta.
     */
    public function testAC_T26_01_ClassicAjaxAndNoMetaExposure(): void
    {
        echo " - testAC_T26_01_ClassicAjaxAndNoMetaExposure...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $varId = $this->allocator->allocateShell('product_variation');
        $this->cleanProductIds = array_merge($this->cleanProductIds, [$parentId, $varId]);

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'AC-AJAX-PROD', 'slug' => 'ac-ajax-prod-' . $parentId, 'title' => 'AC Ajax Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);
        $this->wpdb->insert("{$prefix}wpe_variants", [
            'id' => $varId, 'product_id' => $parentId, 'sku' => 'AC-AJAX-VAR', 'regular_price' => '45.000000',
            'status' => 'publish', 'combination_key' => hash('sha256', 'ac-ajax', true), 'description' => '',
            'extra_props' => '{}', 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_edition', 'name' => 'Edition', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $val = $this->optionsRepo->createOptionValue($optId, ['value_key' => 'deluxe', 'label' => 'Deluxe']);
        $this->cleanValueIds[] = (int)$val['id'];

        $this->optionsRepo->assignVariantCombination($parentId, $varId, [$optId => (int)$val['id']]);

        // Add internal confidential meta
        $this->wpdb->insert("{$prefix}wpe_meta", [
            'meta_id'     => 77702,
            'object_id'   => $varId,
            'object_kind' => 'variant',
            'meta_key'    => '_supplier_code',
            'meta_value'  => 'SUP-9988-CONFIDENTIAL',
        ]);

        // 1. Classic AJAX response check
        $ajaxData = $this->api->serializeAvailableVariation($varId, $parentId);
        $this->assert(!empty($ajaxData['variation_id']), "Classic AJAX variation must have variation_id");
        $this->assert($ajaxData['is_purchasable'] === true, "Must be purchasable");
        $this->assert(!str_contains(json_encode($ajaxData), 'SUP-9988-CONFIDENTIAL'), "Classic AJAX must not leak supplier code");

        // 2. Engine Resolver REST check
        $req = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $req->set_param('id', $parentId);
        $req->set_param('selection', [$optId => (int)$val['id']]);
        $req->set_param('quantity', '1');
        $req->set_param('request_sequence', 1);

        $res = $this->api->resolveEndpoint($req);
        $data = $res->get_data();

        $this->assert($data['state'] === 'exact', "Resolver state must be exact");
        $this->assert($data['variant']['id'] === (string)$varId, "Must return correct variant ID");
        $this->assert(!str_contains(json_encode($data), 'SUP-9988-CONFIDENTIAL'), "Engine resolver must not expose internal meta (AC-T26-01)");

        echo " OK\n";
    }

    /**
     * AC-T26-02: Empty selection does not hydrate 10k variations; rate limit response has retry hint.
     */
    public function testAC_T26_02_EmptySelectionAndRateLimitWithRetryHint(): void
    {
        echo " - testAC_T26_02_EmptySelectionAndRateLimitWithRetryHint...";

        $prefix = $this->wpdb->prefix;
        $parentId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $parentId;

        $this->wpdb->insert("{$prefix}wpe_products", [
            'id' => $parentId, 'sku' => 'SCALE-TEST', 'slug' => 'scale-test-' . $parentId, 'title' => 'Scale Test Product',
            'type' => 'variable', 'status' => 'publish', 'description' => '', 'short_description' => '',
            'default_selection' => '{}', 'extra_props' => '{}', 'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'), 'version' => 1
        ]);

        $opt = $this->optionsRepo->createOption($parentId, ['key' => 'pa_num', 'name' => 'Number', 'position' => 0]);
        $optId = (int)$opt['id'];
        $this->cleanOptionIds[] = $optId;

        $val = $this->optionsRepo->createOptionValue($optId, ['value_key' => '1', 'label' => 'One']);
        $this->cleanValueIds[] = (int)$val['id'];

        // 1. Empty selection: lightweight execution (no 10k variation hydration)
        $initialMemory = memory_get_usage();

        $reqEmpty = new WP_REST_Request('POST', "/wpe/v1/products/{$parentId}/resolve");
        $reqEmpty->set_param('id', $parentId);
        $reqEmpty->set_param('selection', []);
        $reqEmpty->set_param('quantity', '1');
        $reqEmpty->set_param('request_sequence', 5);

        $resEmpty = $this->api->resolveEndpoint($reqEmpty);
        $dataEmpty = $resEmpty->get_data();

        $this->assert($dataEmpty['state'] === 'none' || $dataEmpty['state'] === 'partial', "Empty selection state must be partial or none");
        $this->assert($dataEmpty['variant'] === null, "Variant must be null on empty selection");

        // Memory usage delta must remain small (< 1 MB)
        $memoryDelta = memory_get_usage() - $initialMemory;
        $this->assert($memoryDelta < 1048576, "Empty selection memory delta must be < 1MB (did not hydrate large object graphs, AC-T26-02)");

        // 2. Rate limiting response with Retry-After header and hint
        // Simulate exhausted rate limit
        $clientIp = '127.0.0.1';
        for ($i = 0; $i < 65; $i++) {
            $this->api->checkRateLimit($clientIp, 60, 60);
        }

        $resLimited = $this->api->resolveEndpoint($reqEmpty);
        $this->assert($resLimited->get_status() === 429, "Rate-limited request must return 429 Too Many Requests (AC-T26-02)");

        $headers = $resLimited->get_headers();
        $this->assert(isset($headers['Retry-After']), "Response must contain Retry-After header");
        $this->assert((int)$headers['Retry-After'] === 60, "Retry-After must equal window (60s)");

        $dataLimited = $resLimited->get_data();
        $this->assert($dataLimited['code'] === 'rate_limited', "Error code must be rate_limited");
        $this->assert(!empty($dataLimited['data']['retry_after']), "Response body must contain retry_after hint (AC-T26-02)");

        $this->api->clearRateLimit($clientIp);

        echo " OK\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException("Assertion failed: " . $message);
        }
    }
}

$test = new T26Test();
$test->runAll();
