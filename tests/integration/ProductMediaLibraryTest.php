<?php

/**
 * P011.5 — Product media library service.
 *
 * Membership enforced by the backend, link/unlink with impact resolution, atomic apply, revision guard,
 * alt text, ordering, variation image assignment, and "no storage file is touched". Uses a fake storage
 * provider; this is not a certification of real storage.
 */

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';
require_once __DIR__ . '/support/FakeStorageProvider.php';

if ((int) ini_get('zend.assertions') !== 1 || !filter_var(ini_get('assert.exception'), FILTER_VALIDATE_BOOLEAN)) {
    echo "FAILED: assertions are disabled. Run: php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp eval-file <this file>\n";
    exit(1);
}

use WPE\Application\Media\ManagedUploadService;
use WPE\Application\Media\ProductMediaLibrary;
use WPE\Application\Media\Storage\LocalStorageProvider;
use WPE\Application\Media\Storage\StorageResolver;
use WPE\Application\Media\UploadRejectedException;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\UploadItemRepository;

class ProductMediaLibraryTest
{
    private wpdb $wpdb;
    private MediaRepository $media;
    private UploadItemRepository $uploads;
    private FakeStorageProvider $fake;
    private ProductMediaLibrary $lib;
    private array $tmp = [];
    private array $posts = [];
    private array $users = [];
    private array $assetIds = [];
    private int $checks = 0;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb    = $wpdb;
        $this->media   = new MediaRepository($wpdb);
        $this->uploads = new UploadItemRepository($wpdb);
        $this->fake    = new FakeStorageProvider();
        $this->lib     = new ProductMediaLibrary($this->media, $this->uploads, $wpdb);
    }

    public function run(): void
    {
        echo "Running product media library tests...\n";
        wp_set_current_user(1);
        try {
            $this->testReadAndPending();
            $this->testLinkAndMembership();
            $this->testOtherProductAsset();
            $this->testRevisionGuard();
            $this->testOrderingAndAlt();
            $this->testFeaturedChange();
            $this->testUnlinkImpact();
            $this->testVariantAssignment();
            $this->testAtomicity();
            $this->testStorageUntouched();
            echo "All product media library tests passed ({$this->checks} checks).\n";
        } finally {
            $this->cleanup();
        }
    }

    /* ------------------------------------------------------------------ */

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

    private function product(): int
    {
        $id = wp_insert_post(['post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'P011 library test', 'post_author' => 1]);
        $this->posts[] = $id;
        return (int) $id;
    }

    private function variant(int $productId, int $position): int
    {
        $id = wp_insert_post(['post_type' => 'product_variation', 'post_status' => 'publish', 'post_title' => 'P011 var', 'post_parent' => $productId, 'post_author' => 1]);
        $this->posts[] = $id;
        $now = gmdate('Y-m-d H:i:s');
        $this->wpdb->insert($this->wpdb->prefix . 'wpe_variants', [
            'id'              => $id,
            'product_id'      => $productId,
            'status'          => 'publish',
            'combination_key' => random_bytes(32),
            'description'     => '',
            'extra_props'     => '{}',
            'position'        => $position,
            'version'         => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        return (int) $id;
    }

    private function removeVariantRows(): void
    {
        $p = $this->wpdb->prefix;
        foreach ($this->posts as $id) {
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$p}wpe_variant_media WHERE variant_id = %d", $id));
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$p}wpe_variants WHERE id = %d", $id));
            $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$p}wpe_product_media WHERE product_id = %d", $id));
        }
    }

    private function service(): ManagedUploadService
    {
        $local    = new LocalStorageProvider($this->tmpDir(), 'https://uploads.example.test');
        $resolver = new StorageResolver(fn () => $this->fake, static fn () => true, $local);
        return new ManagedUploadService($resolver, $this->media, $this->uploads, null, static fn () => true, $this->wpdb, 1);
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/wpe-pml-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $this->tmp[] = $dir;
        return $dir;
    }

    /** Upload a unique image for the product through the managed service; the asset stays pending. */
    private function pending(int $productId, string $label): int
    {
        static $n = 0;
        $n++;
        $im = imagecreatetruecolor(20, 14);
        imagefill($im, 0, 0, imagecolorallocate($im, ($n * 7) % 250, ($n * 13) % 250, 90));
        imagesetpixel($im, 2, 2, imagecolorallocate($im, ($n * 31) % 250, 4, 4));
        $path = tempnam(sys_get_temp_dir(), 'wpe-pml-img-');
        imagepng($im, $path);
        imagedestroy($im);
        $this->tmp[] = $path;
        $r = $this->service()->upload(
            ['tmp_name' => $path, 'name' => $label . '.png', 'size' => filesize($path), 'error' => UPLOAD_ERR_OK],
            ['upload_key' => 'p011t-lib-' . $n . '-' . bin2hex(random_bytes(3)), 'product_id' => $productId, 'alt_text' => $label]
        );
        $id = (int) $r['asset_id'];
        $this->assetIds[] = $id;
        return $id;
    }

    /** Pending external (CDN URL) asset: a registered intent without managed storage. */
    private function pendingExternal(int $productId, string $label): int
    {
        static $n = 0;
        $n++;
        $asset = $this->media->createAsset(['source_url' => 'https://cdn.example.test/' . $label . '-' . bin2hex(random_bytes(3)) . '.jpg', 'mime' => 'image/jpeg', 'width' => 100, 'height' => 100, 'alt_text' => $label]);
        $id = (int) $asset['id'];
        $this->assetIds[] = $id;
        $item = $this->uploads->begin(1, 'p011t-ext-' . $n . '-' . bin2hex(random_bytes(3)), 'sess-test', $productId, $label . '.jpg');
        $this->uploads->markRegistered((int) $item['id'], $id);
        return $id;
    }

    private function link(int $productId, array $ids, ?string $featured = null): array
    {
        $current = $this->lib->read($productId, 1);
        $patch = ['collection_mode' => 'merge', 'gallery' => array_map(static fn ($id) => ['asset_id' => $id], $ids)];
        if ($featured !== null) {
            $patch['featured_asset_id'] = (int) $featured;
        }
        return $this->lib->apply($productId, $patch, $current['version'], 1);
    }

    private function roles(array $library): array
    {
        $out = [];
        foreach ($library['items'] as $i) {
            $out[$i['asset_id']] = $i['role'];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */

    private function testReadAndPending(): void
    {
        echo "[library] read and pending\n";
        $pid = $this->product();
        $lib = $this->lib->read($pid, 1);
        $this->ok($lib['items'] === [] && is_string($lib['version']) && $lib['version'] !== '', 'a new product has an empty library and a revision');

        $managed  = $this->pending($pid, 'managed');
        $external = $this->pendingExternal($pid, 'external');
        $lib      = $this->lib->read($pid, 1);
        $roles    = $this->roles($lib);
        $this->ok(($roles[(string) $managed] ?? '') === 'pending_link' && ($roles[(string) $external] ?? '') === 'pending_link', 'uploads and CDN URLs are pending until linked');
        $origins = [];
        foreach ($lib['items'] as $i) {
            $origins[$i['asset_id']] = $i['origin'];
        }
        $this->ok($origins[(string) $managed] === 'managed' && $origins[(string) $external] === 'external', 'managed files and external URLs are distinguished');
        $this->ok($this->lib->read($pid, 0)['items'] === [], 'pending items are only shown to the user who added them');
        $this->ok($this->lib->libraryAssetIds($pid) === [], 'pending items are not in the library');
    }

    private function testLinkAndMembership(): void
    {
        echo "[library] link and membership\n";
        $pid = $this->product();
        $a   = $this->pending($pid, 'a');
        $b   = $this->pending($pid, 'b');
        $res = $this->link($pid, [$a, $b], (string) $a);
        $this->ok($res['changed'] === true, 'linking changes the library');
        $roles = $this->roles($res['library']);
        $this->ok($roles[(string) $a] === 'featured' && $roles[(string) $b] === 'gallery', 'featured and gallery roles');
        $this->ok($this->lib->libraryAssetIds($pid) === [$a, $b], 'the library lists featured then gallery');
        $this->ok($this->wpdb->get_var("SELECT MIN(position) FROM {$this->wpdb->prefix}wpe_product_media WHERE product_id = {$pid} AND role = 'gallery'") == 1, 'gallery positions start at 1 like existing data');

        $missing = $this->reason(fn () => $this->lib->assertInLibrary($pid, [$a, 999999991]));
        $this->ok($missing === 'asset_not_in_product', 'an asset outside the library is refused');
        $this->lib->assertInLibrary($pid, [$a, $b]);
        $this->ok(true, 'library assets pass the membership check');

        $unknown = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => 999999992]]], $this->lib->read($pid, 1)['version'], 1));
        $this->ok($unknown === 'asset_not_in_product', 'an unknown asset id cannot be linked');

        $this->ok($this->reason(fn () => $this->lib->apply($pid, ['gallery' => []], 'x', 1)) === 'invalid_request', 'collection_mode is required');
    }

    private function testOtherProductAsset(): void
    {
        echo "[library] other product's assets\n";
        $p1 = $this->product();
        $p2 = $this->product();
        $own = $this->pending($p1, 'own');
        $this->link($p1, [$own], (string) $own);

        $reason = $this->reason(fn () => $this->lib->apply($p2, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => $own]]], $this->lib->read($p2, 1)['version'], 1));
        $this->ok($reason === 'asset_not_in_product', 'a library asset of another product cannot be linked here');

        $other = $this->pending($p2, 'other');
        $reason = $this->reason(fn () => $this->lib->apply($p1, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => $other]]], $this->lib->read($p1, 1)['version'], 1));
        $this->ok($reason === 'asset_not_in_product', 'a pending asset registered for another product cannot be linked');
        $this->ok($this->roles($this->lib->read($p1, 1)) === [(string) $own => 'featured'], 'nothing changed after the refusals');

        // Another user's pending upload is not linkable.
        $uid = wp_insert_user(['user_login' => 'p011-lib-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(20), 'user_email' => 'p011lib' . wp_generate_password(5, false, false) . '@example.test', 'role' => 'administrator']);
        $this->users[] = $uid;
        $mine = $this->pending($p1, 'mine');
        $reason = $this->reason(fn () => $this->lib->apply($p1, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => $mine]]], $this->lib->read($p1, $uid)['version'], $uid));
        $this->ok($reason === 'asset_not_in_product', 'an upload of another user cannot be linked');
    }

    private function testRevisionGuard(): void
    {
        echo "[library] revision guard\n";
        $pid = $this->product();
        $a   = $this->pending($pid, 'r1');
        $this->link($pid, [$a], (string) $a);
        $old = $this->lib->read($pid, 1)['version'];
        $b   = $this->pending($pid, 'r2');
        $this->link($pid, [$b]);
        $reason = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'alt_overrides' => [['asset_id' => $a, 'alt_override' => 'x']]], $old, 1));
        $this->ok($reason === 'stale_version', 'a stale revision is refused');
        $this->ok($this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge'], '', 1)) === 'stale_version', 'a missing revision is refused');
        $same = $this->lib->apply($pid, ['collection_mode' => 'merge'], $this->lib->read($pid, 1)['version'], 1);
        $this->ok($same['changed'] === false, 'an empty patch changes nothing');
    }

    private function testOrderingAndAlt(): void
    {
        echo "[library] ordering and alt text\n";
        $pid = $this->product();
        $ids = [$this->pending($pid, 'o1'), $this->pending($pid, 'o2'), $this->pending($pid, 'o3'), $this->pending($pid, 'o4')];
        $this->link($pid, $ids, (string) $ids[0]);
        $lib = $this->lib->read($pid, 1);
        $v   = $lib['version'];
        $this->lib->apply($pid, ['collection_mode' => 'replace', 'gallery' => [
            ['asset_id' => $ids[3], 'position' => 1], ['asset_id' => $ids[1], 'position' => 2], ['asset_id' => $ids[2], 'position' => 3],
        ]], $v, 1);
        $this->ok($this->lib->libraryAssetIds($pid) === [$ids[0], $ids[3], $ids[1], $ids[2]], 'the gallery follows the requested order');

        $v = $this->lib->read($pid, 1)['version'];
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'alt_overrides' => [['asset_id' => $ids[3], 'alt_override' => 'New <b>alt</b>']]], $v, 1);
        $item = null;
        foreach ($this->lib->read($pid, 1)['items'] as $i) {
            if ($i['asset_id'] === (string) $ids[3]) {
                $item = $i;
            }
        }
        $this->ok($item !== null && $item['alt_override'] === 'New alt', 'alt text is stored sanitized for this product');

        $v      = $this->lib->read($pid, 1)['version'];
        $reason = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'alt_overrides' => [['asset_id' => 999999993, 'alt_override' => 'z']]], $v, 1));
        $this->ok($reason === 'asset_not_in_product', 'alt text cannot be set on an image of another product');

        $v      = $this->lib->read($pid, 1)['version'];
        $reason = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'replace', 'gallery' => [['asset_id' => $ids[1]], ['asset_id' => $ids[1]]]], $v, 1));
        $this->ok($reason === 'invalid_request', 'a duplicate asset in the gallery is refused');
    }

    private function testFeaturedChange(): void
    {
        echo "[library] featured change\n";
        $pid = $this->product();
        $a   = $this->pending($pid, 'f1');
        $b   = $this->pending($pid, 'f2');
        $this->link($pid, [$a, $b], (string) $a);
        $v = $this->lib->read($pid, 1)['version'];
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'featured_asset_id' => $b], $v, 1);
        $roles = $this->roles($this->lib->read($pid, 1));
        $this->ok($roles[(string) $b] === 'featured' && $roles[(string) $a] === 'gallery', 'the previous featured image moves to the gallery');
        $this->ok($this->lib->libraryAssetIds($pid) === [$b, $a], 'no image was lost and none is duplicated');
    }

    private function testUnlinkImpact(): void
    {
        echo "[library] unlink impact\n";
        $pid = $this->product();
        $v1  = $this->variant($pid, 1);
        $v2  = $this->variant($pid, 2);
        $f   = $this->pending($pid, 'u-feat');
        $g1  = $this->pending($pid, 'u-g1');
        $g2  = $this->pending($pid, 'u-g2');
        $this->link($pid, [$f, $g1, $g2], (string) $f);
        $v = $this->lib->read($pid, 1)['version'];
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'variant_media' => [
            ['variant_id' => $v1, 'primary_asset_id' => $g1, 'assets' => [$g1]],
            ['variant_id' => $v2, 'primary_asset_id' => $g1, 'assets' => [$g1]],
        ]], $v, 1);

        $preview = $this->lib->previewUnlink($pid, [$f, $g1, $g2]);
        $places  = array_map(static fn ($a) => $a['asset_id'] . ':' . $a['place'] . ':' . ($a['variant_id'] ?? ''), $preview['affected']);
        $this->ok(in_array("{$f}:featured:", $places, true), 'the preview lists the featured image');
        $this->ok(in_array("{$g1}:variant:{$v1}", $places, true) && in_array("{$g1}:variant:{$v2}", $places, true), 'the preview lists each variation that uses the image');
        $this->ok(in_array("{$g2}:gallery:", $places, true), 'the preview lists gallery items');
        $this->ok($preview['requires_resolution'] === true, 'in-use images need a resolution');
        $this->ok($this->lib->previewUnlink($pid, [$g2])['requires_resolution'] === false, 'an unused gallery image can be removed directly');

        $before = $this->lib->read($pid, 1);
        $v      = $before['version'];
        $r      = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $g1]]], $v, 1));
        $this->ok($r === 'impact_requires_resolution', 'removing an image used by variations without resolution is refused');
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $f]]], $v, 1));
        $this->ok($r === 'impact_requires_resolution', 'removing the featured image without a replacement or fallback is refused');
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'replace', 'gallery' => [['asset_id' => $g2]]], $v, 1));
        $this->ok($r === 'impact_requires_resolution', 'dropping an in-use image through a replaced gallery is also refused');
        $this->ok($this->lib->read($pid, 1)['version'] === $v, 'refused requests change nothing');

        // Remove g1 with replacement g2.
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $g1, 'replacement_asset_id' => $g2]]], $v, 1);
        $lib  = $this->lib->read($pid, 1);
        $used = [];
        foreach ($lib['items'] as $i) {
            $used[$i['asset_id']] = $i['used_by_variants'];
        }
        $this->ok(!isset($used[(string) $g1]) && $used[(string) $g2] === [(string) $v1, (string) $v2], 'the replacement is assigned to the variations and the image leaves the library');

        // Remove the featured image with fallback.
        $v = $lib['version'];
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $f, 'fallback' => true]]], $v, 1);
        $roles = $this->roles($this->lib->read($pid, 1));
        $this->ok(!isset($roles[(string) $f]) && !in_array('featured', $roles, true), 'a confirmed fallback removes the featured image');

        // Remove g2 with fallback: variations lose the image.
        $v = $this->lib->read($pid, 1)['version'];
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $g2, 'fallback' => true]]], $v, 1);
        $this->ok($this->lib->read($pid, 1)['items'] === [], 'the library is empty after the last removal');
        $count = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_variant_media WHERE variant_id IN ({$v1},{$v2})");
        $this->ok($count === 0, 'variations fall back to the product image');
        $this->ok((int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->prefix}wpe_assets WHERE id IN ({$f},{$g1},{$g2})") === 3, 'unlinking keeps the asset records');
    }

    private function testVariantAssignment(): void
    {
        echo "[library] variation assignment\n";
        $pid = $this->product();
        $other = $this->product();
        $v1  = $this->variant($pid, 1);
        $vx  = $this->variant($other, 1);
        $a   = $this->pending($pid, 'va');
        $b   = $this->pending($pid, 'vb');
        $x   = $this->pending($other, 'vx');
        $this->link($other, [$x], (string) $x);
        $this->link($pid, [$a], (string) $a);

        $v = $this->lib->read($pid, 1)['version'];
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'variant_media' => [['variant_id' => $v1, 'primary_asset_id' => $b, 'assets' => [$b]]]], $v, 1));
        $this->ok($r === 'asset_not_in_product', 'a pending image that is not linked cannot be assigned on its own');
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'gallery' => [['asset_id' => $b]], 'variant_media' => [['variant_id' => $v1, 'primary_asset_id' => $b, 'assets' => [$b]]]], $v, 1));
        $this->ok($r === null, 'an image linked in the same request can be assigned');
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'variant_media' => [['variant_id' => $v1, 'primary_asset_id' => $x, 'assets' => [$x]]]], $this->lib->read($pid, 1)['version'], 1));
        $this->ok($r === 'asset_not_in_product', 'an image of another product cannot be assigned to a variation');
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'variant_media' => [['variant_id' => $vx, 'primary_asset_id' => $a, 'assets' => [$a]]]], $this->lib->read($pid, 1)['version'], 1));
        $this->ok($r === 'validation_failed', 'a variation of another product is refused');

        $roles = $this->roles($this->lib->read($pid, 1));
        $this->ok(($roles[(string) $b] ?? '') === 'gallery', 'the image linked and assigned together is in the gallery');
    }

    private function testAtomicity(): void
    {
        echo "[library] atomic apply\n";
        $pid = $this->product();
        $v1  = $this->variant($pid, 1);
        $a   = $this->pending($pid, 'at1');
        $b   = $this->pending($pid, 'at2');
        $this->link($pid, [$a, $b], (string) $a);
        $before = $this->lib->read($pid, 1);
        $r = $this->reason(fn () => $this->lib->apply($pid, ['collection_mode' => 'merge', 'featured_asset_id' => $b, 'variant_media' => [['variant_id' => $v1, 'primary_asset_id' => 999999994, 'assets' => [999999994]]]], $before['version'], 1));
        $this->ok($r === 'asset_not_in_product', 'a failing part rejects the request');
        $after = $this->lib->read($pid, 1);
        $this->ok($after['version'] === $before['version'] && $this->roles($after) === $this->roles($before), 'no part of a refused request was saved');
    }

    private function testStorageUntouched(): void
    {
        echo "[library] storage untouched\n";
        $pid = $this->product();
        $a   = $this->pending($pid, 's1');
        $this->link($pid, [$a], (string) $a);
        $objects = $this->fake->objects;
        $v       = $this->lib->read($pid, 1)['version'];
        $this->lib->apply($pid, ['collection_mode' => 'merge', 'unlink' => [['asset_id' => $a, 'fallback' => true]]], $v, 1);
        $this->ok($this->fake->objects === $objects, 'unlinking never deletes the stored file');
        $this->ok($this->media->getAssetStorage($a) !== null, 'the managed storage record stays');
        $this->ok($this->lib->read($pid, 1)['items'] === [], 'a removed image does not return as a pending upload');
    }

    /* ------------------------------------------------------------------ */

    private function cleanup(): void
    {
        wp_set_current_user(1);
        $p = $this->wpdb->prefix;
        $this->removeVariantRows();
        $this->wpdb->query("DELETE FROM {$p}wpe_upload_items WHERE upload_key LIKE 'p011t-%'");
        foreach (array_unique($this->assetIds) as $id) {
            $this->media->deleteAssetStorage((int) $id);
            $this->media->deleteAsset((int) $id, true);
        }
        foreach ($this->posts as $id) {
            wp_delete_post($id, true);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->users as $id) {
            wp_delete_user($id);
        }
        foreach ($this->tmp as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}

try {
    (new ProductMediaLibraryTest())->run();
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
