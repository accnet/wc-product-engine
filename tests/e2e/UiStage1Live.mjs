/**
 * P022 — Live browser checks for UI stage 1 (keyboard, focus, contrast, layout, import).
 *
 * Each section is a list of checks run against http://site1.localhost with a fixture product
 * created and deleted through WP-CLI, like the other *Live.mjs scripts. Nothing is snapshotted
 * or restored.
 *
 * Usage:
 *   node tests/e2e/UiStage1Live.mjs            # every section
 *   node tests/e2e/UiStage1Live.mjs keyboard   # one section
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
const APP = '#wpe-product-editor-inline-app';

const wp = (...args) => execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();

const results = [];
function check(name, ok, detail) {
    results.push({ name, ok: !!ok });
    console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (!ok && detail !== undefined ? '  -> ' + detail : ''));
}

if (SHOT_DIR) mkdirSync(SHOT_DIR, { recursive: true });
const shot = async (page, name) => {
    if (SHOT_DIR) await page.screenshot({ path: path.join(SHOT_DIR, name), fullPage: false });
};

// ---------------------------------------------------------------- fixtures (WP-CLI)
const work = mkdtempSync(path.join(tmpdir(), 'wpe-ui1-'));
writeFileSync(path.join(work, 'fixture.php'), `<?php
$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$assets = new \\WPE\\Compatibility\\Gallery\\AssetService();
$id = wp_insert_post(['post_title' => 'P022 UI ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($id, 'variable', 'product_type');
wp_cache_flush();
$ver = $svc->getProductEditorData($id)['product']['version'];
$a1 = $assets->registerAsset('https://93.184.216.34/p022-red-' . uniqid() . '.jpg', ['alt_text' => 'P022 Red']);
$a2 = $assets->registerAsset('https://93.184.216.34/p022-blue-' . uniqid() . '.jpg', ['alt_text' => 'P022 Blue']);
$res = $svc->saveProductVariants($id, [
    'expected_version' => $ver,
    'media_library' => [
        'featured_asset_id' => $a1['id'],
        'gallery' => [['asset_id' => $a2['id'], 'position' => 0]],
    ],
    'variation_options' => [
        ['name' => 'Color', 'position' => 0, 'values' => [['label' => 'Red', 'position' => 0], ['label' => 'Blue', 'position' => 1]]],
        ['name' => 'Size', 'position' => 1, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]],
    ],
    'variations' => [
        ['combination' => ['Color' => 'Red', 'Size' => 'S'], 'sku' => 'P022-RS-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Red', 'Size' => 'M'], 'sku' => 'P022-RM-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Blue', 'Size' => 'S'], 'sku' => 'P022-BS-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
        ['combination' => ['Color' => 'Blue', 'Size' => 'M'], 'sku' => 'P022-BM-' . uniqid(), 'regular_price' => '30', 'status' => 'publish', 'manage_stock' => 0],
    ],
]);
if (empty($res['success'])) { fwrite(STDERR, 'fixture save failed'); exit(1); }
$data = $svc->getProductEditorData($id);
echo 'JSON:' . wp_json_encode([
    'id' => $id,
    'asset1' => (int) $a1['id'],
    'asset2' => (int) $a2['id'],
    'variation_ids' => array_map(fn($v) => (int) $v['id'], $data['variations']),
]);
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

// ---------------------------------------------------------------- helpers
/** Class names of the element that currently has focus. */
const activeClass = (page) => page.evaluate(() => (document.activeElement && document.activeElement.className) || '');

/** Press Tab until the focused element matches the selector; returns true when reached. */
async function tabUntil(page, selector, max = 25) {
    for (let i = 0; i < max; i++) {
        await page.keyboard.press('Tab');
        if (await page.evaluate((s) => !!document.activeElement && document.activeElement.matches(s), selector)) return true;
    }
    return false;
}

async function openEditor(page, fx) {
    await page.goto(`${SITE}/wp-admin/post.php?post=${fx.id}&action=edit`, { waitUntil: 'load' });
    await page.waitForSelector(`${APP} .wpe-row-thumb-btn`, { timeout: 20000 });
}

