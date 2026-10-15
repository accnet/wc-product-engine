/**
 * WC Product Engine — Product Variant & Option Editor
 * Unified reactive editor for variable products.
 */
(function ($) {
    'use strict';

    if (typeof window.wpeEditorConfig === 'undefined') {
        return;
    }

    const config = window.wpeEditorConfig;
    const productId = parseInt(config.productId, 10);
    let submitting = false;
    let generatorNewCombos = [];
    let lastClickedVariantId = null;

    /**
     * Standard POD Color Swatch Dictionary for automatic hex inference
     */
    const POD_COLOR_MAP = {
        'black': '#111111',
        'matte black': '#111111',
        'jet black': '#0a0a0a',
        'white': '#ffffff',
        'pure white': '#ffffff',
        'navy': '#1b263b',
        'navy blue': '#1b263b',
        'heather navy': '#33415c',
        'red': '#d62828',
        'cherry red': '#c1121f',
        'cardinal': '#8c1d40',
        'cardinal red': '#8c1d40',
        'maroon': '#6b1d2f',
        'burgundy': '#6b1d2f',
        'royal': '#00509d',
        'royal blue': '#00509d',
        'blue': '#1d4ed8',
        'light blue': '#a2d2ff',
        'carolina blue': '#56a0d3',
        'sky blue': '#70d6ff',
        'baby blue': '#a2d2ff',
        'sport grey': '#9e9fa5',
        'sport gray': '#9e9fa5',
        'grey': '#808080',
        'gray': '#808080',
        'ash': '#dcdfe3',
        'ash grey': '#dcdfe3',
        'dark heather': '#4a4e54',
        'graphite heather': '#5c6066',
        'charcoal': '#36454f',
        'silver': '#c0c0c0',
        'military green': '#4b5320',
        'olive': '#556b2f',
        'forest green': '#1b4332',
        'dark green': '#1b4332',
        'irish green': '#009e60',
        'kelly green': '#009e60',
        'green': '#16a34a',
        'mint': '#98f5e1',
        'teal': '#008080',
        'turquoise': '#00b4d8',
        'sand': '#d8c8b8',
        'natural': '#f3ead8',
        'cream': '#fffdd0',
        'beige': '#e6ccb2',
        'khaki': '#c3b091',
        'tan': '#d2b48c',
        'brown': '#582f0e',
        'dark chocolate': '#3d2314',
        'coffee': '#4b3621',
        'pink': '#ff8fab',
        'light pink': '#ffc2d1',
        'azalea': '#f77fbe',
        'heliconia': '#e0115f',
        'hot pink': '#ff007f',
        'coral': '#ff6b6b',
        'purple': '#5a189a',
        'lavender': '#cdb4db',
        'violet': '#7b2cbf',
        'orange': '#f77f00',
        'safety orange': '#ff5f00',
        'safety green': '#ccff00',
        'gold': '#fcbf49',
        'yellow': '#ffd166',
        'daisy': '#fed100',
        'mustard': '#e09f3e'
    };

    function inferPodHexColor(label) {
        if (!label) return '';
        const key = String(label).trim().toLowerCase().replace(/\s+/g, ' ');
        return POD_COLOR_MAP[key] || '';
    }

    /**
     * Unified Reactive Draft Store
     */
    const wpeVariantStore = {
        productId: productId,
        version: 1,
        isDirty: false,
        mediaDirty: false,
        isLoading: true,
        isLoaded: false,
        isRequested: false,
        mediaRequested: false,
        mediaLoaded: false,
        fullscreenTab: 'variants',
        type: config.productType || 'simple',
        derivedType: config.productType || 'simple',
        product: {
            sku: '',
            status: 'publish',
            regular_price: '',
            sale_price: '',
            sale_from: null,
            sale_to: null,
            weight: '',
            length: '',
            width: '',
            height: '',
            manage_stock: 0,
            stock_quantity: null,
            stock_status: 'instock',
            backorders: 'no',
            parent_inventory: { manage_stock: 0, quantity: null, stock_status: 'instock', backorders: 'no' }
        },
        commonPrice: null,
        referencePrice: null,
        typeTransition: null,
        descriptiveAttributes: [],
        variationOptions: [],
        variations: [],
        taxonomies: [],
        mediaLibrary: {
            featured_asset_id: null,
            featured: null,
            gallery: [],
            assets: []
        },
        uploadQueue: [],
        sessionToken: 'sess-' + Math.random().toString(36).slice(2, 12) + '-' + Date.now(),
        selection: new Set(),
        collapsedGroups: new Set(),
        filters: {
            search: '',
            stockStatus: '',
            attrFilter: '',
            groupBy: 'none',
            page: 1,
            pageSize: 25
        },
        listeners: new Set(),

        subscribe(listener) {
            this.listeners.add(listener);
            return () => this.listeners.delete(listener);
        },

        notify() {
            this.syncHiddenPayload();
            this.listeners.forEach(fn => fn());
        },

        markDirty() {
            this.isDirty = true;
            this.notify();
        },

        markMediaDirty() {
            this.isDirty = true;
            this.mediaDirty = true;
            this.notify();
        },

        /**
         * Non-destructive dirty update for inline table cell editing:
         * keeps the active table DOM and input focus intact while syncing badges,
         * sticky save bar, hidden payload, and background containers.
         */
        markDirtyInline(sourceContainerId) {
            this.isDirty = true;
            this.syncHiddenPayload();
            updateBoxHeader();
            updateDirtyChrome();
            if (sourceContainerId === '#wpe-product-editor-modal-app' && $('#wpe-product-editor-inline-app').length) {
                renderVariantsApp('#wpe-product-editor-inline-app');
            }
            const $infoApp = $('#wpe-product-info-app');
            if ($infoApp.length && wpeVariantStore.commonPrice === null) {
                const $refCard = $infoApp.find('.wpe-ref-card');
                if ($refCard.length) {
                    $refCard.replaceWith(renderReferencePriceCard());
                    bindProductInfoEvents($infoApp);
                }
            }
        },

        /**
         * The hidden field travels with the WordPress Update/Publish submit.
         */
        syncHiddenPayload() {
            const shouldSync = config.editorMode === 'blocks'
                ? (this.isDirty && isSupportedProductType())
                : (this.isDirty && (isVariableType() || this.mediaDirty));
            if (!shouldSync) {
                $('#wpe_variants_payload').val('');
                return;
            }
            const payload = buildSavePayload();
            $('#wpe_variants_payload').val(JSON.stringify(payload));
        }
    };
    window.wpeVariantStore = wpeVariantStore;

    function generateIdempotencyKey(prefix) {
        const cleanPrefix = String(prefix || 'wpe').replace(/[^A-Za-z0-9_-]/g, '').slice(0, 16) || 'wpe';
        const rand = Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 10);
        return `${cleanPrefix}-${Date.now()}-${rand}`.slice(0, 60);
    }

    function buildSavePayload() {
        const prod = Object.assign({}, wpeVariantStore.product);
        if (prod.status === 'auto-draft') {
            delete prod.status;
        }
        const isVar = isVariableType();
        if (isVar) {
            prod.regular_price = null;
            prod.sale_price = null;
            prod.sale_from = null;
            prod.sale_to = null;
        }
        const stockObj = {
            manage_stock: wpeVariantStore.product.manage_stock ? 1 : 0,
            stock_quantity: wpeVariantStore.product.manage_stock && wpeVariantStore.product.stock_quantity !== null && wpeVariantStore.product.stock_quantity !== ''
                ? parseFloat(wpeVariantStore.product.stock_quantity)
                : null,
            stock_status: wpeVariantStore.product.stock_status || 'instock',
            backorders: wpeVariantStore.product.backorders || 'no'
        };
        prod.stock = stockObj;
        prod.parent_stock = stockObj;

        const hasCommonPrice = isVar && wpeVariantStore.commonPrice !== null && typeof wpeVariantStore.commonPrice === 'object';
        const cpReg = hasCommonPrice ? (wpeVariantStore.commonPrice.regular_price !== undefined ? wpeVariantStore.commonPrice.regular_price : wpeVariantStore.commonPrice.regular) : null;
        const cpSale = hasCommonPrice ? (wpeVariantStore.commonPrice.sale_price !== undefined ? wpeVariantStore.commonPrice.sale_price : wpeVariantStore.commonPrice.sale) : null;
        const cpFrom = hasCommonPrice ? (wpeVariantStore.commonPrice.sale_from || wpeVariantStore.commonPrice.from || null) : null;
        const cpTo = hasCommonPrice ? (wpeVariantStore.commonPrice.sale_to || wpeVariantStore.commonPrice.to || null) : null;
        const commonPricePayload = hasCommonPrice
            ? {
                regular_price: cpReg !== '' && cpReg !== null && cpReg !== undefined ? String(cpReg) : null,
                sale_price: cpSale !== '' && cpSale !== null && cpSale !== undefined ? String(cpSale) : null,
                sale_from: cpFrom,
                sale_to: cpTo,
                regular: cpReg !== '' && cpReg !== null && cpReg !== undefined ? String(cpReg) : null,
                sale: cpSale !== '' && cpSale !== null && cpSale !== undefined ? String(cpSale) : null,
                from: cpFrom,
                to: cpTo
            }
            : null;

        const mediaLib = wpeVariantStore.mediaLibrary || {};
        const altTexts = [];
        let featuredAltOverride = null;
        const featId = mediaLib.featured_asset_id ? parseInt(mediaLib.featured_asset_id, 10) : null;
        (mediaLib.assets || []).forEach(a => {
            const aid = parseInt(a.asset_id || a.id, 10);
            if (aid > 0) {
                const altVal = a.alt_override !== undefined && a.alt_override !== null && a.alt_override !== ''
                    ? a.alt_override
                    : (a.alt_text || '');
                altTexts.push({ asset_id: aid, alt_text: altVal });
                if (featId && aid === featId) {
                    featuredAltOverride = altVal;
                }
            }
        });

        const payload = {
            expected_version: wpeVariantStore.version,
            product: prod,
            descriptive_attributes: wpeVariantStore.descriptiveAttributes,
            variation_options: wpeVariantStore.variationOptions.map(opt => {
                const copy = normalizeVariationOptionDefaults(opt);
                copy.display_type = 'auto';
                copy.help_text = '';
                return copy;
            }),
            variations: wpeVariantStore.variations.map(v => {
                const copy = Object.assign({}, v);
                delete copy.inventory_mode;
                if (hasCommonPrice) {
                    copy.pricing_mode = copy.pricing_mode === 'shared' ? 'shared' : 'own';
                } else {
                    delete copy.pricing_mode;
                }
                if (copy.image_asset_id !== undefined && copy.image_asset_id !== null && copy.image_asset_id !== '') {
                    copy.image_asset_id = parseInt(copy.image_asset_id, 10);
                }
                return copy;
            }),
            media_library: {
                featured_asset_id: featId,
                featured_alt_override: featuredAltOverride,
                gallery: (mediaLib.gallery || []).map((g, idx) => ({
                    asset_id: parseInt(g.asset_id || g.id, 10),
                    position: idx,
                    alt_override: g.alt_override !== undefined ? g.alt_override : null
                })),
                alt_texts: altTexts
            }
        };
        if (hasCommonPrice) {
            payload.common_price = commonPricePayload;
        }
        if (wpeVariantStore.typeTransition) {
            payload.type_transition = wpeVariantStore.typeTransition;
        }
        return payload;
    }

    /**
     * REST API Client
     */
    const api = {
        fetchData() {
            return $.ajax({
                url: config.restBase,
                method: 'GET',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        },

        saveData(payload) {
            return $.ajax({
                url: config.restBase,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        },

        previewGenerator(variationOptions) {
            return $.ajax({
                url: config.restBase + '/preview-generator',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ variation_options: variationOptions }),
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        },

        previewBulkEdit(variationIds, actions) {
            return $.ajax({
                url: config.restBase + '/preview-bulk-edit',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ variation_ids: variationIds, actions: actions }),
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        },

        fetchMedia() {
            const url = config.restMediaBase || (config.restBase.replace(/\/product\/(\d+)\/variants$/, '/products/$1/media'));
            return $.ajax({
                url: url,
                method: 'GET',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        },

        patchMedia(payload, idempotencyKey) {
            const url = config.restMediaBase || (config.restBase.replace(/\/product\/(\d+)\/variants$/, '/products/$1/media'));
            const key = idempotencyKey || generateIdempotencyKey('media-patch');
            const rev = (wpeVariantStore.mediaLibrary && wpeVariantStore.mediaLibrary.version) || wpeVariantStore.version || '1';
            return $.ajax({
                url: url,
                method: 'PATCH',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                    xhr.setRequestHeader('If-Match', String(rev));
                    xhr.setRequestHeader('Idempotency-Key', key);
                }
            });
        },

        previewUnlink(assetIds) {
            const url = (config.restMediaBase || (config.restBase.replace(/\/product\/(\d+)\/variants$/, '/products/$1/media'))) + '/unlink-preview';
            return $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ asset_ids: assetIds.map(id => String(id)) }),
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        },

        uploadAssetFile(file, uploadKey, altText) {
            const url = config.restAssetsUrl || (config.restBase.replace(/\/product\/\d+\/variants$/, '/assets'));
            const fd = new FormData();
            fd.append('file', file);
            fd.append('upload_key', uploadKey);
            fd.append('product_id', String(productId));
            fd.append('session_token', wpeVariantStore.sessionToken);
            if (altText) {
                fd.append('alt_text', altText);
            }
            return $.ajax({
                url: url,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                    xhr.setRequestHeader('Idempotency-Key', uploadKey);
                }
            });
        },

        registerExternalAsset(extUrl, altText, idempotencyKey) {
            const baseUrl = config.restAssetsUrl || (config.restBase.replace(/\/product\/\d+\/variants$/, '/assets'));
            const url = `${baseUrl}?product_id=${encodeURIComponent(String(productId))}`;
            const key = idempotencyKey || generateIdempotencyKey('ext-url');
            return $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    url: extUrl,
                    alt_text: altText || ''
                }),
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                    xhr.setRequestHeader('Idempotency-Key', key);
                }
            });
        },

        deleteAssetFile(assetId, idempotencyKey) {
            const baseUrl = config.restAssetsUrl || (config.restBase.replace(/\/product\/\d+\/variants$/, '/assets'));
            const key = idempotencyKey || generateIdempotencyKey('del-file');
            return $.ajax({
                url: `${baseUrl}/${encodeURIComponent(String(assetId))}/file`,
                method: 'DELETE',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                    xhr.setRequestHeader('Idempotency-Key', key);
                }
            });
        },

        previewImageAssignment(payload) {
            const url = config.restPreviewImageUrl || (config.restBase + '/preview-image-assignment');
            return $.ajax({
                url: url,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', config.nonce)
            });
        }
    };

    function getCurrentProductType() {
        const $type = $('#product-type');
        return $type.length ? $type.val() : (config.productType || 'simple');
    }

    function isVariableType() {
        if (!isSupportedProductType()) {
            return false;
        }
        if (config.editorMode === 'blocks') {
            return (wpeVariantStore.derivedType || wpeVariantStore.type || config.productType) === 'variable' || wpeVariantStore.variationOptions.length > 0;
        }
        return getCurrentProductType() === 'variable';
    }

    function isSupportedProductType() {
        const t = getCurrentProductType();
        return t === 'simple' || t === 'variable';
    }

    /**
     * Layer manager: one place for every modal-like surface (fullscreen editor, drawer, dialogs).
     * Moves focus in on open, traps Tab, closes the top layer on Escape and returns focus to the trigger.
     */
    const layers = {
        stack: [],
        focusable: 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',

        visibleFocusables($el) {
            return $el.find(this.focusable).filter(':visible').toArray();
        },

        open($el, opts) {
            if (!$el.length) return;
            opts = opts || {};
            this.stack = this.stack.filter(l => l.$el[0] !== $el[0]);
            this.stack.push({ $el: $el, trigger: document.activeElement, onClose: opts.onClose || null });

            if ($el.hasClass('wpe-modal-backdrop')) {
                const title = $.trim($el.find('.wpe-modal-title').first().text());
                $el.attr({ role: 'dialog', 'aria-modal': 'true' });
                if (title && !$el.attr('aria-label')) $el.attr('aria-label', title);
            }

            setTimeout(() => {
                const target = this.visibleFocusables($el)[0];
                if (target) {
                    target.focus();
                } else {
                    $el.attr('tabindex', '-1')[0].focus();
                }
            }, 40);
        },

        hide($el) {
            if ($el.hasClass('wpe-modal-backdrop')) {
                $el.remove();
            } else {
                $el.fadeOut(150);
            }
        },

        close($el) {
            const i = this.stack.findIndex(l => l.$el[0] === $el[0]);
            if (i < 0) {
                this.hide($el);
                return;
            }
            const layer = this.stack.splice(i, 1)[0];
            if (layer.onClose) {
                layer.onClose();
            } else {
                this.hide(layer.$el);
            }
            if (layer.trigger && document.contains(layer.trigger) && typeof layer.trigger.focus === 'function') {
                layer.trigger.focus();
            }
        },

        top() {
            this.stack = this.stack.filter(l => document.contains(l.$el[0]) && l.$el.is(':visible'));
            return this.stack.length ? this.stack[this.stack.length - 1] : null;
        },

        /** Re-renders replace the DOM under the user; put focus back inside the top layer. */
        keepFocus() {
            const top = this.top();
            if (top && !top.$el[0].contains(document.activeElement)) {
                const target = this.visibleFocusables(top.$el)[0];
                if (target) target.focus();
            }
        },

        onKeydown(e) {
            const top = this.top();
            if (!top) return;
            if (e.key === 'Escape') {
                if ($(e.target).closest('.media-modal').length) return;
                e.preventDefault();
                e.stopPropagation();
                this.close(top.$el);
                return;
            }
            if (e.key === 'Tab') {
                const items = this.visibleFocusables(top.$el);
                if (!items.length) {
                    e.preventDefault();
                    return;
                }
                const first = items[0];
                const last = items[items.length - 1];
                const active = document.activeElement;
                if (!top.$el[0].contains(active)) {
                    e.preventDefault();
                    first.focus();
                } else if (e.shiftKey && active === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && active === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }
    };

    function openModalHtml(modalHtml) {
        const $modal = $(modalHtml);
        const modalId = $modal.attr('id');
        if (modalId) {
            $('#' + modalId).remove();
        }
        $('body').append($modal);
        layers.open($modal, {
            onClose: function () {
                $modal.fadeOut(150, function () {
                    $modal.remove();
                });
            }
        });
        return $modal;
    }

    /**
     * Toast notifications
     */
    function showToast(message, type = 'success') {
        $('.wpe-toast').remove();
        const $toast = $(`<div class="wpe-toast ${type}" role="status"><span class="dashicons dashicons-${type === 'success' ? 'yes' : 'warning'}"></span><span>${escapeHtml(message)}</span></div>`);
        $('body').append($toast);
        setTimeout(() => $toast.fadeOut(300, function () { $(this).remove(); }), 3500);
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatNumberForInput(val) {
        if (val === null || val === undefined || val === '') return '';
        const num = parseFloat(val);
        return isNaN(num) ? String(val) : String(num);
    }

    /**
     * Normalize server media_library payload into wpeVariantStore.mediaLibrary
     */
    function normalizeMediaLibrary(raw) {
        if (!raw || typeof raw !== 'object') {
            return { version: '1', featured_asset_id: null, featured: null, gallery: [], assets: [] };
        }
        const libVersion = raw.version ? String(raw.version) : '1';
        const assetsMap = new Map();
        let featuredAssetId = raw.featured_asset_id ? parseInt(raw.featured_asset_id, 10) : null;
        const galleryItems = [];

        // Support canonical ProductMediaLibrary::read() shape: { version, items: [...] }
        if (Array.isArray(raw.items)) {
            raw.items.forEach(item => {
                const aid = parseInt(item.asset_id || item.id, 10);
                if (!aid) return;
                const role = item.role || '';
                const isPending = role === 'pending_link' || !!item.pending_link;
                const existing = assetsMap.get(aid);
                if (!existing) {
                    assetsMap.set(aid, {
                        id: aid,
                        asset_id: aid,
                        url: item.url || '',
                        origin: item.origin || 'managed',
                        storage_provider: item.storage_provider || null,
                        mime: item.mime || 'image/jpeg',
                        width: item.width || null,
                        height: item.height || null,
                        alt_text: item.alt_text || '',
                        alt_override: item.alt_override !== undefined ? item.alt_override : null,
                        roles: role ? [role] : [],
                        variant_ids: Array.isArray(item.used_by_variants) ? item.used_by_variants.map(v => parseInt(v, 10)) : [],
                        pending_link: isPending
                    });
                } else {
                    if (role && existing.roles.indexOf(role) === -1) existing.roles.push(role);
                    if (item.alt_override !== undefined && item.alt_override !== null) {
                        existing.alt_override = item.alt_override;
                    }
                    if (!isPending) existing.pending_link = false;
                }
                if (role === 'featured') {
                    featuredAssetId = aid;
                } else if (role === 'gallery') {
                    galleryItems.push({
                        asset_id: aid,
                        position: item.position || 0,
                        alt_override: item.alt_override !== undefined ? item.alt_override : null
                    });
                }
            });
        }

        (raw.assets || []).forEach(a => {
            const aid = parseInt(a.asset_id || a.id, 10);
            if (!aid) return;
            assetsMap.set(aid, {
                id: aid,
                asset_id: aid,
                url: a.url || '',
                origin: a.origin || 'managed',
                storage_provider: a.storage_provider || null,
                mime: a.mime || 'image/jpeg',
                width: a.width || null,
                height: a.height || null,
                alt_text: a.alt_text || '',
                alt_override: a.alt_override !== undefined ? a.alt_override : null,
                roles: Array.isArray(a.roles) ? a.roles.slice() : [],
                variant_ids: Array.isArray(a.variant_ids) ? a.variant_ids.map(v => parseInt(v, 10)) : [],
                pending_link: !!a.pending_link
            });
        });

        if (!featuredAssetId && raw.featured && (raw.featured.asset_id || raw.featured.id)) {
            featuredAssetId = parseInt(raw.featured.asset_id || raw.featured.id, 10);
        }
        if (featuredAssetId && !assetsMap.has(featuredAssetId) && raw.featured && raw.featured.url) {
            assetsMap.set(featuredAssetId, {
                id: featuredAssetId,
                asset_id: featuredAssetId,
                url: raw.featured.url,
                origin: raw.featured.origin || 'managed',
                storage_provider: raw.featured.storage_provider || null,
                mime: raw.featured.mime || 'image/jpeg',
                width: raw.featured.width || null,
                height: raw.featured.height || null,
                alt_text: raw.featured.alt_text || '',
                alt_override: raw.featured.alt_override || null,
                roles: ['featured'],
                variant_ids: [],
                pending_link: false
            });
        }

        const gallery = [];
        const seenGallery = new Set();
        const rawGallery = Array.isArray(raw.gallery) && raw.gallery.length ? raw.gallery : galleryItems;
        rawGallery
            .slice()
            .sort((a, b) => (a.position || 0) - (b.position || 0))
            .forEach(g => {
                const aid = parseInt(g.asset_id || g.id, 10);
                if (!aid || aid === featuredAssetId || seenGallery.has(aid)) return;
                seenGallery.add(aid);
                gallery.push({
                    asset_id: aid,
                    position: gallery.length,
                    alt_override: g.alt_override !== undefined ? g.alt_override : null
                });
                if (!assetsMap.has(aid) && g.url) {
                    assetsMap.set(aid, {
                        id: aid,
                        asset_id: aid,
                        url: g.url,
                        origin: g.origin || 'managed',
                        storage_provider: g.storage_provider || null,
                        mime: g.mime || 'image/jpeg',
                        width: g.width || null,
                        height: g.height || null,
                        alt_text: g.alt_text || '',
                        alt_override: g.alt_override || null,
                        roles: ['gallery'],
                        variant_ids: [],
                        pending_link: false
                    });
                }
            });

        return {
            version: libVersion,
            featured_asset_id: featuredAssetId || null,
            featured: featuredAssetId ? (assetsMap.get(featuredAssetId) || null) : null,
            gallery: gallery,
            assets: Array.from(assetsMap.values())
        };
    }

    /**
     * Return all product library assets in display order:
     * 1. Featured asset first
     * 2. Gallery assets in position order
     * 3. Any remaining linked/pending assets (e.g. variant-only or pending link)
     */
    function getOrderedProductAssets() {
        const lib = wpeVariantStore.mediaLibrary || { featured_asset_id: null, gallery: [], assets: [] };
        const byId = new Map();
        (lib.assets || []).forEach(a => {
            const aid = parseInt(a.asset_id || a.id, 10);
            if (aid) byId.set(aid, a);
        });

        const ordered = [];
        const visited = new Set();
        const featId = lib.featured_asset_id ? parseInt(lib.featured_asset_id, 10) : null;

        if (featId && byId.has(featId)) {
            ordered.push(Object.assign({}, byId.get(featId), { isFeatured: true, galleryIndex: -1 }));
            visited.add(featId);
        }

        (lib.gallery || []).forEach((g, idx) => {
            const aid = parseInt(g.asset_id || g.id, 10);
            if (!aid || visited.has(aid) || !byId.has(aid)) return;
            const asset = byId.get(aid);
            ordered.push(Object.assign({}, asset, {
                isFeatured: false,
                galleryIndex: idx,
                alt_override: g.alt_override !== undefined && g.alt_override !== null ? g.alt_override : asset.alt_override
            }));
            visited.add(aid);
        });

        byId.forEach((asset, aid) => {
            if (!visited.has(aid)) {
                ordered.push(Object.assign({}, asset, { isFeatured: false, galleryIndex: -1 }));
                visited.add(aid);
            }
        });

        // Compute live variant usage count from current variations store
        return ordered.map(item => {
            const matchingVars = (wpeVariantStore.variations || []).filter(v => {
                const vidAsset = v.image_asset_id ? parseInt(v.image_asset_id, 10) : 0;
                if (vidAsset > 0) return vidAsset === item.asset_id;
                return item.url && v.image_url === item.url;
            });
            item.liveVariantIds = matchingVars.map(v => v.id);
            item.liveVariantCount = matchingVars.length;
            return item;
        });
    }

    function upsertLocalLibraryAsset(assetData, linkToProduct = true) {
        const aid = parseInt(assetData.asset_id || assetData.id, 10);
        if (!aid) return null;
        const lib = wpeVariantStore.mediaLibrary;
        let existing = (lib.assets || []).find(a => parseInt(a.asset_id || a.id, 10) === aid);
        if (!existing) {
            existing = {
                id: aid,
                asset_id: aid,
                url: assetData.url || '',
                origin: assetData.origin || 'managed',
                storage_provider: assetData.storage_provider || null,
                mime: assetData.mime || 'image/jpeg',
                width: assetData.width || null,
                height: assetData.height || null,
                alt_text: assetData.alt_text || '',
                alt_override: null,
                roles: [],
                variant_ids: [],
                pending_link: !!assetData.pending_link
            };
            lib.assets.push(existing);
        } else {
            Object.assign(existing, {
                url: assetData.url || existing.url,
                origin: assetData.origin || existing.origin,
                storage_provider: assetData.storage_provider || existing.storage_provider,
                alt_text: assetData.alt_text !== undefined ? assetData.alt_text : existing.alt_text,
                pending_link: !!assetData.pending_link
            });
        }

        if (linkToProduct) {
            if (!lib.featured_asset_id) {
                lib.featured_asset_id = aid;
                lib.featured = existing;
            } else if (parseInt(lib.featured_asset_id, 10) !== aid && !lib.gallery.some(g => parseInt(g.asset_id, 10) === aid)) {
                lib.gallery.push({
                    asset_id: aid,
                    position: lib.gallery.length,
                    alt_override: null
                });
            }
        }
        return existing;
    }

    /**
     * Link a newly uploaded/registered asset to the product on the server via PATCH /media
     * so pending_link is cleared immediately and version stays in sync.
     */
    function linkAssetToProductImmediate(assetData, onDone) {
        const asset = upsertLocalLibraryAsset(assetData, true);
        if (!asset) {
            if (onDone) onDone(null);
            return;
        }
        const lib = wpeVariantStore.mediaLibrary;
        const patchPayload = {
            collection_mode: 'merge',
            featured_asset_id: lib.featured_asset_id ? String(lib.featured_asset_id) : null,
            gallery: (lib.gallery || []).map((g, idx) => ({
                asset_id: String(g.asset_id),
                position: idx,
                ...(g.alt_override ? { alt_override: g.alt_override } : {})
            }))
        };

        api.patchMedia(patchPayload)
            .done(function (res) {
                asset.pending_link = false;
                if (res && res.version && wpeVariantStore.mediaLibrary) {
                    wpeVariantStore.mediaLibrary.version = String(res.version);
                }
                wpeVariantStore.notify();
                if (onDone) onDone(asset);
            })
            .fail(function () {
                // Keep in draft so unified Save Changes / Update persists it
                asset.pending_link = true;
                wpeVariantStore.markMediaDirty();
                if (onDone) onDone(asset);
            });
    }

    /**
     * Ensure the Product Media container exists in DOM (supports both metabox and blocks mode)
     * and toggle native WP image boxes (#postimagediv, #woocommerce-product-images) based on product type.
     */
    function ensureProductMediaBox() {
        if (!$('#wpe_product_media').length) {
            const $targetBox = $('#wpe_product_info').length
                ? $('#wpe_product_info')
                : $('#' + (config.variantsBoxId || config.boxId));
            const boxHtml = `
                <div id="wpe_product_media" class="postbox">
                    <div class="postbox-header">
                        <h2 class="hndle ui-sortable-handle">Product Media</h2>
                    </div>
                    <div class="inside">
                        <div id="wpe-product-media-app" class="wpe-editor-root"></div>
                    </div>
                </div>
            `;
            if ($targetBox.length) {
                $targetBox.before(boxHtml);
            } else if ($('#woocommerce-product-data').length) {
                $('#woocommerce-product-data').after(boxHtml);
            }
        }
        const supported = isSupportedProductType();
        $('#wpe_product_media').toggleClass('wpe-box-hidden', !supported);
        $('#postimagediv, #woocommerce-product-images').toggleClass('wpe-box-hidden', supported);
    }

    function getOriginBadgeLabel(asset) {
        if (asset.origin === 'external') return 'CDN';
        if (asset.origin === 'wp_attachment') return 'WP';
        if (asset.storage_provider === 's3') return 'S3';
        return 'Local';
    }

    const INLINE_MEDIA_MAX_SLOTS = 10; // 2 rows x 5 columns

    function buildMediaCardHtml(asset, idx, totalCount) {
        const isFeat = !!asset.isFeatured;
        const altVal = asset.alt_override !== null && asset.alt_override !== undefined && asset.alt_override !== ''
            ? asset.alt_override
            : (asset.alt_text || '');
        const originLabel = getOriginBadgeLabel(asset);
        const canMovePrev = idx > 0;
        const canMoveNext = idx < totalCount - 1;

        return `
            <div class="wpe-media-card ${isFeat ? 'is-featured' : ''}" data-asset-id="${asset.asset_id}" data-order-idx="${idx}" draggable="true">
                <div class="wpe-media-thumb-wrap">
                    <img src="${escapeHtml(asset.url)}" alt="${escapeHtml(altVal)}" loading="lazy" />
                    <div class="wpe-media-badges-top">
                        <div>
                            ${isFeat ? '<span class="wpe-media-badge featured"><span class="dashicons dashicons-star-filled" style="font-size:11px; width:11px; height:11px;"></span> Featured</span>' : ''}
                            ${asset.pending_link ? '<span class="wpe-media-badge pending" title="Uploaded in this session; save to finalize link">Pending</span>' : ''}
                        </div>
                        <span class="wpe-media-badge origin">${escapeHtml(originLabel)}</span>
                    </div>
                    ${asset.liveVariantCount > 0 ? `
                        <span class="wpe-media-badge usage">${asset.liveVariantCount} variant${asset.liveVariantCount > 1 ? 's' : ''}</span>
                    ` : ''}
                    <div class="wpe-media-hover-actions">
                        ${!isFeat ? `
                            <button type="button" class="button button-small button-primary wpe-btn-media-set-featured" data-asset-id="${asset.asset_id}">
                                Set as Featured
                            </button>
                        ` : (totalCount > 1 ? `
                            <button type="button" class="button button-small wpe-btn-media-demote-featured" data-asset-id="${asset.asset_id}">
                                Move to Gallery
                            </button>
                        ` : '')}
                    </div>
                </div>
                <div class="wpe-media-card-body">
                    <input type="text" class="wpe-media-alt-input" data-asset-id="${asset.asset_id}" value="${escapeHtml(altVal)}" placeholder="Alt text..." title="Image alt text" />
                    <div class="wpe-media-card-footer">
                        <div class="wpe-media-reorder-btns">
                            <button type="button" class="button wpe-btn-media-move wpe-btn-media-move-prev" data-order-idx="${idx}" ${!canMovePrev ? 'disabled' : ''} title="Move left">&lsaquo;</button>
                            <button type="button" class="button wpe-btn-media-move wpe-btn-media-move-next" data-order-idx="${idx}" ${!canMoveNext ? 'disabled' : ''} title="Move right">&rsaquo;</button>
                        </div>
                        <button type="button" class="button-link-delete wpe-btn-media-unlink" data-asset-id="${asset.asset_id}" title="Remove image from product">
                            <span class="dashicons dashicons-trash" style="font-size:15px; width:15px; height:15px;"></span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    function buildUploadQueueHtml(queue) {
        if (!queue || queue.length === 0) return '';
        return `<div class="wpe-upload-queue-list">` + queue.map(item => `
            <div class="wpe-upload-queue-item ${item.status === 'error' ? 'is-error' : ''}" data-queue-id="${escapeHtml(item.id)}">
                <div style="display:flex; align-items:center; gap:8px;">
                    ${item.status === 'uploading'
                        ? '<span class="spinner is-active" style="float:none; margin:0;"></span>'
                        : '<span class="dashicons dashicons-warning" style="color:#dc2626;"></span>'}
                    <strong>${escapeHtml(item.fileName)}</strong>
                    <span>${item.status === 'uploading' ? 'Uploading...' : escapeHtml(item.errorMessage || 'Upload failed')}</span>
                </div>
                <div style="display:flex; gap:6px;">
                    ${item.status === 'error' ? `
                        <button type="button" class="button button-small wpe-btn-retry-upload" data-queue-id="${escapeHtml(item.id)}">Retry</button>
                        <button type="button" class="button button-small wpe-btn-dismiss-upload" data-queue-id="${escapeHtml(item.id)}">Dismiss</button>
                    ` : ''}
                </div>
            </div>
        `).join('') + `</div>`;
    }

    /**
     * Render the Per-Product Media Library Section (P011.9)
     * Inline view displays at most 2 rows (10 slots on desktop: 9 cards + 1 "+X View All" tile when > 10 images).
     */
    function renderProductMediaApp(targetSelector) {
        ensureProductMediaBox();
        const $container = $(targetSelector || '#wpe-product-media-app');
        if (!$container.length) return;

        if (!isSupportedProductType()) {
            $container.empty();
            return;
        }

        if (wpeVariantStore.isLoading && !wpeVariantStore.mediaLoaded) {
            $container.html(`
                <div class="wpe-editor-loading">
                    <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
                    <span>Loading product media library...</span>
                </div>
            `);
            return;
        }

        const orderedAssets = getOrderedProductAssets();
        const queue = wpeVariantStore.uploadQueue || [];
        const viewAllLabel = (config.i18n && config.i18n.viewAllMedia) || 'View All';

        let cardsHtml = '';
        if (orderedAssets.length === 0) {
            cardsHtml = `
                <div style="grid-column: 1 / -1; text-align:center; padding:24px 16px; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px;">
                    <span class="dashicons dashicons-format-gallery" style="font-size:28px; width:28px; height:28px; color:#94a3b8; margin-bottom:6px;"></span>
                    <div style="font-weight:600; color:#334155;">No images in this product's media library yet</div>
                    <div style="font-size:12px; margin-top:2px;">Upload images from your computer or add via external CDN URL below. Images belong exclusively to this product.</div>
                </div>
            `;
        } else if (orderedAssets.length <= INLINE_MEDIA_MAX_SLOTS) {
            orderedAssets.forEach((asset, idx) => {
                cardsHtml += buildMediaCardHtml(asset, idx, orderedAssets.length);
            });
        } else {
            const visibleCount = INLINE_MEDIA_MAX_SLOTS - 1; // 9 cards + 1 "+X View All" card = 10 slots (2 rows)
            const hiddenCount = orderedAssets.length - visibleCount;
            const tenthAsset = orderedAssets[visibleCount];

            orderedAssets.slice(0, visibleCount).forEach((asset, idx) => {
                cardsHtml += buildMediaCardHtml(asset, idx, orderedAssets.length);
            });

            cardsHtml += `
                <button type="button" class="wpe-media-more-card wpe-btn-open-full-media" title="View all ${orderedAssets.length} product images">
                    ${tenthAsset && tenthAsset.url ? `<img class="wpe-media-more-card-bg" src="${escapeHtml(tenthAsset.url)}" alt="" loading="lazy" />` : ''}
                    <div class="wpe-media-more-card-content">
                        <span class="wpe-media-more-count">+${hiddenCount}</span>
                        <span class="wpe-media-more-label">${escapeHtml(viewAllLabel)}</span>
                        <span class="wpe-media-more-sub">Show all ${orderedAssets.length} images</span>
                    </div>
                </button>
            `;
        }

        const queueHtml = buildUploadQueueHtml(queue);
        const isCompactDropzone = orderedAssets.length > 0;

        const html = `
            <div class="wpe-editor-wrap wpe-editor-wrap--media">
                <div class="wpe-section wpe-section--flat">
                    <div class="wpe-section-header">
                        <div class="wpe-section-title-wrap">
                            <span class="dashicons dashicons-images-alt2" style="color:#008060;"></span>
                            <h4 class="wpe-section-title">Product Media Library</h4>
                            <span class="wpe-section-count">${orderedAssets.length}</span>
                            <span class="wpe-section-subtitle">(Product-scoped &bull; Max 2 rows inline &bull; First image is Featured)</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            ${orderedAssets.length > 0 ? `
                                <button type="button" class="button wpe-btn-open-full-media">
                                    <span class="dashicons dashicons-grid-view"></span> ${escapeHtml(viewAllLabel)} (${orderedAssets.length})
                                </button>
                            ` : ''}
                            <button type="button" class="button wpe-btn-trigger-media-upload">
                                <span class="dashicons dashicons-upload"></span> Upload Images
                            </button>
                            ${wpeVariantStore.isDirty ? `
                                <button type="button" class="button button-primary wpe-btn-save-media-direct">
                                    <span class="dashicons dashicons-saved"></span> Save Media
                                </button>
                            ` : ''}
                        </div>
                    </div>
                    <div class="wpe-section-body">
                        ${queueHtml}
                        <div class="wpe-media-grid wpe-media-grid--inline">
                            ${cardsHtml}
                        </div>

                        <div class="wpe-media-upload-zone ${isCompactDropzone ? 'wpe-media-upload-zone--compact' : ''} wpe-media-dropzone" id="wpe-media-dropzone">
                            <input type="file" id="wpe-media-file-input" class="wpe-media-file-input" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/avif" style="display:none;" />
                            <span class="dashicons dashicons-cloud-upload" style="font-size:${isCompactDropzone ? '20px' : '24px'}; width:${isCompactDropzone ? '20px' : '24px'}; height:${isCompactDropzone ? '20px' : '24px'}; color:#2563eb;"></span>
                            <div>
                                <span style="font-weight:600; font-size:12.5px; color:#1e293b;">Drop product images here or click to browse</span>
                                <span style="font-size:11.5px; color:#64748b; margin-left:6px;">(JPG, PNG, WebP, GIF, AVIF &bull; Product-isolated library)</span>
                            </div>
                        </div>

                        <div class="wpe-media-url-bar">
                            <span class="dashicons dashicons-admin-links" style="color:#64748b;"></span>
                            <input type="url" id="wpe-media-ext-url" class="wpe-media-ext-url-input" placeholder="Add external image via HTTPS URL (e.g. https://cdn.example.com/photo.jpg)..." />
                            <input type="text" id="wpe-media-ext-alt" class="wpe-media-ext-alt-input" placeholder="Alt text (optional)" style="max-width:190px;" />
                            <button type="button" class="button wpe-btn-add-media-url">Add URL</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $container.html(html);
        bindProductMediaEvents($container);

        if ($('#wpe-product-media-full-modal').length) {
            renderFullProductMediaModalBody();
        }
    }

    /**
     * Open Full Product Media Library Modal (displays all product images in a scrollable grid)
     */
    function openFullProductMediaModal() {
        if ($('#wpe-product-media-full-modal').length) {
            renderFullProductMediaModalBody();
            return;
        }

        const orderedAssets = getOrderedProductAssets();
        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-product-media-full-modal">
                <div class="wpe-modal-dialog wpe-modal-dialog-xl">
                    <div class="wpe-modal-header">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="dashicons dashicons-images-alt2" style="color:#008060;"></span>
                            <h4 class="wpe-modal-title">Product Media Library &mdash; All Images (<span id="wpe-full-media-count">${orderedAssets.length}</span>)</h4>
                            <span style="font-size:12px; color:#64748b;">(Drag &amp; drop to reorder &bull; First image is Featured)</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button type="button" class="button wpe-btn-trigger-media-upload">
                                <span class="dashicons dashicons-upload"></span> Upload Images
                            </button>
                            <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px; text-decoration:none;">&times;</button>
                        </div>
                    </div>
                    <div class="wpe-modal-body" id="wpe-product-media-full-body"></div>
                    <div class="wpe-modal-footer" id="wpe-product-media-full-footer"></div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);
        renderFullProductMediaModalBody();
    }

    function renderFullProductMediaModalBody() {
        const $modal = $('#wpe-product-media-full-modal');
        if (!$modal.length) return;

        const orderedAssets = getOrderedProductAssets();
        const queue = wpeVariantStore.uploadQueue || [];

        $modal.find('#wpe-full-media-count').text(orderedAssets.length);

        let allCardsHtml = '';
        if (orderedAssets.length === 0) {
            allCardsHtml = `
                <div style="grid-column: 1 / -1; text-align:center; padding:24px 16px; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px;">
                    <span class="dashicons dashicons-format-gallery" style="font-size:28px; width:28px; height:28px; color:#94a3b8; margin-bottom:6px;"></span>
                    <div style="font-weight:600; color:#334155;">No images in this product's media library yet</div>
                </div>
            `;
        } else {
            orderedAssets.forEach((asset, idx) => {
                allCardsHtml += buildMediaCardHtml(asset, idx, orderedAssets.length);
            });
        }

        const queueHtml = buildUploadQueueHtml(queue);

        $modal.find('#wpe-product-media-full-body').html(`
            ${queueHtml}
            <div class="wpe-media-grid wpe-media-grid--full">
                ${allCardsHtml}
            </div>
            <div class="wpe-media-upload-zone wpe-media-upload-zone--compact wpe-media-dropzone">
                <input type="file" class="wpe-media-file-input" multiple accept="image/jpeg,image/png,image/webp,image/gif,image/avif" style="display:none;" />
                <span class="dashicons dashicons-cloud-upload" style="font-size:20px; width:20px; height:20px; color:#2563eb;"></span>
                <div style="font-weight:600; font-size:12.5px; color:#1e293b;">Drop product images here or click to upload more</div>
            </div>
            <div class="wpe-media-url-bar">
                <span class="dashicons dashicons-admin-links" style="color:#64748b;"></span>
                <input type="url" class="wpe-media-ext-url-input" placeholder="Add external image via HTTPS URL (e.g. https://cdn.example.com/photo.jpg)..." />
                <input type="text" class="wpe-media-ext-alt-input" placeholder="Alt text (optional)" style="max-width:190px;" />
                <button type="button" class="button wpe-btn-add-media-url">Add URL</button>
            </div>
        `);

        $modal.find('#wpe-product-media-full-footer').html(`
            <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                <span style="font-size:12px; color:#64748b;">Showing all ${orderedAssets.length} product images</span>
                <div style="display:flex; gap:8px;">
                    ${wpeVariantStore.isDirty ? `
                        <button type="button" class="button button-primary wpe-btn-save-media-direct">
                            <span class="dashicons dashicons-saved"></span> Save Media
                        </button>
                    ` : ''}
                    <button type="button" class="button wpe-btn-close-modal">Close</button>
                </div>
            </div>
        `);

        bindProductMediaEvents($modal);
    }

    /**
     * Reorder ordered product assets from oldIdx to newIdx.
     * Index 0 becomes the Featured image; indices 1..N become Gallery positions 0..N-1.
     */
    function reorderProductAssets(oldIdx, newIdx) {
        const ordered = getOrderedProductAssets();
        if (oldIdx < 0 || newIdx < 0 || oldIdx >= ordered.length || newIdx >= ordered.length || oldIdx === newIdx) {
            return;
        }
        const moved = ordered.splice(oldIdx, 1)[0];
        ordered.splice(newIdx, 0, moved);

        const lib = wpeVariantStore.mediaLibrary;
        const first = ordered[0];
        lib.featured_asset_id = first ? first.asset_id : null;
        lib.featured = first || null;
        lib.gallery = ordered.slice(1).map((item, idx) => ({
            asset_id: item.asset_id,
            position: idx,
            alt_override: item.alt_override !== undefined ? item.alt_override : null
        }));
        wpeVariantStore.markMediaDirty();
    }

    function enqueueAndUploadFiles(fileList, onEachUploaded) {
        const files = Array.from(fileList || []);
        if (!files.length) return;

        files.forEach(file => {
            const qItem = {
                id: 'q-' + Math.random().toString(36).slice(2, 10),
                file: file,
                fileName: file.name || 'image.jpg',
                uploadKey: generateIdempotencyKey('upload'),
                status: 'uploading',
                errorMessage: ''
            };
            wpeVariantStore.uploadQueue.push(qItem);
            renderProductMediaApp();
            runUploadQueueItem(qItem, onEachUploaded);
        });
    }

    function runUploadQueueItem(qItem, onUploaded) {
        qItem.status = 'uploading';
        qItem.errorMessage = '';
        renderProductMediaApp();

        api.uploadAssetFile(qItem.file, qItem.uploadKey, '')
            .done(function (res) {
                wpeVariantStore.uploadQueue = wpeVariantStore.uploadQueue.filter(i => i.id !== qItem.id);
                const draftAsset = upsertLocalLibraryAsset(Object.assign({}, res, { pending_link: true }), true);
                wpeVariantStore.markMediaDirty();
                showToast(`Uploaded "${qItem.fileName}" to product draft! Click Save to finalize.`, 'success');
                if (onUploaded && draftAsset) {
                    onUploaded(draftAsset);
                }
            })
            .fail(function (xhr) {
                const err = (xhr && xhr.responseJSON) || {};
                qItem.status = 'error';
                qItem.errorMessage = err.message || 'Upload failed. Click Retry to resume with same idempotency key.';
                renderProductMediaApp();
            });
    }

    function bindProductMediaEvents($container) {
        let dragSrcIdx = null;

        // Open full media library popup
        $container.find('.wpe-btn-open-full-media').off('click').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            openFullProductMediaModal();
        });

        // Trigger file input
        $container.find('.wpe-btn-trigger-media-upload, .wpe-media-dropzone').off('click').on('click', function (e) {
            if ($(e.target).is('.wpe-media-file-input')) return;
            $container.find('.wpe-media-file-input').first().trigger('click');
        });

        $container.find('.wpe-media-file-input').off('change').on('change', function () {
            if (this.files && this.files.length) {
                enqueueAndUploadFiles(this.files);
                $(this).val('');
            }
        });

        // Drag & drop files on upload zone
        $container.find('.wpe-media-dropzone').off('dragover dragenter dragleave drop')
            .on('dragover dragenter', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('is-dragover');
            })
            .on('dragleave', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('is-dragover');
            })
            .on('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('is-dragover');
                const dt = e.originalEvent && e.originalEvent.dataTransfer;
                if (dt && dt.files && dt.files.length) {
                    enqueueAndUploadFiles(dt.files);
                }
            });

        // Retry / Dismiss failed upload
        $container.find('.wpe-btn-retry-upload').off('click').on('click', function () {
            const qId = $(this).attr('data-queue-id');
            const qItem = (wpeVariantStore.uploadQueue || []).find(i => i.id === qId);
            if (qItem) {
                runUploadQueueItem(qItem);
            }
        });

        $container.find('.wpe-btn-dismiss-upload').off('click').on('click', function () {
            const qId = $(this).attr('data-queue-id');
            wpeVariantStore.uploadQueue = (wpeVariantStore.uploadQueue || []).filter(i => i.id !== qId);
            renderProductMediaApp();
        });

        // Add external CDN URL
        $container.find('.wpe-btn-add-media-url').off('click').on('click', function () {
            const $btn = $(this);
            const $urlInput = $container.find('.wpe-media-ext-url-input').first();
            const $altInput = $container.find('.wpe-media-ext-alt-input').first();
            const extUrl = ($urlInput.val() || '').trim();
            const extAlt = ($altInput.val() || '').trim();
            if (!extUrl) {
                showToast('Please enter a valid HTTPS image URL.', 'warning');
                return;
            }
            $btn.prop('disabled', true).text('Adding...');
            api.registerExternalAsset(extUrl, extAlt)
                .done(function (res) {
                    $urlInput.val('');
                    $altInput.val('');
                    upsertLocalLibraryAsset(Object.assign({}, res, { pending_link: true }), true);
                    wpeVariantStore.markMediaDirty();
                    showToast('Added external image to product draft! Click Save to finalize.', 'success');
                })
                .fail(function (xhr) {
                    const err = (xhr && xhr.responseJSON) || {};
                    showToast(err.message || 'Failed to add external URL.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).text('Add URL');
                });
        });

        // Set as Featured
        $container.find('.wpe-btn-media-set-featured').off('click').on('click', function (e) {
            e.stopPropagation();
            const targetId = parseInt($(this).attr('data-asset-id'), 10);
            if (!targetId) return;
            const lib = wpeVariantStore.mediaLibrary;
            const oldFeatId = lib.featured_asset_id ? parseInt(lib.featured_asset_id, 10) : null;
            if (oldFeatId === targetId) return;

            // Remove targetId from gallery
            lib.gallery = (lib.gallery || []).filter(g => parseInt(g.asset_id, 10) !== targetId);
            // Move previous featured to front of gallery
            if (oldFeatId && !lib.gallery.some(g => parseInt(g.asset_id, 10) === oldFeatId)) {
                lib.gallery.unshift({ asset_id: oldFeatId, position: 0, alt_override: null });
            }
            lib.gallery.forEach((g, i) => { g.position = i; });
            lib.featured_asset_id = targetId;
            lib.featured = (lib.assets || []).find(a => parseInt(a.asset_id || a.id, 10) === targetId) || null;
            wpeVariantStore.markMediaDirty();
        });

        // Demote Featured to Gallery (promotes first gallery item to Featured)
        $container.find('.wpe-btn-media-demote-featured').off('click').on('click', function (e) {
            e.stopPropagation();
            reorderProductAssets(0, 1);
        });

        // Reorder Left / Right buttons
        $container.find('.wpe-btn-media-move-prev').off('click').on('click', function (e) {
            e.stopPropagation();
            const idx = parseInt($(this).attr('data-order-idx'), 10);
            reorderProductAssets(idx, idx - 1);
        });

        $container.find('.wpe-btn-media-move-next').off('click').on('click', function (e) {
            e.stopPropagation();
            const idx = parseInt($(this).attr('data-order-idx'), 10);
            reorderProductAssets(idx, idx + 1);
        });

        // Drag and Drop Card Reorder
        $container.find('.wpe-media-card').off('dragstart dragover dragleave drop dragend')
            .on('dragstart', function (e) {
                dragSrcIdx = parseInt($(this).attr('data-order-idx'), 10);
                $(this).addClass('is-dragging');
                if (e.originalEvent && e.originalEvent.dataTransfer) {
                    e.originalEvent.dataTransfer.effectAllowed = 'move';
                    e.originalEvent.dataTransfer.setData('text/plain', String(dragSrcIdx));
                }
            })
            .on('dragover', function (e) {
                e.preventDefault();
                $(this).addClass('is-drag-over');
            })
            .on('dragleave', function () {
                $(this).removeClass('is-drag-over');
            })
            .on('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('is-drag-over');
                const targetIdx = parseInt($(this).attr('data-order-idx'), 10);
                if (dragSrcIdx !== null && !isNaN(targetIdx) && dragSrcIdx !== targetIdx) {
                    reorderProductAssets(dragSrcIdx, targetIdx);
                }
                dragSrcIdx = null;
            })
            .on('dragend', function () {
                $(this).removeClass('is-dragging is-drag-over');
                dragSrcIdx = null;
            });

        // Inline Alt Text edit (non-destructive)
        $container.find('.wpe-media-alt-input').off('input change').on('input change', function () {
            const aid = parseInt($(this).attr('data-asset-id'), 10);
            const val = $(this).val();
            const lib = wpeVariantStore.mediaLibrary;
            const asset = (lib.assets || []).find(a => parseInt(a.asset_id || a.id, 10) === aid);
            if (asset) {
                asset.alt_text = val;
                asset.alt_override = val;
            }
            const gItem = (lib.gallery || []).find(g => parseInt(g.asset_id, 10) === aid);
            if (gItem) {
                gItem.alt_override = val;
            }
            $('.wpe-media-alt-input[data-asset-id="' + aid + '"]').not(this).val(val);
            wpeVariantStore.isDirty = true;
            wpeVariantStore.mediaDirty = true;
            wpeVariantStore.syncHiddenPayload();
            updateBoxHeader();
            updateDirtyChrome();
        });

        // Unlink / Delete Image with Impact Preview
        $container.find('.wpe-btn-media-unlink').off('click').on('click', function (e) {
            e.stopPropagation();
            const aid = parseInt($(this).attr('data-asset-id'), 10);
            if (aid) {
                openUnlinkMediaModal(aid);
            }
        });

        // Direct Save Media button
        $container.find('.wpe-btn-save-media-direct').off('click').on('click', function () {
            const $mainSave = $('.wpe-btn-save-api:not(:disabled)').first();
            if ($mainSave.length && isVariableType()) {
                $mainSave.trigger('click');
                return;
            }
            const $btn = $(this);
            $btn.prop('disabled', true).text(config.i18n.saving || 'Saving...');
            const payload = buildSavePayload();
            api.saveData(payload)
                .done(function (res) {
                    if (res.success) {
                        if (res.result && res.result.data) {
                            applyServerData(res.result.data);
                        }
                        wpeVariantStore.isDirty = false;
                        wpeVariantStore.mediaDirty = false;
                        wpeVariantStore.notify();
                        showToast(config.i18n.saveSuccess || 'Saved successfully!', 'success');
                    } else {
                        showToast(res.message || 'Error saving media.', 'error');
                    }
                })
                .fail(function (xhr) {
                    const err = (xhr && xhr.responseJSON) || {};
                    showToast(err.message || 'Server error saving media.', 'error');
                })
                .always(function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Media');
                });
        });
    }

    /**
     * Safe Unlink & Physical File Delete Modal with Impact Preview (P011.9 / A4)
     */
    function openUnlinkMediaModal(assetId) {
        const ordered = getOrderedProductAssets();
        const asset = ordered.find(a => a.asset_id === assetId);
        if (!asset) return;

        const otherAssets = ordered.filter(a => a.asset_id !== assetId);

        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-media-unlink-modal">
                <div class="wpe-modal-dialog">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Remove Image from Product</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body" id="wpe-media-unlink-body">
                        <div style="text-align:center; padding:20px;">
                            <span class="spinner is-active" style="float:none;"></span>
                            <p>Checking image usage across product &amp; variations...</p>
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Cancel</button>
                        <button type="button" class="button button-primary wpe-btn-confirm-unlink-media" disabled>Remove Image</button>
                    </div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);

        api.previewUnlink([assetId])
            .always(function (resOrXhr) {
                const serverAffected = (resOrXhr && Array.isArray(resOrXhr.affected)) ? resOrXhr.affected : [];
                const serverEntry = serverAffected.find(e => parseInt(e.asset_id, 10) === assetId) || {};

                const isFeatured = asset.isFeatured || (Array.isArray(serverEntry.roles) && serverEntry.roles.indexOf('featured') !== -1);
                const localVars = (wpeVariantStore.variations || []).filter(v => {
                    const vidAsset = v.image_asset_id ? parseInt(v.image_asset_id, 10) : 0;
                    return vidAsset === assetId || (asset.url && v.image_url === asset.url);
                });
                const serverVarCount = Array.isArray(serverEntry.variant_ids) ? serverEntry.variant_ids.length : 0;
                const affectedCount = Math.max(localVars.length, serverVarCount);
                const requiresResolution = isFeatured || affectedCount > 0 || !!serverEntry.requires_resolution;

                let impactHtml = '';
                if (requiresResolution) {
                    const rolesText = [];
                    if (isFeatured) rolesText.push('<strong>Featured Product Image</strong>');
                    if (affectedCount > 0) rolesText.push(`<strong>${affectedCount} variation(s)</strong>`);

                    const replaceOptions = otherAssets.map(o => `
                        <option value="${o.asset_id}">Asset #${o.asset_id} ${o.alt_text ? '(' + escapeHtml(o.alt_text) + ')' : ''}</option>
                    `).join('');

                    impactHtml = `
                        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 14px; margin-bottom:14px; color:#92400e;">
                            <div style="font-weight:600; margin-bottom:4px;">Impact Preview: This image is currently in use</div>
                            <div>Assigned as: ${rolesText.join(' and ')}. Choose how to resolve before removing:</div>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:14px;">
                            ${otherAssets.length > 0 ? `
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:500;">
                                    <input type="radio" name="wpe_unlink_mode" value="replace" checked />
                                    <span>Replace with another image from this product:</span>
                                </label>
                                <div style="padding-left:24px;">
                                    <select id="wpe-unlink-replacement-select" style="width:100%;">
                                        ${replaceOptions}
                                    </select>
                                </div>
                            ` : ''}
                            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:500;">
                                <input type="radio" name="wpe_unlink_mode" value="fallback" ${otherAssets.length === 0 ? 'checked' : ''} />
                                <span>Clear assignment and fall back to no image / parent product image</span>
                            </label>
                        </div>
                    `;
                } else {
                    impactHtml = `
                        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; margin-bottom:14px; color:#166534;">
                            This gallery image is not used as Featured or by any variations and can be safely removed.
                        </div>
                    `;
                }

                const deleteFileHtml = asset.origin === 'managed' ? `
                    <div style="padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:500; color:#1e293b;">
                            <input type="checkbox" id="wpe-unlink-delete-file" checked />
                            <span>Also delete physical file from storage (${escapeHtml(getOriginBadgeLabel(asset))}) if no other references remain</span>
                        </label>
                    </div>
                ` : '';

                $('#wpe-media-unlink-body').html(`
                    <div style="display:flex; gap:14px; align-items:center; margin-bottom:14px;">
                        <img src="${escapeHtml(asset.url)}" alt="" style="width:68px; height:68px; object-fit:cover; border-radius:8px; border:1px solid #e2e8f0;" />
                        <div>
                            <div style="font-weight:600; font-size:14px;">Asset #${asset.asset_id} (${escapeHtml(getOriginBadgeLabel(asset))})</div>
                            <div style="font-size:12px; color:#64748b; word-break:break-all;">${escapeHtml(asset.url)}</div>
                        </div>
                    </div>
                    ${impactHtml}
                    ${deleteFileHtml}
                `);

                $('#wpe-media-unlink-modal .wpe-btn-confirm-unlink-media')
                    .prop('disabled', false)
                    .off('click')
                    .on('click', function () {
                        const $btn = $(this);
                        $btn.prop('disabled', true).text('Removing...');

                        const mode = $('input[name="wpe_unlink_mode"]:checked').val() || 'fallback';
                        const repId = mode === 'replace' ? parseInt($('#wpe-unlink-replacement-select').val(), 10) : null;
                        const deletePhysical = $('#wpe-unlink-delete-file').is(':checked');

                        const unlinkEntry = { asset_id: String(assetId) };
                        if (repId) {
                            unlinkEntry.replacement_asset_id = String(repId);
                        } else {
                            unlinkEntry.fallback = true;
                        }

                        if (asset.pending_link) {
                            applyLocalUnlinkResolution(assetId, repId);
                            const finishDraftUnlink = function (msg) {
                                layers.close($('#wpe-media-unlink-modal'));
                                showToast(msg, 'success');
                                wpeVariantStore.markDirty();
                            };
                            if (asset.origin === 'managed' && deletePhysical) {
                                api.deleteAssetFile(assetId).always(function () {
                                    finishDraftUnlink('Image removed from draft and physical file deleted!');
                                });
                            } else {
                                finishDraftUnlink('Image removed from draft.');
                            }
                            return;
                        }

                        api.patchMedia({
                            collection_mode: 'merge',
                            unlink: [unlinkEntry]
                        }).done(function (res) {
                            if (res && res.version && wpeVariantStore.mediaLibrary) {
                                wpeVariantStore.mediaLibrary.version = String(res.version);
                            }
                            applyLocalUnlinkResolution(assetId, repId);
                            if (asset.origin === 'managed' && deletePhysical) {
                                api.deleteAssetFile(assetId).always(function () {
                                    layers.close($('#wpe-media-unlink-modal'));
                                    showToast('Image unlinked and physical file deleted!', 'success');
                                    wpeVariantStore.notify();
                                });
                            } else {
                                layers.close($('#wpe-media-unlink-modal'));
                                showToast('Image removed from product.', 'success');
                                wpeVariantStore.notify();
                            }
                        }).fail(function () {
                            applyLocalUnlinkResolution(assetId, repId);
                            if (asset.origin === 'managed' && deletePhysical) {
                                api.deleteAssetFile(assetId);
                            }
                            layers.close($('#wpe-media-unlink-modal'));
                            showToast('Image removed from draft.', 'success');
                            wpeVariantStore.markDirty();
                        });
                    });
            });
    }

    function applyLocalUnlinkResolution(assetId, replacementAssetId) {
        const lib = wpeVariantStore.mediaLibrary;
        const repAsset = replacementAssetId
            ? (lib.assets || []).find(a => parseInt(a.asset_id || a.id, 10) === replacementAssetId)
            : null;

        if (parseInt(lib.featured_asset_id, 10) === assetId) {
            if (repAsset) {
                lib.featured_asset_id = repAsset.asset_id;
                lib.featured = repAsset;
                lib.gallery = (lib.gallery || []).filter(g => parseInt(g.asset_id, 10) !== repAsset.asset_id);
            } else if (lib.gallery && lib.gallery.length > 0) {
                const nextFeatId = parseInt(lib.gallery[0].asset_id, 10);
                lib.featured_asset_id = nextFeatId;
                lib.featured = (lib.assets || []).find(a => parseInt(a.asset_id || a.id, 10) === nextFeatId) || null;
                lib.gallery.shift();
            } else {
                lib.featured_asset_id = null;
                lib.featured = null;
            }
        }

        lib.gallery = (lib.gallery || [])
            .filter(g => parseInt(g.asset_id, 10) !== assetId)
            .map((g, idx) => Object.assign({}, g, { position: idx }));
        lib.assets = (lib.assets || []).filter(a => parseInt(a.asset_id || a.id, 10) !== assetId);

        (wpeVariantStore.variations || []).forEach(v => {
            const vidAsset = v.image_asset_id ? parseInt(v.image_asset_id, 10) : 0;
            if (vidAsset === assetId) {
                if (repAsset) {
                    v.image_asset_id = repAsset.asset_id;
                    v.image_url = repAsset.url;
                    v.attachment_id = null;
                } else {
                    v.image_asset_id = null;
                    v.image_url = null;
                    v.attachment_id = null;
                }
            }
        });
    }

    /**
     * Product-Scoped Variant Image Picker Modal (P011.10)
     * Replaces all wp.media calls for single variation, bulk selection, and option group image assignment.
     * Supports in-picker upload & external URL registration that auto-links to product and assigns in 1 step (A3).
     */
    function openProductVariantImagePicker(opts) {
        opts = opts || {};
        let mode = opts.mode || 'single'; // 'single' | 'bulk' | 'option'
        const singleVariantId = opts.variantId ? parseInt(opts.variantId, 10) : null;
        const singleVariant = singleVariantId ? wpeVariantStore.variations.find(v => v.id === singleVariantId) : null;
        const options = wpeVariantStore.variationOptions || [];

        let currentOptName = opts.optionName || (options[0] ? options[0].name : '');
        let currentOptVal = opts.optionValue || '';
        if (!currentOptVal && currentOptName) {
            const foundOpt = options.find(o => o.name === currentOptName);
            if (foundOpt && foundOpt.values && foundOpt.values.length) {
                const fv = foundOpt.values[0];
                currentOptVal = typeof fv === 'object' ? fv.label : fv;
            }
        }

        const orderedAssets = getOrderedProductAssets();
        let selectedAssetId = null;

        if (mode === 'single' && singleVariant) {
            if (singleVariant.image_asset_id) {
                selectedAssetId = parseInt(singleVariant.image_asset_id, 10);
            } else if (singleVariant.image_url) {
                const matched = orderedAssets.find(a => a.url === singleVariant.image_url);
                if (matched) selectedAssetId = matched.asset_id;
            }
        }
        if (!selectedAssetId && orderedAssets.length > 0) {
            selectedAssetId = orderedAssets[0].asset_id;
        }

        const optSelectHtml = options.map(o => `
            <option value="${escapeHtml(o.name)}" ${o.name === currentOptName ? 'selected' : ''}>${escapeHtml(o.name)}</option>
        `).join('');

        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-variant-image-picker-modal">
                <div class="wpe-modal-dialog wpe-modal-dialog-lg">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Choose Product Image for Variation(s)</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <!-- Assignment Scope Bar -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <span style="font-weight:600; font-size:12px; color:#334155;">Assign To:</span>
                                    ${singleVariant ? `
                                        <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer;">
                                            <input type="radio" name="wpe_picker_scope" value="single" ${mode === 'single' ? 'checked' : ''} />
                                            <span>Variant #${singleVariant.id} (${escapeHtml(singleVariant.sku || Object.values(singleVariant.attributes || singleVariant.combination || {}).join(' / '))})</span>
                                        </label>
                                    ` : ''}
                                    ${wpeVariantStore.selection.size > 0 || mode === 'bulk' ? `
                                        <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer;">
                                            <input type="radio" name="wpe_picker_scope" value="bulk" ${mode === 'bulk' ? 'checked' : ''} />
                                            <span>Selected Variants (${wpeVariantStore.selection.size})</span>
                                        </label>
                                    ` : ''}
                                    ${options.length > 0 ? `
                                        <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer;">
                                            <input type="radio" name="wpe_picker_scope" value="option" ${mode === 'option' ? 'checked' : ''} />
                                            <span>Option Value Group</span>
                                        </label>
                                    ` : ''}
                                </div>
                                <div id="wpe-picker-scope-preview" style="font-size:12px; font-weight:600; color:#1d4ed8;"></div>
                            </div>

                            <div id="wpe-picker-option-controls" style="display:${mode === 'option' ? 'flex' : 'none'}; gap:10px; margin-top:10px; align-items:center;">
                                <select id="wpe-picker-group-opt" style="min-width:160px;">${optSelectHtml}</select>
                                <select id="wpe-picker-group-val" style="min-width:180px;"></select>
                            </div>
                        </div>

                        <!-- Product Images Grid -->
                        <div style="margin-bottom:14px;">
                            <div style="font-weight:600; font-size:12px; color:#334155; margin-bottom:8px;">
                                Select an image from this product's library (<span id="wpe-picker-lib-count">${orderedAssets.length}</span> available):
                            </div>
                            <div id="wpe-picker-grid-wrap"></div>
                        </div>

                        <!-- In-Picker Upload & External URL (auto-links to product and assigns in 1 step) -->
                        <div style="background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; padding:12px 14px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:8px;">
                                <div>
                                    <strong style="font-size:12.5px; color:#1e293b;">Or upload a new image directly for this variant</strong>
                                    <div style="font-size:11.5px; color:#64748b;">Automatically adds to this product's media library and assigns to the target variation(s) in 1 step.</div>
                                </div>
                                <div>
                                    <input type="file" id="wpe-picker-file-input" accept="image/jpeg,image/png,image/webp,image/gif,image/avif" style="display:none;" />
                                    <button type="button" class="button wpe-btn-picker-upload-file">
                                        <span class="dashicons dashicons-upload" style="margin-top:3px;"></span> Upload &amp; Assign
                                    </button>
                                </div>
                            </div>
                            <div class="wpe-media-url-bar" style="padding:6px 8px; background:#ffffff;">
                                <input type="url" id="wpe-picker-ext-url" placeholder="Or paste HTTPS image URL to add &amp; assign..." />
                                <button type="button" class="button button-small wpe-btn-picker-add-url">Add URL &amp; Assign</button>
                            </div>
                        </div>
                    </div>
                    <div class="wpe-modal-footer" style="justify-content:space-between;">
                        <div>
                            <button type="button" class="button button-link-delete wpe-btn-picker-clear-image">Remove Assigned Image</button>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="button wpe-btn-close-modal">Cancel</button>
                            <button type="button" class="button button-primary wpe-btn-confirm-variant-picker" ${!selectedAssetId ? 'disabled' : ''}>Assign Selected Image</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);

        function renderPickerOptionValues() {
            const optName = $('#wpe-picker-group-opt').val() || currentOptName;
            currentOptName = optName;
            const optObj = options.find(o => o.name === optName);
            let html = '';
            if (optObj && Array.isArray(optObj.values)) {
                optObj.values.forEach(v => {
                    const label = typeof v === 'object' ? v.label : v;
                    const sel = label === currentOptVal ? 'selected' : '';
                    html += `<option value="${escapeHtml(label)}" ${sel}>${escapeHtml(label)}</option>`;
                });
            }
            $('#wpe-picker-group-val').html(html);
            currentOptVal = $('#wpe-picker-group-val').val() || '';
        }

        function getTargetVariations() {
            if (mode === 'single' && singleVariant) {
                return [singleVariant];
            }
            if (mode === 'bulk') {
                const ids = opts.variantIds || Array.from(wpeVariantStore.selection);
                return wpeVariantStore.variations.filter(v => ids.indexOf(v.id) !== -1);
            }
            if (mode === 'option') {
                const oName = $('#wpe-picker-group-opt').val() || currentOptName;
                const oVal = $('#wpe-picker-group-val').val() || currentOptVal;
                return wpeVariantStore.variations.filter(v => {
                    const attrs = v.attributes || v.combination || {};
                    return (attrs[oName] || 'Unassigned') === oVal;
                });
            }
            return [];
        }

        function updateScopePreview() {
            const targets = getTargetVariations();
            $('#wpe-picker-scope-preview').text(`Will update ${targets.length} variation${targets.length === 1 ? '' : 's'}`);
            const selAsset = getOrderedProductAssets().find(a => a.asset_id === selectedAssetId);
            if (selectedAssetId && targets.length > 0 && (!selAsset || !selAsset.pending_link)) {
                const previewPayload = { asset_id: String(selectedAssetId) };
                if (mode === 'option') {
                    previewPayload.option = {
                        name: $('#wpe-picker-group-opt').val() || currentOptName,
                        value: $('#wpe-picker-group-val').val() || currentOptVal
                    };
                } else {
                    previewPayload.variant_ids = targets.map(t => t.id);
                }
                api.previewImageAssignment(previewPayload).done(function (res) {
                    if (res && typeof res.affected_count === 'number') {
                        const count = Math.max(targets.length, res.affected_count);
                        $('#wpe-picker-scope-preview').text(`Will update ${count} variation${count === 1 ? '' : 's'}`);
                    }
                });
            }
        }

        function renderPickerGrid() {
            const assets = getOrderedProductAssets();
            $('#wpe-picker-lib-count').text(assets.length);
            if (assets.length === 0) {
                $('#wpe-picker-grid-wrap').html(`
                    <div style="padding:24px; text-align:center; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b;">
                        This product has no images yet. Use <strong>Upload &amp; Assign</strong> below to upload an image directly!
                    </div>
                `);
                $('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').prop('disabled', true);
                return;
            }
            const itemsHtml = assets.map(a => {
                const isSel = a.asset_id === selectedAssetId;
                return `
                    <div class="wpe-picker-item ${isSel ? 'is-selected' : ''}" data-asset-id="${a.asset_id}">
                        <div class="wpe-picker-item-thumb">
                            <img src="${escapeHtml(a.url)}" alt="${escapeHtml(a.alt_text || '')}" />
                            <span class="wpe-picker-check"><span class="dashicons dashicons-yes" style="font-size:15px; width:15px; height:15px;"></span></span>
                        </div>
                        <div class="wpe-picker-item-meta">
                            ${a.isFeatured ? '<strong>[Featured]</strong> ' : ''}#${a.asset_id} ${escapeHtml(a.alt_text || getOriginBadgeLabel(a))}
                        </div>
                    </div>
                `;
            }).join('');
            $('#wpe-picker-grid-wrap').html(`<div class="wpe-picker-grid">${itemsHtml}</div>`);
            $('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').prop('disabled', !selectedAssetId);

            $('#wpe-picker-grid-wrap .wpe-picker-item').off('click').on('click', function () {
                selectedAssetId = parseInt($(this).attr('data-asset-id'), 10);
                renderPickerGrid();
                updateScopePreview();
            });
        }

        function applyAssetToTargets(assetObj) {
            const targets = getTargetVariations();
            if (targets.length === 0) {
                showToast('No matching variations for the selected scope.', 'warning');
                return;
            }
            targets.forEach(v => {
                if (assetObj) {
                    v.image_asset_id = assetObj.asset_id;
                    v.image_url = assetObj.url;
                    v.attachment_id = null;
                } else {
                    v.image_asset_id = null;
                    v.image_url = null;
                    v.attachment_id = null;
                }
            });
            layers.close($('#wpe-variant-image-picker-modal'));
            if ($('#wpe-variation-drawer-overlay').is(':visible') && singleVariantId) {
                renderDrawer(singleVariantId);
            }
            showToast(
                assetObj
                    ? `Assigned product image #${assetObj.asset_id} to ${targets.length} variation${targets.length === 1 ? '' : 's'}!`
                    : `Cleared image from ${targets.length} variation${targets.length === 1 ? '' : 's'}.`,
                'success'
            );
            wpeVariantStore.markDirty();
        }

        renderPickerOptionValues();
        renderPickerGrid();
        updateScopePreview();

        // Scope radio switch
        $('#wpe-variant-image-picker-modal input[name="wpe_picker_scope"]').on('change', function () {
            mode = $(this).val();
            $('#wpe-picker-option-controls').toggle(mode === 'option');
            updateScopePreview();
        });

        $('#wpe-picker-group-opt').on('change', function () {
            renderPickerOptionValues();
            updateScopePreview();
        });

        $('#wpe-picker-group-val').on('change', function () {
            currentOptVal = $(this).val() || '';
            updateScopePreview();
        });

        // Confirm assign selected image
        $('#wpe-variant-image-picker-modal .wpe-btn-confirm-variant-picker').on('click', function () {
            const assets = getOrderedProductAssets();
            const chosen = assets.find(a => a.asset_id === selectedAssetId);
            if (!chosen) return;
            applyAssetToTargets(chosen);
        });

        // Clear image on targets
        $('#wpe-variant-image-picker-modal .wpe-btn-picker-clear-image').on('click', function () {
            applyAssetToTargets(null);
        });

        // In-picker Upload & Assign in 1 step (A3)
        $('#wpe-variant-image-picker-modal .wpe-btn-picker-upload-file').on('click', function () {
            $('#wpe-picker-file-input').trigger('click');
        });

        $('#wpe-picker-file-input').on('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            const $btn = $('#wpe-variant-image-picker-modal .wpe-btn-picker-upload-file');
            $btn.prop('disabled', true).text('Uploading & Assigning...');
            const uploadKey = generateIdempotencyKey('picker-upl');
            api.uploadAssetFile(file, uploadKey, '')
                .done(function (res) {
                    linkAssetToProductImmediate(res, function (linkedAsset) {
                        if (linkedAsset) {
                            selectedAssetId = linkedAsset.asset_id;
                            applyAssetToTargets(linkedAsset);
                        }
                    });
                })
                .fail(function (xhr) {
                    const err = (xhr && xhr.responseJSON) || {};
                    showToast(err.message || 'Upload failed.', 'error');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-upload" style="margin-top:3px;"></span> Upload &amp; Assign');
                });
        });

        // In-picker Add External URL & Assign in 1 step
        $('#wpe-variant-image-picker-modal .wpe-btn-picker-add-url').on('click', function () {
            const extUrl = ($('#wpe-picker-ext-url').val() || '').trim();
            if (!extUrl) {
                showToast('Please enter a valid HTTPS image URL.', 'warning');
                return;
            }
            const $btn = $(this);
            $btn.prop('disabled', true).text('Adding...');
            api.registerExternalAsset(extUrl, '')
                .done(function (res) {
                    linkAssetToProductImmediate(res, function (linkedAsset) {
                        if (linkedAsset) {
                            selectedAssetId = linkedAsset.asset_id;
                            applyAssetToTargets(linkedAsset);
                        }
                    });
                })
                .fail(function (xhr) {
                    const err = (xhr && xhr.responseJSON) || {};
                    showToast(err.message || 'Failed to add external URL.', 'error');
                    $btn.prop('disabled', false).text('Add URL & Assign');
                });
        });
    }

    function getVariantLabel(v) {
        const attrs = v.attributes || v.combination || {};
        const vals = Object.values(attrs).filter(Boolean);
        if (vals.length > 0) return vals.join(' / ');
        if (v.sku) return v.sku;
        return '#' + (v.id || '');
    }

    function formatDateForInput(val) {
        if (!val) return '';
        const m = String(val).match(/^(\d{4}-\d{2}-\d{2})/);
        return m ? m[1] : '';
    }

    function emptyVal(v) {
        return v === null || v === undefined || String(v).trim() === '';
    }

    function getEffectiveReferencePrice() {
        const vars = wpeVariantStore.variations;
        if (!vars || vars.length === 0) {
            return {
                state: 'none',
                label: null,
                source_variant_id: null,
                regular_price: null,
                sale_price: null,
                sale_from: null,
                sale_to: null,
                active_price: null,
                sale_active: false
            };
        }

        // Must sort strictly by position ASC, id ASC over the entire set, ignoring UI filters/pagination
        const sorted = [...vars].sort((a, b) => {
            const posA = a.position !== undefined ? a.position : 0;
            const posB = b.position !== undefined ? b.position : 0;
            if (posA !== posB) return posA - posB;
            return (a.id || 0) - (b.id || 0);
        });

        const first = sorted[0];
        const label = getVariantLabel(first);

        const hasReg = first.regular_price !== null && first.regular_price !== undefined && String(first.regular_price).trim() !== '';
        const hasSale = first.sale_price !== null && first.sale_price !== undefined && String(first.sale_price).trim() !== '';

        if (!hasReg && !hasSale) {
            return {
                state: 'unpriced',
                label: label,
                source_variant_id: first.id,
                regular_price: null,
                sale_price: null,
                sale_from: null,
                sale_to: null,
                active_price: null,
                sale_active: false
            };
        }

        const reg = hasReg ? String(first.regular_price).trim() : null;
        const sale = hasSale ? String(first.sale_price).trim() : null;

        let saleActive = false;
        let activePrice = reg;
        if (sale !== null) {
            const now = new Date();
            const fromOk = !first.sale_from || new Date(first.sale_from) <= now;
            const toOk = !first.sale_to || new Date(first.sale_to) >= now;
            if (fromOk && toOk) {
                saleActive = true;
                activePrice = sale;
            }
        }

        return {
            state: 'priced',
            label: label,
            source_variant_id: first.id,
            regular_price: reg,
            sale_price: sale,
            sale_from: first.sale_from || null,
            sale_to: first.sale_to || null,
            active_price: activePrice,
            sale_active: saleActive
        };
    }

    function openCommonPriceRemovalModal(sharedVariants) {
        const cp = wpeVariantStore.commonPrice || {};
        const count = sharedVariants.length;
        const modalHtml = `
            <div id="wpe-common-removal-modal" class="wpe-modal-backdrop" role="dialog" aria-modal="true" aria-label="Xác nhận xóa giá chung">
                <div class="wpe-modal-dialog wpe-modal-dialog-md">
                    <div class="wpe-modal-header">
                        <h3 class="wpe-modal-title">Xác nhận xóa giá chung / Remove Common Price</h3>
                        <button type="button" class="wpe-modal-close wpe-btn-cancel-modal" aria-label="Đóng">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <div class="wpe-dialog-warning-banner">
                            <strong>Lưu ý:</strong> Đang có <strong>${count}</strong> biến thể sử dụng mức giá chung này ($${escapeHtml(cp.regular_price || '0')}${cp.sale_price ? ' / $' + escapeHtml(cp.sale_price) : ''}).
                            Vui lòng chọn phương án xử lý giá cho các biến thể bị ảnh hưởng trước khi xóa.
                        </div>
                        <div style="max-height: 160px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 4px; margin-bottom: 14px;">
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th style="padding:6px 10px;">Biến thể</th>
                                        <th style="padding:6px 10px;">SKU</th>
                                        <th style="padding:6px 10px;">Giá đang dùng</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${sharedVariants.map(v => `
                                        <tr>
                                            <td style="padding:6px 10px;"><strong>${escapeHtml(getVariantLabel(v))}</strong></td>
                                            <td style="padding:6px 10px;">${escapeHtml(v.sku || '—')}</td>
                                            <td style="padding:6px 10px;">$${escapeHtml(cp.regular_price || '')}${cp.sale_price ? ' / $' + escapeHtml(cp.sale_price) : ''}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        <div class="wpe-dialog-resolution-options">
                            <label>
                                <input type="radio" name="wpe_common_removal_res" value="convert_own" checked />
                                <span><strong>Chuyển thành giá riêng (Khuyên dùng):</strong> Giữ mức giá hiện tại làm giá riêng cho từng biến thể.</span>
                            </label>
                            <label>
                                <input type="radio" name="wpe_common_removal_res" value="leave_unpriced" />
                                <span><strong>Để trống giá riêng:</strong> Các biến thể sẽ chuyển sang giá riêng nhưng chưa có giá bán (cần nhập thủ công sau).</span>
                            </label>
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-cancel-modal">Hủy</button>
                        <button type="button" class="button button-primary wpe-btn-confirm-common-removal">Xác nhận xóa</button>
                    </div>
                </div>
            </div>
        `;

        const $modal = openModalHtml(modalHtml);

        $modal.find('.wpe-btn-cancel-modal').on('click', function () {
            layers.close($modal);
        });

        $modal.find('.wpe-btn-confirm-common-removal').on('click', function () {
            const res = $modal.find('input[name="wpe_common_removal_res"]:checked').val();
            sharedVariants.forEach(v => {
                v.pricing_mode = 'own';
                if (res === 'convert_own') {
                    v.regular_price = cp.regular_price || '';
                    v.sale_price = cp.sale_price || '';
                    v.sale_from = cp.sale_from || null;
                    v.sale_to = cp.sale_to || null;
                } else {
                    v.regular_price = '';
                    v.sale_price = '';
                    v.confirm_unpriced = true;
                }
            });
            wpeVariantStore.commonPrice = null;
            wpeVariantStore.markDirty();
            layers.close($modal);
            showToast('Đã xóa giá chung và cập nhật các biến thể', 'info');
        });
    }

    function openSimpleToVariableModal() {
        const prod = wpeVariantStore.product || {};
        const regPrice = prod.regular_price ? formatNumberForInput(prod.regular_price) : '';
        const salePrice = prod.sale_price ? formatNumberForInput(prod.sale_price) : '';

        const modalHtml = `
            <div id="wpe-simple-to-variable-modal" class="wpe-modal-backdrop" role="dialog" aria-modal="true" aria-label="Chuyển sang sản phẩm có biến thể">
                <div class="wpe-modal-dialog wpe-modal-dialog-md">
                    <div class="wpe-modal-header">
                        <h3 class="wpe-modal-title">Chuyển sang sản phẩm có biến thể / Switch to Variable Product</h3>
                        <button type="button" class="wpe-modal-close wpe-btn-cancel-modal" aria-label="Đóng">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <div class="wpe-dialog-warning-banner" style="background:#eff6ff; border-left-color:#3b82f6; color:#1e40af;">
                            Sản phẩm sẽ được cấu hình thành <strong>Sản phẩm có biến thể (Variable)</strong> khi tạo các tổ hợp biến thể.
                        </div>
                        <div id="wpe-simple-to-variable-preview" style="margin-top:12px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; font-size:13px;">
                            ${regPrice ? `<div><strong>Giá bán chung:</strong> Giá hiện tại ($${escapeHtml(regPrice)}${salePrice ? ' / Sale $' + escapeHtml(salePrice) : ''}) sẽ được đề xuất làm giá chung cho các biến thể.</div>` : '<div>Chưa có giá bán trực tiếp. Biến thể có thể dùng giá riêng hoặc thiết lập giá chung sau.</div>'}
                            <div style="margin-top:6px;"><strong>SKU và Tồn kho:</strong> Mã SKU sản phẩm cha và kho sản phẩm tiếp tục được lưu giữ.</div>
                        </div>
                        <div class="wpe-form-row" style="margin-top:14px;">
                            <label for="wpe-first-option-name"><strong>Tên tùy chọn biến thể đầu tiên:</strong></label>
                            <input type="text" id="wpe-first-option-name" class="wpe-input" value="Size" placeholder="e.g. Size, Color, Style" />
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-cancel-modal">Hủy</button>
                        <button type="button" class="button button-primary wpe-btn-confirm-to-variable">Xác nhận tạo tùy chọn</button>
                    </div>
                </div>
            </div>
        `;

        const $modal = openModalHtml(modalHtml);

        $modal.find('.wpe-btn-cancel-modal').on('click', function () {
            layers.close($modal);
        });

        $modal.find('.wpe-btn-confirm-to-variable').on('click', function () {
            const optName = $modal.find('#wpe-first-option-name').val().trim() || 'Size';
            wpeVariantStore.variationOptions.push({
                name: optName,
                position: 0,
                display_type: 'auto',
                help_text: '',
                values: []
            });
            wpeVariantStore.derivedType = 'variable';
            wpeVariantStore.type = 'variable';
            wpeVariantStore.product.type = 'variable';
            if (wpeVariantStore.product.regular_price && !wpeVariantStore.commonPrice) {
                const regStr = String(wpeVariantStore.product.regular_price);
                const saleStr = wpeVariantStore.product.sale_price ? String(wpeVariantStore.product.sale_price) : '';
                wpeVariantStore.commonPrice = {
                    regular_price: regStr,
                    sale_price: saleStr,
                    sale_from: wpeVariantStore.product.sale_from || null,
                    sale_to: wpeVariantStore.product.sale_to || null,
                    regular: regStr,
                    sale: saleStr,
                    from: wpeVariantStore.product.sale_from || null,
                    to: wpeVariantStore.product.sale_to || null
                };
                wpeVariantStore.product.regular_price = null;
                wpeVariantStore.product.sale_price = null;
                wpeVariantStore.product.sale_from = null;
                wpeVariantStore.product.sale_to = null;
            }
            layers.close($modal);
            wpeVariantStore.markDirty();
            showToast(`Đã thêm tùy chọn "${optName}"!`, 'success');
        });
    }

    function openVariableToSimpleModal() {
        const vars = wpeVariantStore.variations;
        const modalHtml = `
            <div id="wpe-variable-to-simple-modal" class="wpe-modal-backdrop" role="dialog" aria-modal="true" aria-label="Chuyển sang sản phẩm đơn giản">
                <div class="wpe-modal-dialog wpe-modal-dialog-md">
                    <div class="wpe-modal-header">
                        <h3 class="wpe-modal-title">Chuyển sang sản phẩm đơn giản / Switch to Simple Product</h3>
                        <button type="button" class="wpe-modal-close wpe-btn-cancel-modal" aria-label="Đóng">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <div class="wpe-dialog-warning-banner">
                            <strong>Cảnh báo:</strong> Sản phẩm có biến thể sẽ chuyển thành Sản phẩm đơn giản. Tất cả tùy chọn và biến thể khác sẽ bị gỡ bỏ.
                            Vui lòng chọn 1 biến thể kế thừa để giữ lại giá, SKU, tồn kho và kích thước cho sản phẩm đơn giản.
                        </div>
                        ${vars.length > 0 ? `
                            <div class="wpe-form-row">
                                <label for="wpe-receiver-variant-select"><strong>Biến thể kế thừa dữ liệu:</strong></label>
                                <select id="wpe-receiver-variant-select" class="wpe-select">
                                    ${vars.map((v, i) => `
                                        <option value="${v.id}" ${i === 0 ? 'selected' : ''}>
                                            ${escapeHtml(getVariantLabel(v))} (SKU: ${escapeHtml(v.sku || 'N/A')}, Giá: ${escapeHtml(v.regular_price || 'Chưa có')})
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                            <div id="wpe-receiver-preview-box" style="margin-top:12px; padding:10px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; font-size:13px;">
                            </div>
                            ${vars.length > 1 ? `
                                <div style="margin-top:14px;">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                        <input type="checkbox" id="wpe-confirm-tombstone-check" checked />
                                        <span>Tôi hiểu và xác nhận gỡ bỏ ${vars.length - 1} biến thể còn lại.</span>
                                    </label>
                                </div>
                            ` : ''}
                        ` : '<p>Chưa có biến thể nào được tạo. Sản phẩm sẽ chuyển về Sản phẩm đơn giản.</p>'}
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-cancel-modal">Hủy</button>
                        <button type="button" class="button button-primary wpe-btn-confirm-to-simple">Xác nhận chuyển đổi</button>
                    </div>
                </div>
            </div>
        `;

        const $modal = openModalHtml(modalHtml);

        function updateReceiverPreview() {
            const selId = parseInt($modal.find('#wpe-receiver-variant-select').val(), 10);
            const receiver = vars.find(v => v.id === selId) || vars[0];
            if (!receiver) {
                $modal.find('#wpe-receiver-preview-box').html('');
                return;
            }
            $modal.find('#wpe-receiver-preview-box').html(`
                <div><strong>Giá bán sẽ nhận:</strong> $${escapeHtml(receiver.regular_price || 'Chưa có')}${receiver.sale_price ? ' (Sale: $' + escapeHtml(receiver.sale_price) + ')' : ''}</div>
                <div><strong>SKU sẽ nhận:</strong> ${escapeHtml(receiver.sku || 'Giữ SKU cha')}</div>
                <div><strong>Tồn kho sẽ nhận:</strong> ${receiver.manage_stock ? receiver.stock_quantity + ' cái' : (receiver.stock_status || 'Còn hàng')}</div>
            `);
        }

        $modal.find('#wpe-receiver-variant-select').on('change', updateReceiverPreview);
        updateReceiverPreview();

        $modal.find('.wpe-btn-cancel-modal').on('click', function () {
            layers.close($modal);
        });

        $modal.find('.wpe-btn-confirm-to-simple').on('click', function () {
            if (vars.length > 1) {
                const confirmed = $modal.find('#wpe-confirm-tombstone-check').is(':checked');
                if (!confirmed) {
                    alert('Vui lòng đánh dấu xác nhận gỡ bỏ các biến thể còn lại.');
                    return;
                }
            }

            const selId = parseInt($modal.find('#wpe-receiver-variant-select').val(), 10);
            const receiver = vars.find(v => v.id === selId) || vars[0];

            if (receiver) {
                wpeVariantStore.product.sku = receiver.sku || wpeVariantStore.product.sku || '';
                wpeVariantStore.product.regular_price = receiver.regular_price || '';
                wpeVariantStore.product.sale_price = receiver.sale_price || '';
                wpeVariantStore.product.sale_from = receiver.sale_from || null;
                wpeVariantStore.product.sale_to = receiver.sale_to || null;
                if (receiver.weight) wpeVariantStore.product.weight = receiver.weight;
                if (receiver.length) wpeVariantStore.product.length = receiver.length;
                if (receiver.width) wpeVariantStore.product.width = receiver.width;
                if (receiver.height) wpeVariantStore.product.height = receiver.height;
                if (receiver.manage_stock) {
                    wpeVariantStore.product.manage_stock = 1;
                    wpeVariantStore.product.stock_quantity = receiver.stock_quantity;
                    wpeVariantStore.product.backorders = receiver.backorders || 'no';
                } else {
                    wpeVariantStore.product.manage_stock = 0;
                    wpeVariantStore.product.stock_status = receiver.stock_status || 'instock';
                }
                const stockObj = {
                    manage_stock: wpeVariantStore.product.manage_stock,
                    stock_quantity: wpeVariantStore.product.stock_quantity,
                    stock_status: wpeVariantStore.product.stock_status || 'instock',
                    backorders: wpeVariantStore.product.backorders || 'no'
                };
                wpeVariantStore.product.stock = stockObj;
                wpeVariantStore.product.parent_stock = stockObj;
                wpeVariantStore.typeTransition = {
                    receiver_id: receiver.id,
                    confirm_tombstone: true
                };
            }

            wpeVariantStore.variationOptions = [];
            wpeVariantStore.variations = [];
            wpeVariantStore.commonPrice = null;
            wpeVariantStore.product.type = 'simple';
            wpeVariantStore.type = 'simple';
            wpeVariantStore.derivedType = 'simple';
            layers.close($modal);
            wpeVariantStore.markDirty();
            showToast('Đã chuyển sang Sản phẩm đơn giản (vui lòng Lưu thay đổi để hoàn tất)', 'info');
        });
    }

    function renderReferencePriceCard() {
        const ref = getEffectiveReferencePrice();
        if (ref.state === 'none') {
            return `
                <div class="wpe-ref-card">
                    <div class="wpe-ref-header">
                        <span class="wpe-ref-badge">Giá tham chiếu / Reference Price</span>
                    </div>
                    <div class="wpe-ref-help" style="margin:0 0 12px 0;">
                        Chưa có biến thể nào được tạo để tham chiếu giá.
                    </div>
                    <button type="button" class="button button-secondary wpe-btn-set-common-price">
                        <span class="dashicons dashicons-money-alt" style="margin-top:2px;"></span> Thiết lập giá chung / Set common price
                    </button>
                </div>
            `;
        }

        if (ref.state === 'unpriced') {
            return `
                <div class="wpe-ref-card unpriced">
                    <div class="wpe-ref-header">
                        <span class="wpe-ref-badge wpe-ref-badge-warning">Chưa có giá / No price yet</span>
                        <span class="wpe-ref-source">Giá tham chiếu từ biến thể ${escapeHtml(ref.label)}</span>
                    </div>
                    <div class="wpe-ref-unpriced-warning">
                        <span class="dashicons dashicons-warning"></span>
                        Biến thể đầu tiên (${escapeHtml(ref.label)}) chưa có giá bán. Vui lòng cập nhật giá cho biến thể này tại block Biến thể hoặc thiết lập giá chung.
                    </div>
                    <div class="wpe-ref-help">
                        Giá tham chiếu chỉ phục vụ hiển thị tại block này, không tự ghi thành giá chung hoặc phân phối sang các biến thể khác.
                    </div>
                    <button type="button" class="button button-secondary wpe-btn-set-common-price">
                        <span class="dashicons dashicons-money-alt" style="margin-top:2px;"></span> Thiết lập giá chung / Set common price
                    </button>
                </div>
            `;
        }

        const regFormatted = '$' + parseFloat(ref.regular_price || 0).toFixed(2);
        const saleFormatted = ref.sale_price !== null && ref.sale_price !== '' ? '$' + parseFloat(ref.sale_price).toFixed(2) : '';

        return `
            <div class="wpe-ref-card">
                <div class="wpe-ref-header">
                    <span class="wpe-ref-badge">Giá tham chiếu / Reference Price</span>
                    <span class="wpe-ref-source">Giá tham chiếu từ biến thể ${escapeHtml(ref.label)}</span>
                </div>
                <div class="wpe-ref-amount">
                    ${saleFormatted ? `<span style="text-decoration:line-through; color:#94a3b8; font-size:18px; margin-right:8px;">${regFormatted}</span><span>${saleFormatted}</span>` : `<span>${regFormatted}</span>`}
                    ${ref.sale_active ? '<span class="wpe-ref-badge" style="background:#fee2e2; color:#b91c1c; margin-left:8px;">Sale</span>' : ''}
                </div>
                <div class="wpe-ref-help">
                    Giá tham chiếu chỉ phục vụ hiển thị tại block này, không tự ghi thành giá chung hoặc phân phối sang các biến thể khác. Mỗi biến thể tiếp tục sử dụng giá riêng đã lưu.
                </div>
                <button type="button" class="button button-secondary wpe-btn-set-common-price">
                    <span class="dashicons dashicons-money-alt" style="margin-top:2px;"></span> Thiết lập giá chung / Set common price
                </button>
            </div>
        `;
    }

    function renderCommonPriceCard() {
        const cp = wpeVariantStore.commonPrice || {};
        const cpReg = cp.regular_price !== undefined && cp.regular_price !== null ? cp.regular_price : (cp.regular || '');
        const cpSale = cp.sale_price !== undefined && cp.sale_price !== null ? cp.sale_price : (cp.sale || '');
        const cpFrom = cp.sale_from !== undefined && cp.sale_from !== null ? cp.sale_from : (cp.from || '');
        const cpTo = cp.sale_to !== undefined && cp.sale_to !== null ? cp.sale_to : (cp.to || '');
        return `
            <div class="wpe-common-price-card">
                <div class="wpe-common-price-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="wpe-common-badge">Giá chung / Common Price</span>
                        <span style="font-size:13px; color:#166534; font-weight:500;">Áp dụng cho các biến thể ở chế độ "Dùng giá chung"</span>
                    </div>
                    <button type="button" class="button-link-delete wpe-btn-remove-common-price" title="Xóa cấu hình giá chung">
                        <span class="dashicons dashicons-trash" style="font-size:16px;"></span> Xóa giá chung
                    </button>
                </div>
                <div class="wpe-form-grid-2">
                    <div class="wpe-form-row">
                        <label>Giá thường chung ($)</label>
                        <input type="text" class="wpe-input wpe-common-regular-price" value="${escapeHtml(formatNumberForInput(cpReg))}" placeholder="0.00" />
                    </div>
                    <div class="wpe-form-row">
                        <label>Giá sale chung ($)</label>
                        <input type="text" class="wpe-input wpe-common-sale-price" value="${escapeHtml(formatNumberForInput(cpSale))}" placeholder="0.00" />
                    </div>
                </div>
                <div class="wpe-form-grid-2" style="margin-top:8px;">
                    <div class="wpe-form-row">
                        <label>Lịch sale từ ngày</label>
                        <input type="date" class="wpe-input wpe-common-sale-from" value="${escapeHtml(formatDateForInput(cpFrom))}" />
                    </div>
                    <div class="wpe-form-row">
                        <label>Lịch sale đến ngày</label>
                        <input type="date" class="wpe-input wpe-common-sale-to" value="${escapeHtml(formatDateForInput(cpTo))}" />
                    </div>
                </div>
                <div class="wpe-field-desc" style="margin-top:8px;">
                    Biến thể mới sẽ mặc định dùng giá chung này nếu chưa cấu hình giá riêng.
                </div>
            </div>
        `;
    }

    function renderSimplePricing() {
        const prod = wpeVariantStore.product || {};
        return `
            <div class="wpe-form-grid-2">
                <div class="wpe-form-row">
                    <label for="wpe-info-regular-price">Giá thường ($) <span class="required">*</span></label>
                    <input type="text" id="wpe-info-regular-price" class="wpe-input" value="${escapeHtml(formatNumberForInput(prod.regular_price))}" placeholder="0.00" />
                </div>
                <div class="wpe-form-row">
                    <label for="wpe-info-sale-price">Giá khuyến mãi / Sale ($)</label>
                    <input type="text" id="wpe-info-sale-price" class="wpe-input" value="${escapeHtml(formatNumberForInput(prod.sale_price))}" placeholder="0.00" />
                </div>
            </div>
            <div class="wpe-form-grid-2" style="margin-top:8px;">
                <div class="wpe-form-row">
                    <label for="wpe-info-sale-from">Lên lịch sale từ ngày</label>
                    <input type="date" id="wpe-info-sale-from" class="wpe-input" value="${escapeHtml(formatDateForInput(prod.sale_from))}" />
                </div>
                <div class="wpe-form-row">
                    <label for="wpe-info-sale-to">Lên lịch sale đến ngày</label>
                    <input type="date" id="wpe-info-sale-to" class="wpe-input" value="${escapeHtml(formatDateForInput(prod.sale_to))}" />
                </div>
            </div>
        `;
    }

    function renderProductInventory() {
        const prod = wpeVariantStore.product || {};
        const inv = prod.stock || prod.parent_stock || {};
        const manageStock = !emptyVal(inv.manage_stock) || !emptyVal(prod.manage_stock);
        const isVar = isVariableType();
        const stockQty = inv.stock_quantity !== null && inv.stock_quantity !== undefined ? inv.stock_quantity : (prod.stock_quantity !== null && prod.stock_quantity !== undefined ? prod.stock_quantity : '');
        const backorders = inv.backorders || prod.backorders || 'no';
        const stockStatus = inv.stock_status || prod.stock_status || 'instock';

        return `
            <div class="wpe-form-row">
                <label for="wpe-info-sku">Mã SKU sản phẩm</label>
                <input type="text" id="wpe-info-sku" class="wpe-input" value="${escapeHtml(prod.sku || '')}" placeholder="e.g. PRD-001" />
                <span class="wpe-field-desc">
                    ${isVar ? 'SKU cấp sản phẩm cha. Phân biệt rõ với mã SKU riêng của từng biến thể.' : 'Mã định danh sản phẩm độc nhất cho quản lý kho và đơn hàng.'}
                </span>
            </div>
            <div class="wpe-form-row" style="margin-top:14px;">
                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" id="wpe-info-manage-stock" ${manageStock ? 'checked' : ''} />
                    <span><strong>Quản lý tồn kho cấp sản phẩm</strong> ${isVar ? '(Tồn kho chung)' : ''}</span>
                </label>
                <span class="wpe-field-desc" style="margin-left:24px;">
                    ${isVar ? 'Khi bật, sản phẩm quản lý số lượng tồn kho chung thay vì quản lý theo từng biến thể riêng lẻ.' : 'Theo dõi số lượng hàng tồn kho cho sản phẩm này.'}
                </span>
            </div>
            <div id="wpe-info-stock-fields" style="margin-top:12px; padding-left:24px; display:${manageStock ? 'block' : 'none'};">
                <div class="wpe-form-grid-2">
                    <div class="wpe-form-row">
                        <label for="wpe-info-stock-quantity">Số lượng tồn kho</label>
                        <input type="number" step="any" id="wpe-info-stock-quantity" class="wpe-input" value="${escapeHtml(formatNumberForInput(stockQty))}" placeholder="0" />
                    </div>
                    <div class="wpe-form-row">
                        <label for="wpe-info-backorders">Cho phép đặt hàng trước (Backorders)</label>
                        <select id="wpe-info-backorders" class="wpe-select">
                            <option value="no" ${backorders === 'no' ? 'selected' : ''}>Không cho phép</option>
                            <option value="notify" ${backorders === 'notify' ? 'selected' : ''}>Cho phép nhưng thông báo khách</option>
                            <option value="yes" ${backorders === 'yes' ? 'selected' : ''}>Cho phép</option>
                        </select>
                    </div>
                </div>
            </div>
            <div id="wpe-info-stock-status-field" class="wpe-form-row" style="margin-top:12px; padding-left:24px; display:${manageStock ? 'none' : 'block'};">
                <label for="wpe-info-stock-status">Trạng thái kho</label>
                <select id="wpe-info-stock-status" class="wpe-select" style="max-width:260px;">
                    <option value="instock" ${stockStatus === 'instock' ? 'selected' : ''}>Còn hàng (In stock)</option>
                    <option value="outofstock" ${stockStatus === 'outofstock' ? 'selected' : ''}>Hết hàng (Out of stock)</option>
                    <option value="onbackorder" ${stockStatus === 'onbackorder' ? 'selected' : ''}>Chờ hàng (On backorder)</option>
                </select>
            </div>
        `;
    }

    function renderProductShipping() {
        const prod = wpeVariantStore.product || {};
        return `
            <div class="wpe-form-grid-3">
                <div class="wpe-form-row">
                    <label for="wpe-info-weight">Trọng lượng (kg)</label>
                    <input type="text" id="wpe-info-weight" class="wpe-input" value="${escapeHtml(formatNumberForInput(prod.weight))}" placeholder="0.0" />
                </div>
                <div class="wpe-form-row">
                    <label for="wpe-info-length">Dài (cm)</label>
                    <input type="text" id="wpe-info-length" class="wpe-input" value="${escapeHtml(formatNumberForInput(prod.length))}" placeholder="0" />
                </div>
                <div class="wpe-form-row">
                    <label for="wpe-info-width">Rộng (cm)</label>
                    <input type="text" id="wpe-info-width" class="wpe-input" value="${escapeHtml(formatNumberForInput(prod.width))}" placeholder="0" />
                </div>
            </div>
            <div class="wpe-form-row" style="margin-top:8px; max-width:33%;">
                <label for="wpe-info-height">Cao (cm)</label>
                <input type="text" id="wpe-info-height" class="wpe-input" value="${escapeHtml(formatNumberForInput(prod.height))}" placeholder="0" />
            </div>
        `;
    }

    /**
     * Render Descriptive Attributes (used_for_variation = 0)
     */
    function renderSectionA() {
        const attrs = wpeVariantStore.descriptiveAttributes || [];
        let rowsHtml = '';

        if (attrs.length === 0) {
            rowsHtml = `<div style="color:#64748b; font-style:italic; padding:6px 0;">No descriptive specs added (e.g. Material, Brand, Care Instructions).</div>`;
        } else {
            attrs.forEach((attr, idx) => {
                const valStr = Array.isArray(attr.values)
                    ? attr.values.map(v => typeof v === 'object' ? v.label : v).join(', ')
                    : (attr.value || '');
                rowsHtml += `
                    <div class="wpe-desc-attr-row" data-attr-idx="${idx}" style="display:flex; gap:10px; align-items:center; margin-bottom:8px;">
                        <input type="text" class="wpe-desc-attr-name" value="${escapeHtml(attr.name || '')}" placeholder="Attribute Name (e.g. Material)" style="width:220px;" />
                        <input type="text" class="wpe-desc-attr-values" value="${escapeHtml(valStr)}" placeholder="Values separated by comma (e.g. 100% Cotton, Ring-spun)" style="flex:1;" />
                        <label style="display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#475569;">
                            <input type="checkbox" class="wpe-desc-attr-visible" ${attr.visible !== 0 && attr.visible !== false ? 'checked' : ''} />
                            Visible
                        </label>
                        <button type="button" class="button-link-delete wpe-btn-delete-desc-attr" title="Remove attribute">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                `;
            });
        }

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-tag" style="color:#2563eb;"></span>
                        <h4 class="wpe-section-title">Descriptive Attributes</h4>
                        <span class="wpe-section-count">${attrs.length}</span>
                    </div>
                    <button type="button" class="button wpe-btn-add-desc-attr">
                        <span class="dashicons dashicons-plus-alt2"></span> Add Attribute
                    </button>
                </div>
                <div class="wpe-section-body">
                    ${rowsHtml}
                </div>
            </div>
        `;
    }

    function renderProductInfoAppHtml() {
        const isVar = isVariableType();

        return `
            <div class="wpe-info-wrap">
                <div class="wpe-top-bar" style="margin-bottom:0;">
                    <div class="wpe-top-title-area">
                        <span class="wpe-badge-type">${isVar ? 'Sản phẩm có biến thể (Variable)' : 'Sản phẩm đơn giản (Simple)'}</span>
                        <span class="wpe-dirty-badge ${wpeVariantStore.isDirty ? 'dirty' : 'clean'}">
                            <span class="dashicons dashicons-${wpeVariantStore.isDirty ? 'warning' : 'yes'}"></span>
                            ${wpeVariantStore.isDirty ? 'Chưa lưu' : 'Đã đồng bộ'}
                        </span>
                    </div>
                    <div class="wpe-top-actions">
                        <button type="button" class="button button-primary wpe-btn-save-api" ${!wpeVariantStore.isDirty ? 'disabled' : ''}>
                            <span class="dashicons dashicons-saved"></span> Lưu thay đổi
                        </button>
                    </div>
                </div>

                <div class="wpe-info-section">
                    <h4 class="wpe-info-section-title">
                        <span class="dashicons dashicons-money-alt" style="color:#16a34a;"></span>
                        ${isVar ? 'Giá chung & Giá tham chiếu' : 'Giá bán'}
                    </h4>
                    ${isVar ? (wpeVariantStore.commonPrice !== null ? renderCommonPriceCard() : renderReferencePriceCard()) : renderSimplePricing()}
                </div>

                <div class="wpe-info-section">
                    <h4 class="wpe-info-section-title">
                        <span class="dashicons dashicons-archive" style="color:#d97706;"></span>
                        Mã sản phẩm & Quản lý tồn kho
                    </h4>
                    ${renderProductInventory()}
                </div>

                <div class="wpe-info-section">
                    <h4 class="wpe-info-section-title">
                        <span class="dashicons dashicons-car" style="color:#0284c7;"></span>
                        Trọng lượng & Kích thước
                    </h4>
                    ${renderProductShipping()}
                </div>

                <div class="wpe-info-section" style="padding:0; border:none; background:transparent;">
                    ${renderSectionA()}
                </div>
            </div>
        `;
    }

    function renderProductInfoApp(containerId) {
        const $container = $(containerId || '#wpe-product-info-app');
        if (!$container.length) return;

        if (wpeVariantStore.isLoading) {
            $container.html(`
                <div class="wpe-editor-loading">
                    <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
                    <span>Đang tải thông tin sản phẩm...</span>
                </div>
            `);
            return;
        }

        $container.html(renderProductInfoAppHtml());
        bindProductInfoEvents($container);
        layers.keepFocus();
    }

    function bindProductInfoEvents($container) {
        $container.find('#wpe-info-sku').off('change').on('change', function () {
            wpeVariantStore.product.sku = $(this).val().trim();
            wpeVariantStore.markDirty();
        });

        $container.find('#wpe-info-regular-price').off('change').on('change', function () {
            wpeVariantStore.product.regular_price = $(this).val().trim();
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-sale-price').off('change').on('change', function () {
            wpeVariantStore.product.sale_price = $(this).val().trim();
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-sale-from').off('change').on('change', function () {
            const val = $(this).val();
            wpeVariantStore.product.sale_from = val ? val + ' 00:00:00' : null;
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-sale-to').off('change').on('change', function () {
            const val = $(this).val();
            wpeVariantStore.product.sale_to = val ? val + ' 23:59:59' : null;
            wpeVariantStore.markDirty();
        });

        $container.find('.wpe-common-regular-price').off('change').on('change', function () {
            if (!wpeVariantStore.commonPrice) wpeVariantStore.commonPrice = {};
            const val = $(this).val().trim();
            wpeVariantStore.commonPrice.regular_price = val;
            wpeVariantStore.commonPrice.regular = val;
            wpeVariantStore.markDirty();
        });
        $container.find('.wpe-common-sale-price').off('change').on('change', function () {
            if (!wpeVariantStore.commonPrice) wpeVariantStore.commonPrice = {};
            const val = $(this).val().trim();
            wpeVariantStore.commonPrice.sale_price = val;
            wpeVariantStore.commonPrice.sale = val;
            wpeVariantStore.markDirty();
        });
        $container.find('.wpe-common-sale-from').off('change').on('change', function () {
            if (!wpeVariantStore.commonPrice) wpeVariantStore.commonPrice = {};
            const val = $(this).val();
            const from = val ? val + ' 00:00:00' : null;
            wpeVariantStore.commonPrice.sale_from = from;
            wpeVariantStore.commonPrice.from = from;
            wpeVariantStore.markDirty();
        });
        $container.find('.wpe-common-sale-to').off('change').on('change', function () {
            if (!wpeVariantStore.commonPrice) wpeVariantStore.commonPrice = {};
            const val = $(this).val();
            const to = val ? val + ' 23:59:59' : null;
            wpeVariantStore.commonPrice.sale_to = to;
            wpeVariantStore.commonPrice.to = to;
            wpeVariantStore.markDirty();
        });

        $container.find('.wpe-btn-set-common-price').off('click').on('click', function () {
            const ref = getEffectiveReferencePrice();
            const rReg = ref.regular_price || '';
            const rSale = ref.sale_price || '';
            wpeVariantStore.commonPrice = {
                regular_price: rReg,
                sale_price: rSale,
                sale_from: ref.sale_from || null,
                sale_to: ref.sale_to || null,
                regular: rReg,
                sale: rSale,
                from: ref.sale_from || null,
                to: ref.sale_to || null
            };
            wpeVariantStore.markDirty();
        });

        $container.find('.wpe-btn-remove-common-price').off('click').on('click', function () {
            const shared = wpeVariantStore.variations.filter(v => v.pricing_mode === 'shared');
            if (shared.length > 0) {
                openCommonPriceRemovalModal(shared);
            } else {
                wpeVariantStore.commonPrice = null;
                wpeVariantStore.markDirty();
            }
        });

        $container.find('#wpe-info-manage-stock').off('change').on('change', function () {
            const isChecked = $(this).is(':checked');
            wpeVariantStore.product.manage_stock = isChecked ? 1 : 0;
            if (!wpeVariantStore.product.stock) wpeVariantStore.product.stock = {};
            wpeVariantStore.product.stock.manage_stock = isChecked ? 1 : 0;
            wpeVariantStore.product.parent_stock = Object.assign({}, wpeVariantStore.product.stock);
            $container.find('#wpe-info-stock-fields').toggle(isChecked);
            $container.find('#wpe-info-stock-status-field').toggle(!isChecked);
            wpeVariantStore.markDirty();
        });

        $container.find('#wpe-info-stock-quantity').off('change').on('change', function () {
            const val = $(this).val();
            const qty = val === '' ? null : parseFloat(val);
            wpeVariantStore.product.stock_quantity = qty;
            if (!wpeVariantStore.product.stock) wpeVariantStore.product.stock = {};
            wpeVariantStore.product.stock.stock_quantity = qty;
            wpeVariantStore.product.parent_stock = Object.assign({}, wpeVariantStore.product.stock);
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-backorders').off('change').on('change', function () {
            const bo = $(this).val();
            wpeVariantStore.product.backorders = bo;
            if (!wpeVariantStore.product.stock) wpeVariantStore.product.stock = {};
            wpeVariantStore.product.stock.backorders = bo;
            wpeVariantStore.product.parent_stock = Object.assign({}, wpeVariantStore.product.stock);
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-stock-status').off('change').on('change', function () {
            const st = $(this).val();
            wpeVariantStore.product.stock_status = st;
            if (!wpeVariantStore.product.stock) wpeVariantStore.product.stock = {};
            wpeVariantStore.product.stock.stock_status = st;
            wpeVariantStore.product.parent_stock = Object.assign({}, wpeVariantStore.product.stock);
            wpeVariantStore.markDirty();
        });

        $container.find('#wpe-info-weight').off('change').on('change', function () {
            wpeVariantStore.product.weight = $(this).val().trim();
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-length').off('change').on('change', function () {
            wpeVariantStore.product.length = $(this).val().trim();
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-width').off('change').on('change', function () {
            wpeVariantStore.product.width = $(this).val().trim();
            wpeVariantStore.markDirty();
        });
        $container.find('#wpe-info-height').off('change').on('change', function () {
            wpeVariantStore.product.height = $(this).val().trim();
            wpeVariantStore.markDirty();
        });

        // Descriptive Attributes
        $container.find('.wpe-btn-add-desc-attr').off('click').on('click', function () {
            wpeVariantStore.descriptiveAttributes.push({
                name: '',
                values: [],
                visible: 1,
                position: wpeVariantStore.descriptiveAttributes.length
            });
            wpeVariantStore.markDirty();
        });

        $container.find('.wpe-desc-attr-name').off('input change').on('input change', function () {
            const idx = parseInt($(this).closest('.wpe-desc-attr-row').attr('data-attr-idx'), 10);
            if (wpeVariantStore.descriptiveAttributes[idx]) {
                wpeVariantStore.descriptiveAttributes[idx].name = $(this).val();
                wpeVariantStore.isDirty = true;
                wpeVariantStore.syncHiddenPayload();
                updateBoxHeader();
                updateDirtyChrome();
            }
        });

        $container.find('.wpe-desc-attr-values').off('input change').on('input change', function () {
            const idx = parseInt($(this).closest('.wpe-desc-attr-row').attr('data-attr-idx'), 10);
            if (wpeVariantStore.descriptiveAttributes[idx]) {
                const vals = $(this).val().split(',').map(s => s.trim()).filter(Boolean);
                wpeVariantStore.descriptiveAttributes[idx].values = vals;
                wpeVariantStore.isDirty = true;
                wpeVariantStore.syncHiddenPayload();
                updateBoxHeader();
                updateDirtyChrome();
            }
        });

        $container.find('.wpe-desc-attr-visible').off('change').on('change', function () {
            const idx = parseInt($(this).closest('.wpe-desc-attr-row').attr('data-attr-idx'), 10);
            if (wpeVariantStore.descriptiveAttributes[idx]) {
                wpeVariantStore.descriptiveAttributes[idx].visible = $(this).is(':checked') ? 1 : 0;
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-btn-delete-desc-attr').off('click').on('click', function () {
            const idx = parseInt($(this).closest('.wpe-desc-attr-row').attr('data-attr-idx'), 10);
            if (!isNaN(idx)) {
                wpeVariantStore.descriptiveAttributes.splice(idx, 1);
                wpeVariantStore.markDirty();
            }
        });

        bindEditorEvents($container, '#wpe-product-info-app');
    }

    function renderEmptyVariantsState() {
        return `
            <div class="wpe-empty-variants-card">
                <div class="wpe-empty-icon">
                    <span class="dashicons dashicons-networking"></span>
                </div>
                <h3>Sản phẩm đơn giản (Chưa có biến thể)</h3>
                <p>Sản phẩm này hiện chưa có tùy chọn biến thể. Bạn có thể bán sản phẩm với các thuộc tính như Màu sắc, Kích thước bằng cách thêm tùy chọn biến thể đầu tiên.</p>
                <button type="button" class="button button-primary button-hero wpe-btn-add-first-option">
                    <span class="dashicons dashicons-plus-alt2" style="margin-top:4px;"></span> Thêm tùy chọn / Add Option
                </button>
            </div>
        `;
    }

    function renderVariantsAppHtml() {
        if (config.editorMode === 'blocks' && wpeVariantStore.variationOptions.length === 0) {
            return renderEmptyVariantsState();
        }
        return `
            <!-- Variation Options -->
            ${renderSectionB()}

            <!-- Variations Matrix -->
            ${renderSectionC()}
        `;
    }

    function renderVariantsApp(containerId) {
        const $container = $(containerId || '#wpe-product-editor-inline-app');
        if (!$container.length) return;

        if (wpeVariantStore.isLoading) {
            $container.html(`
                <div class="wpe-editor-loading">
                    <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
                    <span>Loading variants...</span>
                </div>
            `);
            return;
        }

        // Preserve search input focus and caret selection across reactive re-renders
        const $activeSearch = $container.find('.wpe-search-input');
        const wasSearchFocused = $activeSearch.length > 0 && document.activeElement === $activeSearch[0];
        const caretStart = wasSearchFocused ? $activeSearch[0].selectionStart : null;
        const caretEnd = wasSearchFocused ? $activeSearch[0].selectionEnd : null;

        let html = `
            <div class="wpe-editor-wrap">
                <div class="wpe-top-bar">
                    <div class="wpe-top-title-area">
                        <span class="wpe-dirty-badge ${wpeVariantStore.isDirty ? 'dirty' : 'clean'}">
                            <span class="dashicons dashicons-${wpeVariantStore.isDirty ? 'warning' : 'yes'}"></span>
                            ${wpeVariantStore.isDirty ? 'Unsaved changes' : 'In sync'}
                        </span>
                    </div>
                    <div class="wpe-top-actions">
                        <button type="button" class="button wpe-btn-open-fullscreen">
                            <span class="dashicons dashicons-editor-expand"></span> Fullscreen Mode
                        </button>
                        <button type="button" class="button button-primary wpe-btn-save-api" ${!wpeVariantStore.isDirty ? 'disabled' : ''}>
                            <span class="dashicons dashicons-saved"></span> Save Changes
                        </button>
                    </div>
                </div>

                ${renderVariantsAppHtml()}

                ${wpeVariantStore.isDirty ? `
                    <div class="wpe-sticky-savebar">
                        <div class="wpe-sticky-savebar-left">
                            <span class="wpe-sticky-savebar-dot"></span>
                            <span>Unsaved variant changes</span>
                        </div>
                        <div class="wpe-sticky-savebar-actions">
                            <button type="button" class="wpe-sticky-btn-discard">Discard</button>
                            <button type="button" class="wpe-sticky-btn-save">Save Changes</button>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;

        $container.html(html);
        bindEditorEvents($container, containerId);
        if (wasSearchFocused) {
            const $newSearch = $container.find('.wpe-search-input');
            if ($newSearch.length) {
                $newSearch[0].focus();
                if (caretStart !== null && caretEnd !== null && typeof $newSearch[0].setSelectionRange === 'function') {
                    try { $newSearch[0].setSelectionRange(caretStart, caretEnd); } catch (e) {}
                }
            }
        } else {
            layers.keepFocus();
        }
    }

    function renderFullscreenApp(containerId) {
        const $container = $(containerId || '#wpe-product-editor-modal-app');
        if (!$container.length) return;

        const $activeSearch = $container.find('.wpe-search-input');
        const wasSearchFocused = $activeSearch.length > 0 && document.activeElement === $activeSearch[0];
        const caretStart = wasSearchFocused ? $activeSearch[0].selectionStart : null;
        const caretEnd = wasSearchFocused ? $activeSearch[0].selectionEnd : null;

        const activeTab = wpeVariantStore.fullscreenTab || 'variants';
        let tabBodyHtml = '';
        if (activeTab === 'product_info') {
            tabBodyHtml = renderProductInfoAppHtml();
        } else if (activeTab === 'product_media') {
            tabBodyHtml = `<div id="wpe-fullscreen-media-mount"></div>`;
        } else {
            tabBodyHtml = renderVariantsAppHtml();
        }

        let html = `
            <div class="wpe-editor-wrap">
                <div class="wpe-top-bar">
                    <div class="wpe-top-title-area">
                        <h3 class="wpe-top-title wpe-modal-title">Variants Manager (Fullscreen)</h3>
                        <span class="wpe-dirty-badge ${wpeVariantStore.isDirty ? 'dirty' : 'clean'}">
                            <span class="dashicons dashicons-${wpeVariantStore.isDirty ? 'warning' : 'yes'}"></span>
                            ${wpeVariantStore.isDirty ? 'Unsaved changes' : 'In sync'}
                        </span>
                    </div>
                    <div class="wpe-top-actions">
                        <button type="button" class="button wpe-btn-close-fullscreen">
                            <span class="dashicons dashicons-editor-contract"></span> Collapse
                        </button>
                        <button type="button" class="button button-primary wpe-btn-save-api" ${!wpeVariantStore.isDirty ? 'disabled' : ''}>
                            <span class="dashicons dashicons-saved"></span> Save Changes
                        </button>
                    </div>
                </div>

                <div class="wpe-modal-nav" role="tablist">
                    <button type="button" class="wpe-tab-btn ${activeTab === 'variants' ? 'active' : ''}" data-tab="variants">
                        <span class="dashicons dashicons-grid-view"></span> Variants (${wpeVariantStore.variations.length})
                    </button>
                    <button type="button" class="wpe-tab-btn ${activeTab === 'product_info' ? 'active' : ''}" data-tab="product_info">
                        <span class="dashicons dashicons-info-outline"></span> Product Info
                    </button>
                    <button type="button" class="wpe-tab-btn ${activeTab === 'product_media' ? 'active' : ''}" data-tab="product_media">
                        <span class="dashicons dashicons-format-gallery"></span> Product Media (${(wpeVariantStore.mediaLibrary.assets || []).length})
                    </button>
                </div>

                <div class="wpe-fullscreen-tab-body" style="padding-top:16px;">
                    ${tabBodyHtml}
                </div>

                ${wpeVariantStore.isDirty ? `
                    <div class="wpe-sticky-savebar">
                        <div class="wpe-sticky-savebar-left">
                            <span class="wpe-sticky-savebar-dot"></span>
                            <span>Unsaved variant changes</span>
                        </div>
                        <div class="wpe-sticky-savebar-actions">
                            <button type="button" class="wpe-sticky-btn-discard">Discard</button>
                            <button type="button" class="wpe-sticky-btn-save">Save Changes</button>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;

        $container.html(html);
        if (activeTab === 'product_info') {
            bindProductInfoEvents($container);
        } else if (activeTab === 'product_media') {
            renderFullProductMediaModalBody();
            const $mount = $container.find('#wpe-fullscreen-media-mount');
            if ($mount.length) {
                const assets = getOrderedProductAssets();
                const featuredId = wpeVariantStore.mediaLibrary.featured_asset_id;
                $mount.html(`
                    <div class="wpe-section">
                        <div class="wpe-section-header">
                            <div class="wpe-section-title-wrap">
                                <span class="dashicons dashicons-format-gallery" style="color:#2563eb;"></span>
                                <h4 class="wpe-section-title">Product Media Library (${assets.length})</h4>
                            </div>
                        </div>
                        <div class="wpe-section-body">
                            <div class="wpe-media-grid">
                                ${assets.map((asset, idx) => renderMediaCardHtml(asset, idx, assets.length, featuredId)).join('')}
                            </div>
                        </div>
                    </div>
                `);
                bindProductMediaEvents($mount);
            }
        }
        bindEditorEvents($container, containerId);
        if (wasSearchFocused) {
            const $newSearch = $container.find('.wpe-search-input');
            if ($newSearch.length) {
                $newSearch[0].focus();
                if (caretStart !== null && caretEnd !== null && typeof $newSearch[0].setSelectionRange === 'function') {
                    try { $newSearch[0].setSelectionRange(caretStart, caretEnd); } catch (e) {}
                }
            }
        } else {
            layers.keepFocus();
        }
    }

    function updateDirtyChrome() {
        const isDirty = wpeVariantStore.isDirty;
        $('.wpe-dirty-badge')
            .toggleClass('dirty', isDirty)
            .toggleClass('clean', !isDirty)
            .html(`<span class="dashicons dashicons-${isDirty ? 'warning' : 'yes'}"></span> ${isDirty ? 'Unsaved changes' : 'In sync'}`);
        $('.wpe-btn-save-api').prop('disabled', !isDirty);

        ['#wpe-product-editor-inline-app', '#wpe-product-editor-modal-app', '#wpe-product-info-app'].forEach(sel => {
            const $wrap = $(sel).find('.wpe-editor-wrap').first();
            if (!$wrap.length) return;
            const $bar = $wrap.find('.wpe-sticky-savebar');
            if (isDirty && !$bar.length && sel !== '#wpe-product-info-app') {
                $wrap.append(`
                    <div class="wpe-sticky-savebar">
                        <div class="wpe-sticky-savebar-left">
                            <span class="wpe-sticky-savebar-dot"></span>
                            <span>Unsaved variant changes</span>
                        </div>
                        <div class="wpe-sticky-savebar-actions">
                            <button type="button" class="wpe-sticky-btn-discard">Discard</button>
                            <button type="button" class="wpe-sticky-btn-save">Save Changes</button>
                        </div>
                    </div>
                `);
            } else if (!isDirty && $bar.length) {
                $bar.remove();
            }
        });
    }

    function addValuesToOption(optIdx, rawInput) {
        if (optIdx === undefined || !wpeVariantStore.variationOptions[optIdx]) return 0;
        const opt = wpeVariantStore.variationOptions[optIdx];
        opt.values = opt.values || [];
        const tokens = String(rawInput || '')
            .split(/[\n,\t]+/)
            .map(s => s.trim())
            .filter(s => s.length > 0);
        if (!tokens.length) return 0;

        const isColorOpt = opt.display_type === 'color' || /^(color|colour|màu|màu sắc)$/i.test(String(opt.name || '').trim());
        let added = 0;
        tokens.forEach(token => {
            const exists = opt.values.some(v => String(typeof v === 'object' ? v.label : v).toLowerCase() === token.toLowerCase());
            if (!exists) {
                const newVal = { label: token, value_key: token };
                const autoHex = isColorOpt ? inferPodHexColor(token) : '';
                if (autoHex) {
                    newVal.color = autoHex;
                }
                opt.values.push(newVal);
                added++;
            }
        });
        return added;
    }

    function getFilteredVariations() {
        const variations = wpeVariantStore.variations;
        const filters = wpeVariantStore.filters;

        return variations.filter(v => {
            if (filters.stockStatus) {
                if (filters.stockStatus === 'instock' && (v.stock_status !== 'instock' || (v.manage_stock && v.stock_quantity <= 0))) return false;
                if (filters.stockStatus === 'outofstock' && v.stock_status !== 'outofstock' && (!v.manage_stock || v.stock_quantity > 0)) return false;
                if (filters.stockStatus === 'parent_stock' && v.manage_stock) return false;
            }
            if (filters.attrFilter) {
                const af = filters.attrFilter;
                const attrs = v.attributes || v.combination || {};
                if (af === 'missing_price') {
                    if (v.regular_price !== null && v.regular_price !== undefined && String(v.regular_price).trim() !== '') return false;
                } else if (af === 'missing_sku') {
                    if (v.sku && String(v.sku).trim() !== '') return false;
                } else if (af === 'missing_image') {
                    if (v.image_url) return false;
                } else if (af === 'on_sale') {
                    if (v.sale_price === null || v.sale_price === undefined || String(v.sale_price).trim() === '') return false;
                } else if (af.indexOf('opt:') === 0) {
                    const parts = af.substring(4).split('::');
                    const optName = parts[0] || '';
                    const optVal = parts.slice(1).join('::');
                    if (String(attrs[optName] || '') !== optVal) return false;
                }
            }
            if (filters.search) {
                const q = filters.search.toLowerCase();
                const skuMatch = v.sku && v.sku.toLowerCase().indexOf(q) !== -1;
                const comboMatch = Object.values(v.attributes || v.combination || {}).some(val => String(val).toLowerCase().indexOf(q) !== -1);
                const priceMatch = String(v.regular_price || '').indexOf(q) !== -1;
                if (!skuMatch && !comboMatch && !priceMatch) return false;
            }
            return true;
        });
    }

    /**
     * Main Editor UI Renderer
     */
    function renderEditor(containerId) {
        if (!containerId || containerId === '#wpe-product-editor-inline-app') {
            renderVariantsApp('#wpe-product-editor-inline-app');
            renderProductInfoApp('#wpe-product-info-app');
        } else if (containerId === '#wpe-product-editor-modal-app') {
            renderFullscreenApp('#wpe-product-editor-modal-app');
        } else if (containerId === '#wpe-product-info-app') {
            renderProductInfoApp('#wpe-product-info-app');
        }
    }

    /**
     * Render Variation Options Cards (used_for_variation = 1)
     */
    function renderSectionB() {
        const options = wpeVariantStore.variationOptions;
        let cardsHtml = '';

        if (options.length === 0) {
            cardsHtml = `<div style="color:#64748b; font-style:italic; padding:8px 0;">No variation options configured yet (Color, Size...). Click "Add Option" to begin.</div>`;
        } else {
            options.forEach((opt, idx) => {
                const rawValues = opt.values || [];
                const isColorOpt = opt.display_type === 'color' || /^(color|colour|màu|màu sắc)$/i.test(String(opt.name || '').trim());

                const chipsHtml = rawValues.map((vObj, vIdx) => {
                    const valLabel = typeof vObj === 'object' ? vObj.label : vObj;
                    const valColor = (typeof vObj === 'object' && vObj && vObj.color) ? vObj.color : (isColorOpt ? inferPodHexColor(valLabel) : '');
                    const colorDotHtml = valColor ? `<span class="wpe-chip-color-dot" style="background-color:${escapeHtml(valColor)};"></span>` : '';
                    const moveLeft = vIdx > 0 ? `<span class="wpe-chip-move wpe-chip-move-prev" data-opt-idx="${idx}" data-val-idx="${vIdx}" title="Move left">&lsaquo;</span>` : '';
                    const moveRight = vIdx < rawValues.length - 1 ? `<span class="wpe-chip-move wpe-chip-move-next" data-opt-idx="${idx}" data-val-idx="${vIdx}" title="Move right">&rsaquo;</span>` : '';
                    return `
                        <span class="wpe-chip" data-opt-idx="${idx}" data-val-idx="${vIdx}">
                            ${moveLeft}
                            ${colorDotHtml}
                            <span>${escapeHtml(valLabel)}</span>
                            ${moveRight}
                            <span class="wpe-chip-remove" title="Remove value">&times;</span>
                        </span>
                    `;
                }).join('');

                const normalizedOpt = normalizeVariationOptionDefaults(opt);
                const defaultVal = normalizedOpt.default_value || '';
                const defaultValId = normalizedOpt.default_value_id || null;
                const defaultOptionsHtml = `<option value="">-- No Default --</option>` + rawValues.map(vObj => {
                    const valLabel = typeof vObj === 'object' ? vObj.label : vObj;
                    const valId = (typeof vObj === 'object' && vObj !== null && vObj.id) ? vObj.id : null;
                    const isDef = (defaultValId !== null && valId !== null && String(defaultValId) === String(valId))
                        || (defaultVal !== '' && String(defaultVal) === String(valLabel));
                    return `<option value="${escapeHtml(valLabel)}" ${isDef ? 'selected' : ''}>${escapeHtml(valLabel)}</option>`;
                }).join('');

                cardsHtml += `
                    <div class="wpe-option-card" data-idx="${idx}">
                        <div class="wpe-option-card-header">
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <div class="wpe-option-reorder-btns">
                                    <button type="button" class="button wpe-btn-move-option wpe-btn-move-option-up" data-opt-idx="${idx}" ${idx === 0 ? 'disabled' : ''} title="Move option up">&uarr;</button>
                                    <button type="button" class="button wpe-btn-move-option wpe-btn-move-option-down" data-opt-idx="${idx}" ${idx === options.length - 1 ? 'disabled' : ''} title="Move option down">&darr;</button>
                                </div>
                                <input type="text" class="wpe-option-name-input" value="${escapeHtml(opt.name)}" placeholder="Option name (e.g. Size, Color)" />
                                <span style="font-size:12px; color:#64748b; font-weight:500;">(${rawValues.length} values)</span>
                            </div>
                            <div class="wpe-option-card-actions" style="display:flex; align-items:center; gap:10px;">
                                <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569;">
                                    <span>Default:</span>
                                    <select class="wpe-option-default-value-select" data-opt-idx="${idx}" style="font-size:12px; min-height:28px;">
                                        ${defaultOptionsHtml}
                                    </select>
                                </label>
                                <button type="button" class="button button-small wpe-btn-toggle-bulk-paste">
                                    <span class="dashicons dashicons-clipboard"></span> Paste Values
                                </button>
                                <button type="button" class="button-link-delete wpe-btn-delete-option" title="Delete option">
                                    <span class="dashicons dashicons-trash" style="font-size:16px;"></span>
                                </button>
                            </div>
                        </div>

                        <!-- Bulk paste values box -->
                        <div class="wpe-bulk-paste-box">
                            <p style="margin:0 0 6px 0; font-size:11px; color:#475569;">Paste values separated by commas, tabs, or new lines:</p>
                            <textarea rows="2" placeholder="Red, Blue, Green, Yellow..."></textarea>
                            <div style="margin-top:8px; display:flex; justify-content:flex-end; gap:8px;">
                                <button type="button" class="button button-small wpe-btn-cancel-bulk-paste">Close</button>
                                <button type="button" class="button button-small button-primary wpe-btn-apply-bulk-paste">Add Values</button>
                            </div>
                        </div>

                        <div class="wpe-chips-container" data-opt-idx="${idx}">
                            ${chipsHtml}
                            <input type="text" class="wpe-chip-input" placeholder="Type value(s) (or paste S, M, L) and press Enter..." />
                        </div>
                    </div>
                `;
            });
        }

        let taxonomyOptions = '<option value="">-- Custom Variation Option --</option>';
        wpeVariantStore.taxonomies.forEach(t => {
            taxonomyOptions += `<option value="${escapeHtml(t.name)}">${escapeHtml(t.label || t.name)}</option>`;
        });

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-admin-settings" style="color:#2563eb;"></span>
                        <h4 class="wpe-section-title">Variation Options</h4>
                        <span class="wpe-section-count">${options.length}</span>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select class="wpe-add-taxonomy-select" style="max-width:220px; font-size:12px;">
                            ${taxonomyOptions}
                        </select>
                        <button type="button" class="button wpe-btn-add-option">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Option
                        </button>
                    </div>
                </div>
                <div class="wpe-section-body">
                    <div class="wpe-options-list">
                        ${cardsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render Variations Matrix Table
     */
    function renderSectionC() {
        const variations = wpeVariantStore.variations;
        const filters = wpeVariantStore.filters;
        const options = wpeVariantStore.variationOptions;
        const hasCommonPrice = wpeVariantStore.commonPrice !== null && typeof wpeVariantStore.commonPrice === 'object';

        const filtered = getFilteredVariations();

        // Pagination
        const totalItems = filtered.length;
        const totalPages = Math.ceil(totalItems / filters.pageSize) || 1;
        if (filters.page > totalPages) filters.page = totalPages;
        const startIdx = (filters.page - 1) * filters.pageSize;
        const paginatedList = filtered.slice(startIdx, startIdx + filters.pageSize);

        // Group by dropdown options
        let groupByOptions = '<option value="none">-- No Grouping --</option>';
        options.forEach(opt => {
            groupByOptions += `<option value="${escapeHtml(opt.name)}" ${filters.groupBy === opt.name ? 'selected' : ''}>Group by ${escapeHtml(opt.name)}</option>`;
        });

        // Attribute & Data filter options
        let attrFilterOptions = `
            <option value="">Filter: All Variants</option>
            <optgroup label="Data Status">
                <option value="missing_price" ${filters.attrFilter === 'missing_price' ? 'selected' : ''}>Missing Regular Price</option>
                <option value="missing_sku" ${filters.attrFilter === 'missing_sku' ? 'selected' : ''}>Missing SKU</option>
                <option value="missing_image" ${filters.attrFilter === 'missing_image' ? 'selected' : ''}>Missing Image</option>
                <option value="on_sale" ${filters.attrFilter === 'on_sale' ? 'selected' : ''}>On Sale</option>
            </optgroup>
        `;
        options.forEach(opt => {
            const vals = opt.values || [];
            if (vals.length > 0) {
                attrFilterOptions += `<optgroup label="${escapeHtml(opt.name)}">`;
                vals.forEach(vObj => {
                    const label = typeof vObj === 'object' ? vObj.label : vObj;
                    const key = `opt:${opt.name}::${label}`;
                    attrFilterOptions += `<option value="${escapeHtml(key)}" ${filters.attrFilter === key ? 'selected' : ''}>${escapeHtml(opt.name)}: ${escapeHtml(label)}</option>`;
                });
                attrFilterOptions += `</optgroup>`;
            }
        });

        const selectedCount = wpeVariantStore.selection.size;
        const allPageSelected = paginatedList.length > 0 && paginatedList.every(v => wpeVariantStore.selection.has(v.id));

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-grid-view" style="color:#2563eb;"></span>
                        <h4 class="wpe-section-title">Variations Matrix</h4>
                        <span class="wpe-section-count">${variations.length}</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="button wpe-btn-add-single-variant" title="Add a single custom variation">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Variant
                        </button>
                        <button type="button" class="button button-primary wpe-btn-open-generator">
                            <span class="dashicons dashicons-forms"></span> Generate Variations
                        </button>
                    </div>
                </div>

                <!-- Matrix Toolbar -->
                <div class="wpe-matrix-toolbar">
                    <div class="wpe-toolbar-left">
                        <input type="text" class="wpe-search-input wpe-filter-input" placeholder="Search by SKU, attributes, price..." value="${escapeHtml(filters.search)}" />
                        <select class="wpe-filter-select wpe-filter-stock">
                            <option value="">Stock: All</option>
                            <option value="instock" ${filters.stockStatus === 'instock' ? 'selected' : ''}>In Stock</option>
                            <option value="outofstock" ${filters.stockStatus === 'outofstock' ? 'selected' : ''}>Out of Stock</option>
                            <option value="parent_stock" ${filters.stockStatus === 'parent_stock' ? 'selected' : ''}>Shared Parent Stock</option>
                        </select>
                        <select class="wpe-filter-select wpe-filter-attr">
                            ${attrFilterOptions}
                        </select>
                        <select class="wpe-filter-select wpe-group-by-select">
                            ${groupByOptions}
                        </select>
                    </div>
                    <div class="wpe-toolbar-right">
                        <button type="button" class="button wpe-btn-assign-media-by-option">
                            <span class="dashicons dashicons-images-alt2"></span> Assign by Group
                        </button>
                        <button type="button" class="button wpe-btn-open-bulk-edit" ${selectedCount === 0 ? 'disabled' : ''}>
                            <span class="dashicons dashicons-edit"></span> Bulk Edit (${selectedCount})
                        </button>
                    </div>
                </div>

                <!-- Selection Bar -->
                <div class="wpe-selection-bar ${selectedCount > 0 ? 'active' : ''}">
                    <div>
                        <strong>${selectedCount}</strong> variants selected
                        <button type="button" class="button-link wpe-btn-select-all-filtered" style="margin-left:8px;">Select all ${totalItems} filtered results</button>
                    </div>
                    <div class="wpe-selection-actions">
                        <button type="button" class="button button-small wpe-btn-bulk-assign-media wpe-btn-bulk-set-image">Assign Media</button>
                        <button type="button" class="button button-small wpe-btn-bulk-delete">Delete Selected</button>
                        <button type="button" class="button button-small wpe-btn-clear-selection">Deselect All</button>
                    </div>
                </div>

                <!-- Table Container -->
                <div class="wpe-table-container">
                    <table class="wpe-table">
                        <thead>
                            <tr>
                                <th style="width:28px;"><input type="checkbox" class="wpe-check-all-page" ${allPageSelected ? 'checked' : ''} /></th>
                                <th style="width:44px;">Image</th>
                                <th style="min-width:120px;">Combination</th>
                                <th style="width:120px;">SKU</th>
                                ${hasCommonPrice ? '<th style="width:100px;">Price Mode</th>' : ''}
                                <th style="width:110px;">Regular (${config.currencySymbol})</th>
                                <th style="width:110px;">Sale (${config.currencySymbol})</th>
                                <th style="width:120px;">Stock</th>
                                <th style="width:72px; text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${renderTableRows(paginatedList, filters.groupBy)}
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="wpe-pagination">
                    <div>
                        Showing ${totalItems > 0 ? startIdx + 1 : 0} &ndash; ${Math.min(startIdx + filters.pageSize, totalItems)} of ${totalItems} variants
                    </div>
                    <div class="wpe-pagination-controls">
                        <button type="button" class="button button-small wpe-page-prev" ${filters.page <= 1 ? 'disabled' : ''}>&laquo; Prev</button>
                        <span style="margin:0 8px; font-weight:500;">Page ${filters.page} / ${totalPages}</span>
                        <button type="button" class="button button-small wpe-page-next" ${filters.page >= totalPages ? 'disabled' : ''}>Next &raquo;</button>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render Variations Table Rows with Grouping Support
     */
    function renderTableRows(variations, groupBy) {
        const hasCommonPrice = wpeVariantStore.commonPrice !== null && typeof wpeVariantStore.commonPrice === 'object';
        const colSpan = hasCommonPrice ? 9 : 8;
        if (variations.length === 0) {
            return `<tr><td colspan="${colSpan}" style="text-align:center; padding:36px; color:#64748b;">No matching variants found.</td></tr>`;
        }

        let html = '';
        let currentGroup = null;

        // Sort by group value when grouping is active so identical groups stay contiguous
        const displayList = (groupBy && groupBy !== 'none')
            ? variations.slice().sort((a, b) => {
                const ga = String((a.attributes || a.combination || {})[groupBy] || '');
                const gb = String((b.attributes || b.combination || {})[groupBy] || '');
                return ga.localeCompare(gb);
            })
            : variations;

        displayList.forEach(v => {
            const attrs = v.attributes || v.combination || {};
            const groupVal = (groupBy && groupBy !== 'none') ? (attrs[groupBy] || 'Unassigned') : null;
            const groupKey = groupVal !== null ? `${groupBy}::${groupVal}` : null;
            const isGroupCollapsed = groupKey ? wpeVariantStore.collapsedGroups.has(groupKey) : false;

            // Group header row
            if (groupBy && groupBy !== 'none' && groupVal !== currentGroup) {
                currentGroup = groupVal;
                const groupMembers = wpeVariantStore.variations.filter(item => {
                    const itemAttrs = item.attributes || item.combination || {};
                    return (itemAttrs[groupBy] || 'Unassigned') === groupVal;
                });
                const allGroupSelected = groupMembers.length > 0 && groupMembers.every(m => wpeVariantStore.selection.has(m.id));
                html += `
                    <tr class="wpe-group-row" data-group-opt="${escapeHtml(groupBy)}" data-group-val="${escapeHtml(groupVal)}" data-group-key="${escapeHtml(groupKey)}">
                        <td colspan="${colSpan}">
                            <div class="wpe-group-row-inner">
                                <div class="wpe-group-row-left">
                                    <input type="checkbox" class="wpe-group-check" data-group-opt="${escapeHtml(groupBy)}" data-group-val="${escapeHtml(groupVal)}" ${allGroupSelected ? 'checked' : ''} title="Select all variants in this group" />
                                    <button type="button" class="wpe-group-collapse-btn" data-group-key="${escapeHtml(groupKey)}" title="${isGroupCollapsed ? 'Expand group' : 'Collapse group'}">
                                        <span class="dashicons dashicons-arrow-${isGroupCollapsed ? 'right' : 'down'}-alt2"></span>
                                    </button>
                                    <strong>${escapeHtml(groupBy)}: ${escapeHtml(groupVal)}</strong>
                                    <span style="font-size:12px; color:#64748b; font-weight:500;">(${groupMembers.length} variants)</span>
                                </div>
                                <div>
                                    <button type="button" class="button button-small wpe-btn-group-inline-media" data-group-opt="${escapeHtml(groupBy)}" data-group-val="${escapeHtml(groupVal)}">
                                        <span class="dashicons dashicons-format-image"></span> Set Group Image
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
            }

            if (isGroupCollapsed) {
                return;
            }

            const isSelected = wpeVariantStore.selection.has(v.id);
            const isSharedPrice = hasCommonPrice && v.pricing_mode === 'shared';
            const regVal = isSharedPrice
                ? (wpeVariantStore.commonPrice.regular_price || '')
                : (v.regular_price !== null && v.regular_price !== undefined ? v.regular_price : '');
            const saleVal = isSharedPrice
                ? (wpeVariantStore.commonPrice.sale_price || '')
                : (v.sale_price !== null && v.sale_price !== undefined ? v.sale_price : '');
            const isParentStock = !v.manage_stock;

            // Badges
            const comboBadges = Object.entries(attrs).map(([opt, val]) => `
                <span class="wpe-combo-badge"><strong>${escapeHtml(opt)}:</strong> ${escapeHtml(val)}</span>
            `).join('');

            html += `
                <tr class="wpe-variant-row ${isSelected ? 'selected' : ''}" data-variant-id="${v.id}">
                    <td><input type="checkbox" class="wpe-row-check wpe-row-select" data-id="${v.id}" ${isSelected ? 'checked' : ''} /></td>
                    <td>
                        <div class="wpe-media-slot wpe-row-thumb-btn" data-id="${v.id}" title="Click to choose image">
                            ${v.image_url ? `<img src="${escapeHtml(v.image_url)}" alt="" /><span class="wpe-media-remove" title="Unlink image">&times;</span>` : '<span class="dashicons dashicons-format-image" style="color:#94a3b8;"></span>'}
                        </div>
                    </td>
                    <td><div class="wpe-combo-wrap">${comboBadges}</div></td>
                    <td>
                        <input type="text" class="wpe-editable-input wpe-inline-sku" value="${escapeHtml(v.sku || '')}" placeholder="SKU" />
                    </td>
                    ${hasCommonPrice ? `
                    <td>
                        <select class="wpe-editable-input wpe-pricing-mode-select" style="font-size:11px; padding:2px 4px;">
                            <option value="shared" ${isSharedPrice ? 'selected' : ''}>Shared</option>
                            <option value="own" ${!isSharedPrice ? 'selected' : ''}>Own</option>
                        </select>
                    </td>
                    ` : ''}
                    <td>
                        <input type="number" step="any" class="wpe-editable-input wpe-inline-reg-price" value="${escapeHtml(formatNumberForInput(regVal))}" placeholder="0.00" ${isSharedPrice ? 'disabled title="Using Shared Common Price"' : ''} />
                    </td>
                    <td>
                        <input type="number" step="any" class="wpe-editable-input wpe-inline-sale-price" value="${escapeHtml(formatNumberForInput(saleVal))}" placeholder="0.00" ${isSharedPrice ? 'disabled title="Using Shared Common Price"' : ''} />
                    </td>
                    <td>
                        <div class="wpe-stock-cell">
                        ${isParentStock ? `
                            <span class="wpe-stock-pill parent-stock wpe-clickable-stock" title="Click to track stock quantity for this variant">Shared Stock</span>
                        ` : `
                            <input type="number" step="any" class="wpe-editable-input wpe-inline-stock-qty" value="${escapeHtml(formatNumberForInput(v.stock_quantity !== null ? v.stock_quantity : ''))}" placeholder="Qty" />
                            <span class="wpe-stock-pill ${v.stock_status || 'instock'}">${v.stock_status === 'instock' ? 'In Stock' : 'Out of Stock'}</span>
                            <button type="button" class="wpe-btn-revert-shared-stock" title="Revert to Shared Parent Stock">&times;</button>
                        `}
                        </div>
                    </td>
                    <td style="text-align:right;">
                        <div class="wpe-row-actions">
                            <button type="button" class="button button-small wpe-btn-open-drawer" title="Edit details">
                                <span class="dashicons dashicons-edit"></span>
                            </button>
                            <button type="button" class="button-link-delete wpe-btn-delete-variant" title="Delete variant">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        return html;
    }

    /**
     * Render Slide-over Detail Drawer
     */
    function renderDrawer(variantId) {
        const variant = wpeVariantStore.variations.find(v => v.id === variantId);
        if (!variant) return;

        const filteredList = getFilteredVariations();
        const currentIdx = filteredList.findIndex(v => v.id === variant.id);
        const prevVariant = currentIdx > 0 ? filteredList[currentIdx - 1] : null;
        const nextVariant = (currentIdx !== -1 && currentIdx < filteredList.length - 1) ? filteredList[currentIdx + 1] : null;

        const attrs = variant.attributes || variant.combination || {};
        const badgesHtml = Object.entries(attrs).map(([opt, val]) => `
            <span class="wpe-combo-badge"><strong>${escapeHtml(opt)}:</strong> ${escapeHtml(val)}</span>
        `).join('');

        const html = `
            <div class="wpe-drawer-header">
                <div>
                    <h4 class="wpe-drawer-title">Variant Details #${variant.id}</h4>
                    <div style="margin-top:6px;">${badgesHtml}</div>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div class="wpe-drawer-nav">
                        <button type="button" class="wpe-btn-drawer-nav wpe-btn-drawer-prev" data-current-id="${variant.id}" data-target-id="${prevVariant ? prevVariant.id : ''}" ${!prevVariant ? 'disabled' : ''} title="Previous variant">&lsaquo; Prev</button>
                        <button type="button" class="wpe-btn-drawer-nav wpe-btn-drawer-next" data-current-id="${variant.id}" data-target-id="${nextVariant ? nextVariant.id : ''}" ${!nextVariant ? 'disabled' : ''} title="Next variant">Next &rsaquo;</button>
                    </div>
                    <button type="button" class="button-link wpe-btn-close-drawer" style="font-size:22px; text-decoration:none; color:#64748b;">&times;</button>
                </div>
            </div>
            <div class="wpe-drawer-body">
                <!-- Variant Image (Product-scoped) -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Variant Image</h5>
                    <div style="display:flex; align-items:center; gap:14px;">
                        <div style="width:68px; height:68px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
                            ${variant.image_url
                                ? `<img src="${escapeHtml(variant.image_url)}" alt="" style="width:100%; height:100%; object-fit:cover;" />`
                                : '<span class="dashicons dashicons-format-image" style="color:#94a3b8; font-size:24px; width:24px; height:24px;"></span>'}
                        </div>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <div style="font-size:12px; color:#475569;">
                                ${variant.image_url
                                    ? `Assigned from Product Media Library${variant.image_asset_id ? ' (Asset #' + variant.image_asset_id + ')' : ''}`
                                    : 'Using parent product image (no variant-specific image assigned)'}
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="button button-small wpe-btn-drawer-pick-image" data-variant-id="${variant.id}">
                                    <span class="dashicons dashicons-images-alt2" style="margin-top:2px;"></span> Choose Product Image
                                </button>
                                ${variant.image_url ? `
                                    <button type="button" class="button button-small button-link-delete wpe-btn-drawer-clear-image" data-variant-id="${variant.id}">
                                        Remove Image
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Basic info -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Pricing & SKU</h5>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>SKU</label>
                            <input type="text" id="wpe-drawer-sku" value="${escapeHtml(variant.sku || '')}" />
                        </div>
                    </div>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>Regular Price (${config.currencySymbol})</label>
                            <input type="number" step="any" id="wpe-drawer-reg-price" value="${escapeHtml(formatNumberForInput(variant.regular_price))}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Sale Price (${config.currencySymbol})</label>
                            <input type="number" step="any" id="wpe-drawer-sale-price" value="${escapeHtml(formatNumberForInput(variant.sale_price))}" />
                        </div>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Inventory Management</h5>
                    <div style="margin-bottom:12px;">
                        <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" id="wpe-drawer-manage-stock" ${variant.manage_stock ? 'checked' : ''} />
                            Track stock quantity individually for this variant
                        </label>
                    </div>
                    <div class="wpe-form-row" id="wpe-drawer-stock-fields" style="${!variant.manage_stock ? 'display:none;' : ''}">
                        <div class="wpe-form-group">
                            <label>Stock Quantity</label>
                            <input type="number" step="any" id="wpe-drawer-stock-qty" value="${escapeHtml(variant.stock_quantity !== null ? variant.stock_quantity : '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Allow Backorders?</label>
                            <select id="wpe-drawer-backorders">
                                <option value="no" ${(variant.inventory && variant.inventory.backorders === 'no') ? 'selected' : ''}>Do not allow</option>
                                <option value="notify" ${(variant.inventory && variant.inventory.backorders === 'notify') ? 'selected' : ''}>Allow, but notify customer</option>
                                <option value="yes" ${(variant.inventory && variant.inventory.backorders === 'yes') ? 'selected' : ''}>Allow</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Dimensions & Shipping -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Dimensions & Weight</h5>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>Weight (kg)</label>
                            <input type="text" id="wpe-drawer-weight" value="${escapeHtml(variant.weight || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Length (cm)</label>
                            <input type="text" id="wpe-drawer-length" value="${escapeHtml(variant.length || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Width (cm)</label>
                            <input type="text" id="wpe-drawer-width" value="${escapeHtml(variant.width || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Height (cm)</label>
                            <input type="text" id="wpe-drawer-height" value="${escapeHtml(variant.height || '')}" />
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Variant Description</h5>
                    <div class="wpe-form-group">
                        <textarea rows="3" id="wpe-drawer-desc">${escapeHtml(variant.description || '')}</textarea>
                    </div>
                </div>
            </div>
            <div class="wpe-drawer-footer">
                <button type="button" class="button wpe-btn-close-drawer">Close</button>
                <button type="button" class="button button-primary wpe-btn-save-drawer" data-variant-id="${variant.id}">Apply Details</button>
            </div>
        `;

        $('#wpe-variation-drawer-app').html(html);
        $('#wpe-variation-drawer-overlay').fadeIn(150);
        layers.open($('#wpe-variation-drawer-overlay'));
    }

    function updateGeneratorButtonState() {
        const checkedCount = $('#wpe-generator-body .wpe-gen-check:checked').length;
        const $btn = $('#wpe-generator-modal .wpe-btn-apply-generator');
        $btn.prop('disabled', checkedCount === 0);
        $btn.text(checkedCount > 0 ? `Create ${checkedCount} Selected Variant${checkedCount === 1 ? '' : 's'}` : 'Create Selected Variants');
    }

    /**
     * Add Single Custom Variation Modal
     */
    function openAddVariantModal() {
        const options = wpeVariantStore.variationOptions.filter(o => o.name && (o.values || []).length > 0);
        if (options.length === 0) {
            showToast('Please add at least one Variation Option with values first.', 'warning');
            return;
        }

        // Find first combination not yet in wpeVariantStore.variations to pre-select
        const existingKeys = new Set(wpeVariantStore.variations.map(v => {
            const c = v.attributes || v.combination || {};
            return options.map(o => `${o.name}:${c[o.name] || ''}`).join('|');
        }));

        let suggestedCombo = {};
        options.forEach(o => {
            const firstVal = o.values[0];
            suggestedCombo[o.name] = typeof firstVal === 'object' ? firstVal.label : firstVal;
        });

        // Try to find first unused combination
        (function findUnused(idx, current) {
            if (idx >= options.length) {
                const k = options.map(o => `${o.name}:${current[o.name] || ''}`).join('|');
                if (!existingKeys.has(k)) {
                    suggestedCombo = Object.assign({}, current);
                    return true;
                }
                return false;
            }
            const opt = options[idx];
            for (let i = 0; i < opt.values.length; i++) {
                const vObj = opt.values[i];
                const label = typeof vObj === 'object' ? vObj.label : vObj;
                current[opt.name] = label;
                if (findUnused(idx + 1, current)) return true;
            }
            return false;
        })(0, {});

        const optionSelectsHtml = options.map(opt => {
            const valsHtml = (opt.values || []).map(vObj => {
                const label = typeof vObj === 'object' ? vObj.label : vObj;
                const sel = suggestedCombo[opt.name] === label ? 'selected' : '';
                return `<option value="${escapeHtml(label)}" ${sel}>${escapeHtml(label)}</option>`;
            }).join('');
            return `
                <div class="wpe-form-group" style="min-width:140px;">
                    <label>${escapeHtml(opt.name)}</label>
                    <select class="wpe-add-var-opt-select" data-opt-name="${escapeHtml(opt.name)}">${valsHtml}</select>
                </div>
            `;
        }).join('');

        const defaultPriceVal = $('#_regular_price').val() || formatNumberForInput(wpeVariantStore.product.regular_price) || '0';
        const rawParentSku = ($('#_sku').val() || wpeVariantStore.product.sku || '').trim();
        const skuPrefix = rawParentSku ? (rawParentSku.endsWith('-') ? rawParentSku : `${rawParentSku}-`) : '';

        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-add-variant-modal">
                <div class="wpe-modal-dialog">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Add Single Variation</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                            ${optionSelectsHtml}
                        </div>
                        <div id="wpe-add-var-dup-warning" style="display:none; margin-bottom:12px; padding:8px 12px; border-radius:6px; background:#fef2f2; color:#b91c1c; font-size:12px; font-weight:500;">
                            This combination already exists in the matrix.
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                            <div class="wpe-form-group">
                                <label>SKU</label>
                                <input type="text" id="wpe-add-var-sku" placeholder="Auto or custom SKU" />
                            </div>
                            <div class="wpe-form-group">
                                <label>Regular Price (${config.currencySymbol})</label>
                                <input type="number" step="any" id="wpe-add-var-reg-price" value="${escapeHtml(defaultPriceVal)}" placeholder="0.00" />
                            </div>
                            <div class="wpe-form-group">
                                <label>Sale Price (${config.currencySymbol})</label>
                                <input type="number" step="any" id="wpe-add-var-sale-price" value="" placeholder="Optional" />
                            </div>
                            <div class="wpe-form-group">
                                <label>Stock Quantity (leave blank for Shared Stock)</label>
                                <input type="number" step="any" id="wpe-add-var-stock-qty" value="" placeholder="Shared Stock" />
                            </div>
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Cancel</button>
                        <button type="button" class="button button-primary wpe-btn-confirm-add-variant">Add Variation</button>
                    </div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);

        function syncAddVariantState() {
            const combo = {};
            $('#wpe-add-variant-modal .wpe-add-var-opt-select').each(function () {
                combo[$(this).attr('data-opt-name')] = $(this).val();
            });
            const key = options.map(o => `${o.name}:${combo[o.name] || ''}`).join('|');
            const isDup = existingKeys.has(key);
            $('#wpe-add-var-dup-warning').toggle(isDup);
            $('#wpe-add-variant-modal .wpe-btn-confirm-add-variant').prop('disabled', isDup);

            const $sku = $('#wpe-add-var-sku');
            if (!$sku.data('user-edited') && skuPrefix) {
                const suffix = Object.values(combo).map(v => String(v).replace(/\s+/g, '-').toUpperCase()).join('-');
                $sku.val(`${skuPrefix}${suffix}`);
            }
        }

        $('#wpe-add-var-sku').on('input', function () {
            $(this).data('user-edited', $(this).val().trim() !== '');
        });
        $('#wpe-add-variant-modal .wpe-add-var-opt-select').on('change', syncAddVariantState);
        syncAddVariantState();
    }

    /**
     * Selective Combination Generator Modal
     */
    function openGeneratorModal() {
        if (wpeVariantStore.variationOptions.length === 0) {
            alert('Please add at least one Variation Option (e.g. Color, Size) before generating variants.');
            return;
        }

        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-generator-modal">
                <div class="wpe-modal-dialog wpe-modal-dialog-lg">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Selectively Generate Variations</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body" id="wpe-generator-body">
                        <div style="text-align:center; padding:30px;">
                            <span class="spinner is-active" style="float:none;"></span>
                            <p>Analyzing potential combinations...</p>
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Cancel</button>
                        <button type="button" class="button button-primary wpe-btn-apply-generator" disabled>Create Selected Variants</button>
                    </div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);

        api.previewGenerator(wpeVariantStore.variationOptions)
            .done(function (res) {
                if (!res.success) {
                    $('#wpe-generator-body').html(`<p style="color:#ef4444;">Error generating combinations: ${escapeHtml(res.message)}</p>`);
                    return;
                }

                const result = res.result;
                const existingCombos = result.existing_combinations || [];
                const newCombos = result.new_combinations || [];

                let listHtml = '';
                newCombos.forEach((nc, idx) => {
                    const badgeText = Object.entries(nc.combination || nc.attributes || {}).map(([k, v]) => `${k}: ${v}`).join(' / ');
                    listHtml += `
                        <label style="display:block; padding:8px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer;">
                            <input type="checkbox" class="wpe-gen-check" checked data-idx="${idx}" />
                            <strong>${escapeHtml(badgeText)}</strong>
                            <span style="font-size:11px; color:#059669; margin-left:8px; font-weight:600;">[New]</span>
                        </label>
                    `;
                });

                existingCombos.forEach((ec) => {
                    const badgeText = Object.entries(ec.combination || ec.attributes || {}).map(([k, v]) => `${k}: ${v}`).join(' / ');
                    listHtml += `
                        <label style="display:block; padding:8px 12px; border-bottom:1px solid #f1f5f9; color:#94a3b8;">
                            <input type="checkbox" checked disabled />
                            <span>${escapeHtml(badgeText)}</span>
                            <span style="font-size:11px; margin-left:8px;">[Existing - SKU: ${escapeHtml(ec.sku || '')}]</span>
                        </label>
                    `;
                });

                let quickFilterBarHtml = '';
                if (newCombos.length > 0 && wpeVariantStore.variationOptions.length > 0) {
                    const groupsHtml = wpeVariantStore.variationOptions.map(opt => {
                        const vals = opt.values || [];
                        if (!vals.length) return '';
                        const chips = vals.map(vObj => {
                            const label = typeof vObj === 'object' ? vObj.label : vObj;
                            return `<button type="button" class="wpe-gen-filter-chip" data-opt-name="${escapeHtml(opt.name)}" data-opt-val="${escapeHtml(label)}" title="Toggle combinations with ${escapeHtml(opt.name)}: ${escapeHtml(label)}">${escapeHtml(label)}</button>`;
                        }).join('');
                        return `<div class="wpe-gen-filter-group"><strong>${escapeHtml(opt.name)}:</strong> ${chips}</div>`;
                    }).filter(Boolean).join('');
                    if (groupsHtml) {
                        quickFilterBarHtml = `
                            <div class="wpe-gen-filter-bar">
                                <span style="font-size:11px; color:#64748b; font-weight:600;">Quick toggle by attribute value:</span>
                                ${groupsHtml}
                            </div>
                        `;
                    }
                }

                const defaultPriceVal = $('#_regular_price').val() || formatNumberForInput(wpeVariantStore.product.regular_price) || '0';
                const rawParentSku = ($('#_sku').val() || wpeVariantStore.product.sku || '').trim();
                const defaultSkuPrefix = rawParentSku ? (rawParentSku.endsWith('-') ? rawParentSku : `${rawParentSku}-`) : '';

                const bodyHtml = `
                    <div style="background:#eff6ff; padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:12px; color:#1e40af;">
                        Total Potential: <strong>${result.total_possible || result.total_potential}</strong> |
                        Existing: <strong>${result.total_existing}</strong> |
                        Available to Create: <strong>${result.total_new}</strong>
                    </div>

                    <div style="display:flex; gap:14px; margin-bottom:16px; background:#f8fafc; padding:12px 14px; border-radius:6px; border:1px solid #e2e8f0;">
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600; color:#475569;">Default Regular Price (${config.currencySymbol}):</label>
                            <input type="number" step="any" id="wpe-gen-def-price" value="${escapeHtml(defaultPriceVal)}" placeholder="0.00" style="width:100%; font-size:12px; margin-top:4px;" />
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600; color:#475569;">SKU Prefix:</label>
                            <input type="text" id="wpe-gen-def-sku" value="${escapeHtml(defaultSkuPrefix)}" placeholder="SKU-" style="width:100%; font-size:12px; margin-top:4px;" />
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600; color:#475569;">Initial Stock Quantity:</label>
                            <input type="number" step="any" id="wpe-gen-def-stock" placeholder="10" style="width:100%; font-size:12px; margin-top:4px;" />
                        </div>
                    </div>

                    ${quickFilterBarHtml}

                    <div style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; font-size:12px; color:#0f172a;">Select combinations to create:</span>
                        <div>
                            <button type="button" class="button-link wpe-btn-gen-select-all" style="font-size:12px;">Select All</button> |
                            <button type="button" class="button-link wpe-btn-gen-unselect-all" style="font-size:12px;">Deselect All</button>
                        </div>
                    </div>

                    <div style="max-height:280px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:6px; background:#fff;">
                        ${listHtml || '<div style="padding:24px; text-align:center; color:#64748b;">No new combinations available to generate.</div>'}
                    </div>
                `;

                generatorNewCombos = newCombos;
                $('#wpe-generator-body').html(bodyHtml);
                $('#wpe-generator-modal .wpe-btn-apply-generator').data('new-combos', newCombos);
                updateGeneratorButtonState();
            })
            .fail(function () {
                $('#wpe-generator-body').html(`<p style="color:#ef4444;">Server connection error.</p>`);
            });
    }

    /**
     * Bulk Edit Modal
     */
    function openBulkEditModal() {
        const selectedIds = Array.from(wpeVariantStore.selection);
        if (selectedIds.length === 0) return;

        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-bulk-modal">
                <div class="wpe-modal-dialog wpe-modal-dialog-lg">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Bulk Edit (${selectedIds.length} variants selected)</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body" id="wpe-bulk-body">
                        <div style="margin-bottom:14px;">
                            <label style="font-weight:600; display:block; margin-bottom:6px;">Select field to update:</label>
                            <select id="wpe-bulk-field" style="width:100%;">
                                <option value="regular_price">Regular Price</option>
                                <option value="sale_price">Sale Price</option>
                                <option value="manage_stock">Stock Management</option>
                                <option value="stock_quantity">Stock Quantity</option>
                                <option value="sku_prefix">Auto-Generate SKUs by Prefix</option>
                            </select>
                        </div>

                        <div id="wpe-bulk-field-controls" style="margin-bottom:14px;">
                            <!-- Field specific inputs injected here -->
                        </div>

                        <button type="button" class="button wpe-btn-preview-bulk-diff">
                            <span class="dashicons dashicons-visibility" style="margin-top:3px;"></span> Preview Changes
                        </button>

                        <div id="wpe-bulk-diff-preview" style="margin-top:14px; max-height:220px; overflow-y:auto; display:none;">
                            <!-- Diff table will be rendered here -->
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Cancel</button>
                        <button type="button" class="button button-primary wpe-btn-apply-bulk">Apply Changes</button>
                    </div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);
        renderBulkFieldControls('regular_price');
    }

    function renderBulkFieldControls(field) {
        let html = '';
        if (field === 'regular_price' || field === 'sale_price') {
            const clearOpt = field === 'sale_price' ? `<option value="clear">Clear sale price (remove discount)</option>` : '';
            html = `
                <div style="display:flex; gap:10px;">
                    <select id="wpe-bulk-price-type" style="width:220px;">
                        <option value="fixed">Set fixed price</option>
                        <option value="increase_fixed">Increase by amount</option>
                        <option value="decrease_fixed">Decrease by amount</option>
                        <option value="increase_percent">Increase by percentage (%)</option>
                        <option value="decrease_percent">Decrease by percentage (%)</option>
                        ${clearOpt}
                    </select>
                    <input type="number" step="any" id="wpe-bulk-price-val" placeholder="Enter value" style="flex:1;" />
                </div>
            `;
        } else if (field === 'manage_stock') {
            html = `
                <select id="wpe-bulk-manage-stock-val" style="width:100%;">
                    <option value="1">Enable individual stock tracking</option>
                    <option value="0">Disable (Use shared parent stock)</option>
                </select>
            `;
        } else if (field === 'stock_quantity') {
            html = `<input type="number" step="any" id="wpe-bulk-stock-qty-val" placeholder="Enter quantity" style="width:100%;" />`;
        } else if (field === 'sku_prefix') {
            const rawParentSku = ($('#_sku').val() || wpeVariantStore.product.sku || '').trim();
            const defPrefix = rawParentSku ? (rawParentSku.endsWith('-') ? rawParentSku : `${rawParentSku}-`) : 'SKU-';
            html = `
                <div>
                    <input type="text" id="wpe-bulk-sku-prefix-val" value="${escapeHtml(defPrefix)}" placeholder="e.g. TEE-" style="width:100%;" />
                    <small style="display:block; margin-top:4px; color:#64748b;">Generates SKU as <code>{PREFIX}{OPTION_VALUES}</code> (e.g. <code>${escapeHtml(defPrefix)}S-BLACK</code>).</small>
                </div>
            `;
        }
        $('#wpe-bulk-field-controls').html(html);
    }

    /**
     * Modal: Assign Media by Option Group (delegates to Product-Scoped Variant Image Picker)
     */
    function openMediaByOptionModal() {
        const options = wpeVariantStore.variationOptions;
        if (options.length === 0) {
            showToast('No variation options available (Color, Size...) to assign images by group.', 'warning');
            return;
        }
        openProductVariantImagePicker({ mode: 'option' });
    }

    /**
     * Open the fullscreen editor. It renders from the same store as the inline editor, so filters,
     * selection and edits are shared; closing it never saves or discards anything.
     */
    function openFullscreen(initialTab) {
        if (typeof initialTab === 'string' && initialTab) {
            wpeVariantStore.fullscreenTab = initialTab;
        }
        const $overlay = $('#wpe-fullscreen-modal-overlay');
        if (!$overlay.length || $overlay.is(':visible')) return;
        $overlay.fadeIn(150);
        renderEditor('#wpe-product-editor-modal-app');
        layers.open($overlay, {
            onClose: function () {
                $overlay.fadeOut(150);
                if ($('#wpe-product-editor-inline-app').length) renderVariantsApp('#wpe-product-editor-inline-app');
                if ($('#wpe-product-info-app').length) renderProductInfoApp('#wpe-product-info-app');
            }
        });
    }

    /**
     * Bind all DOM events
     */
    function bindEditorEvents($container, containerId) {
        if (!containerId) {
            containerId = '#' + ($container.attr('id') || 'wpe-product-editor-inline-app');
        }

        // Fullscreen toggle
        $container.find('.wpe-btn-open-fullscreen').off('click').on('click', function () {
            const targetTab = $(this).attr('data-target-tab') || 'variants';
            openFullscreen(targetTab);
        });

        // Fullscreen tab switch
        $container.find('.wpe-modal-nav .wpe-tab-btn').off('click').on('click', function () {
            const tab = $(this).attr('data-tab');
            if (tab) {
                wpeVariantStore.fullscreenTab = tab;
                renderFullscreenApp('#wpe-product-editor-modal-app');
            }
        });

        // Empty variants card: Add First Option (Simple -> Variable transition)
        $container.find('.wpe-btn-add-first-option').off('click').on('click', function () {
            openSimpleToVariableModal();
        });

        $('#wpe-fullscreen-modal-overlay').find('.wpe-btn-close-fullscreen').off('click').on('click', function () {
            layers.close($('#wpe-fullscreen-modal-overlay'));
        });

        // Save via REST API
        $container.find('.wpe-btn-save-api').off('click').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true).text(config.i18n.saving);

            const payload = buildSavePayload();

            api.saveData(payload)
                .done(function (res) {
                    if (res.success) {
                        if (res.result && res.result.data) {
                            applyServerData(res.result.data);
                        } else {
                            wpeVariantStore.version = (res.result && res.result.new_version) || (wpeVariantStore.version + 1);
                        }
                        wpeVariantStore.isDirty = false;
                        wpeVariantStore.mediaDirty = false;
                        wpeVariantStore.typeTransition = null;
                        wpeVariantStore.notify();
                        const proj = (res.result && res.result.projection) || null;
                        const okMirror = ['mirrored', 'skipped_obsolete_revision'];
                        if (proj && (okMirror.indexOf(proj.legacy_mirror) === -1 || proj.catalog_index !== 'ok')) {
                            showToast(`${config.i18n.projectionWarning} (mirror: ${proj.legacy_mirror}, index: ${proj.catalog_index})`, 'warning');
                        } else {
                            showToast(config.i18n.saveSuccess, 'success');
                        }
                    } else {
                        showToast(res.message || config.i18n.saveError, 'error');
                    }
                })
                .fail(function (xhr) {
                    const res = xhr.responseJSON || {};
                    if (xhr.status === 409 && res.code === 'concurrency_conflict') {
                        alert(config.i18n.conflictError + '\n\n' + res.message);
                    } else {
                        alert(config.i18n.saveError + ' ' + (res.message || 'Server error saving variants.'));
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Changes');
                });
        });

        // Variation Options: Chip Input (Enter, comma, blur auto-commit, multi-value paste) & Remove
        $container.find('.wpe-chip-input').off('keydown blur paste')
            .on('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    const raw = $(this).val();
                    const $chipsWrap = $(this).closest('.wpe-chips-container');
                    const optIdx = parseInt($chipsWrap.attr('data-opt-idx'), 10);
                    $(this).val('');
                    if (!isNaN(optIdx) && addValuesToOption(optIdx, raw) > 0) {
                        wpeVariantStore.markDirty();
                        const $activeRoot = $(containerId);
                        $activeRoot.find(`.wpe-chips-container[data-opt-idx="${optIdx}"] .wpe-chip-input`).trigger('focus');
                    }
                }
            })
            .on('blur', function () {
                const raw = $(this).val().trim();
                if (!raw) return;
                const $chipsWrap = $(this).closest('.wpe-chips-container');
                const optIdx = parseInt($chipsWrap.attr('data-opt-idx'), 10);
                $(this).val('');
                if (!isNaN(optIdx) && addValuesToOption(optIdx, raw) > 0) {
                    wpeVariantStore.markDirty();
                }
            })
            .on('paste', function (e) {
                const clipboardData = (e.originalEvent || e).clipboardData || window.clipboardData;
                const pastedText = clipboardData ? clipboardData.getData('text') : '';
                if (pastedText && /[,\n\t]/.test(pastedText)) {
                    e.preventDefault();
                    const $chipsWrap = $(this).closest('.wpe-chips-container');
                    const optIdx = parseInt($chipsWrap.attr('data-opt-idx'), 10);
                    if (!isNaN(optIdx)) {
                        const added = addValuesToOption(optIdx, pastedText);
                        if (added > 0) {
                            showToast(`Added ${added} option values!`, 'success');
                            wpeVariantStore.markDirty();
                            $(containerId).find(`.wpe-chips-container[data-opt-idx="${optIdx}"] .wpe-chip-input`).trigger('focus');
                        }
                    }
                }
            });

        $container.find('.wpe-chip-remove').off('click').on('click', function (e) {
            e.stopPropagation();
            const $chip = $(this).closest('.wpe-chip');
            const valIdx = $chip.data('val-idx');
            const optIdx = $chip.data('opt-idx');

            if (optIdx !== undefined) {
                const opt = wpeVariantStore.variationOptions[optIdx];
                const valObj = opt.values[valIdx];
                const valLabel = typeof valObj === 'object' ? valObj.label : valObj;

                const affected = wpeVariantStore.variations.filter(v => {
                    const combo = v.attributes || v.combination || {};
                    return combo[opt.name] === valLabel;
                });

                if (affected.length > 0) {
                    const skuList = affected.map(v => v.sku || `#${v.id}`).slice(0, 5).join(', ');
                    if (!confirm(`Deleting value "${valLabel}" will affect ${affected.length} variants (${skuList}...). Continue?`)) {
                        return;
                    }
                }

                const valId = (typeof valObj === 'object' && valObj !== null && valObj.id) ? valObj.id : null;
                if (opt.default_value === valLabel || (valId && String(opt.default_value_id) === String(valId))) {
                    opt.default_value = null;
                    opt.default_value_id = null;
                }

                opt.values.splice(valIdx, 1);
                wpeVariantStore.markDirty();
            }
        });

        // Reorder Option Card Up / Down
        $container.find('.wpe-btn-move-option-up').off('click').on('click', function () {
            const idx = parseInt($(this).attr('data-opt-idx'), 10);
            if (idx > 0 && wpeVariantStore.variationOptions[idx]) {
                const temp = wpeVariantStore.variationOptions[idx - 1];
                wpeVariantStore.variationOptions[idx - 1] = wpeVariantStore.variationOptions[idx];
                wpeVariantStore.variationOptions[idx] = temp;
                wpeVariantStore.variationOptions.forEach((o, i) => { o.position = i; });
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-btn-move-option-down').off('click').on('click', function () {
            const idx = parseInt($(this).attr('data-opt-idx'), 10);
            if (idx >= 0 && idx < wpeVariantStore.variationOptions.length - 1) {
                const temp = wpeVariantStore.variationOptions[idx + 1];
                wpeVariantStore.variationOptions[idx + 1] = wpeVariantStore.variationOptions[idx];
                wpeVariantStore.variationOptions[idx] = temp;
                wpeVariantStore.variationOptions.forEach((o, i) => { o.position = i; });
                wpeVariantStore.markDirty();
            }
        });

        // Reorder Chip Value Left / Right
        $container.find('.wpe-chip-move-prev').off('click').on('click', function (e) {
            e.stopPropagation();
            const optIdx = parseInt($(this).attr('data-opt-idx'), 10);
            const valIdx = parseInt($(this).attr('data-val-idx'), 10);
            const opt = wpeVariantStore.variationOptions[optIdx];
            if (opt && Array.isArray(opt.values) && valIdx > 0) {
                const temp = opt.values[valIdx - 1];
                opt.values[valIdx - 1] = opt.values[valIdx];
                opt.values[valIdx] = temp;
                opt.values.forEach((v, i) => { if (typeof v === 'object' && v !== null) v.position = i; });
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-chip-move-next').off('click').on('click', function (e) {
            e.stopPropagation();
            const optIdx = parseInt($(this).attr('data-opt-idx'), 10);
            const valIdx = parseInt($(this).attr('data-val-idx'), 10);
            const opt = wpeVariantStore.variationOptions[optIdx];
            if (opt && Array.isArray(opt.values) && valIdx >= 0 && valIdx < opt.values.length - 1) {
                const temp = opt.values[valIdx + 1];
                opt.values[valIdx + 1] = opt.values[valIdx];
                opt.values[valIdx] = temp;
                opt.values.forEach((v, i) => { if (typeof v === 'object' && v !== null) v.position = i; });
                wpeVariantStore.markDirty();
            }
        });

        // Add Variation Option (from Global Attribute template or inline Custom Option card)
        $container.find('.wpe-btn-add-option').off('click').on('click', function () {
            const $select = $container.find('.wpe-add-taxonomy-select');
            const taxSlug = $select.length ? $select.val() : '';
            let optName = '';
            let initialValues = [];

            if (taxSlug) {
                const taxObj = wpeVariantStore.taxonomies.find(t => t.name === taxSlug);
                optName = taxObj ? (taxObj.label || taxObj.name) : taxSlug;
                if (taxObj && Array.isArray(taxObj.terms)) {
                    initialValues = taxObj.terms.map((t, idx) => ({
                        label: t.name,
                        value_key: t.slug || t.name,
                        position: idx
                    }));
                }
            } else {
                let n = wpeVariantStore.variationOptions.length + 1;
                optName = `Option ${n}`;
                while (wpeVariantStore.variationOptions.some(o => (o.name || '').toLowerCase() === optName.toLowerCase())) {
                    n++;
                    optName = `Option ${n}`;
                }
            }

            const newIdx = wpeVariantStore.variationOptions.length;
            wpeVariantStore.variationOptions.push({
                name: optName,
                position: newIdx,
                display_type: 'auto',
                help_text: '',
                values: initialValues
            });
            wpeVariantStore.markDirty();
            if (!taxSlug) {
                const $nameInput = $(containerId).find(`.wpe-option-card[data-idx="${newIdx}"] .wpe-option-name-input`);
                $nameInput.trigger('focus').trigger('select');
            }
        });

        // Option Default Value
        $container.find('.wpe-option-default-value-select').off('change').on('change', function () {
            const idx = parseInt($(this).attr('data-opt-idx'), 10);
            if (!isNaN(idx) && wpeVariantStore.variationOptions[idx]) {
                const opt = wpeVariantStore.variationOptions[idx];
                const chosen = $(this).val() || '';
                if (chosen === '') {
                    opt.default_value = null;
                    opt.default_value_id = null;
                } else {
                    opt.default_value = chosen;
                    const matched = (opt.values || []).find(v => (typeof v === 'object' ? v.label : v) === chosen);
                    if (matched && typeof matched === 'object' && matched.id) {
                        opt.default_value_id = matched.id;
                    } else {
                        delete opt.default_value_id;
                    }
                }
                wpeVariantStore.markDirty();
            }
        });

        // Rename Option
        $container.find('.wpe-option-name-input').off('change').on('change', function () {
            const idx = $(this).closest('.wpe-option-card').data('idx');
            const newName = $(this).val().trim();
            const oldName = wpeVariantStore.variationOptions[idx].name;

            if (newName && newName !== oldName) {
                wpeVariantStore.variationOptions[idx].name = newName;
                wpeVariantStore.variations.forEach(v => {
                    const combo = v.attributes || v.combination || {};
                    if (combo[oldName] !== undefined) {
                        combo[newName] = combo[oldName];
                        delete combo[oldName];
                    }
                });
                wpeVariantStore.markDirty();
            }
        });

        // Delete Option
        $container.find('.wpe-btn-delete-option').off('click').on('click', function () {
            const idx = $(this).closest('.wpe-option-card').data('idx');
            const opt = wpeVariantStore.variationOptions[idx];

            if (config.editorMode === 'blocks' && wpeVariantStore.variationOptions.length === 1) {
                if (wpeVariantStore.variations.length > 0) {
                    openVariableToSimpleModal();
                    return;
                }
                wpeVariantStore.variationOptions = [];
                wpeVariantStore.derivedType = 'simple';
                wpeVariantStore.type = 'simple';
                wpeVariantStore.product.type = 'simple';
                wpeVariantStore.markDirty();
                return;
            }

            const affected = wpeVariantStore.variations.filter(v => {
                const combo = v.attributes || v.combination || {};
                return combo[opt.name] !== undefined;
            });

            if (affected.length > 0) {
                if (!confirm(`Deleting option "${opt.name}" will affect ${affected.length} existing variants. Are you sure you want to delete?`)) {
                    return;
                }
            }

            wpeVariantStore.variationOptions.splice(idx, 1);
            wpeVariantStore.markDirty();
        });

        // Toggle Bulk Paste Box
        $container.find('.wpe-btn-toggle-bulk-paste').off('click').on('click', function () {
            const $box = $(this).closest('.wpe-option-card').find('.wpe-bulk-paste-box');
            $box.slideToggle(150);
        });

        $container.find('.wpe-btn-cancel-bulk-paste').off('click').on('click', function () {
            $(this).closest('.wpe-bulk-paste-box').slideUp(150);
        });

        $container.find('.wpe-btn-apply-bulk-paste').off('click').on('click', function () {
            const $card = $(this).closest('.wpe-option-card');
            const idx = parseInt($card.attr('data-idx'), 10);
            const text = $card.find('.wpe-bulk-paste-box textarea').val();
            if (!text || !text.trim() || isNaN(idx)) return;

            const added = addValuesToOption(idx, text);
            if (added > 0) {
                showToast(`Added ${added} option values!`, 'success');
            }
            wpeVariantStore.markDirty();
        });

        // Filters & Search (use 'input' only so arrow keys / Shift do not trigger re-renders)
        $container.find('.wpe-search-input, .wpe-filter-input').off('input keyup').on('input', function () {
            wpeVariantStore.filters.search = $(this).val();
            wpeVariantStore.filters.page = 1;
            renderEditor(containerId);
        });

        $container.find('.wpe-filter-attr').off('change').on('change', function () {
            wpeVariantStore.filters.attrFilter = $(this).val();
            wpeVariantStore.filters.page = 1;
            renderEditor(containerId);
        });

        $container.find('.wpe-filter-stock').off('change').on('change', function () {
            wpeVariantStore.filters.stockStatus = $(this).val();
            wpeVariantStore.filters.page = 1;
            renderEditor(containerId);
        });

        $container.find('.wpe-group-by-select').off('change').on('change', function () {
            wpeVariantStore.filters.groupBy = $(this).val();
            wpeVariantStore.filters.page = 1;
            renderEditor(containerId);
        });

        // Pagination Prev/Next
        $container.find('.wpe-page-prev').off('click').on('click', function () {
            if (wpeVariantStore.filters.page > 1) {
                wpeVariantStore.filters.page--;
                renderEditor(containerId);
            }
        });

        $container.find('.wpe-page-next').off('click').on('click', function () {
            wpeVariantStore.filters.page++;
            renderEditor(containerId);
        });

        // Selection checkboxes
        $container.find('.wpe-check-all-page').off('change').on('change', function () {
            const isChecked = $(this).is(':checked');
            $container.find('.wpe-row-check').each(function () {
                const vId = $(this).closest('tr').data('variant-id');
                if (isChecked) {
                    wpeVariantStore.selection.add(vId);
                } else {
                    wpeVariantStore.selection.delete(vId);
                }
            });
            renderEditor(containerId);
        });

        $container.find('.wpe-row-check').off('click change').on('click', function (e) {
            const $rows = $container.find('tr.wpe-variant-row');
            const $currentRow = $(this).closest('tr');
            const vId = $currentRow.data('variant-id');
            const isChecked = $(this).is(':checked');

            if (e.shiftKey && wpeVariantStore.lastCheckedVariantId !== null) {
                const startIdx = $rows.index($rows.filter(`[data-variant-id="${wpeVariantStore.lastCheckedVariantId}"]`));
                const endIdx = $rows.index($currentRow);
                if (startIdx !== -1 && endIdx !== -1) {
                    const minIdx = Math.min(startIdx, endIdx);
                    const maxIdx = Math.max(startIdx, endIdx);
                    $rows.slice(minIdx, maxIdx + 1).each(function () {
                        const rowVId = $(this).data('variant-id');
                        if (isChecked) {
                            wpeVariantStore.selection.add(rowVId);
                        } else {
                            wpeVariantStore.selection.delete(rowVId);
                        }
                    });
                }
            } else {
                if (isChecked) {
                    wpeVariantStore.selection.add(vId);
                } else {
                    wpeVariantStore.selection.delete(vId);
                }
            }
            wpeVariantStore.lastCheckedVariantId = vId;
            renderEditor(containerId);
        });

        $container.find('.wpe-btn-clear-selection').off('click').on('click', function () {
            wpeVariantStore.selection.clear();
            renderEditor(containerId);
        });

        $container.find('.wpe-btn-select-all-filtered').off('click').on('click', function () {
            getFilteredVariations().forEach(v => wpeVariantStore.selection.add(v.id));
            renderEditor(containerId);
        });

        // Bulk Delete Selected Variations
        $container.find('.wpe-btn-bulk-delete').off('click').on('click', function () {
            const selectedIds = Array.from(wpeVariantStore.selection);
            if (selectedIds.length === 0) return;
            if (!confirm(`Are you sure you want to delete ${selectedIds.length} selected variants?`)) {
                return;
            }
            wpeVariantStore.variations = wpeVariantStore.variations.filter(v => !wpeVariantStore.selection.has(v.id));
            wpeVariantStore.selection.clear();
            showToast(`Deleted ${selectedIds.length} variants.`, 'info');
            wpeVariantStore.markDirty();
        });

        // Group Header Row Actions: Select Group, Collapse/Expand Group, Set Group Image
        $container.find('.wpe-group-check').off('change').on('change', function (e) {
            e.stopPropagation();
            const isChecked = $(this).is(':checked');
            const $tr = $(this).closest('tr.wpe-group-row');
            const gAttr = $tr.attr('data-group-opt') || $tr.attr('data-group-attr');
            const gVal = $tr.attr('data-group-val');
            wpeVariantStore.variations.forEach(v => {
                const combo = v.attributes || v.combination || {};
                const val = combo[gAttr] || 'Unassigned';
                if (val === gVal) {
                    if (isChecked) wpeVariantStore.selection.add(v.id);
                    else wpeVariantStore.selection.delete(v.id);
                }
            });
            renderEditor(containerId);
        });

        $container.find('.wpe-group-collapse-btn').off('click').on('click', function (e) {
            e.stopPropagation();
            const groupKey = $(this).closest('tr.wpe-group-row').attr('data-group-key');
            if (!groupKey) return;
            if (wpeVariantStore.collapsedGroups.has(groupKey)) {
                wpeVariantStore.collapsedGroups.delete(groupKey);
            } else {
                wpeVariantStore.collapsedGroups.add(groupKey);
            }
            renderEditor(containerId);
        });

        $container.find('.wpe-btn-group-inline-media').off('click').on('click', function (e) {
            e.stopPropagation();
            const gAttr = $(this).attr('data-group-opt') || $(this).attr('data-group-attr');
            const gVal = $(this).attr('data-group-val');
            if (!gAttr || !gVal) return;

            openProductVariantImagePicker({
                mode: 'option',
                optionName: gAttr,
                optionValue: gVal
            });
        });

        // Non-destructive inline cell editing + spreadsheet keyboard navigation (Enter / ArrowUp / ArrowDown)
        function handleInlineGridNav(e, $input, selector) {
            if (e.key === 'Enter' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const $inputs = $container.find(selector);
                const curIdx = $inputs.index($input);
                const dir = e.key === 'ArrowUp' ? -1 : 1;
                const nextIdx = curIdx + dir;
                if (nextIdx >= 0 && nextIdx < $inputs.length) {
                    $inputs.eq(nextIdx).trigger('focus').trigger('select');
                }
            }
        }

        $container.find('.wpe-inline-sku').off('input change keydown')
            .on('input change', function () {
                const vId = $(this).closest('tr').data('variant-id');
                const v = wpeVariantStore.variations.find(item => item.id === vId);
                if (v) {
                    v.sku = $(this).val().trim();
                    wpeVariantStore.markDirtyInline(containerId);
                }
            })
            .on('keydown', function (e) {
                handleInlineGridNav(e, $(this), '.wpe-inline-sku');
            });

        $container.find('.wpe-pricing-mode-select').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (!v) return;
            const mode = $(this).val();
            v.pricing_mode = mode === 'shared' ? 'shared' : 'own';
            if (v.pricing_mode === 'shared' && wpeVariantStore.commonPrice) {
                v.regular_price = wpeVariantStore.commonPrice.regular_price || '0';
                v.sale_price = wpeVariantStore.commonPrice.sale_price || '';
            } else if (v.pricing_mode === 'own' && wpeVariantStore.commonPrice && emptyVal(v.regular_price)) {
                v.regular_price = wpeVariantStore.commonPrice.regular_price || '0';
                v.sale_price = wpeVariantStore.commonPrice.sale_price || '';
            }
            wpeVariantStore.markDirty();
        });

        $container.find('.wpe-inline-reg-price').off('input change keydown')
            .on('input change', function () {
                const vId = $(this).closest('tr').data('variant-id');
                const v = wpeVariantStore.variations.find(item => item.id === vId);
                if (v) {
                    v.regular_price = $(this).val();
                    v.pricing_mode = 'own';
                    wpeVariantStore.markDirtyInline(containerId);
                }
            })
            .on('keydown', function (e) {
                handleInlineGridNav(e, $(this), '.wpe-inline-reg-price');
            });

        $container.find('.wpe-inline-sale-price').off('input change keydown')
            .on('input change', function () {
                const vId = $(this).closest('tr').data('variant-id');
                const v = wpeVariantStore.variations.find(item => item.id === vId);
                if (v) {
                    v.sale_price = $(this).val();
                    v.pricing_mode = 'own';
                    wpeVariantStore.markDirtyInline(containerId);
                }
            })
            .on('keydown', function (e) {
                handleInlineGridNav(e, $(this), '.wpe-inline-sale-price');
            });

        $container.find('.wpe-inline-stock-qty').off('input change keydown')
            .on('input change', function () {
                const vId = $(this).closest('tr').data('variant-id');
                const v = wpeVariantStore.variations.find(item => item.id === vId);
                if (v && v.manage_stock) {
                    v.stock_quantity = parseFloat($(this).val()) || 0;
                    v.stock_status = v.stock_quantity > 0 ? 'instock' : 'outofstock';
                    if (!v.inventory) v.inventory = {};
                    v.inventory.manage_stock = 1;
                    v.inventory.quantity = v.stock_quantity;
                    v.inventory.stock_status = v.stock_status;
                    wpeVariantStore.markDirtyInline(containerId);
                }
            })
            .on('keydown', function (e) {
                handleInlineGridNav(e, $(this), '.wpe-inline-stock-qty');
            });

        // 1-Click Toggle between Shared Stock and Individual Stock Qty in Table
        $container.find('.wpe-clickable-stock').off('click').on('click', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.manage_stock = true;
                v.stock_quantity = v.stock_quantity !== null && v.stock_quantity !== undefined ? v.stock_quantity : 10;
                v.stock_status = v.stock_quantity > 0 ? 'instock' : 'outofstock';
                if (!v.inventory) v.inventory = {};
                v.inventory.manage_stock = 1;
                v.inventory.quantity = v.stock_quantity;
                v.inventory.stock_status = v.stock_status;
                wpeVariantStore.markDirty();
                $(containerId).find(`tr[data-variant-id="${vId}"] .wpe-inline-stock-qty`).trigger('focus').trigger('select');
            }
        });

        $container.find('.wpe-btn-revert-shared-stock').off('click').on('click', function (e) {
            e.stopPropagation();
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.manage_stock = false;
                v.stock_quantity = null;
                v.stock_status = 'instock';
                if (!v.inventory) v.inventory = {};
                v.inventory.manage_stock = 0;
                v.inventory.quantity = null;
                v.inventory.stock_status = 'instock';
                wpeVariantStore.markDirty();
            }
        });

        // Single Variation Media Picker (Product-scoped)
        $container.find('.wpe-media-slot').off('click').on('click', function (e) {
            if ($(e.target).hasClass('wpe-media-remove')) return;
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (!v) return;

            openProductVariantImagePicker({
                mode: 'single',
                variantId: vId
            });
        });

        // Single Variation Media Remove (unlink only)
        $container.find('.wpe-media-remove').off('click').on('click', function (e) {
            e.stopPropagation();
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.image_asset_id = null;
                v.image_url = null;
                v.attachment_id = null;
                wpeVariantStore.markDirty();
            }
        });

        // Bulk Assign Media to selected rows (Product-scoped)
        $container.find('.wpe-btn-bulk-assign-media').off('click').on('click', function () {
            const selectedIds = Array.from(wpeVariantStore.selection);
            if (selectedIds.length === 0) return;

            openProductVariantImagePicker({
                mode: 'bulk',
                variantIds: selectedIds
            });
        });

        // Delete Single Variation
        $container.find('.wpe-btn-delete-variant').off('click').on('click', function () {
            const vId = $(this).closest('tr').data('variant-id');
            if (confirm('Are you sure you want to delete this variant?')) {
                const idx = wpeVariantStore.variations.findIndex(v => v.id === vId);
                if (idx !== -1) {
                    wpeVariantStore.variations.splice(idx, 1);
                    wpeVariantStore.selection.delete(vId);
                    wpeVariantStore.markDirty();
                }
            }
        });

        // Open Slide-over Drawer
        $container.find('.wpe-btn-open-drawer').off('click').on('click', function () {
            const vId = $(this).closest('tr').data('variant-id');
            renderDrawer(vId);
        });

        // Open Single Manual Variant Modal
        $container.find('.wpe-btn-add-single-variant').off('click').on('click', function () {
            openAddVariantModal();
        });

        // Open Selective Combination Generator Modal
        $container.find('.wpe-btn-open-generator').off('click').on('click', function () {
            openGeneratorModal();
        });

        // Open Bulk Edit Modal
        $container.find('.wpe-btn-open-bulk-edit').off('click').on('click', function () {
            openBulkEditModal();
        });

        // Open Assign Media by Option Modal
        $container.find('.wpe-btn-assign-media-by-option').off('click').on('click', function () {
            openMediaByOptionModal();
        });
    }

    function commitDrawerEdits(vId) {
        const v = wpeVariantStore.variations.find(item => item.id === vId);
        if (!v || !$('#wpe-drawer-sku').length) return false;

        v.sku = $('#wpe-drawer-sku').val().trim();
        v.regular_price = $('#wpe-drawer-reg-price').val();
        v.sale_price = $('#wpe-drawer-sale-price').val();
        v.pricing_mode = 'own';
        v.manage_stock = $('#wpe-drawer-manage-stock').is(':checked');
        v.stock_quantity = v.manage_stock ? parseFloat($('#wpe-drawer-stock-qty').val()) || 0 : null;
        v.stock_status = v.manage_stock ? (v.stock_quantity > 0 ? 'instock' : 'outofstock') : 'instock';
        v.weight = $('#wpe-drawer-weight').val();
        v.length = $('#wpe-drawer-length').val();
        v.width = $('#wpe-drawer-width').val();
        v.height = $('#wpe-drawer-height').val();
        v.description = $('#wpe-drawer-desc').val();

        if (!v.inventory) v.inventory = {};
        v.inventory.manage_stock = v.manage_stock ? 1 : 0;
        v.inventory.quantity = v.stock_quantity;
        v.inventory.backorders = $('#wpe-drawer-backorders').val();
        return true;
    }

    /**
     * Bind Global & Modal Events
     */
    function bindGlobalEvents() {
        // Drawer close
        $(document).on('click', '.wpe-btn-close-drawer, #wpe-variation-drawer-overlay', function (e) {
            if (e.target === this) {
                layers.close($('#wpe-variation-drawer-overlay'));
            }
        });

        // Drawer Prev / Next variant navigation
        $(document).on('click', '.wpe-btn-drawer-prev, .wpe-btn-drawer-next', function () {
            const currentId = parseInt($(this).attr('data-current-id'), 10);
            const targetId = parseInt($(this).attr('data-target-id'), 10);
            if (!targetId) return;
            if (currentId) {
                commitDrawerEdits(currentId);
                wpeVariantStore.markDirty();
            }
            renderDrawer(targetId);
        });

        // Drawer manage stock checkbox toggle
        $(document).on('change', '#wpe-drawer-manage-stock', function () {
            if ($(this).is(':checked')) {
                $('#wpe-drawer-stock-fields').slideDown(150);
            } else {
                $('#wpe-drawer-stock-fields').slideUp(150);
            }
        });

        // Drawer save
        $(document).on('click', '.wpe-btn-save-drawer', function () {
            const vId = $(this).data('variant-id');
            if (!commitDrawerEdits(vId)) return;

            layers.close($('#wpe-variation-drawer-overlay'));
            wpeVariantStore.markDirty();
        });

        // Drawer Variant Image Picker buttons
        $(document).on('click', '.wpe-btn-drawer-pick-image', function () {
            const vId = parseInt($(this).attr('data-variant-id'), 10);
            if (!vId) return;
            openProductVariantImagePicker({
                mode: 'single',
                variantId: vId
            });
        });

        $(document).on('click', '.wpe-btn-drawer-clear-image', function () {
            const vId = parseInt($(this).attr('data-variant-id'), 10);
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.image_asset_id = null;
                v.image_url = null;
                v.attachment_id = null;
                renderDrawer(vId);
                wpeVariantStore.markDirty();
            }
        });

        // Modal close
        $(document).on('click', '.wpe-btn-close-modal', function () {
            const $own = $(this).closest('.wpe-modal-backdrop');
            layers.close($own.length ? $own : $('.wpe-modal-backdrop').last());
        });

        // Choice card radio highlight
        $(document).on('change', '.wpe-choice-card input[type="radio"]', function () {
            const $group = $(this).closest('.wpe-choice-group');
            $group.find('.wpe-choice-card').removeClass('selected');
            $(this).closest('.wpe-choice-card').addClass('selected');
        });

        // Confirm Common Price Removal Modal
        $(document).on('click', '.wpe-btn-confirm-remove-common-price', function () {
            const mode = $('input[name="wpe_common_price_removal_mode"]:checked').val() || 'copy';
            const cp = wpeVariantStore.commonPrice || {};
            wpeVariantStore.variations.forEach(v => {
                if (v.pricing_mode === 'shared') {
                    if (mode === 'copy') {
                        v.regular_price = cp.regular_price || '0';
                        v.sale_price = cp.sale_price || '';
                        v.sale_from = cp.sale_from || null;
                        v.sale_to = cp.sale_to || null;
                    }
                }
                delete v.pricing_mode;
            });
            wpeVariantStore.commonPrice = null;
            layers.close($('#wpe-remove-common-price-modal'));
            showToast('Removed Common Price.', 'info');
            wpeVariantStore.markDirty();
        });

        // Confirm Simple -> Variable Transition Modal
        $(document).on('click', '.wpe-btn-confirm-s2v', function () {
            const optName = ($('#wpe-first-opt-name').val() || '').trim() || 'Size';
            const rawVals = ($('#wpe-first-opt-values').val() || '').trim() || 'Default';
            const pricingChoice = $('input[name="wpe_s2v_pricing_choice"]:checked').val() || 'common_price';

            const prod = wpeVariantStore.product || {};
            const baseReg = !emptyVal(prod.regular_price) ? String(prod.regular_price) : '0';
            const baseSale = !emptyVal(prod.sale_price) ? String(prod.sale_price) : '';

            wpeVariantStore.type = 'variable';
            $('#product-type').val('variable');

            wpeVariantStore.variationOptions = [{
                name: optName,
                position: 0,
                display_type: 'auto',
                help_text: '',
                values: []
            }];
            addValuesToOption(0, rawVals);

            if (pricingChoice === 'common_price') {
                wpeVariantStore.commonPrice = {
                    regular_price: baseReg,
                    sale_price: baseSale,
                    sale_from: prod.sale_from || null,
                    sale_to: prod.sale_to || null
                };
            } else {
                wpeVariantStore.commonPrice = null;
            }

            wpeVariantStore.typeTransition = {
                from: 'simple',
                to: 'variable',
                pricing_choice: pricingChoice
            };

            // Generate initial variations from the first option values
            const optValues = (wpeVariantStore.variationOptions[0] && wpeVariantStore.variationOptions[0].values) || [];
            const rawParentSku = (prod.sku || '').trim();
            const skuPrefix = rawParentSku ? (rawParentSku.endsWith('-') ? rawParentSku : `${rawParentSku}-`) : '';
            wpeVariantStore.variations = optValues.map((vObj, idx) => {
                const label = typeof vObj === 'object' ? vObj.label : vObj;
                const combo = { [optName]: label };
                const suffix = String(label).replace(/\s+/g, '-').toUpperCase();
                return {
                    id: idx + 1,
                    sku: skuPrefix ? `${skuPrefix}${suffix}` : '',
                    regular_price: baseReg,
                    sale_price: baseSale,
                    sale_from: null,
                    sale_to: null,
                    pricing_mode: pricingChoice === 'common_price' ? 'shared' : 'own',
                    status: 'publish',
                    position: idx,
                    manage_stock: false,
                    stock_quantity: null,
                    stock_status: 'instock',
                    attributes: combo,
                    combination: combo,
                    image_asset_id: null,
                    image_url: null,
                    attachment_id: null,
                    inventory: {
                        manage_stock: 0,
                        quantity: null,
                        stock_status: 'instock',
                        backorders: 'no'
                    }
                };
            });

            layers.close($('#wpe-simple-to-variable-modal'));
            showToast('Converted draft to Variable Product.', 'success');
            wpeVariantStore.markDirty();
        });

        // Confirm Variable -> Simple Transition Modal
        $(document).on('click', '.wpe-btn-confirm-v2s', function () {
            const source = $('input[name="wpe_v2s_price_source"]:checked').val() || 'min_price';
            let newReg = '0';
            let newSale = '';

            if (source === 'variant') {
                const sourceVarId = parseInt($('#wpe-v2s-source-variant').val(), 10);
                const sv = (wpeVariantStore.variations || []).find(v => v.id === sourceVarId) || wpeVariantStore.variations[0];
                if (sv) {
                    const useShared = wpeVariantStore.commonPrice && sv.pricing_mode === 'shared';
                    newReg = useShared ? (wpeVariantStore.commonPrice.regular_price || '0') : (sv.regular_price || '0');
                    newSale = useShared ? (wpeVariantStore.commonPrice.sale_price || '') : (sv.sale_price || '');
                }
            } else if (source === 'manual') {
                newReg = ($('#wpe-v2s-manual-reg').val() || '0').trim();
                newSale = ($('#wpe-v2s-manual-sale').val() || '').trim();
            } else {
                const ref = getEffectiveReferencePrice();
                newReg = (ref && ref.min_regular_price) ? String(ref.min_regular_price) : '0';
                newSale = '';
            }

            wpeVariantStore.type = 'simple';
            $('#product-type').val('simple');
            wpeVariantStore.product.regular_price = newReg;
            wpeVariantStore.product.sale_price = newSale;
            $('#_regular_price').val(newReg);
            $('#_sale_price').val(newSale);

            wpeVariantStore.commonPrice = null;
            wpeVariantStore.variationOptions = [];
            wpeVariantStore.variations = [];
            wpeVariantStore.selection.clear();
            wpeVariantStore.typeTransition = {
                from: 'variable',
                to: 'simple',
                price_source: source,
                simple_regular_price: newReg,
                simple_sale_price: newSale || null
            };

            layers.close($('#wpe-variable-to-simple-modal'));
            showToast('Converted draft to Simple Product.', 'info');
            wpeVariantStore.markDirty();
        });

        // Confirm Add Single Variant Modal
        $(document).on('click', '.wpe-btn-confirm-add-variant', function () {
            const combo = {};
            let valid = true;

            const $optSelects = $('#wpe-add-variant-modal .wpe-add-var-opt-select');
            if ($optSelects.length) {
                $optSelects.each(function () {
                    const optName = $(this).attr('data-opt-name');
                    const val = ($(this).val() || '').trim();
                    if (!optName || !val) {
                        valid = false;
                        return;
                    }
                    combo[optName] = val;
                });
            } else {
                $('.wpe-single-var-attr').each(function () {
                    const optIdx = parseInt($(this).attr('data-opt-idx'), 10);
                    const opt = wpeVariantStore.variationOptions[optIdx];
                    if (!opt) return;
                    let val = $(this).val() ? $(this).val().trim() : '';
                    if (!val) {
                        valid = false;
                        return;
                    }
                    combo[opt.name] = val;
                    if ($(this).is('input')) {
                        addValuesToOption(optIdx, val);
                    }
                });
            }

            if (!valid || Object.keys(combo).length === 0) {
                alert('Please select or enter a value for every option.');
                return;
            }

            // Check if combination already exists
            const existing = wpeVariantStore.variations.find(v => {
                const c = v.attributes || v.combination || {};
                return Object.keys(combo).every(k => c[k] === combo[k]);
            });
            if (existing) {
                layers.close($('#wpe-add-variant-modal'));
                wpeVariantStore.filters.search = Object.values(combo).join(' ');
                renderEditor('#wpe-product-editor-inline-app');
                showToast('This variant combination already exists — filtered to show it.', 'info');
                return;
            }

            const regPrice = $('#wpe-add-var-reg-price').val() || $('#wpe-single-var-price').val() || '0';
            const salePrice = $('#wpe-add-var-sale-price').val() || $('#wpe-single-var-sale').val() || '';
            const sku = ($('#wpe-add-var-sku').val() || $('#wpe-single-var-sku').val() || '').trim();
            const stockVal = $('#wpe-add-var-stock-qty').length ? $('#wpe-add-var-stock-qty').val() : $('#wpe-single-var-stock').val();
            const hasStock = stockVal !== '' && stockVal !== undefined;
            const maxId = wpeVariantStore.variations.reduce((max, v) => Math.max(max, v.id || 0), 0);

            wpeVariantStore.variations.unshift({
                id: maxId + 1,
                sku: sku,
                regular_price: regPrice,
                sale_price: salePrice,
                sale_from: null,
                sale_to: null,
                pricing_mode: 'own',
                status: 'publish',
                position: 0,
                manage_stock: hasStock,
                stock_quantity: hasStock ? parseFloat(stockVal) : null,
                stock_status: hasStock && parseFloat(stockVal) <= 0 ? 'outofstock' : 'instock',
                attributes: combo,
                combination: combo,
                image_asset_id: null,
                image_url: null,
                attachment_id: null,
                inventory: {
                    manage_stock: hasStock ? 1 : 0,
                    quantity: hasStock ? parseFloat(stockVal) : null,
                    stock_status: hasStock && parseFloat(stockVal) <= 0 ? 'outofstock' : 'instock',
                    backorders: 'no'
                }
            });

            layers.close($('#wpe-add-variant-modal'));
            showToast('Added custom variant!', 'success');
            wpeVariantStore.markDirty();
        });

        // Generator Select/Unselect all & Filter Chips
        $(document).on('click', '.wpe-btn-gen-select-all', function () {
            $('#wpe-generator-body .wpe-gen-check').prop('checked', true);
            updateGeneratorButtonState();
        });

        $(document).on('click', '.wpe-btn-gen-unselect-all', function () {
            $('#wpe-generator-body .wpe-gen-check').prop('checked', false);
            updateGeneratorButtonState();
        });

        $(document).on('change', '#wpe-generator-body .wpe-gen-check', function () {
            updateGeneratorButtonState();
        });

        $(document).on('click', '.wpe-gen-filter-chip', function () {
            const optName = $(this).attr('data-opt-name');
            const optVal = $(this).attr('data-opt-val');
            const matchingChecks = [];

            $('#wpe-generator-body .wpe-gen-check').each(function () {
                const idx = parseInt($(this).attr('data-idx'), 10);
                const nc = generatorNewCombos[idx];
                if (!nc) return;
                const combo = nc.combination || nc.attributes || {};
                if (combo[optName] === optVal) {
                    matchingChecks.push($(this));
                }
            });

            if (matchingChecks.length > 0) {
                const allChecked = matchingChecks.every($c => $c.is(':checked'));
                matchingChecks.forEach($c => $c.prop('checked', !allChecked));
                $(this).toggleClass('is-off', allChecked);
                updateGeneratorButtonState();
            }
        });

        // Apply Generator selected combinations
        $(document).on('click', '.wpe-btn-apply-generator', function () {
            const newCombos = generatorNewCombos.length ? generatorNewCombos : ($(this).data('new-combos') || []);
            const defPrice = $('#wpe-gen-def-price').val();
            const defSkuPrefix = $('#wpe-gen-def-sku').val().trim();
            const defStock = $('#wpe-gen-def-stock').val();

            let addedCount = 0;
            $('#wpe-generator-body .wpe-gen-check:checked').each(function () {
                const idx = parseInt($(this).attr('data-idx'), 10);
                const nc = newCombos[idx];
                if (!nc) return;

                const combo = nc.combination || nc.attributes;
                const skuSuffix = Object.values(combo).map(v => String(v).replace(/\s+/g, '-').toUpperCase()).join('-');
                const sku = defSkuPrefix ? `${defSkuPrefix}${skuSuffix}` : '';

                const maxId = wpeVariantStore.variations.reduce((max, v) => Math.max(max, v.id || 0), 0);
                const newVarId = maxId + 1;
                const hasCommonPrice = wpeVariantStore.commonPrice !== null && typeof wpeVariantStore.commonPrice === 'object';
                const regPrice = hasCommonPrice
                    ? (wpeVariantStore.commonPrice.regular_price || defPrice || '0')
                    : (defPrice !== '' ? defPrice : '0');

                wpeVariantStore.variations.push({
                    id: newVarId,
                    sku: sku,
                    regular_price: regPrice,
                    sale_price: hasCommonPrice ? (wpeVariantStore.commonPrice.sale_price || '') : '',
                    sale_from: null,
                    sale_to: null,
                    pricing_mode: hasCommonPrice ? 'shared' : 'own',
                    status: 'publish',
                    position: wpeVariantStore.variations.length,
                    manage_stock: defStock !== '',
                    stock_quantity: defStock !== '' ? parseFloat(defStock) : null,
                    stock_status: defStock !== '' && parseFloat(defStock) <= 0 ? 'outofstock' : 'instock',
                    attributes: combo,
                    combination: combo,
                    image_asset_id: null,
                    image_url: null,
                    attachment_id: null,
                    inventory: {
                        manage_stock: defStock !== '' ? 1 : 0,
                        quantity: defStock !== '' ? parseFloat(defStock) : null,
                        stock_status: 'instock',
                        backorders: 'no'
                    }
                });
                addedCount++;
            });

            layers.close($('#wpe-generator-modal'));
            if (addedCount > 0) {
                showToast(`Added ${addedCount} new variants to the matrix!`, 'success');
                wpeVariantStore.markDirty();
            }
        });

        // Bulk Edit field change
        $(document).on('change', '#wpe-bulk-field', function () {
            renderBulkFieldControls($(this).val());
        });

        $(document).on('change', '#wpe-bulk-price-type', function () {
            if ($(this).val() === 'clear') {
                $('#wpe-bulk-price-val').prop('disabled', true).val('');
            } else {
                $('#wpe-bulk-price-val').prop('disabled', false);
            }
        });

        // Bulk Edit Preview Diff
        $(document).on('click', '.wpe-btn-preview-bulk-diff', function () {
            const field = $('#wpe-bulk-field').val();
            const selectedIds = Array.from(wpeVariantStore.selection);
            const actions = {};

            if (field === 'sku_prefix') {
                const prefix = ($('#wpe-bulk-sku-prefix-val').val() || '').trim();
                let rowsHtml = '';
                selectedIds.forEach(id => {
                    const v = wpeVariantStore.variations.find(item => item.id === id);
                    if (!v) return;
                    const combo = v.attributes || v.combination || {};
                    const suffix = Object.values(combo).map(val => String(val).replace(/\s+/g, '-').toUpperCase()).join('-');
                    const newSku = `${prefix}${suffix}`;
                    rowsHtml += `
                        <tr>
                            <td>#${escapeHtml(v.id)}</td>
                            <td><div><strong>sku:</strong> <span class="wpe-diff-before">${escapeHtml(v.sku || '(empty)')}</span> &rarr; <span class="wpe-diff-after">${escapeHtml(newSku)}</span></div></td>
                        </tr>
                    `;
                });
                $('#wpe-bulk-diff-preview').html(`
                    <table class="wpe-diff-table">
                        <thead><tr><th>Variant</th><th>Projected Changes</th></tr></thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                `).slideDown(150);
                return;
            }

            if (field === 'sale_price' && $('#wpe-bulk-price-type').val() === 'clear') {
                let rowsHtml = '';
                selectedIds.forEach(id => {
                    const v = wpeVariantStore.variations.find(item => item.id === id);
                    if (!v) return;
                    rowsHtml += `
                        <tr>
                            <td>SKU: ${escapeHtml(v.sku || '#' + v.id)}</td>
                            <td><div><strong>sale_price:</strong> <span class="wpe-diff-before">${escapeHtml(v.sale_price || '(empty)')}</span> &rarr; <span class="wpe-diff-after">(cleared)</span></div></td>
                        </tr>
                    `;
                });
                $('#wpe-bulk-diff-preview').html(`
                    <table class="wpe-diff-table">
                        <thead><tr><th>Variant</th><th>Projected Changes</th></tr></thead>
                        <tbody>${rowsHtml}</tbody>
                    </table>
                `).slideDown(150);
                return;
            }

            if (field === 'regular_price') {
                actions.regular_price = {
                    type: $('#wpe-bulk-price-type').val(),
                    value: parseFloat($('#wpe-bulk-price-val').val()) || 0
                };
            } else if (field === 'sale_price') {
                actions.sale_price = {
                    type: $('#wpe-bulk-price-type').val(),
                    value: parseFloat($('#wpe-bulk-price-val').val()) || 0
                };
            } else if (field === 'manage_stock') {
                actions.manage_stock = parseInt($('#wpe-bulk-manage-stock-val').val(), 10);
            } else if (field === 'stock_quantity') {
                actions.stock_quantity = parseFloat($('#wpe-bulk-stock-qty-val').val()) || 0;
            }

            api.previewBulkEdit(selectedIds, actions)
                .done(function (res) {
                    if (res.success && res.result.diffs) {
                        const diffs = res.result.diffs;
                        let rowsHtml = '';
                        diffs.forEach(d => {
                            const changeItems = Object.entries(d.changes).map(([k, change]) => `
                                <div><strong>${k}:</strong> <span class="wpe-diff-before">${escapeHtml(change.before)}</span> &rarr; <span class="wpe-diff-after">${escapeHtml(change.after)}</span></div>
                            `).join('');
                            rowsHtml += `
                                <tr>
                                    <td>SKU: ${escapeHtml(d.sku || '#' + d.variant_id)}</td>
                                    <td>${changeItems}</td>
                                </tr>
                            `;
                        });

                        const tableHtml = `
                            <table class="wpe-diff-table">
                                <thead><tr><th>Variant</th><th>Projected Changes</th></tr></thead>
                                <tbody>${rowsHtml}</tbody>
                            </table>
                        `;
                        $('#wpe-bulk-diff-preview').html(tableHtml).slideDown(150);
                    }
                })
                .fail(function () {
                    alert('Unable to generate changes preview.');
                });
        });

        // Bulk Edit Apply
        $(document).on('click', '.wpe-btn-apply-bulk', function () {
            const field = $('#wpe-bulk-field').val();
            const selectedIds = Array.from(wpeVariantStore.selection);

            selectedIds.forEach(id => {
                const v = wpeVariantStore.variations.find(item => item.id === id);
                if (!v) return;

                if (field === 'sku_prefix') {
                    const prefix = ($('#wpe-bulk-sku-prefix-val').val() || '').trim();
                    const combo = v.attributes || v.combination || {};
                    const suffix = Object.values(combo).map(val => String(val).replace(/\s+/g, '-').toUpperCase()).join('-');
                    v.sku = `${prefix}${suffix}`;
                } else if (field === 'regular_price' || field === 'sale_price') {
                    const type = $('#wpe-bulk-price-type').val();
                    if (field === 'sale_price' && type === 'clear') {
                        v.sale_price = '';
                        v.pricing_mode = 'own';
                        return;
                    }
                    const val = parseFloat($('#wpe-bulk-price-val').val()) || 0;
                    const oldVal = parseFloat(v[field]) || 0;
                    let newVal = oldVal;

                    if (type === 'fixed') newVal = val;
                    else if (type === 'increase_fixed') newVal = oldVal + val;
                    else if (type === 'decrease_fixed') newVal = Math.max(0, oldVal - val);
                    else if (type === 'increase_percent') newVal = oldVal * (1 + val / 100);
                    else if (type === 'decrease_percent') newVal = Math.max(0, oldVal * (1 - val / 100));

                    v[field] = newVal > 0 ? String(Math.round(newVal * 100) / 100) : '';
                    v.pricing_mode = 'own';
                } else if (field === 'manage_stock') {
                    v.manage_stock = parseInt($('#wpe-bulk-manage-stock-val').val(), 10) === 1;
                } else if (field === 'stock_quantity') {
                    if (v.manage_stock) {
                        v.stock_quantity = parseFloat($('#wpe-bulk-stock-qty-val').val()) || 0;
                        v.stock_status = v.stock_quantity > 0 ? 'instock' : 'outofstock';
                    }
                }
            });

            layers.close($('#wpe-bulk-modal'));
            showToast(`Applied changes to ${selectedIds.length} variants!`, 'success');
            wpeVariantStore.markDirty();
        });

        // Sticky Contextual Save Bar actions
        $(document).on('click', '.wpe-sticky-btn-save', function () {
            $('.wpe-btn-save-api:not(:disabled)').first().trigger('click');
        });

        $(document).on('click', '.wpe-sticky-btn-discard', function () {
            if (confirm('Discard all unsaved changes and reload from server?')) {
                api.fetchData().done(function (res) {
                    if (res.success && res.data) {
                        applyServerData(res.data);
                        wpeVariantStore.isDirty = false;
                        wpeVariantStore.mediaDirty = false;
                        wpeVariantStore.typeTransition = null;
                        wpeVariantStore.notify();
                        showToast('Changes discarded', 'info');
                    }
                });
            }
        });

        // Dirty guard on leave. A normal Update/Publish submit is not a "leave": the draft travels with the form.
        window.addEventListener('beforeunload', function (e) {
            if (wpeVariantStore.isDirty && !submitting) {
                e.preventDefault();
                e.returnValue = config.i18n.unsavedWarning;
                return config.i18n.unsavedWarning;
            }
        });

        // Keyboard handling for the topmost layer (Escape, focus trap). Capture phase so it runs before WP handlers.
        document.addEventListener('keydown', function (e) {
            layers.onKeydown(e);
        }, true);

        // Header badge and Fullscreen button are injected into the meta box title bar.
        $(document).on('click', '#wpe-box-fullscreen', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (wpeVariantStore.isLoaded) openFullscreen('variants');
        });
        $(document).on('click', '.wpe-btn-retry-load', function () {
            wpeVariantStore.isRequested = false;
            loadData();
        });

        // Update/Publish submit guard.
        const postForm = document.getElementById('post');
        if (postForm) {
            postForm.addEventListener('submit', function (e) {
                wpeVariantStore.syncHiddenPayload();
                const shouldWarnTypeChange = config.editorMode === 'blocks'
                    ? !isSupportedProductType()
                    : (!isVariableType() && !wpeVariantStore.mediaDirty);
                if (wpeVariantStore.isDirty && shouldWarnTypeChange) {
                    if (!window.confirm(config.i18n.typeChangeWarning)) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        setTimeout(function () {
                            $('#publish').removeClass('disabled button-disabled button-primary-disabled').prop('disabled', false);
                            $('#publishing-action .spinner').removeClass('is-active');
                        }, 10);
                        return;
                    }
                }
                submitting = true;
                setTimeout(function () { submitting = false; }, 4000);
            }, true);
        }
    }

    /**
     * Box header: unsaved/in-sync badge and Fullscreen button live in the WordPress meta box title bar,
     * so they remain visible when the box is collapsed.
     */
    function ensureBoxHeader(boxId) {
        const id = boxId || config.boxId;
        const $header = $('#' + id + ' .postbox-header');
        if (!$header.length) return null;
        let $extras = $header.find('.wpe-box-header-extras');
        if (!$extras.length) {
            const isVariantsBox = id === (config.variantsBoxId || config.boxId);
            const statusIdAttr = isVariantsBox ? 'id="wpe-box-status"' : '';
            $extras = $(
                '<div class="wpe-box-header-extras">' +
                `<span ${statusIdAttr} class="wpe-box-status ${id}-status" role="status" aria-live="polite"></span>` +
                (isVariantsBox ? '<button type="button" id="wpe-box-fullscreen" class="button button-small" disabled>' +
                '<span class="dashicons dashicons-editor-expand" aria-hidden="true"></span> Fullscreen</button>' : '') +
                '</div>'
            );
            const $actions = $header.find('.handle-actions');
            if ($actions.length) {
                $actions.before($extras);
            } else {
                $header.append($extras);
            }
        }
        return $extras;
    }

    function updateBoxHeader() {
        const boxIds = [config.variantsBoxId || config.boxId];
        const infoBoxId = config.infoBoxId || config.productInfoBoxId || 'wpe_product_info';
        if (infoBoxId && config.editorMode === 'blocks') {
            boxIds.push(infoBoxId);
        }
        let label = '';
        let cls = 'idle';
        if (wpeVariantStore.loadFailed) {
            label = 'Load failed';
            cls = 'error';
        } else if (!wpeVariantStore.isRequested) {
            label = '';
        } else if (wpeVariantStore.isLoading) {
            label = 'Loading...';
        } else if (wpeVariantStore.isDirty) {
            label = 'Unsaved changes';
            cls = 'dirty';
        } else {
            label = 'In sync';
            cls = 'clean';
        }
        boxIds.forEach(id => {
            if (!id || !$('#' + id).length) return;
            ensureBoxHeader(id);
            const $status = $('#' + id + ' .wpe-box-status');
            $status.text(label).attr('class', `wpe-box-status ${id}-status ${cls}`);
        });
        $('#wpe-box-fullscreen').prop('disabled', !wpeVariantStore.isLoaded);
    }

    /**
     * Apply a server payload (or a restored draft) to the store.
     */
    function applyServerData(data) {
        wpeVariantStore.version = data.product ? data.product.version : 1;
        wpeVariantStore.product = data.product || {};
        const inv = (data.product && (data.product.stock || data.product.parent_stock)) || {};
        wpeVariantStore.product.manage_stock = inv.manage_stock ? 1 : 0;
        wpeVariantStore.product.stock_quantity = inv.stock_quantity !== null && inv.stock_quantity !== undefined ? inv.stock_quantity : null;
        wpeVariantStore.product.stock_status = inv.stock_status || 'instock';
        wpeVariantStore.product.backorders = inv.backorders || 'no';
        const stockObj = {
            manage_stock: wpeVariantStore.product.manage_stock,
            stock_quantity: wpeVariantStore.product.stock_quantity,
            stock_status: wpeVariantStore.product.stock_status,
            backorders: wpeVariantStore.product.backorders
        };
        wpeVariantStore.product.stock = stockObj;
        wpeVariantStore.product.parent_stock = stockObj;

        wpeVariantStore.type = data.type || (data.product && data.product.type) || 'simple';
        wpeVariantStore.derivedType = data.derived_type || wpeVariantStore.type;
        if (config.editorMode === 'blocks' && $('#product-type').length && (wpeVariantStore.type === 'simple' || wpeVariantStore.type === 'variable')) {
            $('#product-type').val(wpeVariantStore.type);
        }
        if (data.common_price && typeof data.common_price === 'object') {
            const cp = data.common_price;
            const reg = cp.regular_price !== undefined && cp.regular_price !== null ? cp.regular_price : (cp.regular !== undefined ? cp.regular : null);
            const sale = cp.sale_price !== undefined && cp.sale_price !== null ? cp.sale_price : (cp.sale !== undefined ? cp.sale : null);
            const from = cp.sale_from !== undefined && cp.sale_from !== null ? cp.sale_from : (cp.from !== undefined ? cp.from : null);
            const to = cp.sale_to !== undefined && cp.sale_to !== null ? cp.sale_to : (cp.to !== undefined ? cp.to : null);
            wpeVariantStore.commonPrice = {
                regular_price: reg,
                sale_price: sale,
                sale_from: from,
                sale_to: to,
                regular: reg,
                sale: sale,
                from: from,
                to: to
            };
        } else {
            wpeVariantStore.commonPrice = null;
        }
        wpeVariantStore.referencePrice = data.reference_price || null;
        wpeVariantStore.typeTransition = null;
        wpeVariantStore.descriptiveAttributes = data.descriptive_attributes || [];
        wpeVariantStore.variationOptions = (data.variation_options || []).map(normalizeVariationOptionDefaults);
        const hasCommon = wpeVariantStore.commonPrice !== null && typeof wpeVariantStore.commonPrice === 'object';
        wpeVariantStore.variations = (data.variations || []).map(v => {
            const copy = Object.assign({}, v);
            if (hasCommon) {
                copy.pricing_mode = copy.pricing_mode === 'shared' ? 'shared' : 'own';
            } else {
                delete copy.pricing_mode;
            }
            return copy;
        });
        wpeVariantStore.taxonomies = data.taxonomies || data.global_taxonomies || [];
        if (data.media_library) {
            wpeVariantStore.mediaLibrary = normalizeMediaLibrary(data.media_library);
            wpeVariantStore.mediaLoaded = true;
        }
    }

    function normalizeVariationOptionDefaults(opt) {
        if (!opt || typeof opt !== 'object') return opt;
        const copy = Object.assign({}, opt);
        const vals = Array.isArray(copy.values) ? copy.values : [];
        const hasDefId = copy.default_value_id !== undefined && copy.default_value_id !== null && copy.default_value_id !== '';
        const hasDefLabel = copy.default_value !== undefined && copy.default_value !== null && String(copy.default_value).trim() !== '';

        let matchedVal = null;
        if (hasDefId) {
            const targetId = String(copy.default_value_id);
            matchedVal = vals.find(v => typeof v === 'object' && v !== null && v.id !== undefined && v.id !== null && String(v.id) === targetId) || null;
        }
        if (!matchedVal && hasDefLabel) {
            const targetLabel = String(copy.default_value).trim().toLowerCase();
            matchedVal = vals.find(v => {
                const lbl = typeof v === 'object' && v !== null ? String(v.label || '') : String(v || '');
                return lbl.trim().toLowerCase() === targetLabel;
            }) || null;
        }

        if (matchedVal !== null) {
            const resolvedLabel = typeof matchedVal === 'object' && matchedVal !== null ? String(matchedVal.label || '') : String(matchedVal);
            copy.default_value = resolvedLabel;
            if (typeof matchedVal === 'object' && matchedVal !== null && matchedVal.id) {
                copy.default_value_id = parseInt(matchedVal.id, 10);
            } else {
                delete copy.default_value_id;
            }
        } else {
            copy.default_value = null;
            copy.default_value_id = null;
        }
        return copy;
    }

    function applyRestoredDraft(draft) {
        if (!draft || typeof draft !== 'object') return false;
        if (draft.product) wpeVariantStore.product = draft.product;
        if (draft.type) wpeVariantStore.type = draft.type;
        if (draft.derived_type) wpeVariantStore.derivedType = draft.derived_type;
        if (draft.common_price !== undefined) wpeVariantStore.commonPrice = draft.common_price;
        if (draft.type_transition !== undefined) wpeVariantStore.typeTransition = draft.type_transition;
        if (Array.isArray(draft.descriptive_attributes)) wpeVariantStore.descriptiveAttributes = draft.descriptive_attributes;
        if (Array.isArray(draft.variation_options)) wpeVariantStore.variationOptions = draft.variation_options.map(normalizeVariationOptionDefaults);
        const hasCommon = wpeVariantStore.commonPrice !== null && typeof wpeVariantStore.commonPrice === 'object';
        if (Array.isArray(draft.variations)) {
            wpeVariantStore.variations = draft.variations.map(v => {
                const copy = Object.assign({}, v);
                if (hasCommon) {
                    copy.pricing_mode = copy.pricing_mode === 'shared' ? 'shared' : 'own';
                } else {
                    delete copy.pricing_mode;
                }
                return copy;
            });
        }
        if (draft.media_library && typeof draft.media_library === 'object') {
            if (draft.media_library.featured_asset_id !== undefined) {
                wpeVariantStore.mediaLibrary.featured_asset_id = draft.media_library.featured_asset_id ? parseInt(draft.media_library.featured_asset_id, 10) : null;
            }
            if (Array.isArray(draft.media_library.gallery)) {
                wpeVariantStore.mediaLibrary.gallery = draft.media_library.gallery.slice();
            }
        }
        if (draft.expected_version) wpeVariantStore.version = draft.expected_version;
        wpeVariantStore.isDirty = true;
        return true;
    }

    /**
     * Lazy, idempotent data load.
     */
    function loadData() {
        if (wpeVariantStore.isRequested) return;
        wpeVariantStore.isRequested = true;
        wpeVariantStore.isLoading = true;
        wpeVariantStore.loadFailed = false;
        if ($('#wpe-product-editor-inline-app').length && (isVariableType() || config.editorMode === 'blocks')) {
            renderVariantsApp('#wpe-product-editor-inline-app');
        }
        if ($('#wpe-product-info-app').length && config.editorMode === 'blocks') {
            renderProductInfoApp('#wpe-product-info-app');
        }
        renderProductMediaApp();
        updateBoxHeader();

        api.fetchData()
            .done(function (res) {
                if (res.success && res.data) {
                    applyServerData(res.data);
                    let restored = false;
                    if (config.restoredDraft) {
                        restored = applyRestoredDraft(config.restoredDraft);
                        config.restoredDraft = null;
                    }
                    wpeVariantStore.isLoading = false;
                    wpeVariantStore.isLoaded = true;
                    wpeVariantStore.notify();
                    if (restored) showToast(config.i18n.draftRestored, 'error');
                } else {
                    failLoad('Unexpected response');
                }
            })
            .fail(function (err) {
                failLoad(err.statusText || 'Request failed');
            });
    }

    /**
     * Load Product Media only (used for simple products in metabox mode so GET /variants is not called).
     */
    function loadMediaOnly() {
        if (wpeVariantStore.mediaRequested || wpeVariantStore.isRequested) return;
        wpeVariantStore.mediaRequested = true;
        api.fetchMedia()
            .done(function (res) {
                const payload = (res && res.data) ? res.data : res;
                if (payload && typeof payload === 'object') {
                    wpeVariantStore.mediaLibrary = normalizeMediaLibrary(payload);
                    wpeVariantStore.mediaLoaded = true;
                    renderProductMediaApp();
                }
            });
    }

    function failLoad(reason) {
        wpeVariantStore.isRequested = false;
        wpeVariantStore.isLoading = false;
        wpeVariantStore.loadFailed = true;
        const errHtml = `
            <div class="notice notice-error inline" style="margin:12px;">
                <p>Unable to load editor data: ${escapeHtml(reason)}
                <button type="button" class="button wpe-btn-retry-load" style="margin-left:8px;">Retry</button></p>
            </div>
        `;
        if ($('#wpe-product-editor-inline-app').length) $('#wpe-product-editor-inline-app').html(errHtml);
        if ($('#wpe-product-info-app').length) $('#wpe-product-info-app').html(errHtml);
        if ($('#wpe-product-media-app').length) $('#wpe-product-media-app').html(errHtml);
        updateBoxHeader();
    }

    /**
     * Handle Product Type Switch in WooCommerce metabox
     */
    function setupProductTypeToggle() {
        const $productTypeSelect = $('#product-type');
        const $metabox = $('#woocommerce-product-data');
        const $variantsBox = $('#' + (config.variantsBoxId || config.boxId));
        const infoBoxId = config.infoBoxId || config.productInfoBoxId || 'wpe_product_info';
        const $infoBox = infoBoxId ? $('#' + infoBoxId) : $();

        function updateType() {
            const selectedType = $productTypeSelect.length ? $productTypeSelect.val() : wpeVariantStore.type;
            if (selectedType === 'simple' || selectedType === 'variable') {
                wpeVariantStore.type = selectedType;
            }
            const isVariable = isVariableType();
            const isSupported = isSupportedProductType();

            if (config.editorMode === 'blocks') {
                $metabox.toggleClass('wpe-box-hidden', isSupported);
                $infoBox.toggleClass('wpe-box-hidden', !isSupported);
                $variantsBox.toggleClass('wpe-box-hidden', !isSupported);
                ensureProductMediaBox();
                if (isSupported) {
                    loadData();
                    renderProductInfoApp('#wpe-product-info-app');
                    renderVariantsApp('#wpe-product-editor-inline-app');
                    renderProductMediaApp();
                }
            } else {
                $metabox.toggleClass('wpe-is-variable', isVariable);
                $variantsBox.toggleClass('wpe-box-hidden', !isVariable);
                ensureProductMediaBox();
                if (isVariable) {
                    loadData();
                    renderProductMediaApp();
                } else if (isSupported) {
                    wpeVariantStore.isLoading = false;
                    loadMediaOnly();
                    renderProductMediaApp();
                }
            }
            wpeVariantStore.syncHiddenPayload();
            updateBoxHeader();
        }

        $productTypeSelect.on('change', updateType);
        updateType();
    }

    /**
     * Initialization
     */
    $(document).ready(function () {
        ensureProductMediaBox();
        wpeVariantStore.subscribe(() => {
            renderProductMediaApp();
            if ($('#wpe-product-info-app').length && config.editorMode === 'blocks' && isSupportedProductType()) {
                renderProductInfoApp('#wpe-product-info-app');
            }
            if ($('#wpe-product-editor-inline-app').length && (isVariableType() || (config.editorMode === 'blocks' && isSupportedProductType()))) {
                renderVariantsApp('#wpe-product-editor-inline-app');
            }
            if ($('#wpe-fullscreen-modal-overlay').is(':visible')) {
                renderFullscreenApp('#wpe-product-editor-modal-app');
            }
            updateBoxHeader();
        });

        bindGlobalEvents();
        ensureBoxHeader(config.variantsBoxId || config.boxId);
        const infoBoxId = config.infoBoxId || config.productInfoBoxId || 'wpe_product_info';
        if (infoBoxId && config.editorMode === 'blocks') {
            ensureBoxHeader(infoBoxId);
        }

        if (config.restoredDraft) {
            loadData();
        }
        setupProductTypeToggle();
    });

})(jQuery);
