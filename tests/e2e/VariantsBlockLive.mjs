/**
 * P005.4 — Live browser verification of the standalone Variants Manager meta box.
 *
 * Runs against the local WordPress site (default http://site1.localhost) with a headless Chromium, using
 * disposable products that are created and removed through WP-CLI. Authentication cookies are minted at
 * run time through WP-CLI for user 1; nothing secret is stored. Site options are never modified; the
 * script fails if `wpe_feature_flags` differs after the run.
 *
 * Usage:   node tests/e2e/VariantsBlockLive.mjs
 * Env:     WPE_SITE_URL (default http://site1.localhost), WPE_WP_PATH (default /var/www/site1.localhost),
 *          WPE_SHOT_DIR (optional directory for screenshots).
 *
 * This is a UI/integration check of the editor surface. It is not a WooCommerce certification.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync, mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const SITE = process.env.WPE_SITE_URL || 'http://site1.localhost';
const WP_PATH = process.env.WPE_WP_PATH || '/var/www/site1.localhost';
const SHOT_DIR = process.env.WPE_SHOT_DIR || '';
const HOST = new URL(SITE).hostname;

const wp = (...args) => execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
const flagsInDb = () => wp('option', 'get', 'wpe_feature_flags', '--format=json');

const results = [];
function check(name, ok, detail) {
    results.push({ name, ok: !!ok });
    console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (!ok && detail !== undefined ? '  -> ' + detail : ''));
}

if (SHOT_DIR) mkdirSync(SHOT_DIR, { recursive: true });
const shot = async (page, name, opts = {}) => {
    if (SHOT_DIR) await page.screenshot({ path: path.join(SHOT_DIR, name), ...opts });
};

// ---------------------------------------------------------------- fixtures (WP-CLI)
const work = mkdtempSync(path.join(tmpdir(), 'wpe-vlive-'));
const fixtureFile = path.join(work, 'fixture.php');
writeFileSync(fixtureFile, `<?php
$type = $args[0] ?? 'variable';
$id = wp_insert_post(['post_title' => 'P005 Live ' . $type . ' ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($id, $type, 'product_type');
wp_cache_flush();
$out = ['id' => $id, 'variation_ids' => []];
if ($type === 'variable') {
    $svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
    $version = $svc->getProductEditorData($id)['product']['version'];
    $vars = [];
    foreach ([['S','Red',20],['S','Blue',20],['M','Red',25],['M','Blue',25]] as $r) {
        $vars[] = ['combination' => ['Size' => $r[0], 'Color' => $r[1]], 'sku' => 'P5L-' . $r[0] . $r[1] . '-' . uniqid(), 'regular_price' => $r[2], 'sale_price' => null, 'status' => 'publish', 'manage_stock' => 0, 'stock_quantity' => null];
    }
    $res = $svc->saveProductVariants($id, [
        'expected_version' => $version,
        'descriptive_attributes' => [['name' => 'Material', 'taxonomy' => null, 'is_visible' => 1, 'position' => 0, 'values' => ['Cotton']]],
        'variation_options' => [
            ['name' => 'Size', 'position' => 0, 'values' => [['label' => 'S', 'position' => 0], ['label' => 'M', 'position' => 1]]],
            ['name' => 'Color', 'position' => 1, 'values' => [['label' => 'Red', 'position' => 0], ['label' => 'Blue', 'position' => 1]]],
        ],
        'variations' => $vars,
    ]);
    if (empty($res['success'])) { fwrite(STDERR, 'fixture save failed'); exit(1); }
    $out['variation_ids'] = array_map(fn($v) => (int) $v['id'], $svc->getProductEditorData($id)['variations']);
}
echo 'JSON:' . wp_json_encode($out);
`);
writeFileSync(path.join(work, 'cleanup.php'), `<?php
foreach ($args as $id) {
    $id = (int) $id;
    foreach (get_posts(['post_type' => 'product_variation', 'post_parent' => $id, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']) as $c) { wp_delete_post($c, true); }
    wp_delete_post($id, true);
}
echo 'cleaned';
`);
writeFileSync(path.join(work, 'children.php'), `<?php
$id = (int) $args[0];
wp_cache_flush();
$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
echo 'JSON:' . wp_json_encode(array_map(fn($v) => (int) $v['id'], $svc->getProductEditorData($id)['variations']));
`);
writeFileSync(path.join(work, 'skus.php'), `<?php
$id = (int) $args[0];
wp_cache_flush();
$svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
$o = [];
foreach ($svc->getProductEditorData($id)['variations'] as $v) { $o[(int) $v['id']] = ['sku' => $v['sku'], 'price' => (string) $v['regular_price']]; }
echo 'JSON:' . wp_json_encode($o);
`);
const evalFile = (file, ...args) => {
    const out = wp('eval-file', path.join(work, file), ...args);
    const m = out.match(/JSON:(.*)$/s);
    return m ? JSON.parse(m[1]) : out;
};

const created = [];
let browser;
const originalFlags = flagsInDb();
let exitCode = 0;

try {
    const variable = evalFile('fixture.php', 'variable');
    const simple = evalFile('fixture.php', 'simple');
    created.push(variable.id, simple.id);
    check('Fixture: variable product has 4 variations', variable.variation_ids.length === 4, JSON.stringify(variable));

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
    page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    const variantGets = [];
    page.on('request', (r) => { if (/\/variants(\?|$)/.test(r.url()) && r.method() === 'GET') variantGets.push(r.url()); });

    const dialogs = [];
    let confirmAnswer = true;
    page.on('dialog', async (d) => {
        dialogs.push({ type: d.type(), message: d.message() });
        if (d.type() === 'confirm' && !confirmAnswer) await d.dismiss(); else await d.accept();
    });

    const editUrl = (id) => `${SITE}/wp-admin/post.php?post=${id}&action=edit`;
    const BOX = '#wpe_variants_manager';
    const INLINE = '#wpe-product-editor-inline-app';
    // The Product data box can be collapsed in the user's saved layout, so judge tabs by computed display.
    const tabShown = (sel) => page.evaluate((q) => { const el = document.querySelector(q); return !!el && getComputedStyle(el).display !== 'none'; }, sel);
    const waitEditor = async () => { await page.waitForSelector(`${INLINE} .wpe-editor-wrap`, { timeout: 20000 }); };
    const rowCount = () => page.locator(`${INLINE} tr[data-variant-id]`).count();
    const badge = async () => (await page.locator('#wpe-box-status').innerText()).trim();
    const payload = () => page.locator('#wpe_variants_payload').inputValue();
    const submitUpdate = async () => {
        const [req] = await Promise.all([
            page.waitForRequest((r) => r.method() === 'POST' && /post\.php/.test(r.url())),
            Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#publish')]),
        ]);
        return req;
    };
    const setInlinePrice = async (rowIndex, value) => {
        const input = page.locator(`${INLINE} tr[data-variant-id] .wpe-inline-reg-price`).nth(rowIndex);
        await input.fill(String(value));
        await input.dispatchEvent('change');
    };

    // ================================================================ 1. Placement & tabs
    await page.goto(editUrl(variable.id), { waitUntil: 'load' });
    await waitEditor();

    check('Meta box #wpe_variants_manager exists and is visible for a variable product', await page.locator(BOX).isVisible());
    const below = await page.evaluate(() => {
        const a = document.getElementById('woocommerce-product-data');
        const b = document.getElementById('wpe_variants_manager');
        return !!a && !!b && !!(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    check('Variants Manager box sits below the Product data box (DOM order)', below);
    const tabLabels = await page.locator('.product_data_tabs li').allInnerTexts();
    check('No "Variants Manager" tab inside Product data', !tabLabels.some((t) => /variants manager/i.test(t)), tabLabels.join('|'));
    check('Legacy Attributes tab hidden for variable product', !(await tabShown('.product_data_tabs li.attribute_options')));
    check('Legacy Variations tab hidden for variable product', !(await tabShown('.product_data_tabs li.variations_options')));
    check('Box has a WordPress title bar with drag/collapse handles', (await page.locator(`${BOX} .postbox-header .handlediv`).count()) === 1);
    check('Header holds the status badge and Fullscreen button', (await page.locator(`${BOX} .postbox-header #wpe-box-status`).count()) === 1 && (await page.locator(`${BOX} .postbox-header #wpe-box-fullscreen`).count()) === 1);
    check('Inline editor has no duplicate "Variants Manager" heading', (await page.locator(`${INLINE} h3.wpe-top-title`).count()) === 0);
    check('4 variations are listed', (await rowCount()) === 4, await rowCount());
    check('Badge reads "In sync" after load', (await badge()) === 'In sync', await badge());
    check('Fullscreen button enabled after load', await page.locator('#wpe-box-fullscreen').isEnabled());
    check('Hidden payload is empty while the draft is clean', (await payload()) === '', await payload());
    check('Exactly one data request for the editor on load', variantGets.length === 1, variantGets.length);
    await shot(page, 'p005_box_below_product_data.png', { fullPage: true });

    // Global attribute dropdown
    const taxCount = Number(wp('eval', 'echo count(wc_get_attribute_taxonomies());'));
    const optTexts = await page.locator(`${INLINE} .wpe-add-taxonomy-select option`).allInnerTexts();
    check('Global attribute dropdown lists every WooCommerce attribute taxonomy', optTexts.length === taxCount + 1, `options=${optTexts.length} taxonomies=${taxCount}`);
    check('Global attribute dropdown has no "undefined" labels', !optTexts.some((t) => /undefined/i.test(t)), optTexts.join('|'));

    // ================================================================ 2. Collapse keeps the badge
    await page.click(`${BOX} .handlediv`);
    check('Collapsing the box hides the editor body', !(await page.locator(INLINE).isVisible()));
    check('Collapsed box still shows the status badge and Fullscreen button', (await page.locator('#wpe-box-status').isVisible()) && (await page.locator('#wpe-box-fullscreen').isVisible()));
    await shot(page, 'p005_box_collapsed.png', { clip: await page.locator(BOX).boundingBox() });
    await page.click(`${BOX} .handlediv`);
    check('Expanding the box shows the editor again', await page.locator(INLINE).isVisible());

    // ================================================================ 3. Shared draft: tab <-> fullscreen
    await setInlinePrice(0, '31.5');
    check('Editing marks the draft as unsaved in the header badge', (await badge()) === 'Unsaved changes', await badge());
    const pl = await payload();
    check('Hidden payload is filled when dirty', pl.startsWith('{') && JSON.parse(pl).variations.length === 4, pl.slice(0, 80));

    await page.click('#wpe-box-fullscreen');
    await page.waitForSelector('#wpe-fullscreen-modal-overlay', { state: 'visible' });
    await page.waitForSelector('#wpe-product-editor-modal-app .wpe-editor-wrap');
    const modalPrice = await page.locator('#wpe-product-editor-modal-app tr[data-variant-id] .wpe-inline-reg-price').first().inputValue();
    check('Fullscreen shows the draft edited inline (shared store)', modalPrice === '31.5', modalPrice);
    check('Fullscreen overlay is announced as a dialog', (await page.locator('#wpe-fullscreen-modal-overlay').getAttribute('role')) === 'dialog' && (await page.locator('#wpe-fullscreen-modal-overlay').getAttribute('aria-modal')) === 'true');
    await shot(page, 'p005_fullscreen.png');

    const inModal = () => page.evaluate(() => document.getElementById('wpe-fullscreen-modal-overlay').contains(document.activeElement));
    // Focus is moved shortly after the overlay opens, so wait for it instead of sampling immediately.
    await page.waitForFunction(() => document.getElementById('wpe-fullscreen-modal-overlay').contains(document.activeElement), null, { timeout: 2000 }).catch(() => {});
    check('Focus moves into the fullscreen editor on open', await inModal());
    let escaped = true;
    for (let i = 0; i < 40; i++) {
        await page.keyboard.press('Tab');
        if (!(await inModal())) { escaped = false; break; }
    }
    check('Tab never leaves the fullscreen editor (focus trap, forward)', escaped);
    escaped = true;
    for (let i = 0; i < 40; i++) {
        await page.keyboard.press('Shift+Tab');
        if (!(await inModal())) { escaped = false; break; }
    }
    check('Shift+Tab never leaves the fullscreen editor (focus trap, backward)', escaped);

    const modalSku = page.locator('#wpe-product-editor-modal-app tr[data-variant-id] .wpe-inline-sku').nth(1);
    const newSku = 'P5L-EDIT-' + Date.now();
    await modalSku.fill(newSku);
    await modalSku.dispatchEvent('change');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#wpe-fullscreen-modal-overlay', { state: 'hidden' });
    check('Escape closes the fullscreen editor', !(await page.locator('#wpe-fullscreen-modal-overlay').isVisible()));
    check('Focus returns to the Fullscreen button', await page.evaluate(() => document.activeElement && document.activeElement.id === 'wpe-box-fullscreen'));
    const inlineSku = await page.locator(`${INLINE} tr[data-variant-id] .wpe-inline-sku`).nth(1).inputValue();
    check('Edit made in fullscreen is visible inline after closing (shared draft)', inlineSku === newSku, inlineSku);
    check('Closing fullscreen neither saves nor discards the draft', (await badge()) === 'Unsaved changes' && (await page.locator(`${INLINE} tr[data-variant-id] .wpe-inline-reg-price`).first().inputValue()) === '31.5');

    // ================================================================ 4. Drawer, dialog, bulk preview
    await page.locator(`${INLINE} .wpe-btn-open-drawer`).first().click();
    await page.waitForSelector('#wpe-variation-drawer-overlay', { state: 'visible' });
    const inDrawer = () => page.evaluate(() => document.getElementById('wpe-variation-drawer-overlay').contains(document.activeElement));
    await page.waitForTimeout(150);
    check('Focus moves into the variation drawer on open', await inDrawer());
    escaped = true;
    for (let i = 0; i < 30; i++) {
        await page.keyboard.press('Tab');
        if (!(await inDrawer())) { escaped = false; break; }
    }
    check('Tab stays inside the drawer (focus trap)', escaped);
    await shot(page, 'p005_drawer.png');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#wpe-variation-drawer-overlay', { state: 'hidden' });
    check('Escape closes the drawer', !(await page.locator('#wpe-variation-drawer-overlay').isVisible()));
    check('Focus returns to the drawer trigger', await page.evaluate(() => !!document.activeElement && document.activeElement.classList.contains('wpe-btn-open-drawer')));

    await page.locator(`${INLINE} .wpe-btn-open-generator`).first().click();
    await page.waitForSelector('#wpe-generator-modal', { state: 'visible' });
    check('Generator dialog exposes role=dialog and an accessible name', (await page.locator('#wpe-generator-modal').getAttribute('role')) === 'dialog' && !!(await page.locator('#wpe-generator-modal').getAttribute('aria-label')));
    await page.keyboard.press('Escape');
    await page.waitForSelector('#wpe-generator-modal', { state: 'detached' });
    check('Escape closes the generator dialog', (await page.locator('#wpe-generator-modal').count()) === 0);

    await page.locator(`${INLINE} .wpe-row-check`).nth(1).check();
    await page.locator(`${INLINE} .wpe-btn-open-bulk-edit`).first().click();
    await page.waitForSelector('#wpe-bulk-modal', { state: 'visible' });
    await page.selectOption('#wpe-bulk-field', 'regular_price');
    await page.selectOption('#wpe-bulk-price-type', 'increase_percent');
    await page.fill('#wpe-bulk-price-val', '10');
    await page.click('.wpe-btn-preview-bulk-diff');
    await page.waitForSelector('#wpe-bulk-diff-preview table', { timeout: 10000 });
    check('Bulk edit preview renders before/after values', (await page.locator('#wpe-bulk-diff-preview .wpe-diff-before').count()) >= 1 && (await page.locator('#wpe-bulk-diff-preview .wpe-diff-after').count()) >= 1);
    await shot(page, 'p005_bulk_preview.png');
    await page.keyboard.press('Escape');
    await page.waitForSelector('#wpe-bulk-modal', { state: 'detached' });

    // ================================================================ 5. Responsive
    for (const w of [782, 360]) {
        await page.setViewportSize({ width: w, height: 900 });
        await page.waitForTimeout(250);
        const fits = await page.evaluate(() => {
            const r = document.getElementById('wpe_variants_manager').getBoundingClientRect();
            return r.right <= window.innerWidth + 1 && r.left >= -1;
        });
        check(`Variants Manager box fits the viewport at ${w}px`, fits);
        if (w === 360) await shot(page, 'p005_mobile.png', { fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 1000 });

    // ================================================================ 6. Leaving with a dirty draft warns
    dialogs.length = 0;
    await page.goto(`${SITE}/wp-admin/index.php`, { waitUntil: 'load' }).catch(() => {});
    check('Leaving the page with an unsaved draft triggers the browser warning', dialogs.some((d) => d.type === 'beforeunload'), JSON.stringify(dialogs));

    // ================================================================ 7. Update with an unchanged draft leaves variants untouched
    await page.goto(editUrl(variable.id), { waitUntil: 'load' });
    await waitEditor();
    const before = evalFile('skus.php', String(variable.id));
    dialogs.length = 0;
    const cleanReq = await submitUpdate();
    check('Update with a clean draft posts an empty variants payload', /wpe_variants_payload=(&|$)/.test(cleanReq.postData() || ''), (cleanReq.postData() || '').slice(0, 120));
    const afterClean = evalFile('skus.php', String(variable.id));
    check('Update with a clean draft keeps every variation (ids, SKUs, prices) unchanged', JSON.stringify(before) === JSON.stringify(afterClean));
    check('No unsaved-changes prompt on a normal Update', !dialogs.some((d) => d.type === 'beforeunload'));
    check('No failure notice after a clean Update', (await page.locator('.notice-error:has-text("NOT saved")').count()) === 0);

    // ================================================================ 8. Update with edits persists
    await waitEditor();
    const idsBefore = evalFile('children.php', String(variable.id));
    await setInlinePrice(0, '33.33');
    dialogs.length = 0;
    const dirtyReq = await submitUpdate();
    check('Update with edits posts the draft payload', /wpe_variants_payload=%7B/.test(dirtyReq.postData() || ''));
    check('No unsaved-changes prompt when the draft travels with Update', !dialogs.some((d) => d.type === 'beforeunload'), JSON.stringify(dialogs));
    check('No failure notice after a successful Update', (await page.locator('.notice-error:has-text("NOT saved")').count()) === 0);
    const projectionNotice = await page.locator('.notice-warning:has-text("variants")').count();
    console.log(`INFO  projection warning notice shown after Update: ${projectionNotice > 0}`);
    await waitEditor();
    const idsAfter = evalFile('children.php', String(variable.id));
    check('Editing prices does not recreate variation IDs', JSON.stringify([...idsBefore].sort()) === JSON.stringify([...idsAfter].sort()), `${idsBefore} vs ${idsAfter}`);
    const savedFirst = await page.locator(`${INLINE} tr[data-variant-id] .wpe-inline-reg-price`).first().inputValue();
    const prices = Object.values(evalFile('skus.php', String(variable.id))).map((v) => v.price);
    check('Reload shows the saved price in the editor', prices.includes('33.33') && (await page.locator(`${INLINE} .wpe-inline-reg-price`).evaluateAll((els) => els.map((e) => e.value))).includes('33.33'), `${savedFirst} / ${prices}`);
    check('Badge reads "In sync" after reload', (await badge()) === 'In sync', await badge());

    // ================================================================ 9. Failed save is never silent and keeps the draft
    await setInlinePrice(1, '44.44');
    await page.evaluate(() => { document.getElementById('wpe_variants_nonce').value = 'tampered'; });
    await submitUpdate();
    const err = page.locator('.notice-error:has-text("NOT saved")');
    check('A rejected save shows an error notice that says the variants were NOT saved', (await err.count()) >= 1);
    await page.waitForSelector(`${INLINE} .wpe-editor-wrap`, { timeout: 20000 });
    await page.waitForFunction(() => document.getElementById('wpe-box-status').textContent.trim() === 'Unsaved changes', null, { timeout: 10000 }).catch(() => {});
    check('The failed draft is restored as unsaved after reload', (await badge()) === 'Unsaved changes', await badge());
    const restored = await page.locator(`${INLINE} .wpe-inline-reg-price`).evaluateAll((els) => els.map((e) => e.value));
    check('The restored draft still holds the entered value', restored.includes('44.44'), restored.join(','));
    await shot(page, 'p005_save_error_restored.png', { fullPage: true });
    const storedAfterFail = Object.values(evalFile('skus.php', String(variable.id))).map((v) => v.price);
    check('Nothing was written to the database by the rejected save', !storedAfterFail.includes('44.44'), storedAfterFail.join(','));
    await page.goto(editUrl(variable.id), { waitUntil: 'load' });
    await waitEditor();
    check('The failure notice and the draft are shown only once', (await page.locator('.notice-error:has-text("NOT saved")').count()) === 0 && (await badge()) === 'In sync');

    // ================================================================ 10. Type change guard
    await setInlinePrice(2, '55.55');
    confirmAnswer = false;
    dialogs.length = 0;
    await page.selectOption('#product-type', 'simple');
    check('Switching to a non-variable type hides the Variants Manager box', !(await page.locator(BOX).isVisible()));
    check('Switching to a non-variable type restores the legacy Attributes tab', await tabShown('.product_data_tabs li.attribute_options'));
    check('No variants payload is posted while the type is not variable', (await payload()) === '');
    await page.click('#publish');
    await page.waitForTimeout(600);
    const confirmDialog = dialogs.find((d) => d.type === 'confirm');
    check('Updating with an unsaved draft under a non-variable type asks for confirmation', !!confirmDialog, JSON.stringify(dialogs));
    check('Cancelling the confirmation keeps the user on the editor', page.url().includes('action=edit') && (await page.locator('#publish').isVisible()));
    check('Cancelling does not leave the Update button stuck as disabled', !(await page.locator('#publish').evaluate((el) => el.classList.contains('disabled') || el.disabled)));
    await page.selectOption('#product-type', 'variable');
    check('Switching back to variable shows the box again with the draft intact', (await page.locator(BOX).isVisible()) && (await badge()) === 'Unsaved changes');
    confirmAnswer = true;

    // ================================================================ 11. Simple product: no box, no data request
    variantGets.length = 0;
    await page.goto(editUrl(simple.id), { waitUntil: 'load' });
    await page.waitForTimeout(800);
    check('Simple product: Variants Manager box is hidden', !(await page.locator(BOX).isVisible()));
    check('Simple product: legacy Attributes tab is unchanged (visible)', await tabShown('.product_data_tabs li.attribute_options'));
    check('Simple product: no variants data request was made (lazy load)', variantGets.length === 0, variantGets.length);
    await page.selectOption('#product-type', 'variable');
    await page.waitForSelector(`${INLINE} .wpe-editor-wrap`, { timeout: 20000 });
    check('Switching a simple product to variable loads the editor on demand', variantGets.length === 1 && (await page.locator(BOX).isVisible()), variantGets.length);

    check('No unexpected console errors on the product screens', consoleErrors.length === 0, consoleErrors.slice(0, 3).join(' | '));
} catch (e) {
    check('Script ran to completion', false, e && e.stack ? e.stack : String(e));
    exitCode = 1;
} finally {
    if (browser) await browser.close().catch(() => {});
    try {
        if (created.length) wp('eval-file', path.join(work, 'cleanup.php'), ...created.map(String));
    } catch (e) {
        console.log('WARN  cleanup failed: ' + e.message);
    }
    check('Site feature flags unchanged by the run', flagsInDb() === originalFlags);
    rmSync(work, { recursive: true, force: true });
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
process.exit(failed.length || exitCode ? 1 : 0);
