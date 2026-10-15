/**
 * WC Product Engine — Variant Selector Client
 *
 * Implements accessible options/swatches, roving tabindex, asynchronous
 * variation resolution with AbortController, out-of-order sequence cancellation,
 * instance-scoped DOM synchronization, Single Price Display, Show Selected Value,
 * dynamic Image Swatch thumbnails, and Mark Unavailable Options.
 */
(function (window, document) {
  'use strict';

  class WpeVariantSelector {
    /**
     * @param {HTMLElement} formElement - The .variations_form or container element
     * @param {Object} config - Configuration object
     */
    constructor(formElement, config = {}) {
      this.form = formElement;

      const embeddedConfig = this.readEmbeddedInstanceConfig();
      const mergedConfig = Object.assign({}, config || {}, embeddedConfig || {});

      this.config = Object.assign(
        {
          productId: 0,
          resolveEndpoint: '',
          wcAjaxUrl: '',
          nonce: '',
          productRevision: '1',
          flags: {},
          options: [],
          defaultSelection: {},
          lowestPriceHtml: '',
          initialResolution: null,
          i18n: {
            unavailable: 'This combination is unavailable',
            ambiguous: 'Selection cannot be resolved',
            loading: 'Checking availability...',
            reset: 'Clear selection',
            inStock: 'In stock',
            outOfStock: 'Out of stock',
            selectOption: 'Choose an option'
          }
        },
        mergedConfig
      );

      this.flags = Object.assign(
        {
          variant_selector_ui: true,
          single_price_display: false,
          show_selected_value: false,
          show_help_text: false,
          button_selector: false,
          color_swatches: false,
          image_swatches: false,
          mark_unavailable_options: false
        },
        this.config.flags || {}
      );

      this.instanceRoot =
        (this.form &&
          typeof this.form.closest === 'function' &&
          this.form.closest('.wpe-product-instance, .product')) ||
        this.form;

      this.currentSelection = Object.assign({}, this.config.defaultSelection || {});
      this.allowedValues = {};
      this.allowedDetails = {};
      this.valueThumbnails = {};
      this.lowestPriceHtml = String(this.config.lowestPriceHtml || '');
      this.requestSequence = 0;
      this.abortController = null;
      this.currentState = 'initial';
      this.productRevision = String(this.config.productRevision || '1');

      // Parent gallery and price cache for restoration
      this.parentImageState = null;
      this.parentPriceHtml = null;
      this.resolutionCache = new Map();

      this.init();
    }

    getSelectionCacheKey(selection) {
      const sel = selection && typeof selection === 'object' ? selection : {};
      const keys = Object.keys(sel)
        .filter((k) => sel[k] !== null && sel[k] !== undefined && sel[k] !== '')
        .sort();
      return keys.map((k) => `${k}:${String(sel[k])}`).join('|');
    }

    readEmbeddedInstanceConfig() {
      if (!this.form || typeof this.form.querySelector !== 'function') {
        return null;
      }
      const scriptEl = this.form.querySelector('script.wpe-selector-instance-config');
      if (!scriptEl || !scriptEl.textContent) {
        return null;
      }
      try {
        return JSON.parse(scriptEl.textContent);
      } catch (e) {
        return null;
      }
    }

    init() {
      if (!this.form) {
        return;
      }

      if (this.flags.single_price_display) {
        if (this.form.classList && typeof this.form.classList.add === 'function') {
          this.form.classList.add('wpe-single-price-active');
        }
        if (
          this.instanceRoot &&
          this.instanceRoot.classList &&
          typeof this.instanceRoot.classList.add === 'function'
        ) {
          this.instanceRoot.classList.add('wpe-single-price-active');
        }
      }

      this.cacheParentState();
      this.bindEvents();
      this.createLiveRegion();
      this.updateOptionLabels();

      // Apply initial server resolution metadata if provided
      if (this.config.initialResolution && typeof this.config.initialResolution === 'object') {
        const initRes = this.config.initialResolution;
        if (initRes.lowest_price_html) {
          this.lowestPriceHtml = String(initRes.lowest_price_html);
        }
        if (initRes.allowed_values) {
          this.allowedValues = initRes.allowed_values;
        }
        if (initRes.allowed_details) {
          this.allowedDetails = initRes.allowed_details;
        }
        if (initRes.value_thumbnails) {
          this.valueThumbnails = initRes.value_thumbnails;
          this.updateValueThumbnails(this.valueThumbnails);
        }
        this.updateAllowedSwatches();
        const initKey = this.getSelectionCacheKey(this.currentSelection);
        this.resolutionCache.set(
          initKey,
          Object.assign({ product_revision: this.productRevision }, initRes)
        );
      }

      // If initial defaults are present, run initial resolution
      if (Object.keys(this.currentSelection).length > 0) {
        this.syncControlsWithSelection();
        this.updateOptionLabels();
        this.resolve();
      } else {
        this.updateUiState('initial');
      }
    }

    findGallery() {
      if (this.instanceRoot && typeof this.instanceRoot.querySelector === 'function') {
        const scopedGallery = this.instanceRoot.querySelector('.woocommerce-product-gallery, .product-gallery');
        if (scopedGallery) {
          return scopedGallery;
        }
      }
      if (typeof document !== 'undefined' && typeof document.querySelector === 'function') {
        return document.querySelector('.woocommerce-product-gallery, .product-gallery');
      }
      return null;
    }

    findFirstThumbnailImage(gallery) {
      if (gallery && typeof gallery.querySelector === 'function') {
        const navThumb = gallery.querySelector(
          '.flex-control-thumbs li:first-child img, .flex-control-nav li:first-child img'
        );
        if (navThumb) {
          return navThumb;
        }
      }
      if (this.instanceRoot && typeof this.instanceRoot.querySelector === 'function') {
        const rootThumb = this.instanceRoot.querySelector(
          '.product-thumbnails .first img, .product-thumbnails .col:first-child img, .flex-control-thumbs li:first-child img'
        );
        if (rootThumb) {
          return rootThumb;
        }
      }
      if (typeof document !== 'undefined' && typeof document.querySelector === 'function') {
        return document.querySelector(
          '.product-thumbnails .first img, .flex-control-thumbs li:first-child img'
        );
      }
      return null;
    }

    cacheParentState() {
      const gallery = this.findGallery();
      if (gallery && typeof gallery.querySelector === 'function') {
        const firstSlide = gallery.querySelector(
          '.woocommerce-product-gallery__image, .slide.first, .slide'
        );
        const mainImg =
          (firstSlide &&
            typeof firstSlide.querySelector === 'function' &&
            firstSlide.querySelector('img.wp-post-image, img:not(.zoomImg)')) ||
          gallery.querySelector(
            '.woocommerce-product-gallery__image img:not(.zoomImg), .wp-post-image, .slide.first img:not(.zoomImg), .slide img:not(.zoomImg)'
          );
        const firstThumbImg = this.findFirstThumbnailImage(gallery);
        if (mainImg) {
          const parentLink = typeof mainImg.closest === 'function' ? mainImg.closest('a') : null;
          this.parentImageState = {
            src: mainImg.getAttribute('src') || '',
            srcset: mainImg.getAttribute('srcset') || '',
            sizes: mainImg.getAttribute('sizes') || '',
            alt: mainImg.getAttribute('alt') || '',
            title: mainImg.getAttribute('title') || '',
            dataSrc: mainImg.getAttribute('data-src') || '',
            fullSrc: mainImg.getAttribute('data-large_image') || mainImg.getAttribute('src') || '',
            largeW: mainImg.getAttribute('data-large_image_width') || '',
            largeH: mainImg.getAttribute('data-large_image_height') || '',
            linkHref: parentLink ? parentLink.getAttribute('href') || '' : '',
            slideThumb: firstSlide ? firstSlide.getAttribute('data-thumb') || '' : '',
            thumbSrc: firstThumbImg ? firstThumbImg.getAttribute('src') || '' : '',
            thumbSrcset: firstThumbImg ? firstThumbImg.getAttribute('srcset') || '' : ''
          };
        }
      }

      // Cache price HTML
      const priceContainer = this.findPriceContainer();
      if (priceContainer) {
        this.parentPriceHtml = priceContainer.innerHTML;
      }
    }

    findPriceContainer() {
      if (this.instanceRoot && typeof this.instanceRoot.querySelector === 'function') {
        const singleTarget = this.instanceRoot.querySelector('.wpe-single-price-target');
        if (singleTarget) {
          return singleTarget;
        }
        const scopedPrice = this.instanceRoot.querySelector('.summary .price, p.price, span.price, .price');
        if (scopedPrice) {
          return scopedPrice;
        }
      }

      const docPrice =
        this.instanceRoot === this.form &&
        typeof document !== 'undefined' &&
        typeof document.querySelector === 'function'
          ? document.querySelector('.single-product div.product .price')
          : null;

      return (
        docPrice ||
        this.form.querySelector('.woocommerce-variation-price') ||
        this.form.querySelector('.single_variation .price')
      );
    }

    createLiveRegion() {
      let liveRegion = this.form.querySelector('.wpe-sr-status');
      if (!liveRegion && typeof document !== 'undefined' && typeof document.createElement === 'function') {
        liveRegion = document.createElement('div');
        liveRegion.className = 'wpe-sr-status wpe-sr-only';
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        this.form.appendChild(liveRegion);
      }
      this.liveRegion = liveRegion;
    }

    announce(message) {
      if (this.liveRegion && message) {
        this.liveRegion.textContent = message;
      }
    }

    detachWcVariationForm() {
      if (this.form && typeof this.form.closest === 'function') {
        const liveContainer = this.form.closest('.starterkit-product-form--live');
        if (liveContainer && typeof liveContainer.setAttribute === 'function') {
          liveContainer.setAttribute('data-selector-type', 'wpe');
          liveContainer.setAttribute('data-preselect-first', 'false');
        }
      }

      if (this.form && typeof this.form.querySelectorAll === 'function') {
        const competingOptions = this.form.querySelectorAll('.starterkit-live-options');
        if (competingOptions && typeof competingOptions.forEach === 'function') {
          competingOptions.forEach((el) => {
            if (el && typeof el.remove === 'function') {
              el.remove();
            }
          });
        }

        const hiddenSelects = this.form.querySelectorAll('select.wpe-select-hidden');
        if (hiddenSelects && typeof hiddenSelects.forEach === 'function') {
          hiddenSelects.forEach((sel) => {
            if (sel && typeof sel.setAttribute === 'function') {
              sel.setAttribute('tabindex', '-1');
              sel.setAttribute('aria-hidden', 'true');
            }
          });
        }
      }

      if (typeof window !== 'undefined' && typeof window.jQuery === 'function') {
        try {
          const $form = window.jQuery(this.form);
          $form.off(
            '.wc-variation-form change check_variations update_variation_values found_variation reset_data hide_variation show_variation click reload_product_variations'
          );
          $form.find('.variations select, select.wpe-select').off('.wc-variation-form change focusin');
          $form.find('.reset_variations').off('.wc-variation-form click');
          $form.find('.single_add_to_cart_button').off('.wc-variation-form click');
          if (typeof $form.removeData === 'function') {
            $form.removeData('wc_variation_form');
          }
          if (typeof document !== 'undefined' && document.body) {
            window.jQuery(document.body).off('.starterkitProductContent');
          }
        } catch (e) {
          // Ignore jQuery detach errors in non-DOM test mocks
        }
      }
    }

    bindEvents() {
      // Detach competing WooCommerce core variation script handlers on this form if present
      this.detachWcVariationForm();
      if (typeof window !== 'undefined' && typeof window.jQuery === 'function') {
        try {
          window.jQuery(() => this.detachWcVariationForm());
        } catch (e) {
          // Ignore jQuery ready errors in non-DOM test mocks
        }
      }
      if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
        window.addEventListener('load', () => this.detachWcVariationForm());
      }
      if (typeof setTimeout === 'function') {
        setTimeout(() => this.detachWcVariationForm(), 0);
      }

      // Swatch click and keyboard navigation
      const swatches = this.form.querySelectorAll('.wpe-swatch');
      swatches.forEach((swatch) => {
        swatch.addEventListener('click', (e) => {
          e.preventDefault();
          const optionId = swatch.getAttribute('data-option-id');
          const valueId = swatch.getAttribute('data-value-id');
          if (swatch.getAttribute('aria-disabled') === 'true' || swatch.disabled) {
            return;
          }
          // Toggle off if already selected, or select new value
          this.onSwatchSelect(optionId, valueId);
        });

        swatch.addEventListener('keydown', (e) => {
          this.handleKeyboardNav(e, swatch);
        });
      });

      // Baseline select dropdowns
      const selects = this.form.querySelectorAll('select.wpe-select, .variations select');
      selects.forEach((select) => {
        select.addEventListener('change', () => {
          const optionId = select.getAttribute('data-option-id') || select.getAttribute('name');
          const rawVal = select.value;
          const normalizedVal = this.normalizeOptionValueId(optionId, rawVal);
          this.onSwatchSelect(optionId, normalizedVal, true);
        });
      });

      // Reset selection button
      const resetBtn = this.form.querySelector('.wpe-reset-selection, .reset_variations');
      if (resetBtn) {
        resetBtn.addEventListener('click', (e) => {
          e.preventDefault();
          this.resetSelection();
        });
      }
    }

    normalizeOptionValueId(optionId, rawVal) {
      if (!rawVal || rawVal === '') {
        return '';
      }
      const rawStr = String(rawVal);
      const rawLower = rawStr.toLowerCase();
      const options = Array.isArray(this.config.options) ? this.config.options : [];
      const opt = options.find(
        (o) =>
          String(o.id) === String(optionId) ||
          o.key === String(optionId) ||
          'attribute_' + o.key === String(optionId) ||
          'attribute_pa_' + o.key === String(optionId)
      );
      if (opt && Array.isArray(opt.values)) {
        const matched = opt.values.find(
          (v) =>
            String(v.id) === rawStr ||
            String(v.key) === rawStr ||
            (v.label !== undefined && String(v.label) === rawStr)
        );
        if (matched) {
          return String(matched.id);
        }
        const matchedCi = opt.values.find(
          (v) =>
            (v.key !== undefined && String(v.key).toLowerCase() === rawLower) ||
            (v.label !== undefined && String(v.label).toLowerCase() === rawLower)
        );
        if (matchedCi) {
          return String(matchedCi.id);
        }
      }
      return rawStr;
    }

    handleKeyboardNav(event, swatch) {
      const key = event.key;
      const group = typeof swatch.closest === 'function' ? swatch.closest('.wpe-swatches') : null;
      if (!group) return;

      const swatchesInGroup = Array.from(group.querySelectorAll('.wpe-swatch:not([aria-disabled="true"])'));
      const currentIndex = swatchesInGroup.indexOf(swatch);
      if (currentIndex === -1) return;

      let targetIndex = -1;

      if (key === 'ArrowRight' || key === 'ArrowDown') {
        event.preventDefault();
        targetIndex = (currentIndex + 1) % swatchesInGroup.length;
      } else if (key === 'ArrowLeft' || key === 'ArrowUp') {
        event.preventDefault();
        targetIndex = (currentIndex - 1 + swatchesInGroup.length) % swatchesInGroup.length;
      } else if (key === ' ' || key === 'Enter') {
        event.preventDefault();
        if (swatch.getAttribute('aria-disabled') === 'true' || swatch.disabled) {
          return;
        }
        const optionId = swatch.getAttribute('data-option-id');
        const valueId = swatch.getAttribute('data-value-id');
        this.onSwatchSelect(optionId, valueId);
        return;
      }

      if (targetIndex >= 0 && swatchesInGroup[targetIndex]) {
        const targetSwatch = swatchesInGroup[targetIndex];
        // Update roving tabindex
        swatchesInGroup.forEach((s) => s.setAttribute('tabindex', '-1'));
        targetSwatch.setAttribute('tabindex', '0');
        targetSwatch.focus();
      }
    }

    onSwatchSelect(optionId, valueId, fromSelect = false) {
      const isToggleOff =
        !fromSelect &&
        valueId &&
        String(this.currentSelection[optionId] || '') === String(valueId);
      const nextValueId = isToggleOff ? '' : valueId;

      if (!nextValueId || nextValueId === '') {
        delete this.currentSelection[optionId];
      } else {
        this.currentSelection[optionId] = String(nextValueId);
      }

      if (isToggleOff && Object.keys(this.currentSelection).length === 0) {
        const hasDefault = Object.keys(this.config.defaultSelection || {}).length > 0;
        if (!hasDefault) {
          this.resetSelection();
          return;
        }
      }

      this.updateRovingTabindex(optionId, nextValueId);
      if (!fromSelect) {
        this.syncSelectForOption(optionId, nextValueId);
      }
      this.updateOptionLabels();
      this.resolve();
    }

    syncSelectForOption(optionId, valueId) {
      if (!this.form || typeof this.form.querySelector !== 'function') return;
      const select = this.form.querySelector(`select[data-option-id="${optionId}"]`);
      if (!select) return;

      if (!valueId || valueId === '') {
        select.value = '';
        return;
      }

      // Match option in <select> by valueId, valueKey, or display label
      let targetOptionVal = String(valueId);
      const options = Array.isArray(this.config.options) ? this.config.options : [];
      const optCfg = options.find((o) => String(o.id) === String(optionId));
      const valCfg =
        optCfg && Array.isArray(optCfg.values)
          ? optCfg.values.find((v) => String(v.id) === String(valueId))
          : null;

      if (typeof select.querySelectorAll === 'function') {
        const domOpts = select.querySelectorAll('option');
        const valKeyStr = valCfg && valCfg.key !== undefined ? String(valCfg.key) : '';
        const valLabelStr = valCfg && valCfg.label !== undefined ? String(valCfg.label) : '';
        domOpts.forEach((domOpt) => {
          const optVal = String(domOpt.value || '');
          if (
            optVal === String(valueId) ||
            (valKeyStr !== '' && (optVal === valKeyStr || optVal.toLowerCase() === valKeyStr.toLowerCase())) ||
            (valLabelStr !== '' && (optVal === valLabelStr || optVal.toLowerCase() === valLabelStr.toLowerCase()))
          ) {
            targetOptionVal = domOpt.value;
          }
        });
      }
      select.value = targetOptionVal;
    }

    syncControlsWithSelection() {
      const options = Array.isArray(this.config.options) ? this.config.options : [];
      options.forEach((opt) => {
        const optId = String(opt.id);
        const valId = this.currentSelection[optId] || '';
        this.updateRovingTabindex(optId, valId);
        this.syncSelectForOption(optId, valId);
      });
    }

    updateRovingTabindex(optionId, valueId) {
      const group = this.form.querySelector(`.wpe-swatches[data-option-id="${optionId}"]`);
      if (!group) return;

      const swatches = group.querySelectorAll('.wpe-swatch');
      let anySelected = false;
      swatches.forEach((s) => {
        const isSelected = Boolean(valueId) && s.getAttribute('data-value-id') === String(valueId);
        if (isSelected) {
          anySelected = true;
        }
        s.setAttribute('aria-checked', isSelected ? 'true' : 'false');
        s.setAttribute('tabindex', isSelected ? '0' : '-1');
        if (isSelected) {
          s.classList.add('is-selected');
        } else {
          s.classList.remove('is-selected');
        }
      });

      if (!anySelected && swatches.length > 0) {
        const firstEnabled =
          Array.from(swatches).find((s) => s.getAttribute('aria-disabled') !== 'true') || swatches[0];
        if (firstEnabled) {
          firstEnabled.setAttribute('tabindex', '0');
        }
      }
    }

    /**
     * Update attribute labels when Show Selected Value is enabled (§3).
     * Uses the display label of the value, never the internal ID or slug.
     */
    updateOptionLabels() {
      if (!this.form || typeof this.form.querySelectorAll !== 'function') {
        return;
      }

      const options = Array.isArray(this.config.options) ? this.config.options : [];
      const optionMap = {};
      options.forEach((o) => {
        optionMap[String(o.id)] = o;
      });

      const controls = this.form.querySelectorAll(
        '.wpe-swatches[data-option-id], select.wpe-select[data-option-id]'
      );
      const seenOptionIds = new Set();

      controls.forEach((ctrl) => {
        const optionId = ctrl.getAttribute('data-option-id');
        if (!optionId || seenOptionIds.has(optionId)) {
          return;
        }
        seenOptionIds.add(optionId);

        const labelEl = this.findLabelElementForOption(optionId, ctrl);
        if (!labelEl) {
          return;
        }

        const optCfg = optionMap[String(optionId)];
        let baseLabel = labelEl.getAttribute('data-wpe-base-label');
        if (!baseLabel) {
          baseLabel =
            (optCfg && optCfg.name) ||
            ctrl.getAttribute('data-option-name') ||
            (labelEl.textContent || '').replace(/:\s*.*$/, '').trim();
          labelEl.setAttribute('data-wpe-base-label', baseLabel);
        }

        if (!this.flags.show_selected_value) {
          labelEl.textContent = baseLabel;
          labelEl.removeAttribute('data-selected-value-label');
          return;
        }

        const selectedVal = this.currentSelection[optionId];
        const valueLabel = selectedVal ? this.resolveValueDisplayLabel(optionId, selectedVal, optCfg) : '';

        if (valueLabel) {
          labelEl.textContent = `${baseLabel}: ${valueLabel}`;
          labelEl.setAttribute('data-selected-value-label', valueLabel);
        } else {
          labelEl.textContent = baseLabel;
          labelEl.removeAttribute('data-selected-value-label');
        }
      });
    }

    findLabelElementForOption(optionId, controlEl) {
      if (!this.form || typeof this.form.querySelector !== 'function') {
        return null;
      }

      const explicit = this.form.querySelector(`label[data-option-id="${optionId}"], .wpe-option-label[data-option-id="${optionId}"]`);
      if (explicit) {
        return explicit;
      }

      if (controlEl && typeof controlEl.closest === 'function') {
        const row = controlEl.closest('tr, .wpe-option-group, .variation-row');
        if (row && typeof row.querySelector === 'function') {
          const rowLabel = row.querySelector('th.label label, .label label, .wpe-option-label, label');
          if (rowLabel) {
            return rowLabel;
          }
        }
      }

      const selectEl = this.form.querySelector(`select[data-option-id="${optionId}"]`);
      if (selectEl) {
        const selectId = selectEl.getAttribute('id') || selectEl.getAttribute('name');
        if (selectId) {
          const byFor = this.form.querySelector(`label[for="${selectId}"]`);
          if (byFor) {
            return byFor;
          }
        }
      }

      return null;
    }

    resolveValueDisplayLabel(optionId, selectedVal, optCfg) {
      if (!selectedVal) {
        return '';
      }

      const selStr = String(selectedVal);
      const selLower = selStr.toLowerCase();

      if (optCfg && Array.isArray(optCfg.values)) {
        const matched = optCfg.values.find(
          (v) =>
            String(v.id) === selStr ||
            String(v.key) === selStr ||
            (v.label !== undefined && String(v.label) === selStr) ||
            (v.key !== undefined && String(v.key).toLowerCase() === selLower) ||
            (v.label !== undefined && String(v.label).toLowerCase() === selLower)
        );
        if (matched && matched.label) {
          return String(matched.label);
        }
      }

      const swatch = this.form.querySelector(
        `.wpe-swatch[data-option-id="${optionId}"][data-value-id="${selectedVal}"], .wpe-swatch[data-option-id="${optionId}"][data-value-key="${selectedVal}"]`
      );
      if (swatch) {
        const attrLabel = swatch.getAttribute('data-value-label') || swatch.getAttribute('aria-label');
        if (attrLabel) {
          return attrLabel.trim();
        }
        if (swatch.textContent) {
          return swatch.textContent.trim();
        }
      }

      const select = this.form.querySelector(`select[data-option-id="${optionId}"]`);
      if (select && typeof select.querySelectorAll === 'function') {
        const opts = select.querySelectorAll('option');
        for (let i = 0; i < opts.length; i++) {
          const optNorm = this.normalizeOptionValueId(optionId, opts[i].value);
          if ((opts[i].value === selStr || optNorm === selStr) && opts[i].textContent) {
            return opts[i].textContent.trim();
          }
        }
      }

      return '';
    }

    /**
     * Dynamically update Image Swatch thumbnails based on candidate resolution (§7).
     */
    updateValueThumbnails(valueThumbnails) {
      if (!valueThumbnails || typeof valueThumbnails !== 'object') {
        return;
      }
      if (!this.form || typeof this.form.querySelectorAll !== 'function') {
        return;
      }

      Object.keys(valueThumbnails).forEach((optionId) => {
        const optThumbs = valueThumbnails[optionId];
        if (!optThumbs || typeof optThumbs !== 'object') {
          return;
        }
        Object.keys(optThumbs).forEach((valueId) => {
          const thumb = optThumbs[valueId];
          if (!thumb || !thumb.src) {
            return;
          }
          const swatch = this.form.querySelector(
            `.wpe-swatch[data-option-id="${optionId}"][data-value-id="${valueId}"]`
          );
          if (!swatch || typeof swatch.querySelector !== 'function') {
            return;
          }
          const img = swatch.querySelector('img.wpe-swatch-image-thumb, img');
          if (img) {
            if (typeof img.getAttribute !== 'function' || img.getAttribute('src') !== thumb.src) {
              if (typeof img.removeAttribute === 'function') {
                img.removeAttribute('loading');
              }
              img.setAttribute('src', thumb.src);
            }
            if (thumb.alt) {
              img.setAttribute('alt', thumb.alt);
            }
            if (thumb.source) {
              img.setAttribute('data-thumb-source', thumb.source);
            }
          }
        });
      });
    }

    /**
     * Asynchronously resolve selection with request sequence tracking,
     * in-flight cancellation via AbortController, and out-of-order rejection.
     */
    resolve() {
      // 1. Increment sequence and capture current sequence
      this.requestSequence++;
      const currentSeq = this.requestSequence;

      // 2. Abort any previous pending in-flight request (R107, AC-T27-01)
      if (this.abortController) {
        try {
          this.abortController.abort();
        } catch (e) {
          // Ignore abort errors
        }
      }

      const selectionKey = this.getSelectionCacheKey(this.currentSelection);
      if (this.resolutionCache && this.resolutionCache.has(selectionKey)) {
        const cachedData = this.resolutionCache.get(selectionKey);
        if (
          !cachedData.product_revision ||
          String(cachedData.product_revision) === String(this.productRevision)
        ) {
          this.handleResponse(cachedData);
          return;
        }
        this.resolutionCache.delete(selectionKey);
      }

      this.abortController = new AbortController();
      const signal = this.abortController.signal;

      // 3. Transition to resolving state
      this.form.setAttribute('aria-busy', 'true');
      this.currentState = 'resolving';

      const payload = {
        selection: this.currentSelection,
        quantity: '1',
        expected_revision: this.productRevision,
        request_sequence: currentSeq
      };

      const endpoint = this.config.resolveEndpoint;

      fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': this.config.nonce
        },
        body: JSON.stringify(payload),
        signal: signal
      })
        .then((res) => {
          if (!res.ok && res.status !== 304) {
            throw new Error(`HTTP error ${res.status}`);
          }
          return res.json();
        })
        .then((data) => {
          // R107: Reject out-of-order responses!
          if (data.request_sequence !== undefined && data.request_sequence < this.requestSequence) {
            // Stale response arrived after a newer request was dispatched. Discard completely!
            return;
          }

          if (this.resolutionCache) {
            if (
              data.product_revision &&
              String(data.product_revision) !== String(this.productRevision)
            ) {
              this.resolutionCache.clear();
            }
            this.resolutionCache.set(selectionKey, data);
          }

          this.handleResponse(data);
        })
        .catch((err) => {
          if (err.name === 'AbortError') {
            // Request was aborted by newer user selection — normal behavior
            return;
          }
          // On network/server error, announce and keep selection
          this.form.removeAttribute('aria-busy');
          this.currentState = 'error';
          this.announce('Error resolving selection. Please try again.');
        });
    }

    /**
     * Process validated response matching latest sequence.
     * @param {Object} data - ResolveResponse contract
     */
    handleResponse(data) {
      this.form.removeAttribute('aria-busy');

      // Check for revision change
      if (data.product_revision && data.product_revision !== this.productRevision) {
        if (this.resolutionCache) {
          this.resolutionCache.clear();
        }
        this.productRevision = data.product_revision;
      }

      if (data.lowest_price_html !== undefined) {
        this.lowestPriceHtml = String(data.lowest_price_html || '');
      }

      this.allowedValues = data.allowed_values || {};
      this.allowedDetails = data.allowed_details || {};
      this.updateAllowedSwatches();
      this.syncControlsWithSelection();

      if (data.value_thumbnails) {
        this.valueThumbnails = data.value_thumbnails;
        this.updateValueThumbnails(data.value_thumbnails);
      }

      this.updateOptionLabels();

      const state = data.state || 'none';
      this.currentState = state;

      // Show reset button if any selection is made
      const hasSelection = Object.keys(this.currentSelection).length > 0;
      const resetBtn = this.form.querySelector('.wpe-reset-selection, .reset_variations');
      if (resetBtn) {
        resetBtn.style.display = hasSelection ? 'inline-block' : 'none';
        resetBtn.style.visibility = hasSelection ? 'visible' : 'hidden';
      }

      switch (state) {
        case 'exact':
          if (data.variant && data.variant.purchasable && data.variant.available) {
            this.applyExactVariant(data.variant);
          } else {
            this.applyUnavailableVariant(data.variant, data.reason);
          }
          break;

        case 'partial':
          this.applyPartialState(data.candidate_image || null);
          break;

        case 'none':
          this.applyNoneState();
          break;

        case 'ambiguous':
          this.applyAmbiguousState();
          break;

        default:
          this.updateUiState(state);
          break;
      }
    }

    updateAllowedSwatches() {
      const markUnavailable = Boolean(this.flags.mark_unavailable_options);
      const swatches = this.form.querySelectorAll('.wpe-swatch');
      swatches.forEach((swatch) => {
        const optionId = swatch.getAttribute('data-option-id');
        const valueId = swatch.getAttribute('data-value-id');

        let isAllowed = true;
        if (
          this.allowedDetails &&
          this.allowedDetails[optionId] &&
          this.allowedDetails[optionId][valueId] !== undefined
        ) {
          const detail = this.allowedDetails[optionId][valueId];
          isAllowed = Boolean(detail.exists && detail.purchasable);
        } else if (this.allowedValues && this.allowedValues[optionId]) {
          const list = this.allowedValues[optionId];
          isAllowed = list.includes(Number(valueId)) || list.includes(String(valueId));
        }

        if (isAllowed) {
          swatch.setAttribute('aria-disabled', 'false');
          if (typeof swatch.removeAttribute === 'function') {
            swatch.removeAttribute('disabled');
          }
          swatch.classList.remove(
            'is-disabled',
            'is-unavailable',
            'is-marked-unavailable',
            'is-disabled-standard'
          );
        } else {
          swatch.setAttribute('aria-disabled', 'true');
          if (typeof swatch.setAttribute === 'function') {
            swatch.setAttribute('disabled', 'disabled');
          }
          swatch.classList.add('is-disabled', 'is-unavailable');
          if (markUnavailable) {
            swatch.classList.add('is-marked-unavailable');
            swatch.classList.remove('is-disabled-standard');
          } else {
            swatch.classList.add('is-disabled-standard');
            swatch.classList.remove('is-marked-unavailable');
          }
        }
      });

      // Update select dropdown options
      const selects = this.form.querySelectorAll('select.wpe-select, .variations select');
      selects.forEach((select) => {
        const optionId = select.getAttribute('data-option-id') || select.getAttribute('name');
        const options = select.querySelectorAll('option');
        options.forEach((opt) => {
          if (!opt.value) return;
          const normVal = this.normalizeOptionValueId(optionId, opt.value);
          let isAllowed = true;
          if (
            this.allowedDetails &&
            this.allowedDetails[optionId] &&
            this.allowedDetails[optionId][normVal] !== undefined
          ) {
            const detail = this.allowedDetails[optionId][normVal];
            isAllowed = Boolean(detail.exists && detail.purchasable);
          } else if (this.allowedValues && this.allowedValues[optionId]) {
            const list = this.allowedValues[optionId];
            isAllowed =
              list.includes(Number(normVal)) ||
              list.includes(String(normVal)) ||
              list.includes(Number(opt.value)) ||
              list.includes(String(opt.value));
          }
          opt.disabled = !isAllowed;
        });
      });
    }

    applyExactVariant(variant) {
      this.currentState = 'exact_purchasable';

      // 1. Populate WooCommerce hidden variation_id input
      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = variant.id;
      }

      // 2. Enable Add to Cart button
      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.removeAttribute('disabled');
        addBtn.classList.remove(
          'disabled',
          'wc-variation-selection-needed',
          'wc-variation-is-unavailable'
        );
      }
      const addWrap = this.form.querySelector('.woocommerce-variation-add-to-cart');
      if (addWrap && addWrap.classList) {
        addWrap.classList.remove('woocommerce-variation-add-to-cart-disabled');
        addWrap.classList.add('woocommerce-variation-add-to-cart-enabled');
      }

      const singleVarWrap = this.form.querySelector('.single_variation');
      if (singleVarWrap && typeof singleVarWrap.querySelector === 'function') {
        const noMatch = singleVarWrap.querySelector('.wc-no-matching-variations');
        if (noMatch && typeof noMatch.remove === 'function') {
          noMatch.remove();
        }
      }

      // 3. Update Price in main price container (Single Price Display or standard container)
      const priceContainer = this.findPriceContainer();
      if (priceContainer) {
        if (variant.price_html) {
          priceContainer.innerHTML = variant.price_html;
        } else if (variant.display_price) {
          priceContainer.innerHTML = `<span class="woocommerce-Price-amount amount">${variant.display_price}</span>`;
        }
        if (typeof priceContainer.setAttribute === 'function') {
          priceContainer.setAttribute('data-wpe-price-state', 'resolved');
        }
      }

      // 4. Update Variant Image if image payload available
      if (variant.image && variant.image.src) {
        this.updateGalleryImage(variant.image);
      }

      // 5. Hide notices
      this.hideNotice();

      // 6. Announce to screen-reader
      this.announce(
        `Variant selected. Price: ${variant.display_price || ''}. In stock.`
      );

      // 7. Trigger jQuery event if present for classic WooCommerce theme extensions
      if (window.jQuery) {
        this.detachWcVariationForm();
        window.jQuery(this.form).trigger('found_variation', [variant]);
        if (variationInput) {
          variationInput.value = variant.id;
        }
        if (addBtn) {
          addBtn.removeAttribute('disabled');
          addBtn.classList.remove(
            'disabled',
            'wc-variation-selection-needed',
            'wc-variation-is-unavailable'
          );
        }
      }
    }

    applyUnavailableVariant(variant, reason) {
      this.currentState = 'exact_unavailable';

      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = variant ? variant.id : '';
      }

      if (variant && variant.image && variant.image.src) {
        this.updateGalleryImage(variant.image);
      }

      // Disable Add to Cart button
      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.setAttribute('disabled', 'disabled');
        addBtn.classList.add('disabled');
      }

      if (this.flags.single_price_display) {
        this.restoreParentPrice();
      }

      const msg =
        reason === 'out_of_stock'
          ? this.config.i18n.outOfStock
          : reason === 'missing_price'
            ? this.config.i18n.unavailable
            : reason || this.config.i18n.outOfStock;
      this.showNotice(msg, 'warning');
      this.announce(msg);
    }

    applyPartialState(candidateImage = null) {
      this.currentState = 'partial';

      // Clear variation_id
      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = '';
      }

      // Disable Add to Cart button
      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.setAttribute('disabled', 'disabled');
        addBtn.classList.add('disabled');
      }

      if (candidateImage && candidateImage.src) {
        this.updateGalleryImage(candidateImage);
      } else {
        this.restoreParentImage();
      }

      // When Single Price Display is enabled, partial selection reverts main price to lowest purchasable price (§2)
      if (this.flags.single_price_display) {
        this.restoreParentPrice();
      }

      this.hideNotice();
      this.announce(this.config.i18n.selectOption);
    }

    applyNoneState() {
      this.currentState = 'none';

      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = '';
      }

      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.setAttribute('disabled', 'disabled');
        addBtn.classList.add('disabled');
      }

      if (this.flags.single_price_display) {
        this.restoreParentPrice();
      }

      this.showNotice(this.config.i18n.unavailable, 'warning');
      this.announce(this.config.i18n.unavailable);
    }

    applyAmbiguousState() {
      this.currentState = 'ambiguous';

      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = '';
      }

      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.setAttribute('disabled', 'disabled');
        addBtn.classList.add('disabled');
      }

      if (this.flags.single_price_display) {
        this.restoreParentPrice();
      }

      this.showNotice(this.config.i18n.ambiguous, 'error');
      this.announce(this.config.i18n.ambiguous);
    }

    normalizeImageUrl(url) {
      if (!url || typeof url !== 'string') return '';
      const trimmed = url.trim();
      if (!trimmed || trimmed.startsWith('data:')) return trimmed;
      const noHash = trimmed.split('#')[0];
      const noQuery = noHash.split('?')[0];
      return noQuery.replace(/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/, '');
    }

    findGallerySlides(gallery) {
      if (!gallery || typeof gallery.querySelectorAll !== 'function') return [];
      const slides = gallery.querySelectorAll(
        '.woocommerce-product-gallery__image, .product-gallery-slider .slide, .slide'
      );
      return slides ? Array.from(slides) : [];
    }

    findGalleryThumbnailItems(gallery) {
      if (gallery && typeof gallery.querySelectorAll === 'function') {
        const navItems = gallery.querySelectorAll(
          '.flex-control-thumbs li, .flex-control-nav li'
        );
        if (navItems && navItems.length > 0) {
          return Array.from(navItems);
        }
      }
      if (this.instanceRoot && typeof this.instanceRoot.querySelectorAll === 'function') {
        const rootThumbs = this.instanceRoot.querySelectorAll(
          '.product-thumbnails .col, .flex-control-thumbs li'
        );
        if (rootThumbs && rootThumbs.length > 0) {
          return Array.from(rootThumbs);
        }
      }
      if (typeof document !== 'undefined' && typeof document.querySelectorAll === 'function') {
        const docThumbs = document.querySelectorAll(
          '.product-thumbnails .col, .flex-control-thumbs li'
        );
        if (docThumbs && docThumbs.length > 0) {
          return Array.from(docThumbs);
        }
      }
      return [];
    }

    findMatchingGalleryIndex(gallery, image) {
      if (!gallery || !image) return -1;
      const rawTargets = [
        image.src,
        image.full_src,
        image.gallery_thumbnail_src,
        image.thumb_src
      ].filter((u) => typeof u === 'string' && u.trim() !== '');
      if (rawTargets.length === 0) return -1;

      const normTargets = rawTargets.map((u) => this.normalizeImageUrl(u)).filter(Boolean);

      const slides = this.findGallerySlides(gallery);
      if (slides.length > 1) {
        // 1. Exact URL match against slides
        for (let i = 0; i < slides.length; i++) {
          const slide = slides[i];
          const img =
            typeof slide.querySelector === 'function'
              ? slide.querySelector('img.wp-post-image, img:not(.zoomImg)')
              : null;
          const link =
            typeof slide.querySelector === 'function' ? slide.querySelector('a') : null;
          const candidates = [
            typeof slide.getAttribute === 'function' ? slide.getAttribute('data-thumb') : '',
            img && typeof img.getAttribute === 'function' ? img.getAttribute('src') : '',
            img && typeof img.getAttribute === 'function' ? img.getAttribute('data-large_image') : '',
            img && typeof img.getAttribute === 'function' ? img.getAttribute('data-src') : '',
            link && typeof link.getAttribute === 'function' ? link.getAttribute('href') : ''
          ].filter(Boolean);

          if (candidates.some((c) => rawTargets.includes(c))) {
            return i;
          }
        }

        // 2. Normalized URL match against slides
        for (let i = 0; i < slides.length; i++) {
          const slide = slides[i];
          const img =
            typeof slide.querySelector === 'function'
              ? slide.querySelector('img.wp-post-image, img:not(.zoomImg)')
              : null;
          const link =
            typeof slide.querySelector === 'function' ? slide.querySelector('a') : null;
          const normCandidates = [
            typeof slide.getAttribute === 'function' ? slide.getAttribute('data-thumb') : '',
            img && typeof img.getAttribute === 'function' ? img.getAttribute('src') : '',
            img && typeof img.getAttribute === 'function' ? img.getAttribute('data-large_image') : '',
            img && typeof img.getAttribute === 'function' ? img.getAttribute('data-src') : '',
            link && typeof link.getAttribute === 'function' ? link.getAttribute('href') : ''
          ]
            .map((c) => this.normalizeImageUrl(c))
            .filter(Boolean);

          if (normCandidates.some((c) => normTargets.includes(c))) {
            return i;
          }
        }
      }

      const thumbItems = this.findGalleryThumbnailItems(gallery);
      if (thumbItems.length > 1) {
        for (let i = 0; i < thumbItems.length; i++) {
          const item = thumbItems[i];
          const img =
            typeof item.querySelector === 'function' ? item.querySelector('img') : null;
          const src =
            img && typeof img.getAttribute === 'function' ? img.getAttribute('src') || '' : '';
          if (src && (rawTargets.includes(src) || normTargets.includes(this.normalizeImageUrl(src)))) {
            return i;
          }
        }
      }

      return -1;
    }

    selectGallerySlideIndex(gallery, targetIndex) {
      if (!gallery || targetIndex < 0) return;

      const slides = this.findGallerySlides(gallery);
      if (slides.length > targetIndex) {
        slides.forEach((slide, idx) => {
          if (slide && slide.classList) {
            if (idx === targetIndex) {
              slide.classList.add('flex-active-slide');
            } else {
              slide.classList.remove('flex-active-slide');
            }
          }
        });
      }

      const thumbItems = this.findGalleryThumbnailItems(gallery);
      if (thumbItems.length > targetIndex) {
        thumbItems.forEach((item, idx) => {
          const isTarget = idx === targetIndex;
          if (item && item.classList) {
            if (item.classList.contains('col')) {
              if (isTarget) {
                item.classList.add('is-nav-selected');
              } else {
                item.classList.remove('is-nav-selected');
              }
            } else {
              if (isTarget) {
                item.classList.add('emoza-flex-active-slide', 'flex-active-slide');
              } else {
                item.classList.remove('emoza-flex-active-slide', 'flex-active-slide');
              }
            }
          }
          const thumbImg =
            item && typeof item.querySelector === 'function' ? item.querySelector('img') : null;
          if (thumbImg && thumbImg.classList) {
            if (isTarget) {
              thumbImg.classList.add('flex-active');
            } else {
              thumbImg.classList.remove('flex-active');
            }
          }
          if (item && item.classList) {
            if (isTarget) {
              item.classList.add('is-active');
            } else {
              item.classList.remove('is-active');
            }
          }
        });
      }

      if (gallery.__starterkitGalleryState) {
        const skState = gallery.__starterkitGalleryState;
        if (skState.mainSwiper && typeof skState.mainSwiper.slideTo === 'function') {
          skState.mainSwiper.slideTo(targetIndex);
        }
        skState.activeIndex = targetIndex;
      }

      if (typeof window !== 'undefined' && typeof window.jQuery === 'function') {
        try {
          const $gallery = window.jQuery(gallery);

          // Trigger native FlexSlider / Emoza thumbnail click or direct slide selection
          if (thumbItems.length > targetIndex) {
            const targetThumbImg =
              typeof thumbItems[targetIndex].querySelector === 'function'
                ? thumbItems[targetIndex].querySelector('img')
                : null;
            if (
              targetThumbImg &&
              typeof targetThumbImg.click === 'function' &&
              $gallery.data('flexslider') &&
              $gallery.data('flexslider').currentSlide !== targetIndex
            ) {
              targetThumbImg.click();
            }
          }

          if (typeof $gallery.flexslider === 'function' && $gallery.data('flexslider')) {
            if ($gallery.data('flexslider').currentSlide !== targetIndex) {
              $gallery.flexslider(targetIndex);
            }
          }

          // Scroll Emoza thumbnail carousel (.emoza-flexslider) to page containing targetIndex
          const $emozaThumbSlider = $gallery.find('.emoza-flexslider');
          if ($emozaThumbSlider.length && $emozaThumbSlider.data('flexslider')) {
            const thumbFlex = $emozaThumbSlider.data('flexslider');
            const move = thumbFlex.move || thumbFlex.visible || 1;
            const maxPage = Math.max(0, (thumbFlex.pagingCount || 1) - 1);
            const targetPage = Math.min(Math.floor(targetIndex / move), maxPage);
            if (thumbFlex.currentSlide !== targetPage && typeof thumbFlex.flexAnimate === 'function') {
              thumbFlex.flexAnimate(targetPage);
            }
          }

          // Scroll Emoza vertical Swiper thumbnail strip if present
          const swiperEl =
            typeof gallery.querySelector === 'function'
              ? gallery.querySelector('.emoza-swiper')
              : null;
          if (swiperEl && swiperEl.swiper && typeof swiperEl.swiper.slideTo === 'function') {
            swiperEl.swiper.slideTo(targetIndex);
          }

          // Flatsome main Flickity slider + thumbnail Flickity strip
          const $slider = $gallery.find('.product-gallery-slider');
          if ($slider.length && typeof $slider.flickity === 'function' && $slider.data('flickity')) {
            $slider.flickity('select', targetIndex);
          }
          const $root = this.instanceRoot ? window.jQuery(this.instanceRoot) : $gallery.closest('.product');
          const $thumbsSlider = $root.find('.product-thumbnails');
          if (
            $thumbsSlider.length &&
            typeof $thumbsSlider.flickity === 'function' &&
            $thumbsSlider.data('flickity')
          ) {
            $thumbsSlider.flickity('select', targetIndex);
          }

          $gallery.trigger('woocommerce_gallery_init_zoom');
        } catch (e) {
          // ignore slider errors
        }
      }
    }

    restoreSlideZeroToParent(gallery) {
      if (!this.parentImageState || !gallery || typeof gallery.querySelector !== 'function') return;

      const firstSlide = gallery.querySelector(
        '.woocommerce-product-gallery__image, .slide.first, .slide'
      );
      const mainImg =
        (firstSlide &&
          typeof firstSlide.querySelector === 'function' &&
          firstSlide.querySelector('img.wp-post-image, img:not(.zoomImg)')) ||
        gallery.querySelector(
          '.woocommerce-product-gallery__image img:not(.zoomImg), .wp-post-image, .slide.first img:not(.zoomImg), .slide img:not(.zoomImg)'
        );

      if (mainImg) {
        mainImg.setAttribute('src', this.parentImageState.src);
        if ('src' in mainImg) {
          mainImg.src = this.parentImageState.src;
        }
        if (this.parentImageState.srcset) {
          mainImg.setAttribute('srcset', this.parentImageState.srcset);
        } else {
          mainImg.setAttribute('srcset', '');
          if (typeof mainImg.removeAttribute === 'function') {
            mainImg.removeAttribute('srcset');
          }
        }
        mainImg.setAttribute('sizes', this.parentImageState.sizes);
        mainImg.setAttribute('alt', this.parentImageState.alt);
        if (this.parentImageState.title !== undefined) {
          mainImg.setAttribute('title', this.parentImageState.title);
        }
        mainImg.setAttribute('data-large_image', this.parentImageState.fullSrc);
        if (this.parentImageState.dataSrc) {
          mainImg.setAttribute('data-src', this.parentImageState.dataSrc);
        }
        if (this.parentImageState.largeW) {
          mainImg.setAttribute('data-large_image_width', this.parentImageState.largeW);
        }
        if (this.parentImageState.largeH) {
          mainImg.setAttribute('data-large_image_height', this.parentImageState.largeH);
        }
        const parentLink = typeof mainImg.closest === 'function' ? mainImg.closest('a') : null;
        if (parentLink && (this.parentImageState.linkHref || this.parentImageState.fullSrc)) {
          parentLink.setAttribute('href', this.parentImageState.linkHref || this.parentImageState.fullSrc);
        }
        if (firstSlide && this.parentImageState.slideThumb) {
          firstSlide.setAttribute('data-thumb', this.parentImageState.slideThumb);
        }

        const firstThumbImg = this.findFirstThumbnailImage(gallery);
        const origThumbSrc =
          this.parentImageState.thumbSrc ||
          this.parentImageState.slideThumb ||
          this.parentImageState.src;
        if (firstThumbImg && origThumbSrc) {
          firstThumbImg.setAttribute('src', origThumbSrc);
          if ('src' in firstThumbImg) {
            firstThumbImg.src = origThumbSrc;
          }
          if (this.parentImageState.thumbSrcset) {
            firstThumbImg.setAttribute('srcset', this.parentImageState.thumbSrcset);
          } else if (typeof firstThumbImg.removeAttribute === 'function') {
            firstThumbImg.removeAttribute('srcset');
          }
        }

        const zoomImg =
          (firstSlide &&
            typeof firstSlide.querySelector === 'function' &&
            firstSlide.querySelector('img.zoomImg')) ||
          gallery.querySelector('img.zoomImg');
        if (zoomImg && this.parentImageState.fullSrc) {
          zoomImg.setAttribute('src', this.parentImageState.fullSrc);
          if ('src' in zoomImg) {
            zoomImg.src = this.parentImageState.fullSrc;
          }
        }
      }
      this.slideZeroMutated = false;
    }

    updateGalleryImage(image) {
      if (!image || !image.src) return;
      const gallery = this.findGallery();
      if (!gallery || typeof gallery.querySelector !== 'function') return;

      // If slide 0 was previously overwritten by a non-gallery variation image, restore it first
      // so all gallery slide/thumbnail positions are intact before matching.
      if (this.slideZeroMutated) {
        this.restoreSlideZeroToParent(gallery);
      }

      const thumbSrc = image.gallery_thumbnail_src || image.thumb_src || image.src;
      if (typeof document !== 'undefined' && typeof document.querySelector === 'function') {
        const stickyImg = document.querySelector('.sticky-add-to-cart-img');
        if (stickyImg && thumbSrc) {
          stickyImg.setAttribute('src', thumbSrc);
        }
      }

      // Check if the variation image already exists in the product gallery at a specific slide/thumbnail position
      const matchedIndex = this.findMatchingGalleryIndex(gallery, image);
      if (matchedIndex >= 0) {
        this.selectGallerySlideIndex(gallery, matchedIndex);
        return;
      }

      // Fallback when variation image is not part of the gallery strip: update slide 0 and select index 0
      const firstSlide = gallery.querySelector(
        '.woocommerce-product-gallery__image, .slide.first, .slide'
      );
      const mainImg =
        (firstSlide &&
          typeof firstSlide.querySelector === 'function' &&
          firstSlide.querySelector('img.wp-post-image, img:not(.zoomImg)')) ||
        gallery.querySelector(
          '.woocommerce-product-gallery__image img:not(.zoomImg), .wp-post-image, .slide.first img:not(.zoomImg), .slide img:not(.zoomImg)'
        );

      if (mainImg) {
        this.slideZeroMutated = true;
        mainImg.setAttribute('src', image.src);
        if ('src' in mainImg) {
          mainImg.src = image.src;
        }
        if (image.srcset) {
          mainImg.setAttribute('srcset', image.srcset);
        } else {
          mainImg.setAttribute('srcset', '');
          if (typeof mainImg.removeAttribute === 'function') {
            mainImg.removeAttribute('srcset');
            mainImg.removeAttribute('data-srcset');
          }
        }
        if (image.sizes !== undefined) {
          mainImg.setAttribute('sizes', image.sizes || '');
        }
        if (image.alt !== undefined) {
          mainImg.setAttribute('alt', image.alt || '');
        }
        if (image.title !== undefined) {
          mainImg.setAttribute('title', image.title || '');
          mainImg.setAttribute('data-caption', image.title || '');
        }
        const fullSrc = image.full_src || image.src;
        mainImg.setAttribute('data-large_image', fullSrc);
        mainImg.setAttribute('data-src', fullSrc);
        if (image.full_src_w || image.width) {
          mainImg.setAttribute('data-large_image_width', String(image.full_src_w || image.width));
        }
        if (image.full_src_h || image.height) {
          mainImg.setAttribute('data-large_image_height', String(image.full_src_h || image.height));
        }
        const parentLink = typeof mainImg.closest === 'function' ? mainImg.closest('a') : null;
        if (parentLink) {
          parentLink.setAttribute('href', fullSrc);
        }

        if (firstSlide && thumbSrc) {
          firstSlide.setAttribute('data-thumb', thumbSrc);
        }

        const firstThumbImg = this.findFirstThumbnailImage(gallery);
        if (firstThumbImg && thumbSrc) {
          firstThumbImg.setAttribute('src', thumbSrc);
          if ('src' in firstThumbImg) {
            firstThumbImg.src = thumbSrc;
          }
          firstThumbImg.setAttribute('srcset', '');
          if (typeof firstThumbImg.removeAttribute === 'function') {
            firstThumbImg.removeAttribute('srcset');
          }
        }

        const zoomImg =
          (firstSlide &&
            typeof firstSlide.querySelector === 'function' &&
            firstSlide.querySelector('img.zoomImg')) ||
          gallery.querySelector('img.zoomImg');
        if (zoomImg) {
          zoomImg.setAttribute('src', fullSrc);
          if ('src' in zoomImg) {
            zoomImg.src = fullSrc;
          }
        }

        this.selectGallerySlideIndex(gallery, 0);
      }
    }

    restoreParentImage() {
      if (!this.parentImageState) return;
      const gallery = this.findGallery();
      if (!gallery || typeof gallery.querySelector !== 'function') return;

      this.restoreSlideZeroToParent(gallery);

      const origThumbSrc =
        this.parentImageState.thumbSrc ||
        this.parentImageState.slideThumb ||
        this.parentImageState.src;
      if (typeof document !== 'undefined' && typeof document.querySelector === 'function') {
        const stickyImg = document.querySelector('.sticky-add-to-cart-img');
        if (stickyImg && origThumbSrc) {
          stickyImg.setAttribute('src', origThumbSrc);
        }
      }

      this.selectGallerySlideIndex(gallery, 0);
    }

    restoreParentPrice() {
      const priceContainer = this.findPriceContainer();
      if (!priceContainer) return;

      if (this.flags.single_price_display && this.lowestPriceHtml) {
        priceContainer.innerHTML = this.lowestPriceHtml;
        if (typeof priceContainer.setAttribute === 'function') {
          priceContainer.setAttribute('data-wpe-price-state', 'lowest');
        }
        return;
      }

      if (this.parentPriceHtml !== null) {
        priceContainer.innerHTML = this.parentPriceHtml;
        if (typeof priceContainer.setAttribute === 'function') {
          priceContainer.setAttribute('data-wpe-price-state', 'lowest');
        }
      }
    }

    /**
     * Reset selection to defaults or clear completely (R109)
     */
    resetSelection() {
      this.currentSelection = Object.assign({}, this.config.defaultSelection || {});

      // Clear variation_id input
      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = '';
      }

      // Disable add to cart button
      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.setAttribute('disabled', 'disabled');
        addBtn.classList.add('disabled');
      }

      // Restore parent image and price
      this.restoreParentImage();
      this.restoreParentPrice();

      // Reset swatches
      const swatches = this.form.querySelectorAll('.wpe-swatch');
      swatches.forEach((swatch) => {
        const optionId = swatch.getAttribute('data-option-id');
        const valueId = swatch.getAttribute('data-value-id');
        const isDefault = this.currentSelection[optionId] === valueId;
        swatch.setAttribute('aria-checked', isDefault ? 'true' : 'false');
        swatch.setAttribute('aria-disabled', 'false');
        if (typeof swatch.removeAttribute === 'function') {
          swatch.removeAttribute('disabled');
        }
        swatch.classList.remove(
          'is-disabled',
          'is-unavailable',
          'is-marked-unavailable',
          'is-disabled-standard'
        );
        if (isDefault) {
          swatch.classList.add('is-selected');
        } else {
          swatch.classList.remove('is-selected');
        }
      });

      // Reset select elements
      const selects = this.form.querySelectorAll('select.wpe-select, .variations select');
      selects.forEach((select) => {
        const optionId = select.getAttribute('data-option-id') || select.getAttribute('name');
        const valId = this.currentSelection[optionId] || '';
        if (optionId && /^\d+$/.test(String(optionId))) {
          this.syncSelectForOption(optionId, valId);
        } else {
          select.value = valId;
        }
      });

      // Restore initial allowed values/details and thumbnails if present
      if (this.config.initialResolution) {
        this.allowedValues = this.config.initialResolution.allowed_values || {};
        this.allowedDetails = this.config.initialResolution.allowed_details || {};
        this.updateAllowedSwatches();
        if (this.config.initialResolution.value_thumbnails) {
          this.updateValueThumbnails(this.config.initialResolution.value_thumbnails);
        }
      }

      // Update option labels
      this.updateOptionLabels();

      // Hide reset button
      const hasDefaultSelection = Object.keys(this.currentSelection).length > 0;
      const resetBtn = this.form.querySelector('.wpe-reset-selection, .reset_variations');
      if (resetBtn) {
        resetBtn.style.display = hasDefaultSelection ? 'inline-block' : 'none';
        resetBtn.style.visibility = hasDefaultSelection ? 'visible' : 'hidden';
      }

      this.hideNotice();
      this.announce('Selection cleared');

      if (window.jQuery) {
        this.detachWcVariationForm();
        window.jQuery(this.form).trigger('reset_data');
      }

      if (hasDefaultSelection) {
        this.resolve();
      } else {
        this.currentState = 'initial';
      }
    }

    showNotice(message, type = 'warning') {
      let notice = this.form.querySelector('.wpe-selector-notice');
      if (!notice) {
        if (typeof document !== 'undefined' && typeof document.createElement === 'function') {
          notice = document.createElement('div');
          notice.className = `wpe-selector-notice notice-${type} is-visible`;
          if (typeof this.form.insertBefore === 'function') {
            this.form.insertBefore(notice, this.form.firstChild);
          }
        }
      } else {
        notice.className = `wpe-selector-notice notice-${type} is-visible`;
      }
      if (notice) {
        notice.textContent = message;
      }
    }

    hideNotice() {
      const notice = this.form.querySelector('.wpe-selector-notice');
      if (notice) {
        notice.classList.remove('is-visible');
        notice.textContent = '';
      }
    }

    updateUiState(state) {
      this.currentState = state;
    }
  }

  // Export class for testing and global usage
  if (typeof window !== 'undefined') {
    window.WpeVariantSelector = WpeVariantSelector;
  }
  if (typeof globalThis !== 'undefined') {
    globalThis.WpeVariantSelector = WpeVariantSelector;
  }
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = WpeVariantSelector;
  }

  // Auto-initialize in browser on DOM ready
  if (typeof document !== 'undefined' && document.addEventListener) {
    document.addEventListener('DOMContentLoaded', () => {
      const forms = document.querySelectorAll('.variations_form, .wpe-variant-selector');
      forms.forEach((form) => {
        const config = (typeof window !== 'undefined' && window.wpeSelectorData) ? window.wpeSelectorData : {};
        new WpeVariantSelector(form, config);
      });
    });
  }
})(typeof window !== 'undefined' ? window : globalThis, typeof document !== 'undefined' ? document : {});
