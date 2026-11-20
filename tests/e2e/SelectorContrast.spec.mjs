// P022.4 — storefront selector contrast in a real browser (offline).
//
// Renders the swatch markup of SelectorAdapter::buildAccessibleSwatchGroupHtml with
// assets/variant-selector/selector.css on a light and a dark theme and measures computed
// colours. Covers WCAG 1.4.3 (text), 1.4.11 (control borders, unavailable mark) and
// docs/09-ui.md mark_unavailable_options (name and thumbnail stay legible).
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const CSS_PATH = path.join(root, 'assets/variant-selector/selector.css');
const CSS = fs.readFileSync(CSS_PATH, 'utf8');

const THEMES = {
  light: { bg: '#ffffff', fg: '#333333' },
  dark: { bg: '#121212', fg: '#e6e6e6' },
};

const html = ({ bg, fg }) => `<!doctype html>
<html><head><style>${CSS}</style>
<style>body { margin: 24px; background: ${bg}; color: ${fg}; font: 16px/1.5 sans-serif; }</style></head>
<body class="single-product"><form class="variations_form cart">
<div class="wpe-variant-selector">
  <div class="wpe-option-group">
    <label class="wpe-option-label">Size <span class="wpe-selected-value-hint">M</span></label>
    <div id="wpe-help-1-1" class="wpe-option-help-text">Choose your size</div>
    <div class="wpe-swatches wpe-swatches-button" role="radiogroup" aria-label="Size" aria-describedby="wpe-help-1-1">
      <button type="button" class="wpe-swatch wpe-swatch-button" id="sw-avail" role="radio" aria-checked="false" aria-disabled="false" tabindex="-1"><span class="wpe-swatch-label">S</span></button>
      <button type="button" class="wpe-swatch wpe-swatch-button is-selected" id="sw-selected" role="radio" aria-checked="true" aria-disabled="false" tabindex="0"><span class="wpe-swatch-label">M</span></button>
      <button type="button" class="wpe-swatch wpe-swatch-button is-disabled is-unavailable is-marked-unavailable" id="sw-marked" role="radio" aria-checked="false" aria-disabled="true" tabindex="-1" disabled><span class="wpe-swatch-label">L</span></button>
      <button type="button" class="wpe-swatch wpe-swatch-button is-disabled is-unavailable is-disabled-standard" id="sw-standard" role="radio" aria-checked="false" aria-disabled="true" tabindex="-1" disabled><span class="wpe-swatch-label">XL</span></button>
    </div>
  </div>
  <button type="button" class="wpe-reset-selection" id="reset">Clear</button>
</div>
</form></body></html>`;

async function measure(page) {
  return page.evaluate(() => {
    // Computed colours come as rgb()/rgba(), or as color(srgb r g b / a) for color-mix().
    const parse = (c) => {
      const s = String(c);
      const srgb = s.match(/color\(srgb ([^)]+)\)/);
      if (srgb) {
        const p = srgb[1].split(/[ /]+/).filter(Boolean).map(Number);
        return { r: p[0] * 255, g: p[1] * 255, b: p[2] * 255, a: p.length > 3 ? p[3] : 1 };
      }
      const m = s.match(/rgba?\(([^)]+)\)/);
      if (!m) throw new Error('Unparsed colour: ' + s);
      const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
    };
    const over = (t, u) => ({ r: t.r * t.a + u.r * (1 - t.a), g: t.g * t.a + u.g * (1 - t.a), b: t.b * t.a + u.b * (1 - t.a), a: 1 });
    const bgOf = (el) => {
      const layers = [];
      for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
        const c = parse(getComputedStyle(n).backgroundColor);
        if (c.a > 0) layers.push(c);
        if (c.a >= 1) break;
      }
      return layers.reverse().reduce((acc, c) => over(c, acc), { r: 255, g: 255, b: 255, a: 1 });
    };
    const lum = ({ r, g, b }) => {
      const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return Math.round(((x + 0.05) / (y + 0.05)) * 100) / 100; };
    const text = (el) => { const bg = bgOf(el); return ratio(over(parse(getComputedStyle(el).color), bg), bg); };
    const effectiveOpacity = (el) => { let o = 1; for (let n = el; n && n.nodeType === 1; n = n.parentElement) o *= Number(getComputedStyle(n).opacity); return o; };
    const $ = (s) => document.querySelector(s);
    const page = bgOf(document.body);
    const avail = $('#sw-avail');
    const marked = $('#sw-marked');
    const markedBg = bgOf(marked);
    const after = getComputedStyle(marked, '::after');
    return {
      availBorder: ratio(over(parse(getComputedStyle(avail).borderTopColor), page), page),
      markedOpacity: effectiveOpacity(marked),
      markedLabel: Math.min(text(marked.querySelector('.wpe-swatch-label')), text(marked)),
      markedLine: after.content === 'none' ? 0 : ratio(over(parse(after.backgroundColor), markedBg), markedBg),
      standardOpacity: effectiveOpacity($('#sw-standard')),
      standardLabel: text($('#sw-standard')),
      standardHasMark: getComputedStyle($('#sw-standard'), '::after').content !== 'none',
      help: text($('.wpe-option-help-text')),
      hint: text($('.wpe-selected-value-hint')),
      reset: text($('#reset')),
    };
  });
}

for (const [name, theme] of Object.entries(THEMES)) {
  test(`P022.4 selector contrast on a ${name} theme`, async ({ page }) => {
    await page.setContent(html(theme));
    const m = await measure(page);
    console.log(name, JSON.stringify(m));

    // Muted text follows the theme text colour and stays readable on light and dark themes.
    expect(m.help, 'help text').toBeGreaterThanOrEqual(4.5);
    expect(m.hint, 'selected value hint').toBeGreaterThanOrEqual(4.5);
    expect(m.reset, 'reset link').toBeGreaterThanOrEqual(4.5);

    if (name === 'light') {
      // Swatch buttons draw their own white surface; the boundary must stand out from the page.
      expect(m.availBorder, 'available swatch border').toBeGreaterThanOrEqual(3);
      // Marked unavailable: no fading, legible name, visible cross-out mark.
      expect(m.markedOpacity, 'marked unavailable opacity').toBe(1);
      expect(m.markedLabel, 'marked unavailable label').toBeGreaterThanOrEqual(4.5);
      expect(m.markedLine, 'unavailable mark').toBeGreaterThanOrEqual(3);
      // Standard disabled: same legible label, no decoration.
      expect(m.standardOpacity, 'standard disabled opacity').toBe(1);
      expect(m.standardLabel, 'standard disabled label').toBeGreaterThanOrEqual(4.5);
      expect(m.standardHasMark, 'standard disabled has no mark').toBe(false);
    }
  });
}
