// Option priority — the selector adopts the effective selection returned by the resolver.
//
// With Style=Classic and Size=5XL chosen, clicking Style=Women's (which has no 5XL) makes the
// server reconcile Size to S and return it in `selection`. The selector must switch the Size
// swatch and its <select> to S, keep Women's selected, and announce the change.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const SELECTOR_JS = fs.readFileSync(path.join(root, 'assets/variant-selector/selector.js'), 'utf8');

const HTML = `<!doctype html>
<html><body class="single-product">
<div class="product"><p class="price">PARENT PRICE</p>
<form class="variations_form" id="form">
  <div class="wpe-swatches" role="radiogroup" aria-label="Style" data-option-id="1">
    <button type="button" class="wpe-swatch" role="radio" aria-checked="false" tabindex="0" data-option-id="1" data-value-id="10">Classic</button>
    <button type="button" class="wpe-swatch" role="radio" aria-checked="false" tabindex="-1" data-option-id="1" data-value-id="11">Women's</button>
  </div>
  <div class="wpe-swatches" role="radiogroup" aria-label="Size" data-option-id="2">
    <button type="button" class="wpe-swatch" role="radio" aria-checked="false" tabindex="0" data-option-id="2" data-value-id="20">S</button>
    <button type="button" class="wpe-swatch" role="radio" aria-checked="false" tabindex="-1" data-option-id="2" data-value-id="21">5XL</button>
  </div>
  <select name="attribute_size" data-option-id="2"><option value="">Choose</option><option value="20">S</option><option value="21">5XL</option></select>
  <input type="hidden" name="variation_id" value="">
  <button type="submit" class="single_add_to_cart_button disabled" disabled>Add to cart</button>
</form></div>
<script>window.wpeSelectorData = {productId: 1, resolveEndpoint: 'http://wpe.test/resolve', nonce: 'n', productRevision: '1', defaultSelection: {},
  options: [{id: 1, name: 'Style', values: [{id: 10, label: 'Classic'}, {id: 11, label: "Women's"}]}, {id: 2, name: 'Size', values: [{id: 20, label: 'S'}, {id: 21, label: '5XL'}]}]};</script>
<script src="http://wpe.test/selector.js"></script>
</body></html>`;

// Combinations that exist: Classic+S, Classic+5XL, Women's+S.
const VARIANTS = { '10:20': 1001, '10:21': 1002, '11:20': 1003 };

function reconcile(selection) {
  const out = {};
  if (selection['1']) out['1'] = String(selection['1']);
  if (selection['2']) {
    const keep = !out['1'] || VARIANTS[`${out['1']}:${selection['2']}`];
    out['2'] = keep ? String(selection['2']) : '20';
  }
  return out;
}

async function setup(page) {
  const requests = [];
  await page.route('http://wpe.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (route.request().method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': 'POST' } });
    }
    if (url.pathname === '/') return route.fulfill({ contentType: 'text/html', body: HTML });
    if (url.pathname === '/selector.js') return route.fulfill({ contentType: 'text/javascript', body: SELECTOR_JS });
    if (url.pathname === '/resolve') {
      const body = JSON.parse(route.request().postData() || '{}');
      requests.push(body.selection);
      const selection = reconcile(body.selection || {});
      const variantId = VARIANTS[`${selection['1']}:${selection['2']}`];
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' },
        body: JSON.stringify({
          state: variantId ? 'exact' : 'partial',
          request_sequence: body.request_sequence,
          product_revision: '1',
          allowed_values: { 1: ['10', '11'], 2: selection['1'] === '11' ? ['20'] : ['20', '21'] },
          selection,
          variant: variantId ? { id: variantId, display_price: '$1.00', purchasable: true, available: true } : null,
        }),
      });
    }
    return route.abort();
  });
  await page.goto('http://wpe.test/');
  await expect(page.locator('.wpe-swatch')).toHaveCount(4);
  return requests;
}

test('choosing an earlier option reconciles a non-matching later option to the server selection', async ({ page }) => {
  await setup(page);
  await page.locator('.wpe-swatch[data-value-id="10"]').click();
  await page.locator('.wpe-swatch[data-value-id="21"]').click();
  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1002');

  await page.locator('.wpe-swatch[data-value-id="11"]').click();

  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1003');
  await expect(page.locator('.wpe-swatch[data-value-id="11"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.locator('.wpe-swatch[data-value-id="20"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.locator('.wpe-swatch[data-value-id="21"]')).toHaveAttribute('aria-checked', 'false');
  await expect(page.locator('select[name="attribute_size"]')).toHaveValue('20');
  await expect(page.locator('.wpe-sr-status')).toContainText('Size changed to S');
});

test('a later option that still matches is kept and nothing is announced as changed', async ({ page }) => {
  const requests = await setup(page);
  await page.locator('.wpe-swatch[data-value-id="11"]').click();
  await page.locator('.wpe-swatch[data-value-id="20"]').click();
  await page.locator('.wpe-swatch[data-value-id="10"]').click();

  await expect(page.locator('input[name="variation_id"]')).toHaveValue('1001');
  await expect(page.locator('.wpe-swatch[data-value-id="20"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.locator('.wpe-sr-status')).not.toContainText('changed to');
  // The follow-up request carries the adopted selection, not the stale one.
  expect(requests.at(-1)).toEqual({ 1: '10', 2: '20' });
});