// ---------------------------------------------------------------- sections
const sections = {
    /** K-01, K-02, K-04, I-01: keyboard-operable controls and accessible names. */
    async keyboard(page, fx) {
        await openEditor(page, fx);

        const fakeControls = await page.evaluate((app) => {
            const sel = '.wpe-picker-item, .wpe-media-slot, .wpe-media-remove, .wpe-chip-remove, .wpe-chip-move, .wpe-clickable-stock';
            return [...document.querySelectorAll(`${app} :is(${sel})`)].filter((el) => el.tagName !== 'BUTTON').map((el) => el.tagName + '.' + el.className);
        }, APP);
        check('Clickable editor controls are native buttons (thumb, remove, chip move/remove, shared stock)', fakeControls.length === 0, fakeControls.join(', '));

        const unnamed = await page.evaluate((app) => {
            const nameOf = (el) => (el.getAttribute('aria-label') || '').trim()
                || (el.labels && [...el.labels].map((l) => l.textContent.trim()).join(' '))
                || (el.tagName === 'BUTTON' ? el.textContent.replace(/\s+/g, ' ').trim() : '');
            return [...document.querySelectorAll(`${app} button, ${app} .wpe-table input, ${app} .wpe-table select`)]
                .filter((el) => el.offsetParent !== null && !nameOf(el))
                .map((el) => el.tagName + '.' + String(el.className).split(' ').slice(0, 2).join('.'));
        }, APP);
        check('Every visible button, table input and table select in the editor has an accessible name (not only a title)', unnamed.length === 0, unnamed.join(', '));

        // Row image -> picker -> choose an image, keyboard only.
        const firstVar = fx.variation_ids[0];
        await page.locator(`${APP} .wpe-row-thumb-btn[data-id="${firstVar}"]`).focus();
        await page.keyboard.press('Enter');
        await page.waitForSelector('#wpe-variant-image-picker-modal .wpe-picker-item', { timeout: 10000 });
        const closeNamed = await page.locator('#wpe-variant-image-picker-modal .wpe-icon-close[aria-label="Close"]').count();
        check('Picker close button is an icon button named "Close"', closeNamed === 1);

        const reachedItem = await tabUntil(page, '#wpe-variant-image-picker-modal .wpe-picker-item');
        check('Tab reaches a picker image', reachedItem);
        const target = `#wpe-variant-image-picker-modal .wpe-picker-item[data-asset-id="${fx.asset2}"]`;
        await page.locator(target).focus();
        await page.keyboard.press('Space');
        const picked = await page.evaluate((t) => {
            const el = document.querySelector(t);
            return { selected: el && el.classList.contains('is-selected'), pressed: el && el.getAttribute('aria-pressed'), focused: document.activeElement === el };
        }, target);
        check('Space on a picker image selects it (is-selected, aria-pressed=true) and keeps focus on it', picked.selected && picked.pressed === 'true' && picked.focused, JSON.stringify(picked));
        await shot(page, 'keyboard-picker.png');

        await page.locator('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').focus();
        await page.keyboard.press('Enter');
        await page.waitForSelector('#wpe-variant-image-picker-modal', { state: 'detached', timeout: 10000 });
        const hasImg = await page.locator(`${APP} .wpe-row-thumb-btn[data-id="${firstVar}"] img`).count();
        check('Enter on "confirm" assigns the chosen image to the row', hasImg === 1);

        // Remove the row image with the keyboard: Tab from the thumbnail reaches the remove button.
        await page.locator(`${APP} .wpe-row-thumb-btn[data-id="${firstVar}"]`).focus();
        await page.keyboard.press('Tab');
        const onRemove = await page.evaluate(() => {
            const el = document.activeElement;
            return !!el && el.classList.contains('wpe-media-remove') && getComputedStyle(el).display !== 'none';
        });
        check('Tab from a row thumbnail reaches its visible "remove image" button', onRemove);
        await page.keyboard.press('Enter');
        await page.waitForFunction((s) => !document.querySelector(s), `${APP} .wpe-row-thumb-btn[data-id="${firstVar}"] img`, { timeout: 5000 }).catch(() => {});
        const removed = await page.locator(`${APP} .wpe-row-thumb-btn[data-id="${firstVar}"] img`).count();
        await page.waitForTimeout(50);
        const focusBack = await page.evaluate((id) => document.activeElement && document.activeElement.matches(`.wpe-row-thumb-btn[data-id="${id}"]`), firstVar);
        check('Enter removes the image and focus returns to the row thumbnail', removed === 0 && focusBack, `removed=${removed === 0} focus=${focusBack}`);

        // Move a value chip right with the keyboard; focus follows the moved chip.
        const chipsBefore = await page.locator(`${APP} .wpe-chips-container[data-opt-idx="0"] .wpe-chip > span:not(.wpe-chip-color-dot)`).allInnerTexts();
        await page.locator(`${APP} .wpe-chip[data-opt-idx="0"][data-val-idx="0"] .wpe-chip-move-next`).focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(100);
        const chipsAfter = await page.locator(`${APP} .wpe-chips-container[data-opt-idx="0"] .wpe-chip > span:not(.wpe-chip-color-dot)`).allInnerTexts();
        const movedFocus = await page.evaluate(() => {
            const el = document.activeElement;
            const chip = el && el.closest('.wpe-chip');
            return chip ? chip.getAttribute('data-val-idx') + ':' + el.className : '';
        });
        check('Enter on "move right" reorders the values and keeps focus on the moved chip', chipsBefore.join('|') === 'Red|Blue' && chipsAfter.join('|') === 'Blue|Red' && movedFocus.startsWith('1:'), `${chipsBefore.join('|')} -> ${chipsAfter.join('|')} focus=${movedFocus}`);

        // Shared stock pill is a button: Enter switches the row to its own stock and focuses the quantity.
        await page.locator(`${APP} tr[data-variant-id="${fx.variation_ids[1]}"] .wpe-clickable-stock`).focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(100);
        const qtyFocused = (await activeClass(page)).includes('wpe-inline-stock-qty');
        check('Enter on "Shared Stock" switches the row to its own stock and focuses the quantity', qtyFocused);

        // Remove a value chip with the keyboard (accept the "affects N variants" confirmation).
        page.once('dialog', (d) => d.accept());
        const countBefore = await page.locator(`${APP} .wpe-chips-container[data-opt-idx="1"] .wpe-chip`).count();
        await page.locator(`${APP} .wpe-chip[data-opt-idx="1"][data-val-idx="1"] .wpe-chip-remove`).focus();
        await page.keyboard.press('Enter');
        await page.waitForTimeout(150);
        const countAfter = await page.locator(`${APP} .wpe-chips-container[data-opt-idx="1"] .wpe-chip`).count();
        const onChipInput = (await activeClass(page)).includes('wpe-chip-input');
        check('Enter on "remove value" removes the chip and focus moves to the option value input', countAfter === countBefore - 1 && onChipInput, `count ${countBefore}->${countAfter} input=${onChipInput}`);
    },
};

