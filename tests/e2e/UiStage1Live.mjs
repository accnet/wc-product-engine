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

/**
 * Installs window.__wpeContrast(el, which) in the page. `which` is 'text' (color vs the
 * effective background) or 'border' (top border color vs the background behind the element).
 * Semi-transparent colours are composited over the ancestors' backgrounds, page white last.
 */
async function installContrast(page) {
    await page.evaluate(() => {
        const parse = (c) => {
            const m = String(c).match(/rgba?\(([^)]+)\)/);
            if (!m) return null;
            const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
            return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
        };
        const over = (top, under) => ({
            r: top.r * top.a + under.r * (1 - top.a),
            g: top.g * top.a + under.g * (1 - top.a),
            b: top.b * top.a + under.b * (1 - top.a),
            a: 1,
        });
        const bgBehind = (el) => {
            const layers = [];
            for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
                const c = parse(getComputedStyle(n).backgroundColor);
                if (c && c.a > 0) layers.push(c);
                if (c && c.a >= 1) break;
            }
            return layers.reverse().reduce((acc, c) => over(c, acc), { r: 255, g: 255, b: 255, a: 1 });
        };
        const lum = ({ r, g, b }) => {
            const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
            return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
        };
        const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
        window.__wpeContrast = (el, which) => {
            const cs = getComputedStyle(el);
            if (which === 'border') {
                const under = el.parentElement ? bgBehind(el.parentElement) : { r: 255, g: 255, b: 255, a: 1 };
                const own = parse(cs.backgroundColor);
                const inside = own && own.a > 0 ? over(own, under) : under;
                const bc = over(parse(cs.borderTopColor), under);
                // A control boundary must stand out from the surface around it (and from its own fill).
                const r = Math.round(Math.min(ratio(bc, under), ratio(bc, inside)) * 100) / 100;
                const hex = (c) => '#' + [c.r, c.g, c.b].map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');
                window.__wpeLastBorder = `${hex(bc)} on ${hex(under)} / fill ${hex(inside)}`;
                return r;
            }
            const bg = bgBehind(el);
            return Math.round(ratio(over(parse(cs.color), bg), bg) * 100) / 100;
        };
    });
}

/** Contrast of every element matching `selector` (visible ones only); returns [{cls, ratio}]. */
async function contrastOf(page, selector, which) {
    return page.evaluate(([s, w]) => [...document.querySelectorAll(s)]
        .filter((el) => el.offsetParent !== null)
        .map((el) => {
            const ratio = window.__wpeContrast(el, w);
            const out = { cls: String(el.className).split(' ').slice(0, 3).join('.'), ratio };
            if (w === 'border') out.colors = window.__wpeLastBorder;
            return out;
        }), [selector, which]);
}

