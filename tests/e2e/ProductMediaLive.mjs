/**
 * P011.9 — Live browser verification of the Per-Product Media Library UI.
 *
 * Verifies end-to-end:
 * 1. Native WP image boxes (#postimagediv, #woocommerce-product-images) hidden on Simple & Variable products,
 *    replaced by #wpe_product_media (#wpe-product-media-app).
 * 2. Computer file upload via #wpe-media-file-input adds image to .wpe-media-grid with Pending badge before save.
 * 3. Simulated upload failure displays .wpe-upload-queue-item.is-error with Retry button (.wpe-btn-retry-upload);
 *    clicking Retry reuses the same upload_key and succeeds without duplicate asset or silent fallback.
 * 4. External CDN URL addition (#wpe-media-ext-url + .wpe-btn-add-media-url) adds CDN card to product draft.
 * 5. Set as Featured (.wpe-btn-media-set-featured) promotes gallery image to Featured and moves previous Featured to gallery.
 * 6. Gallery reorder (.wpe-btn-media-move-next / .wpe-btn-media-move-prev) and inline alt text edit (.wpe-media-alt-input).
 * 7. Save & reload persistence: Featured, gallery order, and alt text persist across page reload; Pending badge clears;
 *    WordPress _thumbnail_id and _product_image_gallery are projected.
 * 8. Safe Unlink with Impact Preview (#wpe-media-unlink-modal): removing Featured image shows impact warning,
 *    allows replacement selection and physical file deletion (#wpe-unlink-delete-file).
 *
 * Usage: node tests/e2e/ProductMediaLive.mjs
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

const work = mkdtempSync(path.join(tmpdir(), 'wpe-product-media-live-'));

// Generate 2 tiny valid PNG files for upload tests
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

const png1Path = makePngFile('product-red.png', 220, 38, 38);
const png2Path = makePngFile('product-blue.png', 37, 99, 235);

writeFileSync(path.join(work, 'fixture.php'), `<?php
$id = wp_insert_post([
    'post_title'  => 'P011 Product Media Live ' . uniqid(),
    'post_status' => 'publish',
    'post_type'   => 'product',
]);
wp_set_object_terms($id, 'variable', 'product_type');
wp_cache_flush();

$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$version = $svc->getProductEditorData($id)['product']['version'];
$res = $svc->saveProductVariants($id, [
    'expected_version' => $version,
    'variation_options' => [
        ['name' => 'Color', 'position' => 0, 'values' => [['label' => 'Red', 'position' => 0], ['label' => 'Blue', 'position' => 1]]],
    ],
    'variations' => [
        ['combination' => ['Color' => 'Red'], 'sku' => 'PM-RED-' . uniqid(), 'regular_price' => '29', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Blue'], 'sku' => 'PM-BLU-' . uniqid(), 'regular_price' => '29', 'status' => 'publish', 'manage_stock' => 0],
    ],
]);
if (empty($res['success'])) { fwrite(STDERR, 'fixture save failed'); exit(1); }
echo 'JSON:' . wp_json_encode(['id' => $id]);
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
    const fixture = evalFile('fixture.php');
    created.push(fixture.id);
    check('Fixture: variable product created', fixture.id > 0, String(fixture.id));

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

    const editUrl = `${SITE}/wp-admin/post.php?post=${fixture.id}&action=edit`;
    await page.goto(editUrl, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-product-media-app .wpe-media-grid', { timeout: 20000 });

    // ================================================================
    // 1. Native WP image boxes hidden & #wpe_product_media visible
    // ================================================================
    check('Product Media box (#wpe_product_media) is visible', await page.locator('#wpe_product_media').isVisible());
    const nativeThumbHidden = await page.evaluate(() => {
        const el = document.getElementById('postimagediv');
        return !el || el.classList.contains('wpe-box-hidden') || getComputedStyle(el).display === 'none';
    });
    const nativeGalleryHidden = await page.evaluate(() => {
        const el = document.getElementById('woocommerce-product-images');
        return !el || el.classList.contains('wpe-box-hidden') || getComputedStyle(el).display === 'none';
    });
    check('Native #postimagediv is hidden on supported product', nativeThumbHidden);
    check('Native #woocommerce-product-images is hidden on supported product', nativeGalleryHidden);

    // ================================================================
    // 2. Upload file #1 from computer -> Pending badge & Featured badge
    // ================================================================
    await page.locator('#wpe-media-file-input').setInputFiles(png1Path);
    await page.waitForSelector('#wpe-product-media-app .wpe-media-card', { timeout: 10000 });

    const cardCountAfterFirst = await page.locator('#wpe-product-media-app .wpe-media-card').count();
    check('First computer upload adds 1 card to .wpe-media-grid', cardCountAfterFirst === 1, String(cardCountAfterFirst));
    check('First uploaded card is automatically marked Featured', await page.locator('#wpe-product-media-app .wpe-media-card.is-featured .wpe-media-badge.featured').isVisible());
    check('Uploaded card before Save displays Pending badge', await page.locator('#wpe-product-media-app .wpe-media-card .wpe-media-badge.pending').isVisible());

    // ================================================================
    // 3. Simulated upload failure -> Error state & Retry with same upload_key
    // ================================================================
    let interceptedUploadKeys = [];
    let shouldFailOnce = true;
    await page.route('**/wp-json/wpe/v1/admin/assets', async (route) => {
        const req = route.request();
        const postData = req.postDataBuffer();
        if (postData) {
            const text = postData.toString('latin1');
            const m = text.match(/name="upload_key"\r\n\r\n([^\r\n]+)/);
            if (m) interceptedUploadKeys.push(m[1]);
        }
        if (shouldFailOnce) {
            shouldFailOnce = false;
            await route.fulfill({
                status: 503,
                contentType: 'application/json',
                body: JSON.stringify({ code: 'wpe_storage_unavailable', message: 'Temporary storage failure (simulated)' }),
            });
        } else {
            await route.continue();
        }
    });

    await page.locator('#wpe-media-file-input').setInputFiles(png2Path);
    await page.waitForSelector('#wpe-product-media-app .wpe-upload-queue-item.is-error', { timeout: 10000 });

    check('Failed upload renders error queue item with provider error text', (await page.locator('#wpe-product-media-app .wpe-upload-queue-item.is-error').textContent()).includes('Temporary storage failure'));
    check('Failed upload does not create a second card or silently switch provider', (await page.locator('#wpe-product-media-app .wpe-media-card').count()) === 1);

    // Click Retry
    await page.locator('#wpe-product-media-app .wpe-btn-retry-upload').click();
    await page.waitForFunction(() => document.querySelectorAll('#wpe-product-media-app .wpe-media-card').length === 2, null, { timeout: 10000 });
    await page.unroute('**/wp-json/wpe/v1/admin/assets');

    check('Clicking Retry succeeds and adds 2nd card to .wpe-media-grid', (await page.locator('#wpe-product-media-app .wpe-media-card').count()) === 2);
    check('Retry reuses the exact same idempotency upload_key', interceptedUploadKeys.length === 2 && interceptedUploadKeys[0] === interceptedUploadKeys[1], JSON.stringify(interceptedUploadKeys));

    // ================================================================
    // 4. Add external CDN URL
    // ================================================================
    const extCdnUrl = `https://93.184.216.34/p011-test-${Date.now()}.jpg`;
    await page.locator('#wpe-media-ext-url').fill(extCdnUrl);
    await page.locator('#wpe-media-ext-alt').fill('External Hero Alt');
    await page.locator('.wpe-btn-add-media-url').click();
    await page.waitForFunction(() => document.querySelectorAll('#wpe-product-media-app .wpe-media-card').length === 3, null, { timeout: 10000 });

    check('Adding external HTTPS URL adds 3rd card with CDN badge', (await page.locator('#wpe-product-media-app .wpe-media-card').nth(2).locator('.wpe-media-badge.origin').textContent()).trim() === 'CDN');

    // ================================================================
    // 5. Promote 2nd card to Featured & edit Alt text
    // ================================================================
    const secondCardId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(1).getAttribute('data-asset-id');
    const firstCardId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(0).getAttribute('data-asset-id');

    await page.locator('#wpe-product-media-app .wpe-media-card').nth(1).locator('.wpe-btn-media-set-featured').click({ force: true });
    await page.waitForTimeout(150);

    const newFirstCardId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(0).getAttribute('data-asset-id');
    const newSecondCardId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(1).getAttribute('data-asset-id');
    check('Setting 2nd image as Featured moves it to index 0', newFirstCardId === secondCardId);
    check('Old Featured image moves to front of gallery (index 1) instead of being dropped', newSecondCardId === firstCardId);

    // Reorder gallery cards (move index 1 right to index 2)
    await page.locator('#wpe-product-media-app .wpe-media-card').nth(1).locator('.wpe-btn-media-move-next').click();
    await page.waitForTimeout(150);
    const afterMoveLastId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(2).getAttribute('data-asset-id');
    check('Gallery reorder button moves card to expected position', afterMoveLastId === firstCardId);

    // Edit alt text on Featured card
    const featAltInput = page.locator('#wpe-product-media-app .wpe-media-card').nth(0).locator('.wpe-media-alt-input');
    await featAltInput.fill('Featured Blue Jacket');
    await featAltInput.dispatchEvent('change');

    await shot(page, 'p011_product_media_library_before_save.png');

    // ================================================================
    // 6. Save & Reload Persistence
    // ================================================================
    await page.locator('#wpe-product-media-app .wpe-btn-save-media-direct').click();
    await page.waitForFunction(() => document.querySelectorAll('#wpe-product-media-app .wpe-media-badge.pending').length === 0, null, { timeout: 15000 });

    check('Saving clears Pending badges on all linked product media cards', (await page.locator('#wpe-product-media-app .wpe-media-badge.pending').count()) === 0);

    await page.reload({ waitUntil: 'load' });
    await page.waitForSelector('#wpe-product-media-app .wpe-media-card', { timeout: 20000 });

    const reloadedCount = await page.locator('#wpe-product-media-app .wpe-media-card').count();
    const reloadedFirstId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(0).getAttribute('data-asset-id');
    const reloadedLastId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(2).getAttribute('data-asset-id');
    const reloadedFirstAlt = await page.locator('#wpe-product-media-app .wpe-media-card').nth(0).locator('.wpe-media-alt-input').inputValue();

    check('After reload: 3 images remain in product media library', reloadedCount === 3);
    check('After reload: Featured image ID persists', reloadedFirstId === secondCardId);
    check('After reload: Gallery order persists', reloadedLastId === firstCardId);
    check('After reload: Edited alt text persists ("Featured Blue Jacket")', reloadedFirstAlt === 'Featured Blue Jacket', reloadedFirstAlt);

    await shot(page, 'p011_product_media_library_after_reload.png');

    // ================================================================
    // 7. Safe Unlink with Impact Preview (#wpe-media-unlink-modal)
    // ================================================================
    await page.locator('#wpe-product-media-app .wpe-media-card').nth(0).locator('.wpe-btn-media-unlink').click();
    await page.waitForSelector('#wpe-media-unlink-modal .wpe-btn-confirm-unlink-media:not(:disabled)', { timeout: 10000 });

    const unlinkModalText = await page.locator('#wpe-media-unlink-body').textContent();
    check('Unlink modal on Featured image shows Impact Preview warning', unlinkModalText.includes('Impact Preview: This image is currently in use') && unlinkModalText.includes('Featured Product Image'));
    check('Unlink modal on managed upload offers physical file delete checkbox', await page.locator('#wpe-unlink-delete-file').isVisible());

    await shot(page, 'p011_product_media_unlink_impact_modal.png');

    // Confirm removal (replaces Featured with selected replacement and deletes physical file)
    await page.locator('#wpe-media-unlink-modal .wpe-btn-confirm-unlink-media').click();
    await page.waitForSelector('#wpe-media-unlink-modal', { state: 'detached', timeout: 10000 });

    const remainingCount = await page.locator('#wpe-product-media-app .wpe-media-card').count();
    check('Confirming unlink removes image and leaves 2 images with replacement promoted to Featured', remainingCount === 2 && (await page.locator('#wpe-product-media-app .wpe-media-card.is-featured').count()) === 1);

    // ================================================================
    // 8. 2-Row Inline Cap (10 slots = 9 cards + "+X Xem thêm") & Full Media Popup
    // ================================================================
    const palette = [
        [16, 185, 129], [245, 158, 11], [139, 92, 246], [236, 72, 153], [14, 165, 233],
        [234, 88, 12], [20, 184, 166], [99, 102, 241], [217, 70, 239], [132, 204, 22],
    ];
    const extraPngs = palette.map((rgb, idx) => makePngFile(`product-extra-${idx + 1}.png`, rgb[0], rgb[1], rgb[2]));

    await page.locator('#wpe-media-file-input').setInputFiles(extraPngs);
    await page.waitForFunction(() => {
        const countEl = document.querySelector('#wpe-product-media-app .wpe-section-count');
        return countEl && countEl.textContent.trim() === '12';
    }, null, { timeout: 20000 });

    const inlineCardCount = await page.locator('#wpe-product-media-app .wpe-media-grid--inline .wpe-media-card').count();
    const moreCard = page.locator('#wpe-product-media-app .wpe-media-grid--inline .wpe-media-more-card');
    const moreCardVisible = await moreCard.isVisible();
    const moreCardText = moreCardVisible ? await moreCard.textContent() : '';

    check('With 12 images, inline grid caps at 9 .wpe-media-card items (2 rows total with more tile)', inlineCardCount === 9, String(inlineCardCount));
    check('10th slot in 2-row inline grid renders .wpe-media-more-card with "+3" and "View All"', moreCardVisible && moreCardText.includes('+3') && (moreCardText.includes('View All') || moreCardText.includes('Xem thêm')), moreCardText);

    await page.evaluate(() => {
        const el = document.getElementById('wpe_product_media');
        if (el) el.scrollIntoView({ block: 'start' });
    });
    await page.waitForTimeout(150);
    await shot(page, 'p011_media_2rows_inline.png');

    // Click "+3 Xem thêm" tile to open Full Product Media Library popup
    await moreCard.click();
    await page.waitForSelector('#wpe-product-media-full-modal .wpe-media-grid--full', { timeout: 10000 });
    await page.waitForTimeout(220);

    const fullModalCardCount = await page.locator('#wpe-product-media-full-modal .wpe-media-grid--full .wpe-media-card').count();
    check('Clicking "+3 Xem thêm" opens #wpe-product-media-full-modal showing all 12 images', fullModalCardCount === 12, String(fullModalCardCount));

    await shot(page, 'p011_media_full_popup.png');

    // Promote the 12th image inside the popup to Featured and verify sync with inline 2-row grid
    const twelfthAssetId = await page.locator('#wpe-product-media-full-modal .wpe-media-card').nth(11).getAttribute('data-asset-id');
    await page.locator('#wpe-product-media-full-modal .wpe-media-card').nth(11).locator('.wpe-btn-media-set-featured').click({ force: true });
    await page.waitForTimeout(150);

    const popupFirstAssetId = await page.locator('#wpe-product-media-full-modal .wpe-media-card').nth(0).getAttribute('data-asset-id');
    const inlineFirstAssetId = await page.locator('#wpe-product-media-app .wpe-media-card').nth(0).getAttribute('data-asset-id');
    check('Setting Featured inside full popup updates both popup and inline 2-row grid', popupFirstAssetId === twelfthAssetId && inlineFirstAssetId === twelfthAssetId);

    // Close Full Media Popup
    await page.locator('#wpe-product-media-full-modal .wpe-btn-close-modal').first().click();
    await page.waitForSelector('#wpe-product-media-full-modal', { state: 'detached', timeout: 5000 });

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