// ---------------------------------------------------------------- runner
const wanted = process.argv[2] ? [process.argv[2]] : Object.keys(sections);
const unknown = wanted.filter((s) => !sections[s]);
if (unknown.length) {
    console.error('Unknown section: ' + unknown.join(', ') + '. Known: ' + Object.keys(sections).join(', '));
    process.exit(2);
}

const created = [];
let browser;
let exitCode = 0;
try {
    const hash = wp('eval', 'echo COOKIEHASH;');
    const logged = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "logged_in");');
    const auth = wp('eval', 'echo wp_generate_auth_cookie(1, time()+3600, "auth");');
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox', `--host-resolver-rules=MAP ${HOST} 127.0.0.1`] });

    for (const name of wanted) {
        console.log(`\n== ${name}`);
        const fx = evalFile('fixture.php');
        created.push(fx.id);
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
        await ctx.addCookies([
            { name: `wordpress_logged_in_${hash}`, value: logged, domain: HOST, path: '/' },
            { name: `wordpress_sec_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
            { name: `wordpress_${hash}`, value: auth, domain: HOST, path: '/wp-admin' },
        ]);
        const page = await ctx.newPage();
        const consoleErrors = [];
        page.on('console', (m) => {
            if (m.type() === 'error' && !/favicon|Failed to load resource|ERR_/.test(m.text())) consoleErrors.push(m.text());
        });
        page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));
        await sections[name](page, fx, { ctx, browser });
        check(`[${name}] no browser console or page errors`, consoleErrors.length === 0, consoleErrors.join(' | '));
        await ctx.close();
    }
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
