/**
 * WC Product Engine — Variant Selector Client
 *
 * Implements accessible options/swatches, roving tabindex, asynchronous
 * variation resolution with AbortController, out-of-order sequence cancellation,
 * and zero DOM bloat for large catalogs (10k+ variants).
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
      this.config = Object.assign(
        {
          productId: 0,
          resolveEndpoint: '',
          wcAjaxUrl: '',
          nonce: '',
          productRevision: '1',
          options: [],
          defaultSelection: {},
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
        config
      );

      this.currentSelection = Object.assign({}, this.config.defaultSelection || {});
      this.allowedValues = {};
      this.requestSequence = 0;
      this.abortController = null;
      this.currentState = 'initial';
      this.productRevision = String(this.config.productRevision || '1');

      // Parent gallery cache for restoring image
      this.parentImageState = null;
      this.parentPriceHtml = null;

      this.init();
    }

    init() {
      if (!this.form) {
        return;
      }

      this.cacheParentState();
      this.bindEvents();
      this.createLiveRegion();

      // If initial defaults are present, run initial resolution
      if (Object.keys(this.currentSelection).length > 0) {
        this.resolve();
      } else {
        this.updateUiState('initial');
      }
    }

    cacheParentState() {
      // Find main product image
      if (typeof document !== 'undefined' && typeof document.querySelector === 'function') {
        const gallery = document.querySelector('.woocommerce-product-gallery');
        if (gallery) {
          const mainImg = gallery.querySelector('.woocommerce-product-gallery__image img, .wp-post-image');
          if (mainImg) {
            this.parentImageState = {
              src: mainImg.getAttribute('src') || '',
              srcset: mainImg.getAttribute('srcset') || '',
              sizes: mainImg.getAttribute('sizes') || '',
              alt: mainImg.getAttribute('alt') || '',
              fullSrc: mainImg.getAttribute('data-large_image') || mainImg.getAttribute('src') || ''
            };
          }
        }
      }

      // Cache price HTML
      const priceContainer = this.findPriceContainer();
      if (priceContainer) {
        this.parentPriceHtml = priceContainer.innerHTML;
      }
    }

    findPriceContainer() {
      const docPrice = (typeof document !== 'undefined' && typeof document.querySelector === 'function')
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

    bindEvents() {
      // Swatch click and keyboard navigation
      const swatches = this.form.querySelectorAll('.wpe-swatch');
      swatches.forEach((swatch) => {
        swatch.addEventListener('click', (e) => {
          e.preventDefault();
          const optionId = swatch.getAttribute('data-option-id');
          const valueId = swatch.getAttribute('data-value-id');
          if (swatch.getAttribute('aria-disabled') === 'true') {
            return;
          }
          this.onSwatchSelect(optionId, valueId);
        });

        swatch.addEventListener('keydown', (e) => {
          this.handleKeyboardNav(e, swatch);
        });
      });

      // Baseline select dropdowns
      const selects = this.form.querySelectorAll('select.wpe-select, .variations select');
      selects.forEach((select) => {
        select.addEventListener('change', (e) => {
          const optionId = select.getAttribute('data-option-id') || select.getAttribute('name');
          const valueId = select.value;
          this.onSwatchSelect(optionId, valueId);
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

    handleKeyboardNav(event, swatch) {
      const key = event.key;
      const group = swatch.closest('.wpe-swatches');
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

    onSwatchSelect(optionId, valueId) {
      if (!valueId || valueId === '') {
        delete this.currentSelection[optionId];
      } else {
        this.currentSelection[optionId] = valueId;
      }

      this.updateRovingTabindex(optionId, valueId);
      this.resolve();
    }

    updateRovingTabindex(optionId, valueId) {
      const group = this.form.querySelector(`.wpe-swatches[data-option-id="${optionId}"]`);
      if (!group) return;

      const swatches = group.querySelectorAll('.wpe-swatch');
      swatches.forEach((s) => {
        const isSelected = s.getAttribute('data-value-id') === String(valueId);
        s.setAttribute('aria-checked', isSelected ? 'true' : 'false');
        s.setAttribute('tabindex', isSelected ? '0' : '-1');
        if (isSelected) {
          s.classList.add('is-selected');
        } else {
          s.classList.remove('is-selected');
        }
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
        this.productRevision = data.product_revision;
      }

      this.allowedValues = data.allowed_values || {};
      this.updateAllowedSwatches();

      const state = data.state || 'none';
      this.currentState = state;

      // Show reset button if any selection is made
      const hasSelection = Object.keys(this.currentSelection).length > 0;
      const resetBtn = this.form.querySelector('.wpe-reset-selection, .reset_variations');
      if (resetBtn) {
        resetBtn.style.display = hasSelection ? 'inline-block' : 'none';
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
          this.applyPartialState();
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
      const swatches = this.form.querySelectorAll('.wpe-swatch');
      swatches.forEach((swatch) => {
        const optionId = swatch.getAttribute('data-option-id');
        const valueId = swatch.getAttribute('data-value-id');

        const isAllowed =
          !this.allowedValues[optionId] || this.allowedValues[optionId].includes(Number(valueId));

        if (isAllowed) {
          swatch.setAttribute('aria-disabled', 'false');
          swatch.classList.remove('is-disabled');
        } else {
          swatch.setAttribute('aria-disabled', 'true');
          swatch.classList.add('is-disabled');
        }
      });

      // Update select dropdown options
      const selects = this.form.querySelectorAll('select.wpe-select, .variations select');
      selects.forEach((select) => {
        const optionId = select.getAttribute('data-option-id') || select.getAttribute('name');
        const options = select.querySelectorAll('option');
        options.forEach((opt) => {
          if (!opt.value) return;
          const isAllowed =
            !this.allowedValues[optionId] || this.allowedValues[optionId].includes(Number(opt.value));
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
        addBtn.classList.remove('disabled');
      }

      // 3. Update Price
      const priceContainer = this.findPriceContainer();
      if (priceContainer && variant.display_price) {
        priceContainer.innerHTML = `<span class="woocommerce-Price-amount amount">${variant.display_price}</span>`;
      }

      // 4. Update Variant Image if media_asset_id or image payload available
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
        window.jQuery(this.form).trigger('found_variation', [variant]);
      }
    }

    applyUnavailableVariant(variant, reason) {
      this.currentState = 'exact_unavailable';

      const variationInput = this.form.querySelector('input[name="variation_id"]');
      if (variationInput) {
        variationInput.value = variant ? variant.id : '';
      }

      // Disable Add to Cart button
      const addBtn = this.form.querySelector('.single_add_to_cart_button');
      if (addBtn) {
        addBtn.setAttribute('disabled', 'disabled');
        addBtn.classList.add('disabled');
      }

      const msg = reason || this.config.i18n.outOfStock;
      this.showNotice(msg, 'warning');
      this.announce(msg);
    }

    applyPartialState() {
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

      this.showNotice(this.config.i18n.ambiguous, 'error');
      this.announce(this.config.i18n.ambiguous);
    }

    updateGalleryImage(image) {
      if (typeof document === 'undefined' || typeof document.querySelector !== 'function') return;
      const gallery = document.querySelector('.woocommerce-product-gallery');
      if (!gallery) return;

      const mainImg = gallery.querySelector('.woocommerce-product-gallery__image img, .wp-post-image');
      if (mainImg && image.src) {
        mainImg.setAttribute('src', image.src);
        if (image.srcset) mainImg.setAttribute('srcset', image.srcset);
        if (image.sizes) mainImg.setAttribute('sizes', image.sizes);
        if (image.alt) mainImg.setAttribute('alt', image.alt);
        if (image.full_src) mainImg.setAttribute('data-large_image', image.full_src);
      }
    }

    restoreParentImage() {
      if (!this.parentImageState) return;
      if (typeof document === 'undefined' || typeof document.querySelector !== 'function') return;
      const gallery = document.querySelector('.woocommerce-product-gallery');
      if (!gallery) return;

      const mainImg = gallery.querySelector('.woocommerce-product-gallery__image img, .wp-post-image');
      if (mainImg) {
        mainImg.setAttribute('src', this.parentImageState.src);
        mainImg.setAttribute('srcset', this.parentImageState.srcset);
        mainImg.setAttribute('sizes', this.parentImageState.sizes);
        mainImg.setAttribute('alt', this.parentImageState.alt);
        mainImg.setAttribute('data-large_image', this.parentImageState.fullSrc);
      }
    }

    restoreParentPrice() {
      const priceContainer = this.findPriceContainer();
      if (priceContainer && this.parentPriceHtml) {
        priceContainer.innerHTML = this.parentPriceHtml;
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
        swatch.classList.remove('is-disabled');
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
        select.value = this.currentSelection[optionId] || '';
      });

      // Hide reset button
      const resetBtn = this.form.querySelector('.wpe-reset-selection, .reset_variations');
      if (resetBtn) {
        resetBtn.style.display = Object.keys(this.currentSelection).length > 0 ? 'inline-block' : 'none';
      }

      this.hideNotice();
      this.announce('Selection cleared');

      if (window.jQuery) {
        window.jQuery(this.form).trigger('reset_data');
      }

      if (Object.keys(this.currentSelection).length > 0) {
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

