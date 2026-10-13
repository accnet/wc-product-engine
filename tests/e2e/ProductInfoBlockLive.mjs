/**
 * P010.9 — Live browser verification of the Product Information block UI.
 *
 * Verifies:
 * - Simple product edit: #wpe_product_info and #wpe_variants_manager visible, empty state with Add Option,
 *   legacy tabs hidden, fills prices/SKU/stock/dimensions, saves via #publish, reloads and verifies persistence.
 * - Variable product with reference price: displays reference price card, shows first variant label & amount,
 *   filtering / search in variations table does NOT alter reference price source or value.
 * - Distinguishes unpriced first variant ("No price yet") vs valid $0.00 price.
 * - Common price setup & removal dialog: sets common price, removes common price with affected variations modal,
 *   cancel preserves common price, confirm converts to own pricing.
 * - Transition Variable -> Simple dialog: removing last option triggers modal, cancel keeps options,
 *   confirm transitions to simple product with receiving variation data.
 * - Leave warning: dirty draft triggers beforeunload confirmation.
 *
 * Usage: node tests/e2e/ProductInfoBlockLive.mjs
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

// Fixtures via WP-CLI
const work = mkdtempSync(path.join(tmpdir(), 'wpe-pinfo-live-'));
const fixtureFile = path.join(work, 'fixture.php');
writeFileSync(fixtureFile, `<?php
$type = $args[0] ?? 'variable';
$id = wp_insert_post(['post_title' => 'P010 Live ' . $type . ' ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
wp_set_object_terms($id, $type, 'product_type');
wp_cache_flush();
$out = ['id' => $id, 'variation_ids' => []];
if ($type === 'variable') {
    $svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
    $version = $svc->getProductEditorData($id)['product']['version'];
    $vars = [];
    foreach ([['S','Red',20],['S','Blue',20],['M','Red',25],['M','Blue',25]] as $r) {
        $vars[] = [
            'combination' => ['Size' => $r[0], 'Color' => $r[1]],
            'sku' => 'P10L-' . $r[0] . $r[1] . '-' . uniqid(),
            'regular_price' => $r[2],
            'sale_price' => null,
            'status' => 'publish',
            'manage_stock' => 0,
            'stock_quantity' => null
        ];
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
    foreach (get_posts(['post_type' => 'product_variation', 'post_parent' => $id, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids']) as $c) {
        wp_delete_post($c, true);
    }
    wp_delete_post($id, true);
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
const originalFlags = flagsInDb();
let exitCode = 0;

try {
    const variable = evalFile('fixture.php', 'variable');
    const simple = evalFile('fixture.php', 'simple');
    created.push(variable.id, simple.id);
    check('Fixture: variable product created with 4 variations', variable.variation_ids.length === 4, JSON.stringify(variable));
    check('Fixture: simple product created', simple.id > 0, String(simple.id));

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
        if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) {
            consoleErrors.push(m.text());
        }
    });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    let confirmAnswer = true;
    page.on('dialog', async (d) => {
        if (d.type() === 'confirm' && !confirmAnswer) {
            await d.dismiss();
        } else {
            await d.accept();
        }
    });

    const editUrl = (id) => `${SITE}/wp-admin/post.php?post=${id}&action=edit`;
    const INFO_BOX = '#wpe_product_info';
    const VARS_BOX = '#wpe_variants_manager';
    const INFO_APP = '#wpe-product-info-app';
    const VARS_APP = '#wpe-product-editor-inline-app';

    const tabShown = (sel) => page.evaluate((q) => {
        const el = document.querySelector(q);
        return !!el && getComputedStyle(el).display !== 'none';
    }, sel);

    const waitInfo = async () => {
        await page.waitForSelector(`${INFO_APP} .wpe-info-wrap`, { timeout: 20000 });
    };

    const waitVars = async () => {
        await page.waitForSelector(`${VARS_APP} .wpe-editor-wrap`, { timeout: 20000 });
    };

    // ================================================================
    // Test 1: Simple Product Edit in Two-Block Mode
    // ================================================================
    await page.goto(editUrl(simple.id), { waitUntil: 'load' });
    await waitInfo();
    await waitVars();

    check('Simple product: #wpe_product_info meta box is visible', await page.locator(INFO_BOX).isVisible());
    check('Simple product: #wpe_variants_manager meta box is visible', await page.locator(VARS_BOX).isVisible());
    check('Simple product: Variants box shows empty state card', await page.locator(`${VARS_APP} .wpe-empty-variants-card`).isVisible());
    check('Simple product: Empty state has "Thêm tùy chọn / Add Option" button', await page.locator(`${VARS_APP} .wpe-btn-add-first-option`).isVisible());

    // Verify legacy WooCommerce product data tabs are hidden by CSS
    check('Legacy General tab is hidden in blocks mode', !(await tabShown('.product_data_tabs li.general_tab')));
    check('Legacy Inventory tab is hidden in blocks mode', !(await tabShown('.product_data_tabs li.inventory_tab')));
    check('Legacy Shipping tab is hidden in blocks mode', !(await tabShown('.product_data_tabs li.shipping_tab')));
    check('Legacy Attributes tab is hidden in blocks mode', !(await tabShown('.product_data_tabs li.attribute_tab')));
    check('Legacy Variations tab is hidden in blocks mode', !(await tabShown('.product_data_tabs li.variations_tab')));

    // Fill Product Info fields
    await page.locator('#wpe-info-regular-price').fill('35');
    await page.locator('#wpe-info-regular-price').dispatchEvent('change');

    await page.locator('#wpe-info-sale-price').fill('28');
    await page.locator('#wpe-info-sale-price').dispatchEvent('change');

    const testSku = 'SMP-LIVE-' + Math.floor(Math.random() * 10000);
    await page.locator('#wpe-info-sku').fill(testSku);
    await page.locator('#wpe-info-sku').dispatchEvent('change');

    await page.locator('#wpe-info-manage-stock').check();
    await page.locator('#wpe-info-manage-stock').dispatchEvent('change');

    await page.locator('#wpe-info-stock-quantity').fill('15');
    await page.locator('#wpe-info-stock-quantity').dispatchEvent('change');

    await page.locator('#wpe-info-backorders').selectOption('notify');
    await page.locator('#wpe-info-backorders').dispatchEvent('change');

    await page.locator('#wpe-info-weight').fill('1.5');
    await page.locator('#wpe-info-weight').dispatchEvent('change');

    await page.locator('#wpe-info-length').fill('10');
    await page.locator('#wpe-info-length').dispatchEvent('change');

    await page.locator('#wpe-info-width').fill('8');
    await page.locator('#wpe-info-width').dispatchEvent('change');

    await page.locator('#wpe-info-height').fill('5');
    await page.locator('#wpe-info-height').dispatchEvent('change');

    // Save changes via API button or #publish submit
    const saveBtn = page.locator(`${INFO_APP} .wpe-btn-save-api`);
    check('Save button is enabled after modifications', await saveBtn.isEnabled());
    await Promise.all([
        page.waitForResponse(r => r.url().includes('/wpe/v1/admin/product/') && r.request().method() === 'POST'),
        saveBtn.click(),
    ]);
    await page.waitForSelector(`${INFO_APP} .wpe-dirty-badge.clean`, { timeout: 10000 });

    // Reload page to verify persistence
    await page.goto(editUrl(simple.id), { waitUntil: 'load' });
    await waitInfo();

    check('Persistence: Regular price loaded as 35', (await page.locator('#wpe-info-regular-price').inputValue()) === '35');
    check('Persistence: Sale price loaded as 28', (await page.locator('#wpe-info-sale-price').inputValue()) === '28');
    check('Persistence: SKU loaded as ' + testSku, (await page.locator('#wpe-info-sku').inputValue()) === testSku);
    check('Persistence: Manage stock is checked', await page.locator('#wpe-info-manage-stock').isChecked());
    check('Persistence: Stock quantity is 15', (await page.locator('#wpe-info-stock-quantity').inputValue()) === '15');
    check('Persistence: Backorders is notify', (await page.locator('#wpe-info-backorders').inputValue()) === 'notify');
    check('Persistence: Weight is 1.5', (await page.locator('#wpe-info-weight').inputValue()) === '1.5');
    check('Persistence: Dimensions length=10, width=8, height=5',
        (await page.locator('#wpe-info-length').inputValue()) === '10' &&
        (await page.locator('#wpe-info-width').inputValue()) === '8' &&
        (await page.locator('#wpe-info-height').inputValue()) === '5'
    );

    // ================================================================
    // Test 2: Variable Product & Reference Price Rule
    // ================================================================
    await page.goto(editUrl(variable.id), { waitUntil: 'load' });
    await waitInfo();
    await waitVars();

    check('Variable product: Reference Price Card is displayed', await page.locator(`${INFO_APP} .wpe-ref-card`).isVisible());
    const refSourceText = await page.locator(`${INFO_APP} .wpe-ref-source`).innerText();
    check('Reference Price source cites first variation (S / Red)', /S \/ Red/i.test(refSourceText), refSourceText);
    const refAmountText = await page.locator(`${INFO_APP} .wpe-ref-amount`).innerText();
    check('Reference Price amount displays $20.00', /\$20/i.test(refAmountText), refAmountText);

    // Verify search / filter in Variants block does NOT alter reference price
    await page.locator(`${VARS_APP} .wpe-filter-input`).fill('Blue');
    await page.locator(`${VARS_APP} .wpe-filter-input`).dispatchEvent('keyup');
    await page.waitForTimeout(300);

    const refSourceAfterFilter = await page.locator(`${INFO_APP} .wpe-ref-source`).innerText();
    check('Reference Price source stays S / Red despite table search for "Blue"', /S \/ Red/i.test(refSourceAfterFilter), refSourceAfterFilter);

    // Clear search
    await page.locator(`${VARS_APP} .wpe-filter-input`).fill('');
    await page.locator(`${VARS_APP} .wpe-filter-input`).dispatchEvent('keyup');
    await page.waitForTimeout(300);

    // Test unpriced first variation: clear regular price of S / Red
    const firstRowRegPrice = page.locator(`${VARS_APP} tr[data-variant-id] .wpe-inline-reg-price`).first();
    await firstRowRegPrice.fill('');
    await firstRowRegPrice.dispatchEvent('change');
    await page.waitForTimeout(300);

    check('Reference Price Card switches to unpriced warning', await page.locator(`${INFO_APP} .wpe-ref-card.unpriced`).isVisible());
    const unpricedText = await page.locator(`${INFO_APP} .wpe-ref-unpriced-warning`).innerText();
    check('Unpriced warning cites first variant (S / Red)', /S \/ Red/i.test(unpricedText), unpricedText);

    // Test 0 price: set regular price of S / Red to 0
    await firstRowRegPrice.fill('0');
    await firstRowRegPrice.dispatchEvent('change');
    await page.waitForTimeout(300);

    check('Price 0 is treated as valid price, not unpriced', !(await page.locator(`${INFO_APP} .wpe-ref-card.unpriced`).isVisible()));
    const zeroAmountText = await page.locator(`${INFO_APP} .wpe-ref-amount`).innerText();
    check('Reference Price amount displays $0.00 for price 0', /\$0\.00/i.test(zeroAmountText), zeroAmountText);

    // Restore first variant price to 20
    await firstRowRegPrice.fill('20');
    await firstRowRegPrice.dispatchEvent('change');
    await page.waitForTimeout(300);

    // ================================================================
    // Test 3: Common Price Setup & Removal Dialog
    // ================================================================
    await page.locator(`${INFO_APP} .wpe-btn-set-common-price`).click();
    await page.waitForTimeout(300);

    check('Clicking "Set common price" opens Common Price Card', await page.locator(`${INFO_APP} .wpe-common-price-card`).isVisible());
    await page.locator(`${INFO_APP} .wpe-common-regular-price`).fill('30');
    await page.locator(`${INFO_APP} .wpe-common-regular-price`).dispatchEvent('change');

    await page.locator(`${INFO_APP} .wpe-common-sale-price`).fill('25');
    await page.locator(`${INFO_APP} .wpe-common-sale-price`).dispatchEvent('change');

    // In variants table, set first variant to shared pricing mode
    const firstRowModeSelect = page.locator(`${VARS_APP} tr[data-variant-id] .wpe-pricing-mode-select`).first();
    if (await firstRowModeSelect.count() > 0) {
        await firstRowModeSelect.selectOption('shared');
        await firstRowModeSelect.dispatchEvent('change');
    }

    // Click remove common price button
    await page.locator(`${INFO_APP} .wpe-btn-remove-common-price`).click();
    await page.waitForTimeout(300);

    const modal = page.locator('#wpe-common-removal-modal');
    check('Removing in-use common price triggers removal modal', await modal.isVisible());

    // Test Cancel button in modal
    await modal.locator('.wpe-btn-cancel-modal').first().click();
    await page.waitForTimeout(300);
    check('Cancel closes modal and keeps Common Price Card intact', !(await modal.isVisible()) && await page.locator(`${INFO_APP} .wpe-common-price-card`).isVisible());

    // Click remove again and confirm with conversion to own price
    await page.locator(`${INFO_APP} .wpe-btn-remove-common-price`).click();
    await page.waitForTimeout(300);
    await modal.locator('.wpe-btn-confirm-common-removal').click();
    await page.waitForTimeout(300);

    check('Confirm removal closes modal and removes Common Price Card', !(await modal.isVisible()) && !(await page.locator(`${INFO_APP} .wpe-common-price-card`).isVisible()));
    check('Reference Price Card is shown again after common price removal', await page.locator(`${INFO_APP} .wpe-ref-card`).isVisible());

    // Save and reload to verify clean state
    await Promise.all([
        page.waitForResponse(r => r.url().includes('/wpe/v1/admin/product/') && r.request().method() === 'POST'),
        page.locator(`${INFO_APP} .wpe-btn-save-api`).click(),
    ]);
    await page.waitForSelector(`${INFO_APP} .wpe-dirty-badge.clean`, { timeout: 10000 });

    // ================================================================
    // Test 4: Transition Variable -> Simple Dialog
    // ================================================================
    await page.goto(editUrl(variable.id), { waitUntil: 'load' });
    await waitInfo();
    await waitVars();

    // Delete first option (Color) so only Size remains
    const deleteOptionBtns = page.locator(`${VARS_APP} .wpe-btn-delete-option`);
    if ((await deleteOptionBtns.count()) > 1) {
        await deleteOptionBtns.first().click();
        await page.waitForTimeout(300);
    }

    // Now delete the remaining option: triggers Variable -> Simple modal
    const lastDeleteBtn = page.locator(`${VARS_APP} .wpe-btn-delete-option`).first();
    await lastDeleteBtn.click();
    await page.waitForTimeout(300);

    const transModal = page.locator('#wpe-variable-to-simple-modal');
    check('Deleting last option triggers Variable -> Simple transition modal', await transModal.isVisible());
    check('Transition modal contains receiver variant selector', await transModal.locator('#wpe-receiver-variant-select').isVisible());
    check('Transition modal contains preview box', await transModal.locator('#wpe-receiver-preview-box').isVisible());

    // Test Cancel: options and variations remain intact
    await transModal.locator('.wpe-btn-cancel-modal').first().click();
    await page.waitForTimeout(300);
    check('Cancel transition keeps options intact', !(await transModal.isVisible()) && (await page.locator(`${VARS_APP} .wpe-option-card`).count()) > 0);

    // Trigger transition again and confirm
    await page.locator(`${VARS_APP} .wpe-btn-delete-option`).first().click();
    await page.waitForTimeout(300);
    await transModal.locator('.wpe-btn-confirm-to-simple').click();
    await page.waitForTimeout(500);

    check('Confirmed transition converts Variants block to empty state', await page.locator(`${VARS_APP} .wpe-empty-variants-card`).isVisible());
    check('Confirmed transition shows Simple product pricing in Product info', await page.locator('#wpe-info-regular-price').isVisible());

    // ================================================================
    // Test 5: Leave Warning (beforeunload)
    // ================================================================
    // Make a change in product info without saving
    await page.locator('#wpe-info-sku').fill('UNSAVED-SKU-99');
    await page.locator('#wpe-info-sku').dispatchEvent('change');
    await page.waitForTimeout(200);

    const isDirty = await page.evaluate(() => window.wpeVariantStore ? window.wpeVariantStore.isDirty : false);
    check('Draft is marked dirty after editing SKU', isDirty);

    const beforeUnloadPrevented = await page.evaluate(() => {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);
        return event.defaultPrevented;
    });
    check('Dirty draft triggers beforeunload leave guard', beforeUnloadPrevented);

    check('No console errors occurred during the test suite', consoleErrors.length === 0, consoleErrors.join(' | '));

} catch (err) {
    console.error('Test threw unexpected exception:', err);
    exitCode = 1;
} finally {
    if (browser) await browser.close();
    try {
        if (created.length > 0) {
            evalFile('cleanup.php', ...created.map(String));
            console.log(`Cleaned up ${created.length} disposable test products.`);
        }
    } catch (e) {
        console.warn('Cleanup error:', e.message);
    }

    const flagsAfter = flagsInDb();
    if (flagsAfter !== originalFlags) {
        console.error('FATAL: wpe_feature_flags was mutated during test run!');
        exitCode = 1;
    }
}

const failed = results.filter((r) => !r.ok);
console.log(`\nResults: ${results.length - failed.length} passed, ${failed.length} failed.`);
if (failed.length > 0 || exitCode !== 0) {
    process.exit(1);
}
