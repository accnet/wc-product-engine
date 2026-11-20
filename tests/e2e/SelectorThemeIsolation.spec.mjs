// P026.3 — Storefront selector hostile-theme isolation & scoped tokens in a real browser (offline).
//
// Injects an aggressive WooCommerce/theme stylesheet (uppercase buttons, gradient fills, thick
// borders, oversized padding, image borders/margins) alongside assets/variant-selector/selector.css
// and verifies that swatches, color circles, image thumbnails, disabled states, labels, and the
// clear/reset button stay visually isolated without relying on routine !important overrides.
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const CSS_PATH = path.join(root, 'assets/variant-selector/selector.css');
const CSS = fs.readFileSync(CSS_PATH, 'utf8');

const HOSTILE_THEME_CSS = `
  button {
    text-transform: uppercase;
    letter-spacing: 0.25em;
    padding: 24px 36px;
    border-radius: 999px;
    background: #6b21a8;
    background-image: linear-gradient(90deg, #ff0000, #0000ff);
    color: #fef08a;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
    margin: 12px;
    font-weight: 800;
  }
  .single-product .variations_form button {
    background: #7c3aed;
    color: #ffffff;
    border: 4px solid #facc15;
    padding: 20px;
    border-radius: 0;
    text-transform: uppercase;
  }
  .single-product img {
    border: 6px solid #ef4444;
    padding: 8px;
    margin: 10px;
  }
  label {
    text-transform: uppercase;
    letter-spacing: 0.2em;
  }
`;

const THEMES = {
  light: { bg: '#ffffff', fg: '#333333' },
  dark: { bg: '#121212', fg: '#e6e6e6' },
};

const DATA_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

const buildHtml = ({ bg, fg }) => `<!doctype html>
<html>
<head>
  <style>
    body { margin: 24px; background: ${bg}; color: ${fg}; font: 16px/1.5 sans-serif; }
    ${HOSTILE_THEME_CSS}
  </style>
  <style>${CSS}</style>
</head>
<body class="single-product woocommerce">
  <form class="variations_form cart">
    <div class="wpe-variant-selector">
      <div class="wpe-option-group">
        <label class="wpe-option-label" id="lbl-size">Size <span class="wpe-selected-value-hint">M</span></label>
        <div class="wpe-swatches wpe-swatches-button" id="grp-btn" role="radiogroup" aria-label="Size">
          <button type="button" class="wpe-swatch wpe-swatch-button" id="sw-btn" role="radio" aria-checked="false"><span class="wpe-swatch-label">S</span></button>
          <button type="button" class="wpe-swatch wpe-swatch-button is-selected" id="sw-btn-sel" role="radio" aria-checked="true"><span class="wpe-swatch-label">M</span></button>
          <button type="button" class="wpe-swatch wpe-swatch-button is-disabled is-unavailable is-marked-unavailable" id="sw-marked" role="radio" aria-disabled="true" disabled><span class="wpe-swatch-label">L</span></button>
          <button type="button" class="wpe-swatch wpe-swatch-button is-disabled is-unavailable is-disabled-standard" id="sw-std" role="radio" aria-disabled="true" disabled><span class="wpe-swatch-label">XL</span></button>
        </div>
      </div>

      <div class="wpe-option-group">
        <label class="wpe-option-label">Color</label>
        <div class="wpe-swatches wpe-swatches-color" role="radiogroup" aria-label="Color">
          <button type="button" class="wpe-swatch wpe-swatch-color" id="sw-color" role="radio" aria-checked="false">
            <span class="wpe-swatch-color-bubble" style="background-color:#cc0000;"></span>
            <span class="wpe-swatch-label">Red</span>
          </button>
          <button type="button" class="wpe-swatch wpe-swatch-color wpe-swatch-color-fallback" id="sw-color-fb" role="radio" aria-checked="false">
            <span class="wpe-swatch-label">Heather</span>
          </button>
        </div>
      </div>

      <div class="wpe-option-group">
        <label class="wpe-option-label">Style</label>
        <div class="wpe-swatches wpe-swatches-image" role="radiogroup" aria-label="Style">
          <button type="button" class="wpe-swatch wpe-swatch-image" id="sw-img-btn" role="radio" aria-checked="false">
            <span class="wpe-swatch-image-wrap"><img class="wpe-swatch-image-thumb" id="sw-img" src="${DATA_PNG}" alt="Classic" /></span>
            <span class="wpe-swatch-image-label">Classic</span>
          </button>
        </div>
      </div>

      <button type="button" class="wpe-reset-selection" id="reset-btn">Clear</button>
    </div>
  </form>
</body>
</html>`;

test('P026.3 selector.css meets !important budget and dead-CSS removal rules', () => {
  const importantCount = (CSS.match(/!important/g) || []).length;
  // 1 single-price hide + 1 competing theme hide + 9 .wpe-sr-only/.wpe-select-hidden utility rules
  expect(importantCount).toBeLessThanOrEqual(11);
  expect(CSS).not.toContain('.wpe-loading-indicator');
  expect(CSS).not.toContain('.wpe-swatch-image-media');
  expect(CSS).toContain('--wpe-sel-border');
});

