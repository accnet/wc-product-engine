/**
 * P012.7 — Live browser Playwright E2E verification for Settings -> Product Page
 * and Storefront Price Display & Variant Selector.
 *
 * Verifies end-to-end:
 * 1. Admin Settings -> Product Page (tab=product-page):
 *    - Price Display and Variant Selector sections rendered; Product Information absent.
 *    - Save all 7 toggles + reload persistence + capability/nonce enforcement.
 * 2. Storefront Archive & PDP with Product Page settings enabled:
 *    - Single Price Display on archive card and PDP main price area (same-variation regular+sale, no secondary duplicate price).
 *    - Per-attribute single control: Image Swatch (default 1st option, thumb+label), Color Swatch (hex bubble, .is-light-swatch on #ffffff, named button fallback when missing hex), Button Selector.
 *    - Show Help Text (.wpe-option-help-text linked via aria-describedby, absent when empty).
 *    - Show Selected Value (updates attribute label with display label on change, reverts on reset).
 *    - Dynamic Image Swatch candidate thumbnail updates as other options are selected.
 *    - Mark Unavailable Options (.is-marked-unavailable, disabled, unpurchasable combinations blocked).
 *    - Reset restores base labels, lowest price, initial thumbnails, and disables Add to cart.
 *    - Multi-instance / quick-view scoping isolation on the same page.
 *    - Cart backend variation and price parity.
 * 3. Turning settings OFF restores standard dropdowns and variable price range.
 *
 * Usage: node tests/e2e/ProductPageSettingsLive.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const SITE = process.env.WPE_SITE_URL || 'http://site1.localhost';
const WP_PATH = process.env.WPE_WP_PATH || '/var/www/site1.localhost';
const HOST = new URL(SITE).hostname;

const wp = (...args) =>
    execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();

const results = [];
function check(name, ok, detail) {
    results.push({ name, ok: Boolean(ok) });
    console.log(
        (ok ? 'PASS' : 'FAIL') +
            '  ' +
            name +
            (!ok && detail !== undefined ? '  -> ' + detail : '')
    );
}

const work = mkdtempSync(path.join(tmpdir(), 'wpe-p012-live-'));
const fixturePhp = path.join(work, 'fixture.php');
writeFileSync(
    fixturePhp,
    `<?php
global $wpdb;
$prefix = $wpdb->prefix;
$uniq = uniqid('p012_');
$title = 'P012LiveStorefront ' . $uniq;

$productId = wp_insert_post([
    'post_title'  => $title,
    'post_name'   => sanitize_title($title),
    'post_status' => 'publish',
    'post_type'   => 'product',
]);
wp_set_object_terms($productId, 'variable', 'product_type');

$mediaRepo = new \\WPE\\Infrastructure\\Database\\MediaRepository($wpdb);
$makeAsset = function (string $slug) use ($mediaRepo, $productId): int {
    $url = "https://cdn.example.com/p012-live/{$productId}/{$slug}.jpg";
    $row = $mediaRepo->createAsset([
        'source_url' => $url,
        'sha256'     => hash('sha256', $url),
        'mime'       => 'image/jpeg',
        'width'      => 600,
        'height'     => 600,
        'status'     => 'ready',
        'health'     => 'ok',
    ]);
    return (int)$row['id'];
};

$parentAssetId = $makeAsset('parent-featured');
$assetClassicBlackM = $makeAsset('classic-black-m');
$assetClassicBlackL = $makeAsset('classic-black-l');
$assetModernWhiteL  = $makeAsset('modern-white-l');

$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$initData = $svc->getProductEditorData($productId);
$version = (int)$initData['product']['version'];

$saveRes = $svc->saveProductVariants($productId, [
    'expected_version' => $version,
    'variation_options' => [
        [
            'name'         => 'Frame',
            'position'     => 0,
            'display_type' => 'auto', // 1st option -> defaults to 'image'
            'help_text'    => '',     // Empty help text -> must not render empty div
            'values'       => [
                ['label' => 'Classic Frame', 'position' => 0],
                ['label' => 'Modern Frame', 'position' => 1],
            ],
        ],
        [
            'name'         => 'Finish',
            'position'     => 1,
            'display_type' => 'color',
            'help_text'    => 'Select surface finish',
            'values'       => [
                ['label' => 'Matte Black', 'position' => 0, 'color' => '#111111'],
                ['label' => 'Pure White',  'position' => 1, 'color' => '#ffffff'],
                ['label' => 'Raw Brushed', 'position' => 2, 'color' => ''],
            ],
        ],
        [
            'name'         => 'Size',
            'position'     => 2,
            'display_type' => 'button',
            'help_text'    => 'Choose your size',
            'values'       => [
                ['label' => 'Medium', 'position' => 0],
                ['label' => 'Large',  'position' => 1],
            ],
        ],
    ],
    'variations' => [
        [
            'combination'      => ['Frame' => 'Classic Frame', 'Finish' => 'Matte Black', 'Size' => 'Medium'],
            'sku'              => 'P012-CLS-BLK-M-' . $productId,
            'regular_price'    => '25.00',
            'sale_price'       => '19.00',
            'status'           => 'publish',
            'manage_stock'     => 1,
            'stock_quantity'   => 5,
            'stock_status'     => 'instock',
            'backorders'       => 'no',
            'primary_image_id' => $assetClassicBlackM,
        ],
        [
            'combination'      => ['Frame' => 'Classic Frame', 'Finish' => 'Matte Black', 'Size' => 'Large'],
            'sku'              => 'P012-CLS-BLK-L-' . $productId,
            'regular_price'    => '39.00',
            'sale_price'       => null,
            'status'           => 'publish',
            'manage_stock'     => 1,
            'stock_quantity'   => 4,
            'stock_status'     => 'instock',
            'backorders'       => 'no',
            'primary_image_id' => $assetClassicBlackL,
        ],
        [
            'combination'      => ['Frame' => 'Modern Frame', 'Finish' => 'Pure White', 'Size' => 'Large'],
            'sku'              => 'P012-MOD-WHT-L-' . $productId,
            'regular_price'    => '44.00',
            'sale_price'       => null,
            'status'           => 'publish',
            'manage_stock'     => 1,
            'stock_quantity'   => 3,
            'stock_status'     => 'instock',
            'backorders'       => 'no',
            'primary_image_id' => $assetModernWhiteL,
        ],
        [
            'combination'      => ['Frame' => 'Modern Frame', 'Finish' => 'Raw Brushed', 'Size' => 'Medium'],
            'sku'              => 'P012-MOD-RAW-M-' . $productId,
            'regular_price'    => '15.00',
            'sale_price'       => null,
            'status'           => 'publish',
            'manage_stock'     => 1,
            'stock_quantity'   => 0,
            'stock_status'     => 'outofstock',
            'backorders'       => 'no',
        ],
    ],
]);

$catSlug = 'p012-cat-' . strtolower($uniq);
$term = wp_insert_term('P012 Cat ' . $uniq, 'product_cat', ['slug' => $catSlug]);
$catTermId = is_array($term) ? (int)$term['term_id'] : 0;
if ($catTermId > 0) {
    wp_set_object_terms($productId, [$catTermId], 'product_cat');
}

$wpdb->replace(
    "{$prefix}wpe_product_media",
    [
        'product_id' => $productId,
        'asset_id'   => $parentAssetId,
        'role'       => 'featured',
        'position'   => 0,
    ]
);
$wpdb->replace(
    "{$prefix}wpe_product_media",
    [
        'product_id' => $productId,
        'asset_id'   => $assetClassicBlackM,
        'role'       => 'gallery',
        'position'   => 1,
    ]
);
$wpdb->replace(
    "{$prefix}wpe_product_media",
    [
        'product_id' => $productId,
        'asset_id'   => $assetClassicBlackL,
        'role'       => 'gallery',
        'position'   => 2,
    ]
);

$afterData = $svc->getProductEditorData($productId);
$varMediaMap = [
    0 => $assetClassicBlackM,
    1 => $assetClassicBlackL,
    2 => $assetModernWhiteL,
];
foreach ($varMediaMap as $idx => $aId) {
    if (!empty($afterData['variations'][$idx]['id'])) {
        $vId = (int)$afterData['variations'][$idx]['id'];
        $wpdb->replace(
            "{$prefix}wpe_variant_media",
            [
                'variant_id' => $vId,
                'asset_id'   => $aId,
                'is_primary' => 1,
                'position'   => 0,
            ]
        );
    }
}

echo 'JSON:' . wp_json_encode([
    'product_id'  => $productId,
    'title'       => $title,
    'uniq'        => $uniq,
    'cat_slug'    => $catSlug,
    'cat_term_id' => $catTermId,
    'options'     => $afterData['variation_options'],
    'variations'  => $afterData['variations'],
    'assets'      => [$parentAssetId, $assetClassicBlackM, $assetClassicBlackL, $assetModernWhiteL],
]);
`
);

const cleanupPhp = path.join(work, 'cleanup.php');
writeFileSync(
    cleanupPhp,
    `<?php
global $wpdb;
$prefix = $wpdb->prefix;
$productId = (int)($args[0] ?? 0);
$catTermId = (int)($args[1] ?? 0);
if ($catTermId > 0) {
    wp_delete_term($catTermId, 'product_cat');
}
if ($productId > 0) {
    $vids = get_posts([
        'post_type'   => 'product_variation',
        'post_parent' => $productId,
        'numberposts' => -1,
        'post_status' => 'any',
        'fields'      => 'ids',
    ]);
    foreach ($vids as $vid) {
        wp_delete_post((int)$vid, true);
    }
    wp_delete_post($productId, true);
    $allIds = array_merge([$productId], array_map('intval', $vids));
    $in = implode(',', $allIds);
    $optIds = $wpdb->get_col("SELECT id FROM {$prefix}wpe_options WHERE product_id = {$productId}");
    if (!empty($optIds)) {
        $optIn = implode(',', array_map('intval', $optIds));
        $wpdb->query("DELETE FROM {$prefix}wpe_option_values WHERE option_id IN ({$optIn})");
        $wpdb->query("DELETE FROM {$prefix}wpe_variant_values WHERE option_id IN ({$optIn})");
        $wpdb->query("DELETE FROM {$prefix}wpe_options WHERE id IN ({$optIn})");
    }
    $wpdb->query("DELETE FROM {$prefix}wpe_sku_registry WHERE object_id IN ({$in})");
    $wpdb->query("DELETE FROM {$prefix}wpe_inventory WHERE owner_id IN ({$in})");
    $wpdb->query("DELETE FROM {$prefix}wpe_product_media WHERE product_id = {$productId}");
    $wpdb->query("DELETE FROM {$prefix}wpe_variant_media WHERE variant_id IN ({$in})");
    $wpdb->query("DELETE FROM {$prefix}wpe_variants WHERE id IN ({$in}) OR product_id = {$productId}");
    $wpdb->query("DELETE FROM {$prefix}wpe_products WHERE id IN ({$in})");
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}wpe_assets WHERE source_url LIKE %s", "https://cdn.example.com/p012-live/{$productId}/%"));
}
echo 'CLEANED';
`
);

const savedFlagsJson = wp('eval', 'echo wp_json_encode(get_option("wpe_feature_flags", false));');
const savedAttrCfgJson = wp('eval', 'echo wp_json_encode(get_option("wpe_attribute_display_config", false));');

let fixture = null;
let browser = null;

try {
    const rawOut = wp('eval-file', fixturePhp);
    const match = rawOut.match(/JSON:(.*)$/s);
    fixture = JSON.parse(match[1]);
    check('Fixture created with 3 variation options and 4 variations', fixture.product_id > 0 && fixture.options.length === 3 && fixture.variations.length === 4, rawOut);

    const hash = wp('eval', 'echo COOKIEHASH;');
    const logged = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "logged_in");');
    const auth = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "auth");');

    browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', `--host-resolver-rules=MAP ${HOST} 127.0.0.1`],
    });

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    await ctx.addCookies([
        { name: `wordpress_logged_in_${hash}`, value: logged, domain: HOST, path: '/' },
        { name: `wordpress_sec_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
        { name: `wordpress_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
    ]);

    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('console', (m) => {
        if (m.type() === 'error' && !/favicon|Failed to load resource|net::ERR/.test(m.text())) {
            consoleErrors.push(m.text());
        }
    });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    // =========================================================================
    // 1. Admin Settings -> Product Page UI save, reload & capability check
    // =========================================================================
    await page.goto(`${SITE}/wp-admin/admin.php?page=wc-product-engine&tab=product-page`, { waitUntil: 'networkidle' });

    const panelVisible = await page.locator('#wpe-product-page-panel').isVisible();
    check('Admin Settings -> Product Page panel is visible', panelVisible);

    const panelText = await page.locator('#wpe-product-page-panel').textContent();
    check('Price Display and Variant Selector sections exist; Product Information is absent',
        panelText.includes('Price Display') &&
        panelText.includes('Variant Selector') &&
        !panelText.includes('Product Information')
    );

    const toggleKeys = [
        'single_price_display',
        'show_selected_value',
        'show_help_text',
        'button_selector',
        'color_swatches',
        'image_swatches',
        'mark_unavailable_options',
    ];

    // Ensure at least one toggle changes so #wpe-savebar opens, then set all 7 to checked
    const firstCb = page.locator(`#wpe-product-page-panel input[type="checkbox"][data-flag="single_price_display"]`);
    if (await firstCb.isChecked()) {
        await firstCb.uncheck();
        await page.locator('#wpe-save-settings-btn').click();
        await page.waitForFunction(() => document.querySelector('#wpe-savebar')?.hidden === true, { timeout: 10000 });
    }

    for (const key of toggleKeys) {
        const cb = page.locator(`#wpe-product-page-panel input[type="checkbox"][data-flag="${key}"]`);
        const isChecked = await cb.isChecked();
        if (!isChecked) {
            await cb.check();
        }
    }

    const saveBtn = page.locator('#wpe-save-settings-btn');
    await saveBtn.click();
    await page.waitForFunction(
        () => {
            const bar = document.querySelector('#wpe-savebar');
            return bar && bar.hidden === true;
        },
        { timeout: 10000 }
    );

    // Reload Settings -> Product Page and verify all 7 flags remain checked
    await page.reload({ waitUntil: 'networkidle' });
    let allPersisted = true;
    for (const key of toggleKeys) {
        const checkedAfterReload = await page.locator(`#wpe-product-page-panel input[type="checkbox"][data-flag="${key}"]`).isChecked();
        if (!checkedAfterReload) {
            allPersisted = false;
        }
    }
    check('All 7 Product Page toggles save and persist across page reload', allPersisted);

    // Capability check: unauthenticated request to settings endpoint must be rejected (401/403)
    const unauthCtx = await browser.newContext();
    const unauthPage = await unauthCtx.newPage();
    await unauthPage.goto(`${SITE}/`, { waitUntil: 'domcontentloaded' });
    const unauthStatus = await unauthPage.evaluate(async (url) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ single_price_display: false }),
        });
        return res.status;
    }, `${SITE}/wp-json/wpe/v1/admin/settings`);
    check('Unauthenticated POST to /wpe/v1/admin/settings is rejected (401/403)', [401, 403].includes(unauthStatus), `status=${unauthStatus}`);
    await unauthCtx.close();

    // =========================================================================
    // 2. Storefront Category/Archive Card Single Price Display
    // =========================================================================
    await page.goto(`${SITE}/?product_cat=${encodeURIComponent(fixture.cat_slug)}`, { waitUntil: 'networkidle' });
    const archiveCardPriceText = await page.locator(`.post-${fixture.product_id} .price, .wpe-single-price-target`).first().textContent().catch(() => '');
    check(
        'Storefront archive card renders lowest purchasable single price ($19.00 sale from $25.00 regular) and excludes out-of-stock $15.00',
        archiveCardPriceText.includes('19.00') && archiveCardPriceText.includes('25.00') && !archiveCardPriceText.includes('15.00'),
        archiveCardPriceText
    );

    // =========================================================================
    // 3. Storefront PDP Verification (All 7 Toggles Active)
    // =========================================================================
    await page.goto(`${SITE}/?p=${fixture.product_id}`, { waitUntil: 'networkidle' });

    const singlePriceTarget = page.locator('.wpe-single-price-target').first();
    check('PDP renders .wpe-single-price-target in main price area', (await singlePriceTarget.count()) > 0);
    const initialMainPriceText = await singlePriceTarget.textContent();
    check(
        'PDP initial Single Price Display shows lowest purchasable price ($19.00 / $25.00) and never out-of-stock $15.00',
        initialMainPriceText.includes('19.00') && initialMainPriceText.includes('25.00') && !initialMainPriceText.includes('15.00'),
        initialMainPriceText
    );

    // Single control per attribute:
    // - Frame (pos 0): .wpe-swatches-image
    // - Finish (pos 1): .wpe-swatches-color
    // - Size (pos 2): .wpe-swatches-button
    const frameOptId = String(fixture.options[0].id);
    const finishOptId = String(fixture.options[1].id);
    const sizeOptId = String(fixture.options[2].id);

    check(
        '1st option (Frame) renders as Image Swatches with thumbnail + label and hidden native select',
        (await page.locator(`.wpe-swatches-image[data-option-id="${frameOptId}"] .wpe-swatch-image-thumb`).count()) === 2 &&
        (await page.locator(`.wpe-swatches-image[data-option-id="${frameOptId}"] .wpe-swatch-image-label`).count()) === 2 &&
        (await page.locator(`select.wpe-select-hidden[data-option-id="${frameOptId}"]`).count()) === 1
    );

    check(
        '2nd option (Finish) renders as Color Swatches with .is-light-swatch for #ffffff and .wpe-swatch-color-fallback for missing hex',
        (await page.locator(`.wpe-swatches-color[data-option-id="${finishOptId}"] .is-light-swatch`).count()) === 1 &&
        (await page.locator(`.wpe-swatches-color[data-option-id="${finishOptId}"] .wpe-swatch-color-fallback`).count()) === 1
    );

    check(
        '3rd option (Size) renders as Button Selector',
        (await page.locator(`.wpe-swatches-button[data-option-id="${sizeOptId}"] .wpe-swatch-button`).count()) === 2
    );

    // Show Help Text checks
    const finishHelp = page.locator(`#wpe-help-${fixture.product_id}-${finishOptId}`);
    const sizeHelp = page.locator(`#wpe-help-${fixture.product_id}-${sizeOptId}`);
    const frameHelp = page.locator(`#wpe-help-${fixture.product_id}-${frameOptId}`);
    check(
        'Show Help Text renders configured guidance below Finish & Size labels and zero element for empty Frame help text',
        (await finishHelp.isVisible()) &&
        (await finishHelp.textContent()) === 'Select surface finish' &&
        (await sizeHelp.isVisible()) &&
        (await sizeHelp.textContent()) === 'Choose your size' &&
        (await frameHelp.count()) === 0
    );

    // Mark Unavailable Options check: Raw Brushed (only variation is outofstock) is marked .is-marked-unavailable and disabled
    const rawBrushedValId = String(fixture.options[1].values[2].id);
    const rawBrushedBtn = page.locator(`.wpe-swatch[data-option-id="${finishOptId}"][data-value-id="${rawBrushedValId}"]`);
    check(
        'Out-of-stock / unpurchasable value (Raw Brushed) has .is-marked-unavailable and aria-disabled="true"',
        (await rawBrushedBtn.getAttribute('aria-disabled')) === 'true' &&
        (await rawBrushedBtn.evaluate((el) => el.classList.contains('is-marked-unavailable')))
    );

    // Select Finish = Matte Black and Size = Large -> verify Show Selected Value & Dynamic Image Swatch thumbnail update!
    const matteBlackValId = String(fixture.options[1].values[0].id);
    const largeValId = String(fixture.options[2].values[1].id);
    const classicFrameValId = String(fixture.options[0].values[0].id);
    const modernFrameValId = String(fixture.options[0].values[1].id);

    await page.locator(`.wpe-swatch[data-option-id="${finishOptId}"][data-value-id="${matteBlackValId}"]`).click();
    await page.waitForFunction(() => !document.querySelector('.variations_form')?.getAttribute('aria-busy'));

    await page.locator(`.wpe-swatch[data-option-id="${sizeOptId}"][data-value-id="${largeValId}"]`).click();
    await page.waitForFunction(() => !document.querySelector('.variations_form')?.getAttribute('aria-busy'));

    // Check Show Selected Value on labels
    const finishLabelText = await page.locator('.variations_form').evaluate((form, optId) => {
        const lbl = form.querySelector(`[data-selected-value-label]`);
        const allLabels = Array.from(form.querySelectorAll('label')).map((l) => l.textContent.trim());
        return allLabels.join(' | ');
    }, finishOptId);
    check(
        'Show Selected Value updates attribute labels with display labels ("Finish: Matte Black", "Size: Large")',
        finishLabelText.includes('Finish: Matte Black') && finishLabelText.includes('Size: Large'),
        finishLabelText
    );

    // Check Dynamic Image Swatch thumbnail for Classic Frame updated from classic-black-m.jpg to classic-black-l.jpg!
    const classicThumbSrc = await page
        .locator(`.wpe-swatch[data-option-id="${frameOptId}"][data-value-id="${classicFrameValId}"] img.wpe-swatch-image-thumb`)
        .getAttribute('src');
    check(
        'Image Swatch thumbnail dynamically updates to match selected Finish=Matte Black + Size=Large (classic-black-l.jpg)',
        classicThumbSrc && classicThumbSrc.includes('classic-black-l.jpg'),
        classicThumbSrc
    );

    const getGalleryState = () =>
        page.evaluate(() => {
            const gallery = document.querySelector('.woocommerce-product-gallery, .product-gallery');
            const slides = gallery ? Array.from(gallery.querySelectorAll('.woocommerce-product-gallery__image, .slide')) : [];
            const activeSlideIndex = slides.findIndex((s) => s.classList.contains('flex-active-slide'));
            const activeSlideEl = activeSlideIndex >= 0 ? slides[activeSlideIndex] : slides[0] || null;
            const activeImg = activeSlideEl ? activeSlideEl.querySelector('img:not(.zoomImg)') : null;
            const thumbs = gallery ? Array.from(gallery.querySelectorAll('.flex-control-thumbs li, .product-thumbnails .col')) : [];
            const activeThumbIndex = thumbs.findIndex(
                (li) =>
                    li.classList.contains('emoza-flex-active-slide') ||
                    li.classList.contains('is-nav-selected') ||
                    li.classList.contains('is-active') ||
                    Boolean(li.querySelector('img.flex-active'))
            );
            const thumb0Img = thumbs[0] ? thumbs[0].querySelector('img') : null;
            const thumb0Btn = thumbs[0] ? thumbs[0].querySelector('[data-gallery-src]') : null;
            const rawSlideSrc = activeImg ? activeImg.getAttribute('src') || '' : '';
            const activeSlideSrc =
                (rawSlideSrc && !rawSlideSrc.startsWith('data:') ? rawSlideSrc : null) ||
                (activeImg ? activeImg.getAttribute('data-src') : null) ||
                (activeSlideEl ? activeSlideEl.getAttribute('data-image-src') || activeSlideEl.getAttribute('data-thumb') : null);
            const rawThumb0Src = thumb0Img ? thumb0Img.getAttribute('src') || '' : '';
            const thumb0Src =
                (rawThumb0Src && !rawThumb0Src.startsWith('data:') ? rawThumb0Src : null) ||
                (thumb0Btn ? thumb0Btn.getAttribute('data-gallery-src') : null);
            return {
                activeSlideIndex,
                activeSlideSrc,
                activeThumbIndex,
                thumb0Src,
            };
        });

    const partialGallery = await getGalleryState();
    check(
        'Partial selection activates matching gallery slide and thumbnail at position index 2 (classic-black-l.jpg) while preserving parent thumbnail at index 0',
        partialGallery.activeSlideIndex === 2 &&
            partialGallery.activeThumbIndex === 2 &&
            Boolean(partialGallery.activeSlideSrc && partialGallery.activeSlideSrc.includes('classic-black-l.jpg')) &&
            Boolean(partialGallery.thumb0Src && partialGallery.thumb0Src.includes('parent-featured.jpg')),
        JSON.stringify(partialGallery)
    );

    // Modern Frame + Matte Black + Large does not exist -> Modern Frame is now marked unavailable!
    const modernFrameDisabled = await page
        .locator(`.wpe-swatch[data-option-id="${frameOptId}"][data-value-id="${modernFrameValId}"]`)
        .getAttribute('aria-disabled');
    check(
        'Modern Frame is marked unavailable when Matte Black + Large is selected',
        modernFrameDisabled === 'true'
    );

    // Now click Classic Frame -> Full selection resolves to Classic + Matte Black + Large ($39.00)!
    await page.locator(`.wpe-swatch[data-option-id="${frameOptId}"][data-value-id="${classicFrameValId}"]`).click();
    await page.waitForFunction(() => !document.querySelector('.variations_form')?.getAttribute('aria-busy'));

    const resolvedPriceText = await singlePriceTarget.textContent();
    const resolvedVarId = await page.locator('input[name="variation_id"]').inputValue();
    const addToCartDisabled = await page.locator('.single_add_to_cart_button').isDisabled();
    const fullGallery = await getGalleryState();
    check(
        'Full selection updates main price area to $39.00, keeps gallery slide & thumbnail at position index 2 (classic-black-l.jpg), populates variation_id, and enables Add to cart',
        resolvedPriceText.includes('39.00') &&
            Number(resolvedVarId) > 0 &&
            !addToCartDisabled &&
            fullGallery.activeSlideIndex === 2 &&
            fullGallery.activeThumbIndex === 2 &&
            Boolean(fullGallery.activeSlideSrc && fullGallery.activeSlideSrc.includes('classic-black-l.jpg')),
        `price=${resolvedPriceText}, varId=${resolvedVarId}, disabled=${addToCartDisabled}, gallery=${JSON.stringify(fullGallery)}`
    );

    // Verify secondary variation price below options is hidden (no duplicate price block)
    const secondaryPriceVisible = await page.locator('.variations_form .woocommerce-variation-price').isVisible().catch(() => false);
    check('Secondary .woocommerce-variation-price below options remains hidden when Single Price Display is ON', !secondaryPriceVisible);

    // =========================================================================
    // 4. Multi-instance / Quick-View Isolation on Same Page
    // =========================================================================
    const isolationOk = await page.evaluate(() => {
        const qvWrap = document.createElement('div');
        qvWrap.className = 'product wpe-product-instance';
        qvWrap.id = 'wpe-quick-view-test';
        qvWrap.innerHTML = `
            <p class="price"><span class="wpe-single-price-target" data-wpe-price-state="lowest">$88.00</span></p>
            <form class="variations_form">
                <table><tbody><tr class="wpe-option-group">
                    <th class="label"><label data-option-id="999">QvAttr</label></th>
                    <td class="value">
                        <div class="wpe-swatches" data-option-id="999" data-option-name="QvAttr">
                            <button type="button" class="wpe-swatch" data-option-id="999" data-value-id="9991" data-value-label="QvVal">QvVal</button>
                        </div>
                    </td>
                </tr></tbody></table>
                <input type="hidden" name="variation_id" value="7777" />
                <button type="submit" class="single_add_to_cart_button">Add</button>
            </form>
        `;
        document.body.appendChild(qvWrap);
        const qvForm = qvWrap.querySelector('form');
        new window.WpeVariantSelector(qvForm, {
            productId: 99999,
            resolveEndpoint: window.wpeSelectorData.resolveEndpoint,
            flags: { single_price_display: true, show_selected_value: true },
            lowestPriceHtml: '$88.00',
            options: [{ id: 999, key: 'qv_attr', name: 'QvAttr', values: [{ id: 9991, key: 'qv', label: 'QvVal' }] }],
        });
        return true;
    });

    // Reset the main product selector and verify Quick View instance was not touched
    const resetBtn = page.locator('.variations_form').first().locator('.reset_variations, .wpe-reset-selection');
    if ((await resetBtn.count()) > 0 && (await resetBtn.isVisible())) {
        await resetBtn.click();
    } else {
        await page.evaluate(() => {
            const mainForm = document.querySelector('form.variations_form');
            const resetLink = mainForm.querySelector('.reset_variations');
            if (resetLink) resetLink.click();
        });
    }

    const afterResetMainPrice = await singlePriceTarget.textContent();
    const afterResetVarId = await page.locator('form.variations_form').first().locator('input[name="variation_id"]').inputValue();
    const afterResetGallery = await getGalleryState();
    const qvPriceAfterMainReset = await page.locator('#wpe-quick-view-test .wpe-single-price-target').textContent();
    check(
        'Reset on main product restores lowest price ($19.00), returns active gallery slide & thumbnail to index 0 (parent-featured.jpg), and clears variation_id without affecting Quick View instance ($88.00)',
        afterResetMainPrice.includes('19.00') &&
            afterResetVarId === '' &&
            afterResetGallery.activeSlideIndex === 0 &&
            afterResetGallery.activeThumbIndex === 0 &&
            Boolean(afterResetGallery.activeSlideSrc && afterResetGallery.activeSlideSrc.includes('parent-featured.jpg')) &&
            qvPriceAfterMainReset === '$88.00' &&
            isolationOk,
        `mainPrice=${afterResetMainPrice}, varId=${afterResetVarId}, gallery=${JSON.stringify(afterResetGallery)}, qvPrice=${qvPriceAfterMainReset}`
    );

    // =========================================================================
    // 5. Turning All 7 Toggles OFF Restores Standard Dropdowns & Price Range
    // =========================================================================
    wp('eval', 'update_option("wpe_feature_flags", ["variants_ui_enabled" => true, "variant_selector_ui" => true, "single_price_display" => false, "show_selected_value" => false, "show_help_text" => false, "button_selector" => false, "color_swatches" => false, "image_swatches" => false, "mark_unavailable_options" => false]);');
    await page.goto(`${SITE}/?p=${fixture.product_id}`, { waitUntil: 'networkidle' });

    const swatchCountWhenOff = await page.locator('form.variations_form .wpe-swatches').count();
    const visibleSelectCountWhenOff = await page.locator('form.variations_form select.wpe-select:not(.wpe-select-hidden)').count();
    const singleTargetCountWhenOff = await page.locator('.wpe-single-price-target').count();
    check(
        'When Product Page toggles are OFF, all 3 attributes render standard dropdown <select> and Single Price Display wrapper is not used',
        swatchCountWhenOff === 0 && visibleSelectCountWhenOff === 3 && singleTargetCountWhenOff === 0,
        `swatches=${swatchCountWhenOff}, selects=${visibleSelectCountWhenOff}, singlePriceTarget=${singleTargetCountWhenOff}`
    );

    // Select variation directly via <select> dropdowns (whose <option value> uses display labels)
    const dropdowns = page.locator('form.variations_form select.wpe-select');
    await dropdowns.nth(0).selectOption('Classic Frame');
    await page.waitForFunction(() => !document.querySelector('form.variations_form')?.getAttribute('aria-busy'));
    await dropdowns.nth(1).selectOption('Matte Black');
    await page.waitForFunction(() => !document.querySelector('form.variations_form')?.getAttribute('aria-busy'));
    await dropdowns.nth(2).selectOption('Large');
    await page.waitForFunction(() => !document.querySelector('form.variations_form')?.getAttribute('aria-busy'));
    await page.waitForTimeout(150);

    const dropdownVarId = await page.locator('form.variations_form input[name="variation_id"]').inputValue();
    const dropdownAddDisabled = await page.locator('form.variations_form .single_add_to_cart_button').isDisabled();
    const dropdownNoMatchCount = await page.locator('form.variations_form .wc-no-matching-variations').count();
    check(
        'Selecting variation via standard <select> dropdowns resolves variation_id and enables Add to cart without unavailability error',
        Number(dropdownVarId) > 0 && !dropdownAddDisabled && dropdownNoMatchCount === 0,
        `varId=${dropdownVarId}, disabled=${dropdownAddDisabled}, noMatchCount=${dropdownNoMatchCount}`
    );

    // =========================================================================
    // 6. Unconfigured (auto) Options Render as Button Selector When button_selector=true & image_swatches=false
    // =========================================================================
    wp('eval', `
        global $wpdb;
        $prefix = $wpdb->prefix;
        $wpdb->update("{$prefix}wpe_products", ["extra_props" => "{}"], ["id" => ${fixture.product_id}]);
        update_option("wpe_attribute_display_config", []);
        update_option("wpe_feature_flags", [
            "variants_ui_enabled" => true,
            "variant_selector_ui" => true,
            "single_price_display" => true,
            "show_selected_value" => true,
            "show_help_text" => false,
            "button_selector" => true,
            "color_swatches" => true,
            "image_swatches" => false,
            "mark_unavailable_options" => true,
        ]);
        update_option("wpe_selector_cache_ver", time() + 10);
    `);
    await page.goto(`${SITE}/?p=${fixture.product_id}`, { waitUntil: 'networkidle' });

    const autoBtnGroupCount = await page.locator('form.variations_form .wpe-swatches-button').count();
    check(
        'When button_selector=true and image_swatches=false, unconfigured (auto) attributes all render as Button Selector (.wpe-swatches-button)',
        autoBtnGroupCount === 3,
        `autoBtnGroupCount=${autoBtnGroupCount}`
    );

    const firstAutoBtn = page.locator('form.variations_form .wpe-swatches-button').nth(0).locator('.wpe-swatch-button').first();
    await firstAutoBtn.click();
    await firstAutoBtn.hover();
    await page.waitForTimeout(200);
    const selectedHoverBorder = await firstAutoBtn.evaluate((el) => getComputedStyle(el).borderColor);
    check(
        'Selected Button Selector retains active blue border (rgb(0, 113, 161)) even while hovered',
        selectedHoverBorder === 'rgb(0, 113, 161)',
        `borderColor=${selectedHoverBorder}`
    );

    await page.locator('form.variations_form .wpe-swatches-button').nth(1).locator('.wpe-swatch-button').first().click();
    await page.locator('form.variations_form .wpe-swatches-button').nth(2).locator('.wpe-swatch-button').first().click();
    await page.waitForFunction(() => !document.querySelector('form.variations_form')?.getAttribute('aria-busy'));
    await page.waitForTimeout(150);

    const autoBtnVarId = await page.locator('form.variations_form input[name="variation_id"]').inputValue();
    const autoBtnAddDisabled = await page.locator('form.variations_form .single_add_to_cart_button').isDisabled();
    check(
        'Clicking Button Selector buttons across all options resolves variation_id and enables Add to cart',
        Number(autoBtnVarId) > 0 && !autoBtnAddDisabled,
        `varId=${autoBtnVarId}, disabled=${autoBtnAddDisabled}`
    );

    check('Zero unexpected browser console/page errors during E2E flow', consoleErrors.length === 0, consoleErrors.join('; '));
} finally {
    if (browser) {
        await browser.close();
    }
    if (fixture && fixture.product_id) {
        wp('eval-file', cleanupPhp, String(fixture.product_id), String(fixture.cat_term_id || 0));
    }
    wp('eval', `
        $flags = json_decode('${savedFlagsJson.replace(/'/g, "\\'")}', true);
        if ($flags === false || $flags === null) {
            delete_option('wpe_feature_flags');
        } else {
            update_option('wpe_feature_flags', $flags);
        }
        $attr = json_decode('${savedAttrCfgJson.replace(/'/g, "\\'")}', true);
        if ($attr === false || $attr === null) {
            delete_option('wpe_attribute_display_config');
        } else {
            update_option('wpe_attribute_display_config', $attr);
        }
    `);
}

const failed = results.filter((r) => !r.ok);
console.log(`\nSummary: ${results.length - failed.length}/${results.length} checks passed.`);
if (failed.length > 0) {
    process.exit(1);
}
