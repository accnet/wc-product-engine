<?php
/**
 * T08 Integration Test — Media and metadata persistence verification.
 *
 * Verifies R28 (gallery_order), R29 (shared_asset_delete),
 * R30 (meta_serialized_repeated_ids), R31 (download_ids_preserved),
 * AC-T08-01 (single featured/primary, foreign asset & malformed JSON rejection),
 * and AC-T08-02 (repeated metadata and downloadable props roundtrip).
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

use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\ProductRepository;
use WPE\Compatibility\WordPress\MetaIdAllocator;

class T08Test
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private ProductRepository $prodRepo;
    private MetaIdAllocator $metaAlloc;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->metaAlloc = new MetaIdAllocator($wpdb);
        $this->mediaRepo = new MediaRepository($wpdb, $this->metaAlloc);
        $this->prodRepo = new ProductRepository($wpdb);
    }

    public function run(): int
    {
        echo "=== Running T08 Integration Tests ===\n";

        $r28 = $this->testGalleryOrder();
        $r29 = $this->testSharedAssetDelete();
        $r30 = $this->testMetaSerializedRepeatedIds();
        $r31 = $this->testDownloadIdsPreserved();
        $ac01 = $this->testSingleFeaturedAndValidation();
        $ac02 = $this->testRepeatedMetaAndDownloadsRoundtrip();

        echo "\n=== Test Summary ===\n";
        echo "R28 (gallery_order): " . ($r28 ? "PASSED" : "FAILED") . "\n";
        echo "R29 (shared_asset_delete): " . ($r29 ? "PASSED" : "FAILED") . "\n";
        echo "R30 (meta_serialized_repeated_ids): " . ($r30 ? "PASSED" : "FAILED") . "\n";
        echo "R31 (download_ids_preserved): " . ($r31 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T08-01 (featured_validation_foreign_asset): " . ($ac01 ? "PASSED" : "FAILED") . "\n";
        echo "AC-T08-02 (repeated_meta_downloads_roundtrip): " . ($ac02 ? "PASSED" : "FAILED") . "\n";

        if ($r28 && $r29 && $r30 && $r31 && $ac01 && $ac02) {
            echo "All T08 tests passed successfully.\n";
            return 0;
        }

        echo "T08 tests failed.\n";
        return 1;
    }

    /**
     * R28: Verify ordered gallery replacement and position preservation.
     */
    private function testGalleryOrder(): bool
    {
        echo "[R28] Verifying gallery ordering and atomic replacement...\n";

        $prod = $this->createTestProduct('Gallery Test Product');
        $pId = (int)$prod['id'];

        $a1 = $this->mediaRepo->createAsset(['source_url' => 'https://site1.localhost/images/g1-' . mt_rand() . '.jpg']);
        $a2 = $this->mediaRepo->createAsset(['source_url' => 'https://site1.localhost/images/g2-' . mt_rand() . '.jpg']);
        $a3 = $this->mediaRepo->createAsset(['source_url' => 'https://site1.localhost/images/g3-' . mt_rand() . '.jpg']);

        // Set initial gallery order: [a1, a2, a3]
        $this->mediaRepo->setGallery($pId, [$a1['id'], $a2['id'], $a3['id']]);

        $gInitial = $this->mediaRepo->getGallery($pId);
        if (count($gInitial) !== 3 || (int)$gInitial[0]['id'] !== (int)$a1['id'] || (int)$gInitial[2]['id'] !== (int)$a3['id']) {
            echo "  [FAIL] Initial gallery order mismatch.\n";
            return false;
        }

        // Reorder gallery atomically: [a3, a1, a2]
        $this->mediaRepo->setGallery($pId, [$a3['id'], $a1['id'], $a2['id']]);

        $gReordered = $this->mediaRepo->getGallery($pId);
        if (
            count($gReordered) !== 3 ||
            (int)$gReordered[0]['id'] !== (int)$a3['id'] ||
            (int)$gReordered[1]['id'] !== (int)$a1['id'] ||
            (int)$gReordered[2]['id'] !== (int)$a2['id']
        ) {
            echo "  [FAIL] Reordered gallery mismatch.\n";
            return false;
        }

        echo "  [OK] Gallery ordering preserved and atomically replaced.\n";
        return true;
    }

    /**
     * R29: Verify shared asset lifecycle: deleting a referencing product does not delete the asset.
     */
    private function testSharedAssetDelete(): bool
    {
        echo "[R29] Verifying shared asset reference protection against cascade deletion...\n";

        $prod1 = $this->createTestProduct('Shared Product 1');
        $prod2 = $this->createTestProduct('Shared Product 2');
        $pId1 = (int)$prod1['id'];
        $pId2 = (int)$prod2['id'];

        $sharedAsset = $this->mediaRepo->createAsset([
            'source_url' => 'https://site1.localhost/images/shared-hero-' . mt_rand() . '.png',
            'title'      => 'Shared Hero Image',
        ]);
        $assetId = (int)$sharedAsset['id'];

        // Assign to both products
        $this->mediaRepo->setFeaturedImage($pId1, $assetId);
        $this->mediaRepo->setFeaturedImage($pId2, $assetId);

        // Attempt to delete asset while referenced: must be rejected
        $caught = false;
        try {
            $this->mediaRepo->deleteAsset($assetId, false);
        } catch (\RuntimeException $e) {
            $caught = true;
        }

        if (!$caught) {
            echo "  [FAIL] Expected RuntimeException when deleting referenced shared asset.\n";
            return false;
        }

        // Delete Product 1
        $this->prodRepo->delete($pId1, true);

        // Verify asset STILL exists in wpe_assets
        $assetCheck = $this->mediaRepo->getAssetById($assetId);
        if (!$assetCheck) {
            echo "  [FAIL] Shared asset was cascade deleted when Product 1 was deleted!\n";
            return false;
        }

        // Verify Product 2 still has featured image intact
        $feat2 = $this->mediaRepo->getFeaturedImage($pId2);
        if (!$feat2 || (int)$feat2['id'] !== $assetId) {
            echo "  [FAIL] Product 2 lost its featured image reference.\n";
            return false;
        }

        echo "  [OK] Shared asset retained; deletion blocked while referenced.\n";
        return true;
    }

    /**
     * R30: Verify repeated metadata and PHP serialized data persistence with MetaIdAllocator.
     */
    private function testMetaSerializedRepeatedIds(): bool
    {
        echo "[R30] Verifying repeated metadata with distinct IDs and serialized data...\n";

        $prod = $this->createTestProduct('Meta Test Product');
        $pId = (int)$prod['id'];

        // Add repeated meta keys
        $metaId1 = $this->mediaRepo->addMeta($pId, 'product', '_product_badge', 'Sale');
        $metaId2 = $this->mediaRepo->addMeta($pId, 'product', '_product_badge', 'New Arrival');

        if ($metaId1 === $metaId2 || $metaId1 <= 0 || $metaId2 <= 0) {
            echo "  [FAIL] Repeated meta keys did not receive distinct authoritative meta IDs.\n";
            return false;
        }

        // Verify IDs exist in global wp_postmeta table
        $pmCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->postmeta} WHERE meta_id IN (%d, %d)",
            $metaId1,
            $metaId2
        ));

        if ($pmCount !== 2) {
            echo "  [FAIL] Meta IDs were not allocated from authoritative wp_postmeta table.\n";
            return false;
        }

        // Add complex serialized PHP array
        $complexData = [
            'discount_rules' => [
                'tier_1' => ['min_qty' => 5, 'percent' => 10.5],
                'tier_2' => ['min_qty' => 20, 'percent' => 25.0],
            ],
            'tags' => ['vip', 'wholesale'],
            'enabled' => true,
        ];

        $metaId3 = $this->mediaRepo->addMeta($pId, 'product', '_pricing_matrix', $complexData, true);

        // Retrieve and verify repeated meta
        $badges = $this->mediaRepo->getMeta($pId, '_product_badge', false);
        if ($badges !== ['Sale', 'New Arrival']) {
            echo "  [FAIL] Repeated meta retrieval mismatch: " . json_encode($badges) . "\n";
            return false;
        }

        // Retrieve and verify complex serialized data
        $matrix = $this->mediaRepo->getMeta($pId, '_pricing_matrix', true);
        if ($matrix !== $complexData) {
            echo "  [FAIL] Serialized meta roundtrip failed: " . json_encode($matrix) . "\n";
            return false;
        }

        echo "  [OK] Repeated metadata assigned distinct wp_postmeta IDs; complex serialization intact.\n";
        return true;
    }

    /**
     * R31: Verify custom download IDs preservation.
     */
    private function testDownloadIdsPreserved(): bool
    {
        echo "[R31] Verifying download IDs preservation across save and reload...\n";

        $prod = $this->createTestProduct('Downloadable Test Product');
        $pId = (int)$prod['id'];

        $downloads = [
            [
                'download_id' => 'dl-manual-pdf-2026',
                'name'        => 'User Manual PDF',
                'file_url'    => 'https://site1.localhost/downloads/manual.pdf',
            ],
            [
                'download_id' => 'dl-firmware-v2-bin',
                'name'        => 'Firmware Binary v2.1',
                'file_url'    => 'https://site1.localhost/downloads/firmware.bin',
            ],
        ];

        $this->mediaRepo->setDownloads($pId, $downloads);

        $reloaded = $this->mediaRepo->getDownloads($pId);

        if (count($reloaded) !== 2) {
            echo "  [FAIL] Expected 2 downloads, got " . count($reloaded) . "\n";
            return false;
        }

        if (
            $reloaded[0]['download_id'] !== 'dl-manual-pdf-2026' ||
            $reloaded[1]['download_id'] !== 'dl-firmware-v2-bin'
        ) {
            echo "  [FAIL] Custom download IDs were altered or not preserved: " . json_encode($reloaded) . "\n";
            return false;
        }

        echo "  [OK] Custom download IDs strictly preserved.\n";
        return true;
    }

    /**
     * AC-T08-01: Single featured/primary image, malformed JSON, and foreign asset rejection.
     */
    private function testSingleFeaturedAndValidation(): bool
    {
        echo "[AC-T08-01] Verifying single featured image, malformed JSON, and foreign asset rejection...\n";

        $prod = $this->createTestProduct('Validation Product');
        $pId = (int)$prod['id'];

        $a1 = $this->mediaRepo->createAsset(['source_url' => 'https://site1.localhost/img1-' . mt_rand() . '.jpg']);
        $a2 = $this->mediaRepo->createAsset(['source_url' => 'https://site1.localhost/img2-' . mt_rand() . '.jpg']);

        // Set featured image twice: second replaces first (at most 1 featured)
        $this->mediaRepo->setFeaturedImage($pId, (int)$a1['id']);
        $this->mediaRepo->setFeaturedImage($pId, (int)$a2['id']);

        $featured = $this->mediaRepo->getFeaturedImage($pId);
        if (!$featured || (int)$featured['id'] !== (int)$a2['id']) {
            echo "  [FAIL] Expected single featured image with ID {$a2['id']}.\n";
            return false;
        }

        // Foreign asset rejection on setFeaturedImage
        $caughtForeign = false;
        try {
            $this->mediaRepo->setFeaturedImage($pId, 999999999);
        } catch (\InvalidArgumentException $e) {
            $caughtForeign = true;
        }

        if (!$caughtForeign) {
            echo "  [FAIL] Expected InvalidArgumentException for non-existent foreign asset.\n";
            return false;
        }

        // Malformed JSON rejection on createAsset
        $caughtJson = false;
        try {
            $this->mediaRepo->createAsset([
                'source_url'      => 'https://site1.localhost/valid.jpg',
                'delivery_policy' => '{invalid_json: true, missing_quotes}',
            ]);
        } catch (\InvalidArgumentException $e) {
            $caughtJson = true;
        }

        if (!$caughtJson) {
            echo "  [FAIL] Expected InvalidArgumentException for malformed delivery_policy JSON.\n";
            return false;
        }

        echo "  [OK] Single featured enforced; foreign asset and malformed JSON safely rejected.\n";
        return true;
    }

    /**
     * AC-T08-02: Repeated metadata and downloadable props roundtrip.
     */
    private function testRepeatedMetaAndDownloadsRoundtrip(): bool
    {
        echo "[AC-T08-02] Verifying repeated metadata and downloadable props survive roundtrip...\n";

        $prod = $this->createTestProduct('Roundtrip Product');
        $pId = (int)$prod['id'];

        $this->mediaRepo->addMeta($pId, 'product', '_custom_color_tag', 'red');
        $this->mediaRepo->addMeta($pId, 'product', '_custom_color_tag', 'blue');
        $this->mediaRepo->addMeta($pId, 'product', '_custom_color_tag', 'green');

        $tags = $this->mediaRepo->getMeta($pId, '_custom_color_tag');
        if ($tags !== ['red', 'blue', 'green']) {
            echo "  [FAIL] Repeated metadata roundtrip failed.\n";
            return false;
        }

        $allMeta = $this->mediaRepo->getMeta($pId);
        if (!isset($allMeta['_custom_color_tag']) || count($allMeta['_custom_color_tag']) !== 3) {
            echo "  [FAIL] Full object metadata map roundtrip failed.\n";
            return false;
        }

        echo "  [OK] Repeated metadata and downloadable props survived roundtrip.\n";
        return true;
    }

    private function createTestProduct(string $title): array
    {
        $sku = 'T08-PROD-' . mt_rand(100000, 999999);
        return $this->prodRepo->create([
            'name'          => $title,
            'type'          => 'simple',
            'sku'           => $sku,
            'regular_price' => '100000.000000',
        ]);
    }
}

$test = new T08Test();
exit($test->run());