for (const [themeName, theme] of Object.entries(THEMES)) {
  test(`P026.3 hostile-theme isolation on ${themeName} theme`, async ({ page }) => {
    await page.setContent(buildHtml(theme));

    const m = await page.evaluate(() => {
      const cs = (sel, pseudo) => getComputedStyle(document.querySelector(sel), pseudo);
      const btn = cs('#sw-btn');
      const btnSel = cs('#sw-btn-sel');
      const marked = cs('#sw-marked');
      const markedAfter = cs('#sw-marked', '::after');
      const std = cs('#sw-std');
      const stdAfter = cs('#sw-std', '::after');
      const color = cs('#sw-color');
      const colorRect = document.querySelector('#sw-color').getBoundingClientRect();
      const colorLabel = cs('#sw-color .wpe-swatch-label');
      const colorFb = cs('#sw-color-fb');
      const colorFbLabel = cs('#sw-color-fb .wpe-swatch-label');
      const img = cs('#sw-img');
      const reset = cs('#reset-btn');
      const label = cs('#lbl-size');
      const grp = cs('#grp-btn');

      return {
        tokenBorder: grp.getPropertyValue('--wpe-sel-border').trim().toLowerCase(),
        btn: {
          textTransform: btn.textTransform,
          letterSpacing: btn.letterSpacing,
          bg: btn.backgroundColor,
          bgImg: btn.backgroundImage,
          color: btn.color,
          borderColor: btn.borderTopColor,
          borderWidth: btn.borderTopWidth,
          radius: btn.borderRadius,
          marginTop: btn.marginTop,
          boxShadow: btn.boxShadow,
        },
        btnSel: {
          borderColor: btnSel.borderTopColor,
          bg: btnSel.backgroundColor,
          fontWeight: btnSel.fontWeight,
        },
        marked: {
          opacity: marked.opacity,
          afterContent: markedAfter.content,
        },
        std: {
          opacity: std.opacity,
          afterContent: stdAfter.content,
        },
        color: {
          radius: color.borderRadius,
          labelDisplay: colorLabel.display,
          width: Math.round(colorRect.width),
          height: Math.round(colorRect.height),
        },
        colorFb: {
          radius: colorFb.borderRadius,
          labelDisplay: colorFbLabel.display,
        },
        img: {
          borderTopWidth: img.borderTopWidth,
          paddingTop: img.paddingTop,
          marginTop: img.marginTop,
          objectFit: img.objectFit,
        },
        reset: {
          bg: reset.backgroundColor,
          bgImg: reset.backgroundImage,
          borderTopWidth: reset.borderTopWidth,
          textTransform: reset.textTransform,
          decoration: reset.textDecorationLine,
        },
        label: {
          textTransform: label.textTransform,
          letterSpacing: label.letterSpacing,
        },
      };
    });

    expect(m.tokenBorder).toBe('#8c8f94');

    expect(m.btn.textTransform).toBe('none');
    expect(m.btn.letterSpacing).toBe('normal');
    expect(m.btn.bg).toBe('rgb(255, 255, 255)');
    expect(m.btn.bgImg).toBe('none');
    expect(m.btn.color).toBe('rgb(34, 34, 34)');
    expect(m.btn.borderColor).toBe('rgb(140, 143, 148)');
    expect(m.btn.borderWidth).toBe('1px');
    expect(m.btn.radius).toBe('4px');
    expect(m.btn.marginTop).toBe('0px');
    expect(m.btn.boxShadow).toBe('none');

    expect(m.btnSel.borderColor).toBe('rgb(0, 113, 161)');
    expect(m.btnSel.bg).toBe('rgb(240, 247, 251)');
    expect(m.btnSel.fontWeight).toBe('600');

    expect(m.marked.opacity).toBe('1');
    expect(m.marked.afterContent).not.toBe('none');
    expect(m.std.opacity).toBe('1');
    expect(m.std.afterContent).toBe('none');

    expect(m.color.radius).toBe('50%');
    expect(m.color.labelDisplay).toBe('none');
    expect(Math.abs(m.color.width - m.color.height)).toBeLessThanOrEqual(1);

    expect(m.colorFb.radius).toBe('4px');
    expect(m.colorFb.labelDisplay).not.toBe('none');

    expect(m.img.borderTopWidth).toBe('0px');
    expect(m.img.paddingTop).toBe('0px');
    expect(m.img.marginTop).toBe('0px');
    expect(m.img.objectFit).toBe('cover');

    expect(m.reset.bg).toBe('rgba(0, 0, 0, 0)');
    expect(m.reset.bgImg).toBe('none');
    expect(m.reset.borderTopWidth).toBe('0px');
    expect(m.reset.textTransform).toBe('none');
    expect(m.reset.decoration).toContain('underline');

    expect(m.label.textTransform).toBe('none');
    expect(m.label.letterSpacing).toBe('normal');
  });
}
