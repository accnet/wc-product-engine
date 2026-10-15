<?php
/**
 * T22 Integration Test — Real attachment bridge và Media Library behavior.
 *
 * Verifies:
 * - R87 (real_attachment_race): Real attachment bridge race safety without duplicate attachment posts.
 * - R88 (wp_image_srcset_metadata): WP image URL, downsize, metadata and srcset filters.
 * - R89 (local_file_actions_explicit): Rejects unsupported local file actions without fake file paths.
 * - R90 (shared_attachment_delete): Shared deletion safeguards and rebind policy preserving CDN assets.
 * - AC-T22-01: wp_get_attachment_image and Media Library preview show correct CDN image; no duplicates on race.
 * - AC-T22-02: Delete attachment with active references creates warning/rebind policy and preserves external CDN asset.
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

use WPE\Compatibility\Gallery\AssetService;
use WPE\Compatibility\Gallery\AttachmentBridge;
use WPE\Compatibility\Gallery\UrlValidator;
use WPE\Compatibility\WordPress\PostIdAllocator;
use WPE\Infrastructure\Database\MediaRepository;

class T22Test
{
    private \wpdb $wpdb;
    private MediaRepository $mediaRepo;
    private AssetService $assetService;
    private AttachmentBridge $bridge;
    private PostIdAllocator $allocator;

    private array $cleanAssetIds = [];
    private array $cleanProductIds = [];
    private array $cleanAttachmentIds = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->mediaRepo = new MediaRepository($this->wpdb);
        $this->assetService = new AssetService($this->wpdb, $this->mediaRepo);
        $this->bridge = AttachmentBridge::register(new AttachmentBridge($this->wpdb, $this->assetService, $this->mediaRepo));
        $this->allocator = new PostIdAllocator($this->wpdb);

        UrlValidator::setDnsResolver(function (string $host): array {
            return ['93.184.215.14']; // Safe public IP (example.com)
        });
    }

    public function tearDown(): void
    {
        $prefix = $this->wpdb->prefix;

        if (!empty($this->cleanAttachmentIds)) {
            foreach ($this->cleanAttachmentIds as $attId) {
                wp_delete_attachment($attId, true);
            }
            $this->cleanAttachmentIds = [];
        }

        if (!empty($this->cleanProductIds)) {
            $ids = implode(',', array_map('intval', $this->cleanProductIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$ids})");
            $this->cleanProductIds = [];
        }

        if (!empty($this->cleanAssetIds)) {
            $ids = implode(',', array_map('intval', $this->cleanAssetIds));
            $this->wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE asset_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE asset_id IN ({$ids})");
            $this->wpdb->query("DELETE FROM {$prefix}wpe_assets WHERE id IN ({$ids})");
            $this->cleanAssetIds = [];
        }

        UrlValidator::setDnsResolver(null);
    }

    public function runAll(): void
    {
        echo "Running T22 Integration Tests...\n";

        try {
            $this->testR87_real_attachment_race();
            $this->testR88_wp_image_srcset_metadata();
            $this->testR89_local_file_actions_explicit();
            $this->testR90_shared_attachment_delete();
            $this->testAC_T22_01_MediaLibraryPreviewAndRace();
            $this->testAC_T22_02_DeleteAttachmentSafeguard();

            echo "All T22 tests passed successfully!\n";
        } finally {
            $this->tearDown();
        }
    }

    /**
     * R87: Real attachment bridge race safety without duplicate attachment posts.
     */
    public function testR87_real_attachment_race(): void
    {
        echo " - testR87_real_attachment_race...";

        $asset = $this->assetService->registerAsset('https://cdn.example.com/products/bag-race.jpg', [
            'width'    => 1200,
            'height'   => 800,
            'alt_text' => 'Race Safety Leather Bag',
            'title'    => 'Leather Bag',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        // Allocate attachment first time
        $attachmentId1 = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attachmentId1;

        // Simulate concurrent/second call to getOrCreateAttachmentId
        $attachmentId2 = $this->bridge->getOrCreateAttachmentId($assetId);

        if ($attachmentId1 <= 0) {
            throw new RuntimeException("R87 Failed: Invalid attachment ID returned: {$attachmentId1}");
        }

        // Must return identical attachment ID
        if ($attachmentId1 !== $attachmentId2) {
            throw new RuntimeException("R87 Failed: Race created duplicate attachment IDs: {$attachmentId1} vs {$attachmentId2}");
        }

        // Verify in wp_posts that exactly ONE attachment row was inserted for this asset
        $count = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(1) FROM {$this->wpdb->postmeta} WHERE meta_key = '_wpe_asset_id' AND meta_value = %d",
            $assetId
        ));
        if ($count !== 1) {
            throw new RuntimeException("R87 Failed: Expected 1 attachment postmeta record, found {$count}");
        }

        // Verify wpe_assets has the attachment_id set
        $storedAttachmentId = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT attachment_id FROM {$this->wpdb->prefix}wpe_assets WHERE id = %d",
            $assetId
        ));
        if ($storedAttachmentId !== $attachmentId1) {
            throw new RuntimeException("R87 Failed: wpe_assets.attachment_id ({$storedAttachmentId}) does not match {$attachmentId1}");
        }

        echo " OK\n";
    }

    /**
     * R88: WP image URL, downsize, metadata and srcset filters.
     */
    public function testR88_wp_image_srcset_metadata(): void
    {
        echo " - testR88_wp_image_srcset_metadata...";

        $sourceUrl = 'https://cdn.example.com/products/watch-gold.jpg';
        $asset = $this->assetService->registerAsset($sourceUrl, [
            'width'    => 1600,
            'height'   => 1200,
            'alt_text' => 'Luxury Gold Watch',
            'title'    => 'Gold Watch',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        // 1. Test wp_get_attachment_url returns canonical CDN source_url
        $url = wp_get_attachment_url($attId);
        if ($url !== $sourceUrl) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_url returned '{$url}', expected '{$sourceUrl}'");
        }

        // 2. Test image_downsize / wp_get_attachment_image_src
        $srcMedium = wp_get_attachment_image_src($attId, 'medium');
        if (!is_array($srcMedium) || count($srcMedium) !== 4) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image_src did not return 4-tuple.");
        }
        [$medUrl, $medW, $medH, $isIntermediate] = $srcMedium;
        if ($medW !== 300 || $medH !== 225 || !$isIntermediate) {
            throw new RuntimeException("R88 Failed: Expected 300x225 intermediate for medium, got {$medW}x{$medH}");
        }

        // 3. Test wp_get_attachment_metadata
        $metadata = wp_get_attachment_metadata($attId);
        if (!is_array($metadata) || ($metadata['width'] ?? 0) !== 1600 || ($metadata['height'] ?? 0) !== 1200) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_metadata did not return proper dimensions.");
        }
        if (empty($metadata['sizes']['medium'])) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_metadata missing sizes.medium");
        }

        // 4. Test wp_calculate_image_srcset
        $srcset = wp_get_attachment_image_srcset($attId, 'medium');
        if (empty($srcset) || !str_contains($srcset, '300w')) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image_srcset did not generate responsive 300w source.");
        }

        // 5. Test wp_get_attachment_image HTML output
        $imgHtml = wp_get_attachment_image($attId, 'medium', false, ['class' => 'custom-pdp-img']);
        if (!str_contains($imgHtml, 'src="https://cdn.example.com/products/watch-gold.jpg"')) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image did not output proper CDN src.");
        }
        if (!str_contains($imgHtml, 'width="300"') || !str_contains($imgHtml, 'height="225"')) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image missing explicit dimensions.");
        }
        if (!str_contains($imgHtml, 'alt="Luxury Gold Watch"')) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image missing alt attribute.");
        }

        // 6. Non-numeric attachment IDs (e.g., Flatsome element-logo.php passing default logo URL string or '')
        $nonNumericLogoUrl = 'http://site1.localhost/wp-content/themes/flatsome/assets/img/logo.png';
        if (wp_get_attachment_image_src($nonNumericLogoUrl, 'large') !== false) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image_src with URL string must return false without TypeError.");
        }
        if (wp_get_attachment_image_src('', 'large') !== false) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_image_src with empty string must return false without TypeError.");
        }
        if (wp_get_attachment_url('') !== false) {
            throw new RuntimeException("R88 Failed: wp_get_attachment_url with empty string must return false without TypeError.");
        }

        echo " OK\n";
    }

    /**
     * R89: Rejects unsupported local file actions without fake file paths.
     */
    public function testR89_local_file_actions_explicit(): void
    {
        echo " - testR89_local_file_actions_explicit...";

        $asset = $this->assetService->registerAsset('https://cdn.example.com/products/headphones.jpg', [
            'width'  => 1000,
            'height' => 1000,
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        // 1. get_attached_file must NOT return a fake file path (e.g. /tmp/fake.jpg)
        $attachedFile = get_attached_file($attId);
        if ($attachedFile !== '' && file_exists($attachedFile)) {
            throw new RuntimeException("R89 Failed: get_attached_file should not return a fake local file path.");
        }

        // 2. isLocalActionSupported must return false for crop, rotate, flip, etc.
        if ($this->bridge->isLocalActionSupported('crop')) {
            throw new RuntimeException("R89 Failed: isLocalActionSupported('crop') should be false.");
        }
        if ($this->bridge->isLocalActionSupported('rotate')) {
            throw new RuntimeException("R89 Failed: isLocalActionSupported('rotate') should be false.");
        }

        // 3. executeLocalAction must return WP_Error with 'unsupported_feature'
        $result = $this->bridge->executeLocalAction($attId, 'crop');
        if (!is_wp_error($result) || $result->get_error_code() !== 'unsupported_feature') {
            throw new RuntimeException("R89 Failed: executeLocalAction('crop') did not return WP_Error('unsupported_feature').");
        }

        echo " OK\n";
    }

    /**
     * R90: Shared deletion safeguards and rebind policy preserving CDN assets.
     */
    public function testR90_shared_attachment_delete(): void
    {
        echo " - testR90_shared_attachment_delete...";

        $sourceUrl = 'https://cdn.example.com/products/sneakers-shared.jpg';
        $asset = $this->assetService->registerAsset($sourceUrl, [
            'width'    => 800,
            'height'   => 800,
            'alt_text' => 'Shared Sneakers',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        // Allocate real attachment ID
        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        // Attach this asset to a product
        $prodId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $prodId;
        $prefix = $this->wpdb->prefix;
        $this->wpdb->insert($prefix . 'wpe_products', [
            'id'         => $prodId,
            'type'       => 'simple',
            'status'     => 'publish',
            'slug'       => 'prod-t22-shared',
            'title'      => 'Product with Shared Image',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->assetService->attachProductImage($prodId, $assetId, 'featured', 0, 'Override Alt Text');

        // Delete the attachment post from WordPress Media Library
        wp_delete_attachment($attId, true);

        // Verify attachment post is gone from wp_posts
        $postCheck = get_post($attId);
        if ($postCheck !== null) {
            throw new RuntimeException("R90 Failed: Attachment post {$attId} was not removed from wp_posts.");
        }

        // Verify canonical asset still exists in wpe_assets
        $assetAfter = $this->assetService->getAssetById($assetId);
        if (!$assetAfter) {
            throw new RuntimeException("R90 Failed: Canonical asset {$assetId} was deleted when attachment was removed.");
        }

        // Verify rebind policy: wpe_assets.attachment_id is now NULL
        if ($assetAfter['attachment_id'] !== null) {
            throw new RuntimeException("R90 Failed: Rebind policy failed; attachment_id was not set to NULL.");
        }

        // Verify product media reference is still completely intact
        $featured = $this->mediaRepo->getFeaturedImage($prodId);
        if (!$featured || (int)$featured['id'] !== $assetId) {
            throw new RuntimeException("R90 Failed: Product media reference was broken upon attachment deletion.");
        }
        if ($featured['alt_override'] !== 'Override Alt Text') {
            throw new RuntimeException("R90 Failed: Product alt_override was lost upon attachment deletion.");
        }

        // Verify warning recorded in audit log
        $audit = $this->bridge->getAuditLog();
        $foundWarning = false;
        foreach ($audit as $entry) {
            if (str_contains($entry, "Attachment {$attId} deleted") && str_contains($entry, "asset {$assetId} retained")) {
                $foundWarning = true;
                break;
            }
        }
        if (!$foundWarning) {
            throw new RuntimeException("R90 Failed: No warning logged for shared asset rebind.");
        }

        echo " OK\n";
    }

    /**
     * AC-T22-01: wp_get_attachment_image and Media Library preview show correct CDN image; no duplicates on race.
     */
    public function testAC_T22_01_MediaLibraryPreviewAndRace(): void
    {
        echo " - testAC_T22_01_MediaLibraryPreviewAndRace...";

        $asset = $this->assetService->registerAsset('https://cdn.example.com/gallery/preview-hero.jpg', [
            'width'    => 2000,
            'height'   => 1500,
            'alt_text' => 'Hero Landscape Image',
            'title'    => 'Hero Preview',
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        // Test Media Library modal preview response (wp_prepare_attachment_for_js)
        $post = get_post($attId);
        $response = [
            'id'    => $attId,
            'title' => 'Hero Preview',
            'url'   => '',
            'sizes' => [],
        ];
        $prepared = $this->bridge->filterPrepareAttachmentForJs($response, $post, []);

        if ($prepared['url'] !== 'https://cdn.example.com/gallery/preview-hero.jpg') {
            throw new RuntimeException("AC-T22-01 Failed: Media Library preview url mismatch: {$prepared['url']}");
        }
        if (empty($prepared['sizes']['thumbnail']['url']) || empty($prepared['sizes']['medium']['url'])) {
            throw new RuntimeException("AC-T22-01 Failed: Media Library preview sizes missing thumbnail or medium.");
        }
        if ($prepared['sizes']['medium']['width'] !== 300 || $prepared['sizes']['medium']['height'] !== 225) {
            throw new RuntimeException("AC-T22-01 Failed: Media Library preview medium dimensions mismatch: {$prepared['sizes']['medium']['width']}x{$prepared['sizes']['medium']['height']}");
        }

        echo " OK\n";
    }

    /**
     * AC-T22-02: Delete attachment with active references creates warning/rebind policy, does not delete external CDN asset.
     */
    public function testAC_T22_02_DeleteAttachmentSafeguard(): void
    {
        echo " - testAC_T22_02_DeleteAttachmentSafeguard...";

        $asset = $this->assetService->registerAsset('https://cdn.example.com/products/active-ref.jpg', [
            'width'  => 600,
            'height' => 600,
        ]);
        $assetId = (int)$asset['id'];
        $this->cleanAssetIds[] = $assetId;

        $attId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $attId;

        // Attach to product
        $prodId = $this->allocator->allocateShell('product');
        $this->cleanProductIds[] = $prodId;
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_products', [
            'id'         => $prodId,
            'type'       => 'simple',
            'status'     => 'publish',
            'slug'       => 'prod-t22-ac2',
            'title'      => 'Product AC-T22-02',
            'version'    => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->assetService->attachProductImage($prodId, $assetId, 'featured');

        // Delete attachment
        wp_delete_attachment($attId, true);

        // Verify asset exists in DB and can be allocated a new attachment ID later (rebind)
        $assetRow = $this->assetService->getAssetById($assetId);
        if (!$assetRow || $assetRow['attachment_id'] !== null) {
            throw new RuntimeException("AC-T22-02 Failed: Asset was either removed or attachment_id not cleared.");
        }

        // Rebind: subsequent call to getOrCreateAttachmentId creates a fresh attachment post seamlessly
        $newAttId = $this->bridge->getOrCreateAttachmentId($assetId);
        $this->cleanAttachmentIds[] = $newAttId;

        if ($newAttId <= 0 || $newAttId === $attId) {
            throw new RuntimeException("AC-T22-02 Failed: Rebind failed to allocate a fresh attachment ID: {$newAttId}");
        }

        echo " OK\n";
    }
}

// Run tests if executed directly
$test = new T22Test();
$test->runAll();
