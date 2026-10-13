/**
 * P010.10 — Live browser verification of the Two-Block Product Management UI.
 *
 * Verifies end-to-end:
 * 1. Simple product baseline & Simple -> Variable transition dialog:
 *    - #wpe_product_info and #wpe_variants_manager visible, empty state with Add Option button.
 *    - Click "Thêm tùy chọn / Add Option" opens #wpe-simple-to-variable-modal preview.
 *    - Cancel keeps Simple baseline; Confirm converts to Variable, creates option card, transfers simple price to common price.
 * 2. Option cards, chips, and Selective Combination Generator:
 *    - Add values to option card via chip inputs.
 *    - Add second option card.
 *    - Selective generator modal previews potential, existing vs new combinations.
 *    - Selective creation applies new combinations to table with default shared pricing.
 * 3. Shared vs Own pricing mode in table:
 *    - Shared mode disables price inputs and displays common price.
 *    - Switching to "Giá riêng / Own" enables price inputs; updating common price preserves own prices.
 * 4. Slide-over detail drawer and Bulk Edit with diff preview:
 *    - Detail drawer edits and saves variation SKU and weight.
 *    - Bulk edit displays diff preview of affected variations before applying.
 * 5. Fullscreen modal shared draft:
 *    - Opens fullscreen overlay with "Biến thể" and "Thông tin sản phẩm" tabs.
 *    - Edits in fullscreen immediately reflect inline upon closing without saving to server yet.
 * 6. Save persistence via unified API:
 *    - Saves via unified endpoint, reloads page, verifies complete persistence.
 * 7. Reference price resilience:
 *    - Variable product reference price displays first variant price independent of table filter/search.
 *    - Distinguishes valid $0.00 vs unpriced first variant.
 * 8. Variable -> Simple transition dialog and dirty draft leave guard:
 *    - Deleting last option triggers transition modal with receiver selector.
 *    - Cancel keeps options; Confirm transitions to simple.
 *    - Dirty draft triggers beforeunload leave guard.
 *
 * Usage: node tests/e2e/TwoBlockLive.mjs
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
const work = mkdtempSync(path.join(tmpdir(), 'wpe-twoblock-live-'));
const fixtureFile = path.join(work, 'fixture.php');
writeFileSync(fixtureFile, `<?php
$type = $args[0] ?? 'variable';
$id = wp_insert_post(['post_title' => 'P010 TwoBlock Live ' . $type . ' ' . uniqid(), 'post_status' => 'publish', 'post_type' => 'product']);
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
            'sku' => 'P102B-' . $r[0] . $r[1] . '-' . uniqid(),
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
} else {
    $svc = \\WPE\\Admin\\ProductEditor\\ProductEditorService::getInstance();
    $version = $svc->getProductEditorData($id)['product']['version'];
    $svc->saveProductVariants($id, [
        'expected_version' => $version,
        'product' => [
            'regular_price' => '35',
            'sale_price' => '28',
            'sku' => 'SMP-ORIG-' . uniqid(),
            'stock' => [
                'manage_stock' => 1,
                'stock_quantity' => 15,
                'stock_status' => 'instock',
                'backorders' => 'no'
            ]
        ]
    ]);
}
echo 'JSON:' . wp_json_encode($out);
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
        console.log(`[BROWSER ${m.type()}]:`, m.text());
        if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) {
            consoleErrors.push(m.text());
        }
    });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    let promptResponse = 'Color';
    let confirmAnswer = true;
    page.on('dialog', async (d) => {
        console.log('[BROWSER DIALOG]:', d.type(), d.message());
        if (d.type() === 'prompt') {
            await d.accept(promptResponse);
        } else if (d.type() === 'confirm' && !confirmAnswer) {
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

    const waitApps = async () => {
        await page.waitForSelector(`${INFO_APP} .wpe-info-wrap`, { timeout: 20000 });
        await page.waitForSelector(`${VARS_APP} .wpe-editor-wrap`, { timeout: 20000 });
    };

    // ================================================================
    // Test 1: Simple Product Baseline & Simple -> Variable Transition Modal
    // ================================================================
    await page.goto(editUrl(simple.id), { waitUntil: 'load' });
    await waitApps();

    check('Meta box #wpe_product_info is visible', await page.locator(INFO_BOX).isVisible());
    check('Meta box #wpe_variants_manager is visible', await page.locator(VARS_BOX).isVisible());
    check('Simple product: Variants block displays empty state card', await page.locator(`${VARS_APP} .wpe-empty-variants-card`).isVisible());
    check('Empty state card has "Thêm tùy chọn" button', await page.locator(`${VARS_APP} .wpe-btn-add-first-option`).isVisible());

    // Click "Thêm tùy chọn" to open Simple -> Variable modal
    await page.locator(`${VARS_APP} .wpe-btn-add-first-option`).click();
    await page.waitForSelector('#wpe-simple-to-variable-modal', { state: 'visible', timeout: 5000 });

    const transModal = page.locator('#wpe-simple-to-variable-modal');
    check('Clicking "Thêm tùy chọn" opens #wpe-simple-to-variable-modal', await transModal.isVisible());
    check('Transition modal contains preview of proposed common price', await transModal.locator('#wpe-simple-to-variable-preview').isVisible());
    check('Transition modal proposes option name input with default "Size"', (await transModal.locator('#wpe-first-option-name').inputValue()) === 'Size');

    // Test Cancel button in Simple -> Variable modal
    await transModal.locator('.wpe-btn-cancel-modal').first().click();
    await page.waitForSelector('#wpe-simple-to-variable-modal', { state: 'detached', timeout: 5000 });
    check('Cancel closes modal and product remains Simple', await page.locator(`${VARS_APP} .wpe-empty-variants-card`).isVisible());

    // Open again and Confirm transition to Variable
    await page.locator(`${VARS_APP} .wpe-btn-add-first-option`).click();
    await page.waitForSelector('#wpe-simple-to-variable-modal', { state: 'visible', timeout: 5000 });
    await transModal.locator('.wpe-btn-confirm-to-variable').click();
    await page.waitForSelector('#wpe-simple-to-variable-modal', { state: 'detached', timeout: 5000 });

    check('Confirmed transition creates first option card "Size"', (await page.locator(`${VARS_APP} .wpe-option-card`).count()) === 1);
    check('Confirmed transition displays Common Price card in Product Info block', await page.locator(`${INFO_APP} .wpe-common-price-card`).isVisible());
    check('Common regular price initialized from simple product price ($35)', (await page.locator(`${INFO_APP} .wpe-common-regular-price`).inputValue()) === '35');

    // ================================================================
    // Test 2: Option Cards, Values, and Selective Combination Generator
    // ================================================================
    // Add values 'S' and 'M' to the first option card (Size)
    const sizeChipInput = page.locator(`${VARS_APP} .wpe-option-card`).first().locator('.wpe-chip-input');
    await sizeChipInput.fill('S');
    await sizeChipInput.press('Enter');
    await page.waitForTimeout(200);

    await sizeChipInput.fill('M');
    await sizeChipInput.press('Enter');
    await page.waitForTimeout(200);

    const sizeChips = await page.locator(`${VARS_APP} .wpe-option-card`).first().locator('.wpe-chip').allInnerTexts();
    check('Size option card has chips S and M', sizeChips.some(t => /S/.test(t)) && sizeChips.some(t => /M/.test(t)), sizeChips.join(', '));

    // Add second option 'Color' via prompt
    promptResponse = 'Color';
    await page.locator(`${VARS_APP} .wpe-btn-add-option`).click();
    await page.waitForTimeout(300);

    check('Second option card "Color" was created', (await page.locator(`${VARS_APP} .wpe-option-card`).count()) === 2);

    // Add values 'Red' and 'Blue' to Color option card
    const colorChipInput = page.locator(`${VARS_APP} .wpe-option-card`).nth(1).locator('.wpe-chip-input');
    await colorChipInput.fill('Red');
    await colorChipInput.press('Enter');
    await page.waitForTimeout(200);

    await colorChipInput.fill('Blue');
    await colorChipInput.press('Enter');
    await page.waitForTimeout(200);

    const colorChips = await page.locator(`${VARS_APP} .wpe-option-card`).nth(1).locator('.wpe-chip').allInnerTexts();
    check('Color option card has chips Red and Blue', colorChips.some(t => /Red/.test(t)) && colorChips.some(t => /Blue/.test(t)), colorChips.join(', '));

    // Open Selective Combination Generator
    await page.locator(`${VARS_APP} .wpe-btn-open-generator`).click();
    await page.waitForSelector('#wpe-generator-modal .wpe-gen-check', { timeout: 10000 });

    const genChecks = page.locator('#wpe-generator-modal .wpe-gen-check');
    check('Generator modal lists 4 new combinations', (await genChecks.count()) === 4, await genChecks.count());

    // Test Deselect All then selectively choose first 2 combinations
    await page.locator('#wpe-generator-modal .wpe-btn-gen-unselect-all').click();
    await page.waitForTimeout(100);
    check('Deselect All unchecks combination checkboxes', (await page.locator('#wpe-generator-modal .wpe-gen-check:checked').count()) === 0);

    await genChecks.nth(0).check();
    await genChecks.nth(1).check();
    check('Checked exactly 2 combinations for selective creation', (await page.locator('#wpe-generator-modal .wpe-gen-check:checked').count()) === 2);

    await page.locator('#wpe-generator-modal .wpe-btn-apply-generator').click();
    await page.waitForSelector('#wpe-generator-modal', { state: 'detached', timeout: 5000 });
    await page.waitForSelector(`${VARS_APP} tr[data-variant-id]`, { timeout: 10000 });

    const tableRows = page.locator(`${VARS_APP} tr[data-variant-id]`);
    check('Selective generator created exactly 2 variation rows in table', (await tableRows.count()) === 2, await tableRows.count());

    // Verify created variations defaulted to shared pricing mode with common regular price 35
    const row1Mode = await tableRows.nth(0).locator('.wpe-pricing-mode-select').inputValue();
    const row2Mode = await tableRows.nth(1).locator('.wpe-pricing-mode-select').inputValue();
    check('New variations default to "shared" pricing mode when common price exists', row1Mode === 'shared' && row2Mode === 'shared', `row1=${row1Mode}, row2=${row2Mode}`);

    const row1RegPrice = await tableRows.nth(0).locator('.wpe-inline-reg-price').inputValue();
    const row1RegDisabled = await tableRows.nth(0).locator('.wpe-inline-reg-price').isDisabled();
    check('Shared variation has price input disabled and formatted from common price (35)', row1RegDisabled && row1RegPrice === '35', `disabled=${row1RegDisabled}, val=${row1RegPrice}`);

    // ================================================================
    // Test 3: Shared vs Own Pricing Mode in Table
    // ================================================================
    // Switch row 1 to "Giá riêng / Own"
    await tableRows.nth(0).locator('.wpe-pricing-mode-select').selectOption('own');
    await tableRows.nth(0).locator('.wpe-pricing-mode-select').dispatchEvent('change');
    await page.waitForTimeout(300);

    const row1RegDisabledAfter = await page.locator(`${VARS_APP} tr[data-variant-id]`).first().locator('.wpe-inline-reg-price').isDisabled();
    check('Switching to "own" enables regular price input', !row1RegDisabledAfter);

    // Edit row 1 own regular price to 42
    await page.locator(`${VARS_APP} tr[data-variant-id]`).first().locator('.wpe-inline-reg-price').fill('42');
    await page.locator(`${VARS_APP} tr[data-variant-id]`).first().locator('.wpe-inline-reg-price').dispatchEvent('change');
    await page.waitForTimeout(200);

    // Update common regular price in Product Info block to 38
    await page.locator(`${INFO_APP} .wpe-common-regular-price`).fill('38');
    await page.locator(`${INFO_APP} .wpe-common-regular-price`).dispatchEvent('change');
    await page.waitForTimeout(300);

    // Verify row 2 (shared) receives the new common price 38, while row 1 (own) stays 42
    const row1PriceCurrent = await page.locator(`${VARS_APP} tr[data-variant-id]`).nth(0).locator('.wpe-inline-reg-price').inputValue();
    const row2PriceCurrent = await page.locator(`${VARS_APP} tr[data-variant-id]`).nth(1).locator('.wpe-inline-reg-price').inputValue();
    check('Row 1 (own) preserves custom price 42 when common price changes', row1PriceCurrent === '42', row1PriceCurrent);
    check('Row 2 (shared) reflects updated common price 38', row2PriceCurrent === '38', row2PriceCurrent);

    // ================================================================
    // Test 4: Detail Drawer & Bulk Edit with Diff Preview
    // ================================================================
    const drawerSku = `TWOB-DRAWER-${simple.id}`;
    const fsVariantSku = `FS-SHARED-${simple.id}`;
    const fsParentSku = `PARENT-SKU-${simple.id}`;

    // Open Detail Drawer on row 1
    await page.locator(`${VARS_APP} tr[data-variant-id]`).first().locator('.wpe-btn-open-drawer').click();
    await page.waitForSelector('#wpe-variation-drawer-overlay', { state: 'visible', timeout: 5000 });
    check('Clicking detail edit opens variation drawer', await page.locator('#wpe-variation-drawer-overlay').isVisible());

    // Edit SKU and weight in drawer
    await page.locator('#wpe-drawer-sku').fill(drawerSku);
    await page.locator('#wpe-drawer-weight').fill('2.25');
    await page.locator('.wpe-btn-save-drawer').click();
    await page.waitForSelector('#wpe-variation-drawer-overlay', { state: 'hidden', timeout: 5000 });

    const updatedSkuInTable = await page.locator(`${VARS_APP} tr[data-variant-id]`).first().locator('.wpe-inline-sku').inputValue();
    check(`Saving drawer updates table SKU to ${drawerSku}`, updatedSkuInTable === drawerSku, updatedSkuInTable);


    // ================================================================
    // Test 5: Fullscreen Modal with Shared Draft
    // ================================================================
    await page.locator(`${VARS_APP} .wpe-btn-open-fullscreen`).click();
    await page.waitForSelector('#wpe-fullscreen-modal-overlay', { state: 'visible', timeout: 5000 });
    check('Fullscreen modal opens with role=dialog', (await page.locator('#wpe-fullscreen-modal-overlay').getAttribute('role')) === 'dialog');

    // Edit row 2 SKU in fullscreen
    const fsRow2Sku = page.locator('#wpe-product-editor-modal-app tr[data-variant-id] .wpe-inline-sku').nth(1);
    await fsRow2Sku.fill(fsVariantSku);
    await fsRow2Sku.dispatchEvent('change');
    await page.waitForTimeout(200);

    // Switch to Product Info tab in fullscreen
    await page.locator('#wpe-fullscreen-modal-overlay .wpe-tab-btn[data-tab="product_info"]').click();
    await page.waitForSelector('#wpe-product-editor-modal-app #wpe-info-sku', { timeout: 5000 });

    // Edit parent SKU in fullscreen Product Info
    await page.locator('#wpe-product-editor-modal-app #wpe-info-sku').fill(fsParentSku);
    await page.locator('#wpe-product-editor-modal-app #wpe-info-sku').dispatchEvent('change');
    await page.waitForTimeout(200);

    // Close Fullscreen modal
    await page.locator('#wpe-fullscreen-modal-overlay .wpe-btn-close-fullscreen').click();
    await page.waitForSelector('#wpe-fullscreen-modal-overlay', { state: 'hidden', timeout: 5000 });

    // Verify inline views immediately reflect fullscreen edits without having saved to server
    const inlineRow2Sku = await page.locator(`${VARS_APP} tr[data-variant-id] .wpe-inline-sku`).nth(1).inputValue();
    const inlineParentSku = await page.locator(`${INFO_APP} #wpe-info-sku`).inputValue();
    check('Fullscreen edit to variant SKU reflected inline (shared draft)', inlineRow2Sku === fsVariantSku, inlineRow2Sku);
    check('Fullscreen edit to parent SKU reflected inline (shared draft)', inlineParentSku === fsParentSku, inlineParentSku);

    // ================================================================
    // Test 6: Unified Save Persistence & Reload
    // ================================================================
    const savePromise = page.waitForResponse(r => r.url().includes('/wpe/v1/admin/product/') && r.request().method() === 'POST');
    await page.locator(`${INFO_APP} .wpe-btn-save-api`).click();
    const saveResp = await savePromise;
    check('Save API response status 200', saveResp.status() === 200, String(saveResp.status()));
    await page.waitForSelector(`${INFO_APP} .wpe-dirty-badge.clean`, { timeout: 10000 });

    // Reload page to verify persistence from server
    await page.goto(editUrl(simple.id), { waitUntil: 'load' });
    await waitApps();

    check('Persistence: Product badge confirms Variable type', /variable/i.test(await page.locator(`${INFO_APP} .wpe-badge-type`).innerText()));
    check('Persistence: Common regular price loaded as 38', (await page.locator(`${INFO_APP} .wpe-common-regular-price`).inputValue()) === '38');
    check(`Persistence: Parent SKU loaded as ${fsParentSku}`, (await page.locator(`${INFO_APP} #wpe-info-sku`).inputValue()) === fsParentSku);
    check('Persistence: 2 option cards loaded (Size, Color)', (await page.locator(`${VARS_APP} .wpe-option-card`).count()) === 2);

    const reloadedRows = page.locator(`${VARS_APP} tr[data-variant-id]`);
    check('Persistence: 2 variation rows loaded', (await reloadedRows.count()) === 2);
    check(`Persistence: Row 1 has SKU ${drawerSku} and own price 42`,
        (await reloadedRows.nth(0).locator('.wpe-inline-sku').inputValue()) === drawerSku &&
        (await reloadedRows.nth(0).locator('.wpe-inline-reg-price').inputValue()) === '42'
    );
    check(`Persistence: Row 2 has SKU ${fsVariantSku} and shared price 38`,
        (await reloadedRows.nth(1).locator('.wpe-inline-sku').inputValue()) === fsVariantSku &&
        (await reloadedRows.nth(1).locator('.wpe-inline-reg-price').inputValue()) === '38'
    );

    // ================================================================
    // Test 7: Reference Price Resilience on Variable Product Fixture
    // ================================================================
    await page.goto(editUrl(variable.id), { waitUntil: 'load' });
    await waitApps();

    check('Variable fixture displays Reference Price Card', await page.locator(`${INFO_APP} .wpe-ref-card`).isVisible());
    const refSrc = await page.locator(`${INFO_APP} .wpe-ref-source`).innerText();
    check('Reference Price cites first variant (S / Red)', /S \/ Red/i.test(refSrc), refSrc);

    // Filter table by search query "Blue"
    await page.locator(`${VARS_APP} .wpe-filter-input`).fill('Blue');
    await page.locator(`${VARS_APP} .wpe-filter-input`).dispatchEvent('keyup');
    await page.waitForTimeout(300);

    const refSrcAfterSearch = await page.locator(`${INFO_APP} .wpe-ref-source`).innerText();
    check('Reference Price source stays S / Red despite table filter for "Blue"', /S \/ Red/i.test(refSrcAfterSearch), refSrcAfterSearch);

    // Clear filter
    await page.locator(`${VARS_APP} .wpe-filter-input`).fill('');
    await page.locator(`${VARS_APP} .wpe-filter-input`).dispatchEvent('keyup');
    await page.waitForTimeout(200);

    // Test 0 price vs unpriced
    const varRow1Reg = page.locator(`${VARS_APP} tr[data-variant-id] .wpe-inline-reg-price`).first();
    await varRow1Reg.fill('');
    await varRow1Reg.dispatchEvent('change');
    await page.waitForTimeout(200);
    check('Empty first variant price displays unpriced warning', await page.locator(`${INFO_APP} .wpe-ref-card.unpriced`).isVisible());

    await varRow1Reg.fill('0');
    await varRow1Reg.dispatchEvent('change');
    await page.waitForTimeout(200);
    check('Price 0 displays valid reference amount $0.00', /\$0\.00/i.test(await page.locator(`${INFO_APP} .wpe-ref-amount`).innerText()));

    await varRow1Reg.fill('20');
    await varRow1Reg.dispatchEvent('change');
    await page.waitForTimeout(200);

    // Bulk edit diff preview on existing variations
    await page.locator(`${VARS_APP} .wpe-row-check`).nth(0).check();
    await page.locator(`${VARS_APP} .wpe-row-check`).nth(1).check();
    await page.locator(`${VARS_APP} .wpe-btn-open-bulk-edit`).click();
    await page.waitForSelector('#wpe-bulk-modal', { state: 'visible', timeout: 5000 });
    await page.selectOption('#wpe-bulk-field', 'regular_price');
    await page.selectOption('#wpe-bulk-price-type', 'increase_percent');
    await page.fill('#wpe-bulk-price-val', '10');
    await page.locator('.wpe-btn-preview-bulk-diff').click();
    await page.waitForSelector('#wpe-bulk-diff-preview table', { timeout: 10000 });
    check('Bulk edit diff preview renders before/after rows', (await page.locator('#wpe-bulk-diff-preview table tr').count()) >= 2);
    await page.locator('#wpe-bulk-modal .wpe-btn-close-modal').first().click();
    await page.waitForSelector('#wpe-bulk-modal', { state: 'detached', timeout: 5000 });

    // ================================================================
    // Test 8: Variable -> Simple Transition Modal & Dirty Leave Guard
    // ================================================================
    // Delete Color option so only Size remains
    const delOpts = page.locator(`${VARS_APP} .wpe-btn-delete-option`);
    if ((await delOpts.count()) > 1) {
        await delOpts.first().click();
        await page.waitForTimeout(300);
    }

    // Deleting the last option triggers Variable -> Simple transition modal
    await page.locator(`${VARS_APP} .wpe-btn-delete-option`).first().click();
    await page.waitForSelector('#wpe-variable-to-simple-modal', { state: 'visible', timeout: 5000 });

    const v2sModal = page.locator('#wpe-variable-to-simple-modal');
    check('Deleting last option triggers #wpe-variable-to-simple-modal', await v2sModal.isVisible());
    check('Modal has receiver variant selector and preview box',
        await v2sModal.locator('#wpe-receiver-variant-select').isVisible() &&
        await v2sModal.locator('#wpe-receiver-preview-box').isVisible()
    );

    // Test Cancel keeps options
    await v2sModal.locator('.wpe-btn-cancel-modal').first().click();
    await page.waitForSelector('#wpe-variable-to-simple-modal', { state: 'detached', timeout: 5000 });
    check('Cancel transition keeps options intact', (await page.locator(`${VARS_APP} .wpe-option-card`).count()) > 0);

    // Delete again and Confirm transition to Simple
    await page.locator(`${VARS_APP} .wpe-btn-delete-option`).first().click();
    await page.waitForSelector('#wpe-variable-to-simple-modal', { state: 'visible', timeout: 5000 });
    await v2sModal.locator('.wpe-btn-confirm-to-simple').click();
    await page.waitForSelector('#wpe-variable-to-simple-modal', { state: 'detached', timeout: 5000 });
    await page.waitForTimeout(400);

    check('Confirmed transition converts Variants block to empty state', await page.locator(`${VARS_APP} .wpe-empty-variants-card`).isVisible());
    check('Confirmed transition activates Simple regular price input in Product Info', await page.locator('#wpe-info-regular-price').isVisible());

    // Test beforeunload dirty leave guard
    await page.locator('#wpe-info-sku').fill('UNSAVED-FINAL-SKU');
    await page.locator('#wpe-info-sku').dispatchEvent('change');
    await page.waitForTimeout(200);

    const dirtyCheck = await page.evaluate(() => window.wpeVariantStore ? window.wpeVariantStore.isDirty : false);
    check('Store marked dirty after unsaved edit', dirtyCheck);

    const prevented = await page.evaluate(() => {
        const ev = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(ev);
        return ev.defaultPrevented;
    });
    check('Dirty draft triggers beforeunload leave guard', prevented);

    check('No console errors occurred throughout two-block test suite', consoleErrors.length === 0, consoleErrors.join(' | '));

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
