// R107 out_of_order_responses — behavior in a real browser.
//
// Two quick swatch clicks fire two resolve requests. The server answers the FIRST request
// slowly and the SECOND quickly, so the stale response is the last one to arrive. The selector
// must end on the second selection. The same scenario is run with the AbortController neutralised,
// so the request_sequence guard is proven on its own, and once more against a mutated selector.js
// to prove this test fails when the guard is removed.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const SELECTOR_JS = fs.readFileSync(path.join(root, 'assets/variant-selector/selector.js'), 'utf8');
const GUARD = 'data.request_sequence !== undefined && data.request_sequence < this.requestSequence';

// Same classes/ARIA contract as SelectorAdapter::buildAccessibleSwatchGroupHtml (asserted by T27 R108).
const HTML = `<!doctype html>
<html><body class="single-product">
<div class="product"><p class="price">PARENT PRICE</p>
<form class="variations_form" id="form">
  <div class="wpe-swatches" role="radiogroup" aria-label="Color" data-option-id="pa_color">
    <button type="button" class="wpe-swatch" role="radio" aria-checked="false" tabindex="0" data-option-id="pa_color" data-value-id="10">Black</button>
    <button type="button" class="wpe-swatch" role="radio" aria-checked="false" tabindex="-1" data-option-id="pa_color" data-value-id="11">Red</button>
  </div>
  <input type="hidden" name="variation_id" value="">
  <button type="submit" class="single_add_to_cart_button disabled" disabled>Add to cart</button>
</form></div>
<script>window.wpeSelectorData = {productId: 1, resolveEndpoint: 'http://wpe.test/resolve', nonce: 'n', productRevision: '1', defaultSelection: {}};</script>
<script src="http://wpe.test/selector.js"></script>
</body></html>`;

// value id -> [server delay in ms, variation id, price]
const SERVER = { 10: [900, 1010, '$10.00'], 11: [60, 1011, '$11.00'] };

async function setup(page, { selectorJs = SELECTOR_JS, neutraliseAbort = false } = {}) {
  const seen = { requests: [], delivered: [] };
  if (neutraliseAbort) {
    await page.addInitScript(() => { AbortController.prototype.abort = function () {}; });
  }
  await page.route('http://wpe.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: HTML });
    if (url.pathname === '/selector.js') return route.fulfill({ contentType: 'text/javascript', body: selectorJs });
    if (url.pathname === '/resolve') {
      const body = JSON.parse(route.request().postData() || '{}');
      const value = body.selection?.pa_color;
      const [delay, variationId, price] = SERVER[value];
      seen.requests.push({ value, sequence: body.request_sequence });
      await new Promise((resolve) => setTimeout(resolve, delay));
      try {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' },
          body: JSON.stringify({
            state: 'exact',
            request_sequence: body.request_sequence,
            product_revision: '1',
            allowed_values: {},
            variant: { id: variationId, display_price: price, purchasable: true, available: true },
          }),
        });
        seen.delivered.push(value);
      } catch {
        // The browser aborted this request: nothing is delivered.
      }
      return;
    }
    return route.abort();
  });
  await page.route('http://wpe.test/resolve', async (route) => {
    if (route.request().method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': 'POST' } });
    }
    return route.fallback();
  });
  await page.goto('http://wpe.test/');
  await expect(page.locator('.wpe-swatch')).toHaveCount(2);
  return seen;
}

async function clickBlackThenRed(page) {
  await page.locator('.wpe-swatch[data-value-id="10"]').click();
  await page.locator('.wpe-swatch[data-value-id="11"]').click();
}

test('R107 fast switching ends on the newest selection and ignores the slow stale response', async ({ page }) => {
  const seen = await setup(page);
  await clickBlackThenRed(page);
  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1011');
  await page.waitForTimeout(SERVER[10][0] + 400); // long after the stale response would have landed
  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1011');
  await expect(page.locator('.price')).toContainText('$11.00');
  await expect(page.locator('#form')).not.toHaveAttribute('aria-busy', 'true');
  await expect(page.locator('.single_add_to_cart_button')).toBeEnabled();
  expect(seen.requests.map((r) => r.sequence)).toEqual([1, 2]);
});

test('R107 request_sequence guard alone discards a stale response that really arrives', async ({ page }) => {
  const seen = await setup(page, { neutraliseAbort: true });
  await clickBlackThenRed(page);
  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1011');
  await expect.poll(() => seen.delivered.length, { timeout: 5000 }).toBe(2);
  await page.waitForTimeout(200);
  expect(seen.delivered).toEqual(['11', '10']); // the stale response was delivered last...
  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1011'); // ...and ignored
  await expect(page.locator('.price')).toContainText('$11.00');
});

test('R107 control: without the sequence guard the stale response wins (the test can fail)', async ({ page }) => {
  expect(SELECTOR_JS).toContain(GUARD);
  const mutated = SELECTOR_JS.replace(GUARD, 'false');
  expect(mutated).not.toBe(SELECTOR_JS);
  const seen = await setup(page, { selectorJs: mutated, neutraliseAbort: true });
  await clickBlackThenRed(page);
  await expect.poll(() => seen.delivered.length, { timeout: 5000 }).toBe(2);
  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1010'); // stale value overwrote the newer one
});
