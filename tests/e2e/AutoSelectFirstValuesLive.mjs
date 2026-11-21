/**
 * P029.3 — Live browser check of Auto-Select First Values (`auto_select_first_values`).
 *
 * On a live variable product without configured defaults:
 * 1. Toggle OFF: page opens with nothing selected (existing behavior).
 * 2. Toggle ON: server HTML already marks the first available values; in the browser the
 *    resolved variation_id, its price and an enabled Add to cart are ready on load; labels show
 *    "Name: Value"; no page errors.
 * 3. Clear empties the selection (returns to the configured defaults: none) and disables Add to cart.
 * 4. Choosing a value again after Clear resolves a variation.
 * Flags are restored to their saved value at the end.
 *
 * Usage: node tests/e2e/AutoSelectFirstValuesLive.mjs [productId]
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';

const SITE = process.env.WPE_SITE_URL || 'http://site1.localhost';
const WP_PATH = process.env.WPE_WP_PATH || '/var/www/site1.localhost';
const PRODUCT_ID = Number(process.argv[2] || process.env.WPE_AUTOSELECT_PRODUCT || 24704);

const wp = (...args) => execFileSync('wp', [`--path=${WP_PATH}`, ...args], { encoding: 'utf8' }).trim();
const php = (code) => wp('eval', code);

const results = [];
function check(name, ok, detail) {
    results.push({ name, ok: Boolean(ok) });
    console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (!ok && detail !== undefined ? '  -> ' + detail : ''));
}

const savedFlags = php('echo wp_json_encode(get_option("wpe_feature_flags", false));');
const setAutoFlag = (on) =>
    php(`$f = get_option("wpe_feature_flags", []); if (!is_array($f)) { $f = []; } $f["auto_select_first_values"] = ${on ? 'true' : 'false'}; update_option("wpe_feature_flags", $f); wp_cache_flush();`);

const productUrl = php(`echo get_permalink(${PRODUCT_ID});`);
const bust = () => `${productUrl}${productUrl.includes('?') ? '&' : '?'}v=${Date.now()}`;

async function readState(page) {
    return page.evaluate(() => {
        const form = document.querySelector('form.variations_form');
        const checked = [...document.querySelectorAll('.wpe-swatch[aria-checked="true"]')].map((e) => e.getAttribute('data-value-id'));
        const btn = document.querySelector('.single_add_to_cart_button');
        const labels = [...document.querySelectorAll('.variations label, .wpe-option-label')].map((l) => l.textContent.replace(/\s+/g, ' ').trim());
        const price = document.querySelector('.wpe-single-price-target, .summary .price');
        return {
            vid: form?.querySelector('input[name="variation_id"]')?.value || '',
            checked,
            addDisabled: btn ? btn.disabled || btn.classList.contains('disabled') : null,
            labels,
            price: price ? price.textContent.replace(/\s+/g, ' ').trim() : '',
        };
    });
}

let browser;
try {
    // Expected opening state, computed by the server with the flag on.
    setAutoFlag(true);
    const expected = JSON.parse(php(`$c = (new \\WPE\\Compatibility\\StoreApi\\SelectorAdapter())->getSelectorConfig(${PRODUCT_ID}); echo wp_json_encode(["sel" => $c["autoSelection"], "default" => $c["defaultSelection"], "vid" => $c["autoResolution"]["variant"]["id"] ?? null, "price" => html_entity_decode(wp_strip_all_tags($c["autoResolution"]["variant"]["price_html"] ?? ""), ENT_QUOTES | ENT_HTML5, "UTF-8")]);`));
    check('server computes an auto selection for the product', expected.sel && Object.keys(expected.sel).length > 0 && expected.vid, JSON.stringify(expected));
    check('product has no configured default (Clear target is empty)', Array.isArray(expected.default) ? expected.default.length === 0 : Object.keys(expected.default || {}).length === 0, JSON.stringify(expected.default));

    browser = await chromium.launch();
    const page = await browser.newPage();
    const pageErrors = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));

    // 1. Toggle OFF
    setAutoFlag(false);
    await page.goto(bust(), { waitUntil: 'networkidle' });
    let st = await readState(page);
    check('OFF: nothing selected on load', st.checked.length === 0 && (st.vid === '' || st.vid === '0'), JSON.stringify(st));

    // 2. Toggle ON — server HTML first (no JS), then the live page
    setAutoFlag(true);
    const html = await (await fetch(bust())).text();
    const ssrChecked = [...html.matchAll(/data-value-id="(\d+)"[^>]*aria-checked="true"|aria-checked="true"[^>]*data-value-id="(\d+)"/g)].map((m) => m[1] || m[2]);
    const expectedValues = Object.values(expected.sel).map(String);
    check('ON: server HTML marks the auto-selected values', expectedValues.every((v) => ssrChecked.includes(v)), `ssr=${ssrChecked} expected=${expectedValues}`);

    await page.goto(bust(), { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    st = await readState(page);
    check('ON: browser selection equals the server auto selection', expectedValues.every((v) => st.checked.includes(v)), JSON.stringify(st.checked));
    check('ON: variation_id resolved on load', String(st.vid) === String(expected.vid), `vid=${st.vid} expected=${expected.vid}`);
    check('ON: Add to cart enabled on load', st.addDisabled === false, JSON.stringify(st));
    check('ON: price shows the selected variation', expected.price !== '' && st.price.includes(expected.price.trim()), `page="${st.price}" expected="${expected.price}"`);
    check('ON: labels show "Name: Value"', st.labels.some((l) => /:\s*\S/.test(l)), JSON.stringify(st.labels));

    // 3. Clear
    const clear = page.locator('.wpe-reset-selection, .reset_variations').first();
    await clear.click();
    await page.waitForTimeout(400);
    st = await readState(page);
    check('Clear: selection emptied (no configured default)', st.checked.length === 0, JSON.stringify(st.checked));
    check('Clear: variation_id cleared and Add to cart disabled', (st.vid === '' || st.vid === '0') && st.addDisabled === true, JSON.stringify(st));

    // 4. Choose again after Clear
    const lastOption = Object.keys(expected.sel).pop();
    const firstOption = Object.keys(expected.sel)[0];
    if (Object.keys(expected.sel).length > 1) {
        await page.locator(`.wpe-swatch[data-option-id="${firstOption}"][data-value-id="${expected.sel[firstOption]}"]`).first().click();
    }
    await page.locator(`.wpe-swatch[data-option-id="${lastOption}"][data-value-id="${expected.sel[lastOption]}"]`).first().click();
    await page.waitForTimeout(800);
    st = await readState(page);
    check('After Clear: choosing values resolves the variation again', String(st.vid) === String(expected.vid), `vid=${st.vid}`);

    check('no page errors', pageErrors.length === 0, pageErrors.join(' | '));
} catch (e) {
    check('run completed', false, e && e.message);
} finally {
    if (browser) {
        await browser.close();
    }
    const restore = savedFlags === 'false'
        ? 'delete_option("wpe_feature_flags");'
        : `update_option("wpe_feature_flags", json_decode(${JSON.stringify(savedFlags)}, true));`;
    php(restore + ' wp_cache_flush();');
}

const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed === 0 ? 0 : 1);
