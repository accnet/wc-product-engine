/**
 * P011.10 — Live browser verification of the Product-Scoped Variant Image Picker.
 *
 * Verifies end-to-end:
 * 1. Product isolation: Picker on Product B only displays Product B's images and never displays Product A's images;
 *    never invokes wp.media.
 * 2. Selection mark (.wpe-picker-item.is-selected) highlights current/chosen product image.
 * 3. Single variation assignment: clicking a variation thumbnail (.wpe-row-thumb-btn) opens #wpe-variant-image-picker-modal,
 *    previews "Will update 1 variation", and assigns the image to that row.
 * 4. Selected rows (Bulk) assignment: selecting multiple rows and clicking .wpe-btn-bulk-set-image previews
 *    "Will update 2 variations" and assigns the image to the selected rows.
 * 5. Option group assignment: choosing "Option Value Group" (e.g. Color = Blue) previews "Will update 2 variations"
 *    via server preview-image-assignment and assigns the image to all matching variations.
 * 6. In-picker 1-step Upload & Assign (A3): uploading a file via #wpe-picker-file-input inside the picker
 *    automatically links the new image to the product's media library and assigns it to the target variation(s).
 * 7. Save & reload persistence of variant image assignments.
 *
 * Usage: node tests/e2e/VariantImagePickerLive.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const SITE = process.env.WPE_SITE_URL || 'http://site1.localhost';
const WP_PATH = process.env.WPE_WP_PATH || '/var/www/site1.localhost';
const SHOT_DIR = process.env.WPE_SHOT_DIR || '';
const HOST = new URL(SITE).hostname;

const wp = (...args) => execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();

const results = [];
function check(name, ok, detail) {
    results.push({ name, ok: !!ok });
    console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (!ok && detail !== undefined ? '  -> ' + detail : ''));
}

if (SHOT_DIR) mkdirSync(SHOT_DIR, { recursive: true });
const shot = async (page, name, opts = {}) => {
    if (SHOT_DIR) await page.screenshot({ path: path.join(SHOT_DIR, name), ...opts });
};

const work = mkdtempSync(path.join(tmpdir(), 'wpe-variant-picker-live-'));

const makePngFile = (fileName, r, g, b) => {
    const php = `<?php
    $im = imagecreatetruecolor(24, 24);
    $c = imagecolorallocate($im, ${r}, ${g}, ${b});
    imagefilledrectangle($im, 0, 0, 23, 23, $c);
    imagepng($im, $args[0]);
    imagedestroy($im);
    `;
    const genScript = path.join(work, 'gen_png.php');
    writeFileSync(genScript, php);
    const outPath = path.join(work, fileName);
    wp('eval-file', genScript, outPath);
    return outPath;
};

const pickerUploadPng = makePngFile('variant-green-inpicker.png', 22, 163, 74);

writeFileSync(path.join(work, 'fixture.php'), `<?php
$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$assets = new \\WPE\\Compatibility\\Gallery\\AssetService();

// Product A with 2 exclusive images
$idA = wp_insert_post(['post_title' => 'P011 Product A ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($idA, 'simple', 'product_type');
$verA = $svc->getProductEditorData($idA)['product']['version'];
$assetA1 = $assets->registerAsset('https://93.184.216.34/prod-a-only-1-' . uniqid() . '.jpg', ['alt_text' => 'Product A Image 1']);
$assetA2 = $assets->registerAsset('https://93.184.216.34/prod-a-only-2-' . uniqid() . '.jpg', ['alt_text' => 'Product A Image 2']);
$svc->saveProductVariants($idA, [
    'expected_version' => $verA,
    'product' => ['regular_price' => '15'],
    'media_library' => [
        'featured_asset_id' => $assetA1['id'],
        'gallery' => [['asset_id' => $assetA2['id'], 'position' => 0]],
    ],
]);

// Product B with 2 existing images and 4 variations
$idB = wp_insert_post(['post_title' => 'P011 Product B ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($idB, 'variable', 'product_type');
wp_cache_flush();
$verB = $svc->getProductEditorData($idB)['product']['version'];
$assetB1 = $assets->registerAsset('https://93.184.216.34/prod-b-red-' . uniqid() . '.jpg', ['alt_text' => 'Product B Red']);
$assetB2 = $assets->registerAsset('https://93.184.216.34/prod-b-blue-' . uniqid() . '.jpg', ['alt_text' => 'Product B Blue']);

$svc->saveProductVariants($idB, [
    'expected_version' => $verB,
    'media_library' => [
        'featured_asset_id' => $assetB1['id'],
        'gallery' => [
            ['asset_id' => $assetB2['id'], 'position' => 0],
        ],
    ],
    'variation_options' => [
        ['name' => 'Color', 'position' => 0, 'values' => [['label' => 'Red', 'position' => 0], ['label' => 'Blue', 'position' => 1]]],
        ['name' => 'Size', 'position' => 1, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]],
    ],
    'variations' => [
        ['combination' => ['Color' => 'Red', 'Size' => 'S'], 'sku' => 'VP-RS-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Red', 'Size' => 'M'], 'sku' => 'VP-RM-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Blue', 'Size' => 'S'], 'sku' => 'VP-BS-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Blue', 'Size' => 'M'], 'sku' => 'VP-BM-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
    ],
]);

$dataB = $svc->getProductEditorData($idB);
echo 'JSON:' . wp_json_encode([
    'idA' => $idA,
    'idB' => $idB,
    'assetA1' => (int) $assetA1['id'],
    'assetA2' => (int) $assetA2['id'],
    'assetB1' => (int) $assetB1['id'],
    'assetB2' => (int) $assetB2['id'],
    'variation_ids' => array_map(fn($v) => (int) $v['id'], $dataB['variations']),
]);
`);

writeFileSync(path.join(work, 'cleanup.php'), `<?php
global $wpdb;
foreach ($args as $id) {
    $id = (int) $id;
    $vids = get_posts(['post_type' => 'product_variation', 'post_parent' => $id, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']);
    foreach ($vids as $c) {
        wp_delete_post($c, true);
    }
    wp_delete_post($id, true);
    $allIds = array_merge([$id], $vids);
    if (!empty($allIds)) {
        $in = implode(',', array_map('intval', $allIds));
        $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_sku_registry WHERE object_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_products WHERE id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_variants WHERE id IN ({$in}) OR product_id = {$id}");
        $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_product_media WHERE product_id = {$id}");
    }
}
echo 'cleaned';
`);

const evalFile = (file, ...args) => {
    const out = wp('eval-file', path.join(work, file), ...args);
    const m = out.match(/JSON:(.*)$/s);
    return m ? JSON.parse(m[1]) : out;
};

const created = [];
let browser;
let exitCode = 0;

try {
    const fx = evalFile('fixture.php');
    created.push(fx.idA, fx.idB);
    check('Fixture: Product A and Product B created with isolated libraries', fx.idA > 0 && fx.idB > 0 && fx.variation_ids.length === 4);

    const hash = wp('eval', 'echo COOKIEHASH;');
    const logged = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "logged_in");');
    const auth = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "auth");');

    browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', `--host-resolver-rules=MAP ${HOST} 127.0.0.1`],
    });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    await ctx.addCookies([
        { name: `wordpress_logged_in_${hash}`, value: logged, domain: HOST, path: '/' },
        { name: `wordpress_sec_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
        { name: `wordpress_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
    ]);
    const page = await ctx.newPage();

    const consoleErrors = [];
    page.on('console', (m) => {
        if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) {
            consoleErrors.push(m.text());
        }
    });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    const editUrlB = `${SITE}/wp-admin/post.php?post=${fx.idB}&action=edit`;
    await page.goto(editUrlB, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-product-editor-inline-app .wpe-row-thumb-btn', { timeout: 20000 });

    // Track any accidental wp.media call
    await page.evaluate(() => {
        window.__wpeWpMediaCalled = false;
        if (window.wp && window.wp.media) {
            const orig = window.wp.media;
            window.wp.media = function () {
                window.__wpeWpMediaCalled = true;
                return orig.apply(this, arguments);
            };
        }
    });

    // ================================================================
    // 1. Open Picker for Single Variation & verify Product Isolation
    // ================================================================
    const firstVarId = fx.variation_ids[0];
    await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${firstVarId}"]`).click();
    await page.waitForSelector('#wpe-variant-image-picker-modal .wpe-picker-item', { timeout: 10000 });

    const pickerAssetIds = await page.locator('#wpe-variant-image-picker-modal .wpe-picker-item').evaluateAll((els) =>
        els.map((e) => Number(e.getAttribute('data-asset-id')))
    );
    check('Picker lists only Product B images (2 items)', pickerAssetIds.length === 2 && pickerAssetIds.includes(fx.assetB1) && pickerAssetIds.includes(fx.assetB2), JSON.stringify(pickerAssetIds));
    check('Picker NEVER lists Product A images', !pickerAssetIds.includes(fx.assetA1) && !pickerAssetIds.includes(fx.assetA2));
    check('Single variation scope preview displays "Will update 1 variation"', (await page.locator('#wpe-picker-scope-preview').textContent()).includes('Will update 1 variation'));

    // Select assetB1 and confirm
    await page.locator(`#wpe-variant-image-picker-modal .wpe-picker-item[data-asset-id="${fx.assetB1}"]`).click();
    check('Clicked picker item gets .is-selected class', await page.locator(`#wpe-variant-image-picker-modal .wpe-picker-item[data-asset-id="${fx.assetB1}"]`). evaluate((el) => el.classList.contains('is-selected')));

    await shot(page, 'p011_variant_picker_single.png');

    await page.locator('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'detached', timeout: 5000 });

    const row1HasImg = await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${firstVarId}"] img`).count();
    check('Single variation row thumbnail updated with selected product image', row1HasImg === 1);

    // ================================================================
    // 2. Bulk Selection Scope (2 selected rows)
    // ================================================================
    const secondVarId = fx.variation_ids[1];
    await page.locator(`#wpe-product-editor-inline-app .wpe-row-select[data-id="${firstVarId}"]`).check();
    await page.locator(`#wpe-product-editor-inline-app .wpe-row-select[data-id="${secondVarId}"]`).check();
    await page.locator('#wpe-product-editor-inline-app .wpe-btn-bulk-set-image').click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'visible', timeout: 5000 });

    check('Bulk picker scope preview displays "Will update 2 variations"', (await page.locator('#wpe-picker-scope-preview').textContent()).includes('Will update 2 variations'));
    await page.locator(`#wpe-variant-image-picker-modal .wpe-picker-item[data-asset-id="${fx.assetB1}"]`).click();
    await page.locator('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'detached', timeout: 5000 });

    const row2HasImg = await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${secondVarId}"] img`).count();
    check('Bulk assignment sets image on both selected variations', row2HasImg === 1);

    // ================================================================
    // 3. Option Group Scope (Color = Blue -> 2 variations)
    // ================================================================
    const thirdVarId = fx.variation_ids[2];
    const fourthVarId = fx.variation_ids[3];
    await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${thirdVarId}"]`).click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'visible', timeout: 5000 });

    await page.locator('#wpe-variant-image-picker-modal input[name="wpe_picker_scope"][value="option"]').check();
    await page.locator('#wpe-picker-group-opt').selectOption('Color');
    await page.locator('#wpe-picker-group-val').selectOption('Blue');
    await page.waitForTimeout(300);

    check('Option group scope preview displays "Will update 2 variations"', (await page.locator('#wpe-picker-scope-preview').textContent()).includes('Will update 2 variations'));
    await page.locator(`#wpe-variant-image-picker-modal .wpe-picker-item[data-asset-id="${fx.assetB2}"]`).click();

    await shot(page, 'p011_variant_picker_option_group.png');

    await page.locator('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'detached', timeout: 5000 });

    const row3HasImg = await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${thirdVarId}"] img`).count();
    const row4HasImg = await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${fourthVarId}"] img`).count();
    check('Option group assignment sets image on all Blue variations (rows 3 and 4)', row3HasImg === 1 && row4HasImg === 1);

    // ================================================================
    // 4. In-Picker 1-Step Upload & Assign (A3)
    // ================================================================
    await page.locator(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${fourthVarId}"]`).click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'visible', timeout: 5000 });

    await page.locator('#wpe-picker-file-input').setInputFiles(pickerUploadPng);
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'detached', timeout: 15000 });

    const mediaLibCountAfterInPickerUpload = await page.locator('#wpe-product-media-app .wpe-media-card').count();
    check('In-picker upload automatically links new image into Product B media library (3 cards total)', mediaLibCountAfterInPickerUpload === 3, String(mediaLibCountAfterInPickerUpload));

    // ================================================================
    // 5. Save & Reload Persistence
    // ================================================================
    await page.locator('#wpe-product-editor-inline-app .wpe-btn-save-api').first().click();
    await page.waitForFunction(() => {
        const btn = document.querySelector('#wpe-product-editor-inline-app .wpe-btn-save-api');
        return btn && btn.disabled;
    }, null, { timeout: 15000 });

    await page.reload({ waitUntil: 'load' });
    await page.waitForSelector('#wpe-product-editor-inline-app .wpe-row-thumb-btn', { timeout: 20000 });

    const allRowsHaveImgsAfterReload = await page.evaluate((ids) => {
        return ids.every((id) => !!document.querySelector(`#wpe-product-editor-inline-app .wpe-row-thumb-btn[data-id="${id}"] img`));
    }, fx.variation_ids);
    check('After save and reload: all 4 variations retain their assigned product images', allRowsHaveImgsAfterReload);

    const wpMediaCalled = await page.evaluate(() => !!window.__wpeWpMediaCalled);
    check('wp.media was never called during variant image picking', !wpMediaCalled);
    check('No unexpected browser console/page errors', consoleErrors.length === 0, consoleErrors.join(' | '));
} catch (err) {
    console.error('FATAL:', err);
    exitCode = 1;
} finally {
    if (browser) await browser.close();
    if (created.length) {
        try { evalFile('cleanup.php', ...created.map(String)); } catch (_) {}
    }
}

const failed = results.filter((r) => !r.ok);
console.log(`\nSummary: ${results.length - failed.length}/${results.length} passed`);
if (failed.length || exitCode) process.exit(1);
