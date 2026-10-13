<?php

/**
 * P011.7 — Variation image rules.
 *
 * A variation image is assigned by asset id and only from the product's own library; the backend refuses
 * anything else (other product, not linked, random id, unknown URL) and leaves the saved data unchanged. An
 * unchanged legacy image is still accepted. Group and selection previews count the affected variations
 * without writing.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Admin\ProductEditor\ProductEditorApiController;
use WPE\Admin\ProductEditor\ProductEditorService;
use WPE\Application\Media\ProductMediaLibrary;
use WPE\Application\Media\UploadRejectedException;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

class VariantImagePickerRulesTest
{
    private wpdb $wpdb;
    private ProductEditorService $service;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private ProductMediaLibrary $library;
    private array $productIds = [];
    private array $assetIds = [];
    private int $checks = 0;
    private int $seq = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb    = $wpdb;
        $this->service = ProductEditorService::getInstance();
        $this->media   = new MediaRepository($wpdb);
        $this->uploads = new UploadItemRepository($wpdb);
        $this->library = new ProductMediaLibrary($this->media, $this->uploads, $wpdb);
    }

    public function run(): void
    {
        echo "Running variation image rule tests...\n";
        wp_set_current_user(1);
        try {
            $this->testAssignAndChange();
            $this->testRefusals();
            $this->testLegacyAndUrlPath();
            $this->testClear();
            $this->testPreviews();
            $this->testRestMapping();
            echo "All variation image rule tests passed ({$this->checks} checks).\n";
        } finally {
            $this->cleanup();
        }
    }

    private function ok(bool $cond, string $label): void
    {
        $this->checks++;
        if (!$cond) {
            throw new RuntimeException("FAILED: {$label}");
        }
        echo "  [OK] {$label}\n";
    }

    private function reason(callable $fn): ?string
    {
        try {
            $fn();
        } catch (UploadRejectedException $e) {
            return $e->reason();
        }
        return null;
    }

    /** A saved variable product with Color x Size variations. */
    private function product(): int
    {
        $id = wp_insert_post(['post_title' => 'P011 picker ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
        wp_set_object_terms($id, 'variable', 'product_type');
        $this->productIds[] = $id;
        $data = $this->service->getProductEditorData($id);
        $vars = [];
        foreach (['Red', 'Blue'] as $color) {
            foreach (['S', 'M'] as $size) {
                $vars[] = ['combination' => ['Color' => $color, 'Size' => $size], 'sku' => 'P011-' . strtoupper(substr($color, 0, 1)) . $size . '-' . uniqid(), 'regular_price' => 10, 'status' => 'publish'];
            }
        }
        $this->service->saveProductVariants($id, [
            'expected_version'  => $data['product']['version'],
            'variation_options' => [
                ['name' => 'Color', 'position' => 0, 'values' => [['label' => 'Red', 'position' => 0], ['label' => 'Blue', 'position' => 1]]],
                ['name' => 'Size', 'position' => 1, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]],
            ],
            'variations' => $vars,
        ]);
        return $id;
    }

    /** An external asset registered for the product and linked into its library (gallery). */
    private function libraryAsset(int $productId, string $label): int
    {
        $this->seq++;
        $asset = $this->media->createAsset(['source_url' => 'https://cdn.example.test/' . $label . '-' . $this->seq . '-' . bin2hex(random_bytes(3)) . '.jpg', 'mime' => 'image/jpeg', 'alt_text' => $label]);
        $id = (int) $asset['id'];
        $this->assetIds[] = $id;
        $item = $this->uploads->begin(1, 'p011t-pick-' . $this->seq . '-' . bin2hex(random_bytes(3)), 'sess-pick', $productId, $label . '.jpg');
        $this->uploads->markRegistered((int) $item['id'], $id);
        $lib = $this->library->read($productId, 1);
        $this->library->apply($productId, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => $id]]], $lib['version'], 1);
        return $id;
    }

    /** A registered asset that is not in any library. */
    private function looseAsset(string $label): int
    {
        $this->seq++;
        $asset = $this->media->createAsset(['source_url' => 'https://cdn.example.test/' . $label . '-' . $this->seq . '-' . bin2hex(random_bytes(3)) . '.jpg', 'mime' => 'image/jpeg']);
        $this->assetIds[] = (int) $asset['id'];
        return (int) $asset['id'];
    }

    private function save(int $productId, array $overrides = [], array $payloadExtra = []): array
    {
        $data = $this->service->getProductEditorData($productId);
        $vars = [];
        foreach ($data['variations'] as $v) {
            $row = array_replace($v, $overrides[(int) $v['id']] ?? []);
            // An older client sends only image_url: the marker removes the id the editor data carries.
            if (($row['image_asset_id'] ?? null) === '__omit') {
                unset($row['image_asset_id']);
            }
            $vars[] = $row;
        }
        return $this->service->saveProductVariants($productId, $payloadExtra + [
            'expected_version'  => $data['product']['version'],
            'variation_options' => $data['variation_options'],
            'variations'        => $vars,
        ]);
    }

    private function variations(int $productId): array
    {
        $out = [];
        foreach ($this->service->getProductEditorData($productId)['variations'] as $v) {
            $out[(int) $v['id']] = $v;
        }
        return $out;
    }

    private function mediaRows(int $variantId): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT asset_id, position, is_primary FROM {$this->wpdb->prefix}wpe_variant_media WHERE variant_id = %d ORDER BY position", $variantId), ARRAY_A);
    }

    private function version(int $productId): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT version FROM {$this->wpdb->prefix}wpe_products WHERE id = %d", $productId));
    }

    /* ------------------------------------------------------------------ */

    private function testAssignAndChange(): void
    {
        echo "[picker] assign and change\n";
        $pid = $this->product();
        $a   = $this->libraryAsset($pid, 'a');
        $b   = $this->libraryAsset($pid, 'b');
        $vars = $this->variations($pid);
        $ids  = array_keys($vars);
        $this->ok(count($ids) === 4 && $vars[$ids[0]]['image_asset_id'] === null, 'the editor data lists four variations without images');

        $this->save($pid, [$ids[0] => ['image_asset_id' => (string) $a], $ids[1] => ['image_asset_id' => (string) $a]]);
        $vars = $this->variations($pid);
        $this->ok($vars[$ids[0]]['image_asset_id'] === (string) $a && $vars[$ids[1]]['image_asset_id'] === (string) $a, 'a library image is assigned to the chosen variations');
        $this->ok($vars[$ids[0]]['image_url'] !== null && $vars[$ids[2]]['image_asset_id'] === null, 'the url is exposed and other variations are untouched');
        $rows = $this->mediaRows($ids[0]);
        $this->ok(count($rows) === 1 && (int) $rows[0]['is_primary'] === 1 && (int) $rows[0]['position'] === 0, 'the image is the primary at position 0');

        $this->save($pid, [$ids[0] => ['image_asset_id' => (string) $b]]);
        $rows = $this->mediaRows($ids[0]);
        $this->ok((int) $rows[0]['asset_id'] === $b && (int) $rows[0]['is_primary'] === 1 && count($rows) === 2 && (int) $rows[1]['asset_id'] === $a && (int) $rows[1]['is_primary'] === 0, 'changing the image replaces the primary and keeps the old one as secondary (no silent failure)');
        $this->ok($this->variations($pid)[$ids[0]]['image_asset_id'] === (string) $b, 'the editor shows the new primary');
        $this->ok($this->variations($pid)[$ids[1]]['image_asset_id'] === (string) $a, 'another variation keeps its image');
    }

    private function testRefusals(): void
    {
        echo "[picker] refusals\n";
        $pid   = $this->product();
        $other = $this->product();
        $own   = $this->libraryAsset($pid, 'own');
        $theirs = $this->libraryAsset($other, 'theirs');
        $loose = $this->looseAsset('loose');
        $ids   = array_keys($this->variations($pid));
        $this->save($pid, [$ids[0] => ['image_asset_id' => (string) $own]]);
        $before = $this->version($pid);

        foreach ([['an image of another product', $theirs], ['an image that is in no library', $loose], ['an id that does not exist', 999999996]] as [$label, $assetId]) {
            $r = $this->reason(fn () => $this->save($pid, [$ids[1] => ['regular_price' => '33', 'image_asset_id' => (string) $assetId]]));
            $this->ok($r === 'asset_not_in_product', "{$label} is refused");
        }
        $this->ok($this->version($pid) === $before, 'a refused save changed no version');
        $this->ok($this->variations($pid)[$ids[1]]['regular_price'] !== '33' && $this->variations($pid)[$ids[1]]['image_asset_id'] === null, 'a refused save changed nothing else in the same request');

        // A pending upload (registered, not linked) is not in the library either.
        $this->seq++;
        $pending = $this->media->createAsset(['source_url' => 'https://cdn.example.test/pending-' . $this->seq . '-' . bin2hex(random_bytes(3)) . '.jpg', 'mime' => 'image/jpeg']);
        $this->assetIds[] = (int) $pending['id'];
        $item = $this->uploads->begin(1, 'p011t-pick-pend-' . $this->seq, 'sess-pick', $pid, 'pending.jpg');
        $this->uploads->markRegistered((int) $item['id'], (int) $pending['id']);
        $this->ok($this->reason(fn () => $this->save($pid, [$ids[1] => ['image_asset_id' => (string) $pending['id']]])) === 'asset_not_in_product', 'a pending upload cannot be assigned before it is linked');

        foreach (['abc', '0', '-4', '1.5'] as $bad) {
            $this->ok($this->reason(fn () => $this->save($pid, [$ids[1] => ['image_asset_id' => $bad]])) === 'invalid_request', "the id '{$bad}' is invalid");
        }
    }

    private function testLegacyAndUrlPath(): void
    {
        echo "[picker] legacy image and url path\n";
        $pid   = $this->product();
        $lib   = $this->libraryAsset($pid, 'lib');
        $loose = $this->looseAsset('legacy');
        $ids   = array_keys($this->variations($pid));
        // A legacy link (found in existing data) outside the library.
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_variant_media', ['variant_id' => $ids[0], 'asset_id' => $loose, 'position' => 0, 'is_primary' => 1]);

        $this->save($pid);
        $this->ok($this->variations($pid)[$ids[0]]['image_asset_id'] === (string) $loose, 'saving the unchanged editor data keeps a legacy image outside the library');
        $v = $this->variations($pid)[$ids[0]];
        $this->save($pid, [$ids[0] => ['image_asset_id' => $v['image_asset_id'], 'image_url' => $v['image_url']]]);
        $this->ok($this->variations($pid)[$ids[0]]['image_asset_id'] === (string) $loose, 'resending the same image id and url is accepted');
        $this->ok($this->reason(fn () => $this->save($pid, [$ids[0] => ['image_asset_id' => (string) $this->looseAsset('other')]])) === 'asset_not_in_product', 'changing a legacy image to another outside image is refused');

        $libUrl = $this->variations($pid) ? $this->media->getAssetById($lib)['source_url'] : '';
        $this->save($pid, [$ids[1] => ['image_asset_id' => '__omit', 'image_url' => $libUrl]]);
        $this->ok($this->variations($pid)[$ids[1]]['image_asset_id'] === (string) $lib, 'a url of a library image is resolved to that image');
        $before = count($this->assetIds);
        $r = $this->reason(fn () => $this->save($pid, [$ids[2] => ['image_asset_id' => '__omit', 'image_url' => 'https://cdn.example.test/never-seen-' . bin2hex(random_bytes(3)) . '.jpg']]));
        $this->ok($r === 'asset_not_in_product', 'an unknown url is refused (no silent asset registration)');
        $this->ok((int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_assets WHERE source_url LIKE %s", '%never-seen-%')) === 0, 'no asset was created from the refused url');
        $r = $this->reason(fn () => $this->save($pid, [$ids[2] => ['image_asset_id' => '__omit', 'image_url' => $this->media->getAssetById($loose)['source_url']]]));
        $this->ok($r === 'asset_not_in_product', 'a url of an image outside the library is refused');
    }

    private function testClear(): void
    {
        echo "[picker] clear\n";
        $pid = $this->product();
        $a   = $this->libraryAsset($pid, 'c1');
        $ids = array_keys($this->variations($pid));
        $this->save($pid, [$ids[0] => ['image_asset_id' => (string) $a], $ids[1] => ['image_asset_id' => (string) $a]]);
        $this->save($pid, [$ids[0] => ['image_asset_id' => null]]);
        $this->ok($this->variations($pid)[$ids[0]]['image_asset_id'] === null && $this->mediaRows($ids[0]) === [], 'a null id clears the variation image');
        $this->save($pid, [$ids[1] => ['image_asset_id' => '__omit', 'image_url' => '']]);
        $this->ok($this->variations($pid)[$ids[1]]['image_asset_id'] === null, 'an empty url clears it too');
        $this->ok((int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_assets WHERE id = {$a}") === 1, 'clearing keeps the asset and the library');
        $this->ok($this->library->libraryAssetIds($pid) === [$a], 'the library still holds the image');
    }

    private function testPreviews(): void
    {
        echo "[picker] previews\n";
        $pid    = $this->product();
        $other  = $this->product();
        $a      = $this->libraryAsset($pid, 'p1');
        $b      = $this->libraryAsset($pid, 'p2');
        $loose  = $this->looseAsset('pl');
        $vars   = $this->variations($pid);
        $ids    = array_keys($vars);
        $foreign = array_keys($this->variations($other))[0];
        $this->save($pid, [$ids[0] => ['image_asset_id' => (string) $a]]);
        $rowsBefore = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variant_media");

        $one = $this->service->previewImageAssignment($pid, $a, ['variant_ids' => [(string) $ids[1]]]);
        $this->ok($one['scope'] === 'variant' && $one['affected_count'] === 1 && $one['affected'][0]['variant_id'] === (string) $ids[1] && $one['affected'][0]['current_asset_id'] === null, 'one variation');
        $sel = $this->service->previewImageAssignment($pid, $a, ['variant_ids' => [$ids[0], $ids[1], $ids[2]]]);
        $this->ok($sel['scope'] === 'selection' && $sel['selected_count'] === 3 && $sel['affected_count'] === 2 && $sel['unchanged'] === [(string) $ids[0]], 'selected rows: the one that already has the image is unchanged');
        $this->ok(isset($sel['affected'][0]['attributes']['Color']), 'each affected row names its options');
        $grp = $this->service->previewImageAssignment($pid, $b, ['option' => ['name' => 'Color', 'value' => 'Red']]);
        $this->ok($grp['scope'] === 'group' && $grp['selected_count'] === 2 && $grp['affected_count'] === 2, 'an option group counts its variations');
        $grpA = $this->service->previewImageAssignment($pid, $a, ['option' => ['name' => 'Color', 'value' => 'Red']]);
        $this->ok($grpA['selected_count'] === 2 && $grpA['affected_count'] === (in_array((string) $ids[0], array_column($grpA['affected'], 'variant_id'), true) ? 2 : 1), 'a group preview skips rows that already have the image');
        $none = $this->service->previewImageAssignment($pid, $a, ['option' => ['name' => 'Color', 'value' => 'Green']]);
        $this->ok($none['selected_count'] === 0 && $none['affected_count'] === 0, 'an option value without variations affects none');

        $this->ok($this->reason(fn () => $this->service->previewImageAssignment($pid, $loose, ['variant_ids' => [$ids[0]]])) === 'asset_not_in_product', 'the preview refuses an image outside the library');
        $this->ok($this->reason(fn () => $this->service->previewImageAssignment($pid, $a, ['variant_ids' => [$foreign]])) === 'validation_failed', 'the preview refuses a variation of another product');
        $this->ok($this->reason(fn () => $this->service->previewImageAssignment($pid, $a, ['variant_ids' => []])) === 'invalid_request', 'the preview needs a selection');
        $this->ok($this->reason(fn () => $this->service->previewImageAssignment($pid, $a, ['option' => ['name' => 'Color']])) === 'invalid_request', 'the preview needs an option value');
        $this->ok((int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variant_media") === $rowsBefore, 'previews write nothing');

        $bulk = $this->service->previewBulkEdit($pid, [$ids[1], $ids[2]], ['image_asset_id' => (string) $b]);
        $this->ok($bulk['affected_count'] === 2 && $bulk['diffs'][0]['changes']['image_asset_id']['after'] === (string) $b, 'the bulk preview carries the image id change');
        $this->ok($this->reason(fn () => $this->service->previewBulkEdit($pid, [$ids[1]], ['image_asset_id' => (string) $loose])) === 'asset_not_in_product', 'the bulk preview refuses an image outside the library');
    }

    private function testRestMapping(): void
    {
        echo "[picker] REST mapping\n";
        $pid   = $this->product();
        $loose = $this->looseAsset('rest');
        $data  = $this->service->getProductEditorData($pid);
        $vars  = $data['variations'];
        $vars[0]['image_asset_id'] = (string) $loose;
        $api = new ProductEditorApiController();

        $request = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants");
        $request->set_param('id', $pid);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode(['expected_version' => $data['product']['version'], 'variation_options' => $data['variation_options'], 'variations' => $vars]));
        $res = $api->saveProductVariants($request);
        $d   = $res->get_data();
        $this->ok($res->get_status() === 422 && $d['success'] === false && $d['code'] === 'asset_not_in_product' && $d['details'][0]['pointer'] === '/variations/0/image_asset_id', 'a refused image is a 422 with its code and the row pointer');

        $request = new WP_REST_Request('POST', "/wpe/v1/admin/product/{$pid}/variants/preview-image-assignment");
        $request->set_param('id', $pid);
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode(['asset_id' => (string) $loose, 'variant_ids' => [$vars[0]['id']]]));
        $res = $api->previewImageAssignment($request);
        $this->ok($res->get_status() === 422 && $res->get_data()['code'] === 'asset_not_in_product', 'the preview route refuses an image outside the library');

        $a = $this->libraryAsset($pid, 'rest-ok');
        $request->set_body(wp_json_encode(['asset_id' => (string) $a, 'variant_ids' => [$vars[0]['id'], $vars[1]['id']]]));
        $res = $api->previewImageAssignment($request);
        $this->ok($res->get_status() === 200 && $res->get_data()['result']['affected_count'] === 2, 'the preview route answers with the counts');
        $request->set_body(wp_json_encode(['asset_id' => 12, 'variant_ids' => [1]]));
        $this->ok($api->previewImageAssignment($request)->get_status() === 400, 'the preview route needs a digit string asset id');
        $routes = rest_get_server()->get_routes();
        $this->ok(isset($routes['/wpe/v1/admin/product/(?P<id>\d+)/variants/preview-image-assignment']), 'the preview route is registered');
    }

    /* ------------------------------------------------------------------ */

    private function cleanup(): void
    {
        $p = $this->wpdb->prefix;
        foreach ($this->productIds as $id) {
            $variantIds = $this->wpdb->get_col($this->wpdb->prepare("SELECT id FROM {$p}wpe_variants WHERE product_id = %d", $id));
            if ($variantIds) {
                $list = implode(',', array_map('intval', $variantIds));
                $this->wpdb->query("DELETE FROM {$p}wpe_variant_media WHERE variant_id IN ({$list})");
                $this->wpdb->query("DELETE FROM {$p}wpe_inventory WHERE owner_id IN ({$list})");
                $this->wpdb->query("DELETE FROM {$p}wpe_sku_registry WHERE object_id IN ({$list})");
            }
            $this->wpdb->delete("{$p}wpe_product_media", ['product_id' => $id]);
            $this->wpdb->delete("{$p}wpe_inventory", ['owner_id' => $id]);
            $this->wpdb->delete("{$p}wpe_variants", ['product_id' => $id]);
            $this->wpdb->delete("{$p}wpe_options", ['product_id' => $id]);
            $this->wpdb->delete("{$p}wpe_products", ['id' => $id]);
            wp_delete_post($id, true);
        }
        $this->wpdb->query("DELETE FROM {$p}wpe_upload_items WHERE upload_key LIKE 'p011t-%'");
        foreach (array_unique($this->assetIds) as $id) {
            $this->media->deleteAssetStorage((int) $id);
            $this->media->deleteAsset((int) $id, true);
        }
    }
}

try {
    (new VariantImagePickerRulesTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
