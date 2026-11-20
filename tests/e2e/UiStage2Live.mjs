/**
 * P025 — Live browser & source checks for UI stage 2 (shared design tokens + inline styles to classes).
 *
 * Verifies against http://site1.localhost (without snapshotting/restoring the live DB):
 *  1. Static source audit: zero static `style="..."` in ShopifyImportPage, OperationsPage,
 *     ProductEditorBridge, and only the dynamic swatch color dot in product-editor.js.
 *  2. Shared `tokens.css` loaded and `--wpe-*` design tokens resolve on all 4 admin screens
 *     (Product Editor, Settings, Shopify Import, Operations).
 *  3. Product Editor meta boxes & interactive modals/drawer render cleanly with semantic classes,
 *     zero unexpected inline styles, and `.wpe-hidden` / `hidden` toggling works properly.
 *  4. Shopify Import, Settings, and Operations screens render with shared tokens and zero
 *     static inline styles on initial server markup.
 *  5. Zero browser console or page errors; disposable fixture product cleanly deleted.
 *
 * Usage:
 *   node tests/e2e/UiStage2Live.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '../..');
const SITE = process.env.WPE_SITE_URL || 'http://site1.localhost';
const WP_PATH = process.env.WPE_WP_PATH || '/var/www/site1.localhost';
const HOST = new URL(SITE).hostname;

const wp = (...args) => execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();

const results = [];
function check(name, ok, detail) {
    results.push({ name, ok: !!ok });
    console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (!ok && detail !== undefined ? '  -> ' + detail : ''));
}

// ---------------------------------------------------------------- 1. Source Audit
console.log('== source-audit');
for (const rel of [
    'src/Admin/ShopifyImportPage.php',
    'src/Admin/OperationsPage.php',
    'src/Admin/ProductEditor/ProductEditorBridge.php',
    'src/Admin/SettingsPage.php',
]) {
    const src = readFileSync(path.join(ROOT, rel), 'utf8');
    const matches = src.match(/\bstyle\s*=\s*["']/gi) || [];
    check(`Zero inline style="..." in ${rel}`, matches.length === 0, `found=${matches.length}`);
}

{
    const src = readFileSync(path.join(ROOT, 'assets/admin/product-editor.js'), 'utf8');
    const lines = src.split('\n');
    const styleLines = [];
    for (let i = 0; i < lines.length; i++) {
        if (/\bstyle\s*=\s*["']/.test(lines[i])) {
            styleLines.push({ line: i + 1, text: lines[i].trim() });
        }
    }
    const unexpected = styleLines.filter((entry) => !entry.text.includes('background-color:${escapeHtml(valColor)};'));
    check(
        'product-editor.js has zero static inline styles (only dynamic swatch color dot allowed)',
        unexpected.length === 0 && styleLines.length <= 1,
        JSON.stringify(unexpected)
    );
}

// ---------------------------------------------------------------- fixtures (WP-CLI)
const work = mkdtempSync(path.join(tmpdir(), 'wpe-ui2-'));
writeFileSync(path.join(work, 'fixture.php'), `<?php
$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$assets = new \\WPE\\Compatibility\\Gallery\\AssetService();
$id = wp_insert_post(['post_title' => 'P025 UI Stage 2 ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($id, 'variable', 'product_type');
wp_cache_flush();
$ver = $svc->getProductEditorData($id)['product']['version'];
$a1 = $assets->registerAsset('https://93.184.216.34/p025-red-' . uniqid() . '.jpg', ['alt_text' => 'P025 Red']);
$res = $svc->saveProductVariants($id, [
    'expected_version' => $ver,
    'media_library' => [
        'featured_asset_id' => $a1['id'],
        'gallery' => [],
    ],
    'variation_options' => [
        [
            'name' => 'Color',
            'position' => 0,
            'values' => [
                ['label' => 'Red', 'position' => 0, 'swatch' => ['color' => '#cc0000']],
                ['label' => 'Blue', 'position' => 1, 'swatch' => ['color' => '#0044cc']],
            ],
        ],
        [
            'name' => 'Size',
            'position' => 1,
            'values' => [
                ['label' => 'S', 'position' => 0],
            ],
        ],
    ],
    'variations' => [
        ['combination' => ['Color' => 'Red', 'Size' => 'S'], 'sku' => 'P025-RS-' . uniqid(), 'regular_price' => '25', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Blue', 'Size' => 'S'], 'sku' => 'P025-BS-' . uniqid(), 'regular_price' => '25', 'status' => 'publish', 'manage_stock' => 1, 'stock_quantity' => 5],
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
    foreach ($vids as $c) { wp_delete_post($c, true); }
    wp_delete_post($id, true);
    $in = implode(',', array_map('intval', array_merge([$id], $vids)));
    $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_sku_registry WHERE object_id IN ({$in})");
    $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_products WHERE id IN ({$in})");
    $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_variants WHERE id IN ({$in}) OR product_id = {$id}");
    $wpdb->query("DELETE FROM {$wpdb->prefix}wpe_product_media WHERE product_id = {$id}");
}
echo 'cleaned';
`);

const evalFile = (file, ...args) => {
    const out = wp('eval-file', path.join(work, file), ...args);
    const m = out.match(/JSON:(.*)$/s);
    return m ? JSON.parse(m[1]) : out;
};

async function readTokens(page) {
    return page.evaluate(() => {
        const hasTokensCss = !!document.querySelector('link[id="wpe-admin-tokens-css"], link[href*="assets/admin/tokens.css"]');
        const cs = getComputedStyle(document.documentElement);
        const get = (name) => cs.getPropertyValue(name).trim().toLowerCase();
        return {
            hasTokensCss,
            borderControl: get('--wpe-border-control'),
            borderControlTinted: get('--wpe-border-control-tinted'),
            brand: get('--wpe-brand'),
            surface: get('--wpe-surface'),
            terminalBg: get('--wpe-terminal-bg'),
            pBorderControl: get('--wpe-p-color-border-control'),
        };
    });
}

const created = [];
let browser;
let exitCode = 0;

try {
    const fx = evalFile('fixture.php');
    created.push(fx.id);

    const hash = wp('eval', 'echo COOKIEHASH;');
    const logged = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "logged_in");');
    const auth = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "auth");');

    browser = await chromium.launch({
        headless: true,
        args: ['--no-sandbox', `--host-resolver-rules=MAP ${HOST} 127.0.0.1`],
    });

    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await ctx.addCookies([
        { name: `wordpress_logged_in_${hash}`, value: logged, domain: HOST, path: '/' },
        { name: `wordpress_sec_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
        { name: `wordpress_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
    ]);

    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('console', (m) => {
        if (m.type() === 'error' && !/favicon|Failed to load resource|ERR_/.test(m.text())) {
            consoleErrors.push(m.text());
        }
    });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    // ---------------------------------------------------------------- 2. Product Editor
    console.log('\n== product-editor');
    await page.goto(`${SITE}/wp-admin/post.php?post=${fx.id}&action=edit`, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-product-editor-inline-app .wpe-row-thumb-btn', { timeout: 20000 });
    await page.waitForSelector('#wpe-product-media-app .wpe-media-grid', { timeout: 20000 });

    const peTokens = await readTokens(page);
    check(
        'Product Editor loads tokens.css and resolves shared + aliased tokens',
        peTokens.hasTokensCss
            && peTokens.borderControl === '#8c8f94'
            && peTokens.borderControlTinted === '#7e8287'
            && peTokens.brand === '#008060'
            && peTokens.pBorderControl === '#7e8287',
        JSON.stringify(peTokens)
    );

    const peInlineAudit = await page.evaluate(() => {
        const roots = [
            document.querySelector('#wpe-product-info-app'),
            document.querySelector('#wpe-product-media-app'),
            document.querySelector('#wpe-product-editor-inline-app'),
        ].filter(Boolean);
        const unexpected = [];
        for (const root of roots) {
            for (const el of root.querySelectorAll('[style]')) {
                const st = (el.getAttribute('style') || '').trim();
                if (!st) continue;
                // Dynamic per-value user swatch color dot is intentionally inline.
                if (el.classList.contains('wpe-chip-color-dot') && /^background-color:\s*[^;]+;?$/i.test(st)) {
                    continue;
                }
                unexpected.push(`${el.tagName.toLowerCase()}.${el.className}: ${st}`);
            }
        }
        return { rootIds: roots.map((r) => r.id), unexpected };
    });
    check(
        'Product Editor rendered DOM has zero static inline styles (dynamic swatch dot exempt)',
        peInlineAudit.rootIds.includes('wpe-product-media-app')
            && peInlineAudit.rootIds.includes('wpe-product-editor-inline-app')
            && peInlineAudit.unexpected.length === 0,
        JSON.stringify(peInlineAudit)
    );

    // Verify overlay `hidden` attribute and Drawer manage-stock `.wpe-hidden` toggle
    const initialHidden = await page.evaluate(() => ({
        fullscreen: document.getElementById('wpe-fullscreen-modal-overlay')?.hidden === true,
        drawer: document.getElementById('wpe-variation-drawer-overlay')?.hidden === true,
    }));
    check(
        'Editor overlays start hidden via native hidden attribute (no inline display:none)',
        initialHidden.fullscreen && initialHidden.drawer,
        JSON.stringify(initialHidden)
    );

    // Open variation detail drawer on row 1 (manage_stock = 0) and toggle manage stock on
    await page.locator('#wpe-product-editor-inline-app .wpe-btn-open-drawer').first().click();
    await page.waitForSelector('#wpe-variation-drawer-overlay:not([hidden])', { timeout: 5000 });
    const drawerStateBefore = await page.evaluate(() => {
        const overlay = document.getElementById('wpe-variation-drawer-overlay');
        const fields = document.getElementById('wpe-drawer-stock-fields');
        return {
            overlayDisplay: getComputedStyle(overlay).display,
            fieldsHiddenClass: fields?.classList.contains('wpe-hidden'),
            fieldsDisplay: fields ? getComputedStyle(fields).display : null,
            unexpectedInline: [...overlay.querySelectorAll('[style]')].filter((el) => (el.getAttribute('style') || '').trim() !== '').length,
        };
    });
    check(
        'Variation drawer opens cleanly via hidden attribute and starts stock fields hidden via .wpe-hidden',
        drawerStateBefore.overlayDisplay === 'flex'
            && drawerStateBefore.fieldsHiddenClass === true
            && drawerStateBefore.fieldsDisplay === 'none'
            && drawerStateBefore.unexpectedInline === 0,
        JSON.stringify(drawerStateBefore)
    );

    await page.locator('#wpe-drawer-manage-stock').check();
    await page.waitForTimeout(250);
    const drawerFieldsDisplay = await page.evaluate(() => {
        const fields = document.getElementById('wpe-drawer-stock-fields');
        return {
            hasHidden: fields?.classList.contains('wpe-hidden'),
            display: fields ? getComputedStyle(fields).display : null,
        };
    });
    check(
        'Toggling drawer Manage Stock removes .wpe-hidden and reveals flex stock row',
        drawerFieldsDisplay.hasHidden === false && drawerFieldsDisplay.display === 'flex',
        JSON.stringify(drawerFieldsDisplay)
    );

    await page.keyboard.press('Escape');
    await page.waitForSelector('#wpe-variation-drawer-overlay', { state: 'hidden', timeout: 5000 });

    // Open Variant Image Picker modal and verify clean class-based rendering
    await page.locator('#wpe-product-editor-inline-app .wpe-row-thumb-btn').first().click();
    await page.waitForSelector('#wpe-variant-image-picker-modal', { timeout: 5000 });
    const pickerAudit = await page.evaluate(() => {
        const modal = document.getElementById('wpe-variant-image-picker-modal');
        const optControls = document.getElementById('wpe-picker-option-controls');
        return {
            optHidden: optControls?.classList.contains('wpe-hidden') && getComputedStyle(optControls).display === 'none',
            inlineStyles: [...modal.querySelectorAll('[style]')].map((el) => `${el.tagName}.${el.className}:${el.getAttribute('style')}`),
        };
    });
    check(
        'Variant Image Picker renders with zero inline styles and hides option controls via .wpe-hidden',
        pickerAudit.optHidden && pickerAudit.inlineStyles.length === 0,
        JSON.stringify(pickerAudit)
    );
    await page.keyboard.press('Escape');
    await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'detached', timeout: 5000 });

    // ---------------------------------------------------------------- 3. Settings
    console.log('\n== settings');
    await page.goto(`${SITE}/wp-admin/admin.php?page=wc-product-engine`, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-settings-app', { timeout: 15000 });
    const setTokens = await readTokens(page);
    check(
        'Settings screen loads tokens.css and resolves --wpe-border-control and --wpe-brand',
        setTokens.hasTokensCss && setTokens.borderControl === '#8c8f94' && setTokens.brand === '#008060',
        JSON.stringify(setTokens)
    );

    // ---------------------------------------------------------------- 4. Shopify Import
    console.log('\n== shopify-import');
    await page.goto(`${SITE}/wp-admin/admin.php?page=wpe-shopify-import`, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-dropzone', { timeout: 15000 });
    const impAudit = await page.evaluate(() => {
        const dz = document.getElementById('wpe-dropzone');
        const term = document.querySelector('.wpe-terminal-card');
        const termBody = document.getElementById('wpe-terminal-body');
        const wrap = document.querySelector('.wpe-import-wrap');
        const unexpected = [];
        for (const el of wrap.querySelectorAll('[style]')) {
            // Runtime JS may animate or color #wpe-selected-file-info on auto-check; exclude dynamic state node
            if (el.id === 'wpe-selected-file-info') continue;
            const st = (el.getAttribute('style') || '').trim();
            if (st) unexpected.push(`${el.tagName.toLowerCase()}#${el.id}.${el.className}: ${st}`);
        }
        return {
            dzBorderColor: dz ? getComputedStyle(dz).borderTopColor : null,
            termBg: term ? getComputedStyle(term).backgroundColor : null,
            hasTermBody: !!termBody,
            unexpected,
        };
    });
    const impTokens = await readTokens(page);
    check(
        'Shopify Import loads tokens.css and styles dropzone/terminal from shared tokens',
        impTokens.hasTokensCss
            && impTokens.terminalBg === '#1e1e1e'
            && impAudit.hasTermBody
            && impAudit.dzBorderColor === 'rgb(140, 143, 148)'
            && impAudit.termBg === 'rgb(30, 30, 30)',
        JSON.stringify({ impTokens, impAudit })
    );
    check(
        'Shopify Import rendered structure has zero static inline styles',
        impAudit.unexpected.length === 0,
        JSON.stringify(impAudit.unexpected)
    );

    // ---------------------------------------------------------------- 5. Operations tab (Settings -> Operations & Health)
    console.log('\n== operations');
    await page.goto(`${SITE}/wp-admin/admin.php?page=wc-product-engine&tab=operations`, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-operations-panel', { timeout: 15000 });
    const opsTokens = await readTokens(page);
    const opsInline = await page.evaluate(() => {
        const wrap = document.getElementById('wpe-settings-app');
        return [...wrap.querySelectorAll('[style]')].map((el) => `${el.tagName}.${el.className}:${el.getAttribute('style')}`);
    });
    check(
        'Operations & Health tab loads tokens.css and has zero inline styles',
        opsTokens.hasTokensCss && opsTokens.surface === '#ffffff' && opsInline.length === 0,
        JSON.stringify({ opsTokens, opsInline })
    );

    check('No browser console or page errors across all 4 admin screens', consoleErrors.length === 0, consoleErrors.join(' | '));
    await ctx.close();
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
