/**
 * P026 — Live browser & CSS-budget checks for UI stage 3
 * (dead CSS removal, !important reduction, native button specificity, storefront tokens).
 *
 * Usage:
 *   node tests/e2e/UiStage3Live.mjs
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
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

function walkFiles(dir, out = []) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walkFiles(full, out);
        else if (/\.(php|js|mjs)$/.test(entry.name)) out.push(full);
    }
    return out;
}

// ---------------------------------------------------------------- 1. CSS Budgets & Dead CSS
console.log('== css-budgets');
const budgets = [
    ['assets/admin/tokens.css', 0],
    ['assets/admin/admin.css', 0],
    ['assets/admin/settings.css', 3],
    ['assets/admin/product-editor.css', 8],
    ['assets/variant-selector/selector.css', 11],
];
for (const [rel, maxImportant] of budgets) {
    const css = readFileSync(path.join(ROOT, rel), 'utf8');
    const count = (css.match(/!important/g) || []).length;
    check(`${rel} stays within !important budget (<= ${maxImportant})`, count <= maxImportant, `actual=${count}`);
}

const corpus = [
    ...walkFiles(path.join(ROOT, 'src')),
    ...walkFiles(path.join(ROOT, 'assets')),
    ...walkFiles(path.join(ROOT, 'tests')),
]
    .map((f) => readFileSync(f, 'utf8'))
    .join('\n');

for (const rel of [
    'assets/admin/product-editor.css',
    'assets/admin/admin.css',
    'assets/admin/settings.css',
    'assets/variant-selector/selector.css',
]) {
    const css = readFileSync(path.join(ROOT, rel), 'utf8');
    const names = new Set();
    for (const m of css.matchAll(/\.(wpe-[a-zA-Z0-9_-]+)/g)) names.add(m[1]);
    const unused = [...names].filter((cls) => !corpus.includes(cls)).sort();
    check(`${rel} has zero unused .wpe-* CSS classes`, unused.length === 0, unused.join(', '));
}

// ---------------------------------------------------------------- fixtures (WP-CLI)
const work = mkdtempSync(path.join(tmpdir(), 'wpe-ui3-'));
writeFileSync(path.join(work, 'fixture.php'), `<?php
$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$id = wp_insert_post(['post_title' => 'P026 UI Stage 3 ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($id, 'variable', 'product_type');
wp_cache_flush();
$ver = $svc->getProductEditorData($id)['product']['version'];
$res = $svc->saveProductVariants($id, [
    'expected_version' => $ver,
    'variation_options' => [
        ['name' => 'Size', 'position' => 0, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]],
    ],
    'variations' => [
        ['combination' => ['Size' => 'S'], 'sku' => 'P026-S-' . uniqid(), 'regular_price' => '20', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Size' => 'M'], 'sku' => 'P026-M-' . uniqid(), 'regular_price' => '22', 'status' => 'publish', 'manage_stock' => 0],
    ],
]);
if (empty($res['success'])) { fwrite(STDERR, 'fixture save failed'); exit(1); }
echo 'JSON:' . wp_json_encode(['id' => $id, 'permalink' => get_permalink($id)]);
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

    // ---------------------------------------------------------------- 2. Product Editor Specificity
    console.log('\n== admin-specificity');
    await page.goto(`${SITE}/wp-admin/post.php?post=${fx.id}&action=edit`, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-product-editor-inline-app .wpe-btn-open-drawer', { timeout: 20000 });

    const peStyles = await page.evaluate(() => {
        const cs = (sel) => {
            const el = document.querySelector(sel);
            return el ? getComputedStyle(el) : null;
        };
        const btn = cs('#wpe-product-editor-inline-app .wpe-editor-wrap .wpe-btn-add-option');
        const toolbarBtn = cs('#wpe-product-editor-inline-app .wpe-toolbar-right .button');
        const filterSel = cs('#wpe-product-editor-inline-app .wpe-filter-select');
        const tableInput = cs('#wpe-product-editor-inline-app .wpe-table input.wpe-editable-input');
        const drawerBtn = cs('#wpe-product-editor-inline-app .wpe-row-actions .wpe-btn-open-drawer');
        const delOptBtn = cs('#wpe-product-editor-inline-app .wpe-btn-delete-option');

        return {
            btn: btn ? { height: btn.height, display: btn.display, borderColor: btn.borderTopColor } : null,
            toolbarBtn: toolbarBtn ? { height: toolbarBtn.height } : null,
            filterSel: filterSel ? { height: filterSel.height, borderColor: filterSel.borderTopColor } : null,
            tableInput: tableInput ? { height: tableInput.height, borderColor: tableInput.borderTopColor } : null,
            drawerBtn: drawerBtn ? { width: drawerBtn.width, height: drawerBtn.height } : null,
            delOptBtn: delOptBtn ? { bg: delOptBtn.backgroundColor, borderColor: delOptBtn.borderTopColor } : null,
        };
    });

    check(
        'Product Editor .button beats .wp-core-ui .button via scoped specificity (32px control height, flex/inline-flex, AA border)',
        peStyles.btn
            && peStyles.btn.height === '32px'
            && (peStyles.btn.display === 'inline-flex' || peStyles.btn.display === 'flex')
            && peStyles.btn.borderColor === 'rgb(126, 130, 135)',
        JSON.stringify(peStyles.btn)
    );
    check(
        'Matrix toolbar button (32px), filter select (32px) and table input (30px) keep exact sizing & AA border',
        peStyles.toolbarBtn?.height === '32px'
            && peStyles.filterSel?.height === '32px'
            && peStyles.filterSel?.borderColor === 'rgb(126, 130, 135)'
            && peStyles.tableInput?.height === '30px'
            && peStyles.tableInput?.borderColor === 'rgb(126, 130, 135)',
        JSON.stringify({ toolbarBtn: peStyles.toolbarBtn, filterSel: peStyles.filterSel, tableInput: peStyles.tableInput })
    );
    check(
        'Row drawer icon button (28x28) and ghost delete option button (transparent) beat WP defaults cleanly',
        peStyles.drawerBtn?.width === '28px'
            && peStyles.drawerBtn?.height === '28px'
            && peStyles.delOptBtn?.bg === 'rgba(0, 0, 0, 0)'
            && peStyles.delOptBtn?.borderColor === 'rgba(0, 0, 0, 0)',
        JSON.stringify({ drawerBtn: peStyles.drawerBtn, delOptBtn: peStyles.delOptBtn })
    );

    // ---------------------------------------------------------------- 3. Shopify Import dragover without !important
    console.log('\n== import-dragover');
    await page.goto(`${SITE}/wp-admin/admin.php?page=wpe-shopify-import`, { waitUntil: 'load' });
    await page.waitForSelector('#wpe-dropzone', { timeout: 15000 });
    await page.evaluate(() => {
        document.getElementById('wpe-dropzone').classList.add('dragover');
    });
    await page.waitForTimeout(300);
    const dragState = await page.evaluate(() => {
        const dz = document.getElementById('wpe-dropzone');
        const cs = getComputedStyle(dz);
        const res = { borderColor: cs.borderTopColor, bg: cs.backgroundColor };
        dz.classList.remove('dragover');
        return res;
    });
    check(
        'Shopify Import #wpe-dropzone.dragover activates highlight border & tint with zero !important',
        dragState.borderColor === 'rgb(34, 113, 177)' && dragState.bg === 'rgb(240, 246, 252)',
        JSON.stringify(dragState)
    );

    // ---------------------------------------------------------------- 4. Storefront PDP scoped tokens
    console.log('\n== storefront-tokens');
    await page.goto(fx.permalink, { waitUntil: 'load' });
    await page.waitForSelector('form.variations_form', { timeout: 15000 });
    const pdpState = await page.evaluate(() => {
        const hasCss = !!document.querySelector('link[href*="assets/variant-selector/selector.css"]');
        const form = document.querySelector('form.variations_form');
        const tokenBorder = form ? getComputedStyle(form).getPropertyValue('--wpe-sel-border').trim().toLowerCase() : '';
        return { hasCss, tokenBorder };
    });
    check(
        'Live storefront PDP loads selector.css and resolves scoped --wpe-sel-border token',
        pdpState.hasCss && pdpState.tokenBorder === '#8c8f94',
        JSON.stringify(pdpState)
    );

    check('No browser console or page errors during Stage 3 live checks', consoleErrors.length === 0, consoleErrors.join(' | '));
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