const minRatio = (list) => (list.length ? Math.min(...list.map((x) => x.ratio)) : 0);

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

    /** K-03, M-01: visible keyboard focus on editor buttons; reduced motion. */
    async focus(page, fx) {
        await openEditor(page, fx);
        const targets = [
            `${APP} .wpe-btn-open-drawer`,
            `${APP} .wpe-row-thumb-btn`,
            `${APP} .wpe-chip-move-next`,
            `${APP} .wpe-clickable-stock`,
            `${APP} .wpe-btn-open-fullscreen`,
            `${APP} .wpe-matrix-toolbar .button`,
        ];
        // A key press first, so programmatic focus counts as keyboard focus (:focus-visible).
        await page.keyboard.press('Shift');
        const missing = [];
        for (const sel of targets) {
            const loc = page.locator(sel).first();
            if (!(await loc.count())) { missing.push(sel + ' (not found)'); continue; }
            await loc.focus();
            const ring = await loc.evaluate((el) => {
                const cs = getComputedStyle(el);
                return { visible: el.matches(':focus-visible'), style: cs.outlineStyle, width: parseFloat(cs.outlineWidth) };
            });
            if (!(ring.visible && ring.style !== 'none' && ring.width >= 2)) missing.push(`${sel} ${JSON.stringify(ring)}`);
        }
        check('Keyboard focus draws a 2px outline on editor buttons (row actions, thumbnail, chip, stock, toolbar, fullscreen)', missing.length === 0, missing.join(' | '));
        await shot(page, 'focus-row-actions.png');

        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.locator(`${APP} .wpe-btn-open-drawer`).first().click();
        await page.waitForSelector('#wpe-variation-drawer-overlay', { state: 'visible', timeout: 5000 });
        const motion = await page.evaluate(() => {
            const overlay = document.getElementById('wpe-variation-drawer-overlay');
            const drawer = overlay && overlay.querySelector('.wpe-drawer-container');
            return {
                overlayOpacity: overlay ? getComputedStyle(overlay).opacity : null,
                drawerAnimation: drawer ? getComputedStyle(drawer).animationName : null,
            };
        });
        check('With prefers-reduced-motion the drawer opens without fade or slide animation', motion.overlayOpacity === '1' && motion.drawerAnimation === 'none', JSON.stringify(motion));
    },

    /** C-04, C-05: text and control contrast in the editor and the settings screen. */
    async contrast(page, fx) {
        await openEditor(page, fx);
        await installContrast(page);
        // One row with its own stock (in stock) and one with its own stock at 0 (out of stock).
        // A row with its own stock renders the "In Stock" badge.
        await page.locator(`${APP} tr[data-variant-id="${fx.variation_ids[0]}"] .wpe-clickable-stock`).click();
        await page.waitForTimeout(150);
        await installContrast(page);

        const inStock = await contrastOf(page, `${APP} .wpe-stock-pill.instock`, 'text');
        // "Out of Stock" comes from saved stock status; probe the same markup inside a matrix cell.
        const outStock = await page.evaluate((app) => {
            const cell = document.querySelector(`${app} .wpe-stock-cell`);
            const pill = document.createElement('span');
            pill.className = 'wpe-stock-pill outofstock';
            pill.textContent = 'Out of Stock';
            cell.appendChild(pill);
            const r = window.__wpeContrast(pill, 'text');
            pill.remove();
            return [{ cls: 'wpe-stock-pill.outofstock', ratio: r }];
        }, APP);
        check('"In Stock" badge text is at least 4.5:1', inStock.length > 0 && minRatio(inStock) >= 4.5, JSON.stringify(inStock));
        check('"Out of Stock" badge text is at least 4.5:1', outStock.length > 0 && minRatio(outStock) >= 4.5, JSON.stringify(outStock));

        const smallText = await contrastOf(page, `${APP} :is(.wpe-chip-move, .wpe-chip-remove, .wpe-btn-revert-shared-stock)`, 'text');
        check('Chip move/remove and "revert stock" glyphs are at least 4.5:1', smallText.length > 0 && minRatio(smallText) >= 4.5, JSON.stringify(smallText.filter((x) => x.ratio < 4.5)));

        const borders = await contrastOf(page, `${APP} :is(.wpe-editable-input:not(:disabled), .wpe-search-input, .wpe-filter-select, .wpe-chips-container, .wpe-option-name-input, .wpe-matrix-toolbar .button:not(:disabled))`, 'border');
        check('Editor control borders (table inputs, search, filters, option inputs, toolbar buttons) are at least 3:1', borders.length > 0 && minRatio(borders) >= 3, JSON.stringify(borders.filter((x) => x.ratio < 3)));
        await shot(page, 'contrast-matrix.png');

        await page.goto(`${SITE}/wp-admin/admin.php?page=wc-product-engine&tab=product-page`, { waitUntil: 'load' });
        await page.waitForSelector('.wpe-admin', { timeout: 15000 });
        await installContrast(page);
        const settingsBorder = await page.evaluate(() => {
            // Probe with a fresh control in case the site has no global attributes to configure.
            const host = document.querySelector('.wpe-admin .wpe-card') || document.querySelector('.wpe-admin');
            const input = document.createElement('input');
            input.className = 'wpe-input';
            host.appendChild(input);
            const r = window.__wpeContrast(input, 'border');
            input.remove();
            return r;
        });
        check('Settings text inputs have a border of at least 3:1', settingsBorder >= 3, String(settingsBorder));
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
