/**
 * WC Product Engine — Product Variant & Attribute Editor
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

    /**
     * Unified Reactive Draft Store
     */
    const wpeVariantStore = {
        productId: productId,
        version: 1,
        isDirty: false,
        isLoading: true,
        isLoaded: false,
        isRequested: false,
        product: {
            sku: '',
            status: 'publish',
            parent_inventory: { manage_stock: 0, quantity: null, stock_status: 'instock', backorders: 'no' }
        },
        descriptiveAttributes: [],
        variationOptions: [],
        variations: [],
        taxonomies: [],
        selection: new Set(),
        filters: {
            search: '',
            status: '',
            stockStatus: '',
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

        /**
         * The hidden field travels with the WordPress Update/Publish submit. It is only filled while the
         * draft is dirty and the product is variable, so an unchanged Update never rewrites variants and a
         * non-variable product never posts variant data.
         */
        syncHiddenPayload() {
            if (!this.isDirty || !isVariableType()) {
                $('#wpe_variants_payload').val('');
                return;
            }
            const payload = {
                expected_version: this.version,
                product: this.product,
                descriptive_attributes: this.descriptiveAttributes,
                variation_options: this.variationOptions,
                variations: this.variations
            };
            $('#wpe_variants_payload').val(JSON.stringify(payload));
        }
    };

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
        }
    };

    function isVariableType() {
        const $type = $('#product-type');
        return $type.length ? $type.val() === 'variable' : config.productType === 'variable';
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
            // Forget layers that were removed or hidden by other code paths.
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
                // Do not steal Escape from the WordPress media frame opened above a layer.
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
        $('body').append($modal);
        layers.open($modal);
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

    /**
     * Main Editor UI Renderer
     */
    function renderEditor(containerId) {
        const $container = $(containerId);
        if (!$container.length) return;

        if (wpeVariantStore.isLoading) {
            $container.html(`
                <div class="wpe-editor-loading">
                    <span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span>
                    <span>Loading attributes and variants...</span>
                </div>
            `);
            return;
        }

        const isModal = containerId.indexOf('modal') !== -1;

        let html = `
            <div class="wpe-editor-wrap">
                <!-- Top Status Bar -->
                <div class="wpe-top-bar">
                    <div class="wpe-top-title-area">
                        ${isModal ? '<h3 class="wpe-top-title wpe-modal-title">Variants Manager (Fullscreen)</h3>' : ''}
                        <span class="wpe-dirty-badge ${wpeVariantStore.isDirty ? 'dirty' : 'clean'}">
                            <span class="dashicons dashicons-${wpeVariantStore.isDirty ? 'warning' : 'yes'}"></span>
                            ${wpeVariantStore.isDirty ? 'Unsaved changes' : 'In Sync'}
                        </span>
                    </div>
                    <div class="wpe-top-actions">
                        ${!isModal ? `
                            <button type="button" class="button wpe-btn-open-fullscreen">
                                <span class="dashicons dashicons-editor-expand"></span> Fullscreen Mode
                            </button>
                        ` : `
                            <button type="button" class="button wpe-btn-close-fullscreen">
                                <span class="dashicons dashicons-editor-contract"></span> Collapse
                            </button>
                        `}
                        <button type="button" class="button button-primary wpe-btn-save-api" ${!wpeVariantStore.isDirty ? 'disabled' : ''}>
                            <span class="dashicons dashicons-saved"></span> Save Changes
                        </button>
                    </div>
                </div>

                <!-- SECTION A: Descriptive Attributes -->
                ${renderSectionA()}

                <!-- SECTION B: Variation Options -->
                ${renderSectionB()}

                <!-- SECTION C: Variations Matrix -->
                ${renderSectionC()}

                <!-- Shopify Sticky Contextual Save Bar -->
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
        layers.keepFocus();
    }

    /**
     * Render Section A: Descriptive Attributes (used_for_variation = 0)
     */
    function renderSectionA() {
        const attrs = wpeVariantStore.descriptiveAttributes;
        let itemsHtml = '';

        if (attrs.length === 0) {
            itemsHtml = `<div style="color:#64748b; font-style:italic; padding:8px 0;">No descriptive attributes added yet (Material, Brand, Fit...). Click "Add Attribute" to create one.</div>`;
        } else {
            attrs.forEach((attr, idx) => {
                const values = (attr.values || []).map(v => typeof v === 'object' ? v.label : v);
                const chipsHtml = values.map((val, vIdx) => `
                    <span class="wpe-chip" data-attr-idx="${idx}" data-val-idx="${vIdx}">
                        <span>${escapeHtml(val)}</span>
                        <span class="wpe-chip-remove" title="Remove">&times;</span>
                    </span>
                `).join('');

                itemsHtml += `
                    <div class="wpe-desc-attr-card" data-idx="${idx}">
                        <div class="wpe-desc-attr-header">
                            <div class="wpe-desc-attr-name">
                                <span>${escapeHtml(attr.name)}</span>
                                ${attr.taxonomy ? `<span class="wpe-taxonomy-tag">Global Taxonomy (${escapeHtml(attr.taxonomy)})</span>` : '<span class="wpe-taxonomy-tag" style="background:#f1f5f9; color:#475569; border-color:#cbd5e1;">Custom Attribute</span>'}
                            </div>
                            <div class="wpe-desc-attr-controls">
                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                    <input type="checkbox" class="wpe-desc-visible-toggle" ${attr.is_visible || attr.visible ? 'checked' : ''} />
                                    Visible on product page
                                </label>
                                <button type="button" class="button-link-delete wpe-desc-attr-delete" title="Delete attribute">
                                    <span class="dashicons dashicons-trash" style="font-size:16px;"></span>
                                </button>
                            </div>
                        </div>
                        <div class="wpe-chips-container" data-attr-idx="${idx}">
                            ${chipsHtml}
                            <input type="text" class="wpe-chip-input" placeholder="Type a value and press Enter..." />
                        </div>
                    </div>
                `;
            });
        }

        // Global taxonomies dropdown options
        let taxonomyOptions = '<option value="">-- Custom Product Attribute --</option>';
        wpeVariantStore.taxonomies.forEach(t => {
            taxonomyOptions += `<option value="${escapeHtml(t.name)}">${escapeHtml(t.label || t.name)}</option>`;
        });

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-tag" style="color:#2563eb;"></span>
                        <h4 class="wpe-section-title">A. Descriptive Attributes</h4>
                        <span class="wpe-section-count">${attrs.length}</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <select class="wpe-add-taxonomy-select" style="max-width:220px; font-size:12px;">
                            ${taxonomyOptions}
                        </select>
                        <button type="button" class="button wpe-btn-add-desc-attr">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Add Attribute
                        </button>
                    </div>
                </div>
                <p class="wpe-section-desc">Descriptive attributes showcase specifications (Material, Brand, Fit, Care...) on the storefront and do not generate product variations.</p>
                <div class="wpe-section-body">
                    <div class="wpe-desc-attr-list">
                        ${itemsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render Section B: Variation Options Cards (used_for_variation = 1)
     */
    function renderSectionB() {
        const options = wpeVariantStore.variationOptions;
        let cardsHtml = '';

        if (options.length === 0) {
            cardsHtml = `<div style="color:#64748b; font-style:italic; padding:8px 0;">No variation options configured yet (Color, Size...). Click "Add Option" to begin.</div>`;
        } else {
            options.forEach((opt, idx) => {
                const values = (opt.values || []).map(v => typeof v === 'object' ? v.label : v);
                const chipsHtml = values.map((val, vIdx) => `
                    <span class="wpe-chip" data-opt-idx="${idx}" data-val-idx="${vIdx}">
                        <span>${escapeHtml(val)}</span>
                        <span class="wpe-chip-remove" title="Remove value">&times;</span>
                    </span>
                `).join('');

                cardsHtml += `
                    <div class="wpe-option-card" data-idx="${idx}">
                        <div class="wpe-option-card-header">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span class="dashicons dashicons-menu" style="color:#94a3b8; cursor:grab;" title="Drag to reorder"></span>
                                <input type="text" class="wpe-option-name-input" value="${escapeHtml(opt.name)}" placeholder="Option name (e.g. Size, Color)" />
                                <span style="font-size:12px; color:#64748b; font-weight:500;">(${values.length} values)</span>
                            </div>
                            <div class="wpe-option-card-actions">
                                <button type="button" class="button button-small wpe-btn-toggle-bulk-paste">
                                    <span class="dashicons dashicons-clipboard" style="margin-top:2px;"></span> Paste Values
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
                            <input type="text" class="wpe-chip-input" placeholder="Type a value and press Enter..." />
                        </div>
                    </div>
                `;
            });
        }

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-admin-settings" style="color:#2563eb;"></span>
                        <h4 class="wpe-section-title">B. Variation Options</h4>
                        <span class="wpe-section-count">${options.length}</span>
                    </div>
                    <div>
                        <button type="button" class="button wpe-btn-add-option">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Add Option
                        </button>
                    </div>
                </div>
                <p class="wpe-section-desc">Manage attributes used to form product variations (Color, Size...). Renaming or reordering preserves variation IDs.</p>
                <div class="wpe-section-body">
                    <div class="wpe-options-list">
                        ${cardsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render Section C: Variations Matrix Table
     */
    function renderSectionC() {
        const variations = wpeVariantStore.variations;
        const filters = wpeVariantStore.filters;
        const options = wpeVariantStore.variationOptions;

        // Apply filters
        let filtered = variations.filter(v => {
            if (filters.status && v.status !== filters.status) return false;
            if (filters.stockStatus) {
                if (filters.stockStatus === 'instock' && (v.stock_status !== 'instock' || (v.manage_stock && v.stock_quantity <= 0))) return false;
                if (filters.stockStatus === 'outofstock' && v.stock_status !== 'outofstock' && (!v.manage_stock || v.stock_quantity > 0)) return false;
                if (filters.stockStatus === 'parent_stock' && v.manage_stock) return false;
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

        // Pagination
        const totalItems = filtered.length;
        const totalPages = Math.ceil(totalItems / filters.pageSize) || 1;
        const startIdx = (filters.page - 1) * filters.pageSize;
        const paginatedList = filtered.slice(startIdx, startIdx + filters.pageSize);

        // Group by dropdown options
        let groupByOptions = '<option value="none">-- No Grouping --</option>';
        options.forEach(opt => {
            groupByOptions += `<option value="${escapeHtml(opt.name)}" ${filters.groupBy === opt.name ? 'selected' : ''}>Group by ${escapeHtml(opt.name)}</option>`;
        });

        const selectedCount = wpeVariantStore.selection.size;

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-grid-view" style="color:#2563eb;"></span>
                        <h4 class="wpe-section-title">C. Variations Matrix</h4>
                        <span class="wpe-section-count">${variations.length}</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="button button-primary wpe-btn-open-generator">
                            <span class="dashicons dashicons-forms" style="margin-top:3px;"></span> Generate Variations
                        </button>
                    </div>
                </div>

                <!-- Matrix Toolbar -->
                <div class="wpe-matrix-toolbar">
                    <div class="wpe-toolbar-left">
                        <input type="text" class="wpe-search-input" placeholder="Search by SKU, attributes, price..." value="${escapeHtml(filters.search)}" />
                        <select class="wpe-filter-select wpe-filter-status">
                            <option value="">Status: All</option>
                            <option value="publish" ${filters.status === 'publish' ? 'selected' : ''}>Published</option>
                            <option value="private" ${filters.status === 'private' ? 'selected' : ''}>Private</option>
                            <option value="draft" ${filters.status === 'draft' ? 'selected' : ''}>Draft</option>
                        </select>
                        <select class="wpe-filter-select wpe-filter-stock">
                            <option value="">Stock: All</option>
                            <option value="instock" ${filters.stockStatus === 'instock' ? 'selected' : ''}>In Stock</option>
                            <option value="outofstock" ${filters.stockStatus === 'outofstock' ? 'selected' : ''}>Out of Stock</option>
                            <option value="parent_stock" ${filters.stockStatus === 'parent_stock' ? 'selected' : ''}>Shared Parent Stock</option>
                        </select>
                        <select class="wpe-filter-select wpe-group-by-select">
                            ${groupByOptions}
                        </select>
                    </div>
                    <div class="wpe-toolbar-right">
                        <button type="button" class="button wpe-btn-assign-media-by-option">
                            <span class="dashicons dashicons-images-alt2" style="margin-top:3px;"></span> Assign by Group
                        </button>
                        <button type="button" class="button wpe-btn-open-bulk-edit" ${selectedCount === 0 ? 'disabled' : ''}>
                            <span class="dashicons dashicons-edit" style="margin-top:3px;"></span> Bulk Edit (${selectedCount})
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
                        <button type="button" class="button button-small wpe-btn-bulk-assign-media">Assign Media</button>
                        <button type="button" class="button button-small wpe-btn-clear-selection">Deselect All</button>
                    </div>
                </div>

                <!-- Table Container -->
                <div class="wpe-table-container">
                    <table class="wpe-table">
                        <thead>
                            <tr>
                                <th style="width:34px;"><input type="checkbox" class="wpe-check-all-page" /></th>
                                <th style="width:52px;">Image</th>
                                <th>Combination</th>
                                <th style="width:140px;">SKU</th>
                                <th style="width:115px;">Regular Price (${config.currencySymbol})</th>
                                <th style="width:115px;">Sale Price (${config.currencySymbol})</th>
                                <th style="width:140px;">Stock</th>
                                <th style="width:95px;">Status</th>
                                <th style="width:90px; text-align:right;">Actions</th>
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
        if (variations.length === 0) {
            return `<tr><td colspan="9" style="text-align:center; padding:36px; color:#64748b;">No matching variants found.</td></tr>`;
        }

        let html = '';
        let currentGroup = null;

        variations.forEach(v => {
            const attrs = v.attributes || v.combination || {};

            // Group header row
            if (groupBy && groupBy !== 'none') {
                const groupVal = attrs[groupBy] || 'Unassigned';
                if (groupVal !== currentGroup) {
                    currentGroup = groupVal;
                    html += `
                        <tr class="wpe-group-row">
                            <td colspan="9">
                                <strong>${escapeHtml(groupBy)}: ${escapeHtml(groupVal)}</strong>
                            </td>
                        </tr>
                    `;
                }
            }

            const isSelected = wpeVariantStore.selection.has(v.id);
            const isParentStock = !v.manage_stock;

            // Badges
            const comboBadges = Object.entries(attrs).map(([opt, val]) => `
                <span class="wpe-combo-badge"><strong>${escapeHtml(opt)}:</strong> ${escapeHtml(val)}</span>
            `).join('');

            html += `
                <tr class="${isSelected ? 'selected' : ''}" data-variant-id="${v.id}">
                    <td><input type="checkbox" class="wpe-row-check" ${isSelected ? 'checked' : ''} /></td>
                    <td>
                        <div class="wpe-media-slot" title="Click to choose image">
                            ${v.image_url ? `<img src="${escapeHtml(v.image_url)}" alt="" /><span class="wpe-media-remove" title="Unlink image">&times;</span>` : '<span class="dashicons dashicons-format-image" style="color:#94a3b8;"></span>'}
                        </div>
                    </td>
                    <td>${comboBadges}</td>
                    <td>
                        <input type="text" class="wpe-editable-input wpe-inline-sku" value="${escapeHtml(v.sku || '')}" placeholder="SKU" />
                    </td>
                    <td>
                        <input type="number" step="any" class="wpe-editable-input wpe-inline-reg-price" value="${escapeHtml(v.regular_price || '')}" placeholder="0.00" />
                    </td>
                    <td>
                        <input type="number" step="any" class="wpe-editable-input wpe-inline-sale-price" value="${escapeHtml(v.sale_price || '')}" placeholder="0.00" />
                    </td>
                    <td>
                        ${isParentStock ? `
                            <span class="wpe-stock-pill parent-stock" title="Inherits stock from parent product">Shared Stock</span>
                        ` : `
                            <input type="number" step="any" class="wpe-editable-input wpe-inline-stock-qty" value="${escapeHtml(v.stock_quantity !== null ? v.stock_quantity : '')}" placeholder="Qty" style="width:70px; display:inline-block;" />
                            <span class="wpe-stock-pill ${v.stock_status || 'instock'}">${v.stock_status === 'instock' ? 'In Stock' : 'Out of Stock'}</span>
                        `}
                    </td>
                    <td>
                        <select class="wpe-editable-input wpe-inline-status" style="padding:2px 4px !important;">
                            <option value="publish" ${v.status === 'publish' ? 'selected' : ''}>Active</option>
                            <option value="private" ${v.status === 'private' ? 'selected' : ''}>Private</option>
                            <option value="draft" ${v.status === 'draft' ? 'selected' : ''}>Draft</option>
                        </select>
                    </td>
                    <td style="text-align:right;">
                        <button type="button" class="button button-small wpe-btn-open-drawer" title="Edit details">
                            <span class="dashicons dashicons-edit" style="font-size:14px; margin-top:2px;"></span>
                        </button>
                        <button type="button" class="button-link-delete wpe-btn-delete-variant" title="Delete variant">
                            <span class="dashicons dashicons-trash" style="font-size:14px; margin-top:2px;"></span>
                        </button>
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
                <button type="button" class="button-link wpe-btn-close-drawer" style="font-size:22px; text-decoration:none; color:#64748b;">&times;</button>
            </div>
            <div class="wpe-drawer-body">
                <!-- Basic info -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Pricing & SKU</h5>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>SKU</label>
                            <input type="text" id="wpe-drawer-sku" value="${escapeHtml(variant.sku || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Status</label>
                            <select id="wpe-drawer-status">
                                <option value="publish" ${variant.status === 'publish' ? 'selected' : ''}>Published</option>
                                <option value="private" ${variant.status === 'private' ? 'selected' : ''}>Private</option>
                                <option value="draft" ${variant.status === 'draft' ? 'selected' : ''}>Draft</option>
                            </select>
                        </div>
                    </div>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>Regular Price (${config.currencySymbol})</label>
                            <input type="number" step="any" id="wpe-drawer-reg-price" value="${escapeHtml(variant.regular_price || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Sale Price (${config.currencySymbol})</label>
                            <input type="number" step="any" id="wpe-drawer-sale-price" value="${escapeHtml(variant.sale_price || '')}" />
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

    /**
     * Selective Combination Generator Modal
     */
    function openGeneratorModal() {
        if (wpeVariantStore.variationOptions.length === 0) {
            alert('Please add at least one Variation Option (e.g. Color, Size) in Section B before generating variants.');
            return;
        }

        // Show loading modal
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

                const bodyHtml = `
                    <div style="background:#eff6ff; padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:12px; color:#1e40af;">
                        Total Potential: <strong>${result.total_possible || result.total_potential}</strong> |
                        Existing: <strong>${result.total_existing}</strong> |
                        Available to Create: <strong>${result.total_new}</strong>
                    </div>

                    <div style="display:flex; gap:14px; margin-bottom:16px; background:#f8fafc; padding:12px 14px; border-radius:6px; border:1px solid #e2e8f0;">
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600; color:#475569;">Default Regular Price (${config.currencySymbol}):</label>
                            <input type="number" step="any" id="wpe-gen-def-price" placeholder="0.00" style="width:100%; font-size:12px; margin-top:4px;" />
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600; color:#475569;">SKU Prefix:</label>
                            <input type="text" id="wpe-gen-def-sku" placeholder="SKU-" style="width:100%; font-size:12px; margin-top:4px;" />
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600; color:#475569;">Initial Stock Quantity:</label>
                            <input type="number" step="any" id="wpe-gen-def-stock" placeholder="10" style="width:100%; font-size:12px; margin-top:4px;" />
                        </div>
                    </div>

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

                $('#wpe-generator-body').html(bodyHtml);
                $('#wpe-generator-modal .wpe-btn-apply-generator').data('new-combos', newCombos).prop('disabled', newCombos.length === 0);
            })
            .fail(function (err) {
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
                                <option value="status">Publication Status</option>
                                <option value="manage_stock">Stock Management</option>
                                <option value="stock_quantity">Stock Quantity</option>
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
            html = `
                <div style="display:flex; gap:10px;">
                    <select id="wpe-bulk-price-type" style="width:180px;">
                        <option value="fixed">Set fixed price</option>
                        <option value="increase_fixed">Increase by amount</option>
                        <option value="decrease_fixed">Decrease by amount</option>
                        <option value="increase_percent">Increase by percentage (%)</option>
                        <option value="decrease_percent">Decrease by percentage (%)</option>
                    </select>
                    <input type="number" step="any" id="wpe-bulk-price-val" placeholder="Enter value" style="flex:1;" />
                </div>
            `;
        } else if (field === 'status') {
            html = `
                <select id="wpe-bulk-status-val" style="width:100%;">
                    <option value="publish">Published (Active)</option>
                    <option value="private">Private</option>
                    <option value="draft">Draft</option>
                </select>
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
        }
        $('#wpe-bulk-field-controls').html(html);
    }

    /**
     * Modal: Assign Media by Option Group
     */
    function openMediaByOptionModal() {
        const options = wpeVariantStore.variationOptions;
        if (options.length === 0) {
            alert('No variation options available (Color, Size...) to assign images by group.');
            return;
        }

        let optOptions = '';
        options.forEach(opt => {
            optOptions += `<option value="${escapeHtml(opt.name)}">${escapeHtml(opt.name)}</option>`;
        });

        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-media-group-modal">
                <div class="wpe-modal-dialog">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Assign Media by Option Group</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <div style="margin-bottom:14px;">
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Select Option:</label>
                            <select id="wpe-media-group-opt" style="width:100%;">
                                ${optOptions}
                            </select>
                        </div>
                        <div style="margin-bottom:14px;">
                            <label style="font-weight:600; display:block; margin-bottom:5px;">Select Option Value:</label>
                            <select id="wpe-media-group-val" style="width:100%;"></select>
                        </div>
                        <div id="wpe-media-group-preview" style="margin-top:14px; font-size:12px; color:#1e40af; background:#eff6ff; padding:10px 14px; border-radius:6px;"></div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Cancel</button>
                        <button type="button" class="button button-primary wpe-btn-pick-group-media">Choose Image from Library</button>
                    </div>
                </div>
            </div>
        `;
        openModalHtml(modalHtml);

        function updateValues() {
            const optName = $('#wpe-media-group-opt').val();
            const opt = wpeVariantStore.variationOptions.find(o => o.name === optName);
            let valOptions = '';
            if (opt && opt.values) {
                opt.values.forEach(v => {
                    const label = typeof v === 'object' ? v.label : v;
                    valOptions += `<option value="${escapeHtml(label)}">${escapeHtml(label)}</option>`;
                });
            }
            $('#wpe-media-group-val').html(valOptions);
            updatePreview();
        }

        function updatePreview() {
            const optName = $('#wpe-media-group-opt').val();
            const val = $('#wpe-media-group-val').val();
            const matching = wpeVariantStore.variations.filter(v => {
                const attrs = v.attributes || v.combination || {};
                return attrs[optName] === val;
            });
            $('#wpe-media-group-preview').html(`<strong>${matching.length}</strong> variants match this condition.`);
        }

        $('#wpe-media-group-opt').on('change', updateValues);
        $('#wpe-media-group-val').on('change', updatePreview);
        updateValues();

        $('.wpe-btn-pick-group-media').on('click', function () {
            const optName = $('#wpe-media-group-opt').val();
            const val = $('#wpe-media-group-val').val();
            const mediaFrame = wp.media({
                title: `Assign image to all variants with ${optName}: ${val}`,
                button: { text: 'Assign to Group' },
                multiple: false
            });

            mediaFrame.on('select', function () {
                const attachment = mediaFrame.state().get('selection').first().toJSON();
                let count = 0;
                wpeVariantStore.variations.forEach(v => {
                    const attrs = v.attributes || v.combination || {};
                    if (attrs[optName] === val) {
                        v.image_url = attachment.url;
                        v.attachment_id = attachment.id;
                        count++;
                    }
                });
                layers.close($('#wpe-media-group-modal'));
                showToast(`Image assigned to ${count} variants with ${optName}: ${val}!`, 'success');
                wpeVariantStore.markDirty();
            });

            mediaFrame.open();
        });
    }

    /**
     * Open the fullscreen editor. It renders from the same store as the inline editor, so filters,
     * selection and edits are shared; closing it never saves or discards anything.
     */
    function openFullscreen() {
        const $overlay = $('#wpe-fullscreen-modal-overlay');
        if (!$overlay.length || $overlay.is(':visible')) return;
        $overlay.fadeIn(150);
        renderEditor('#wpe-product-editor-modal-app');
        layers.open($overlay, {
            onClose: function () {
                $overlay.fadeOut(150);
                renderEditor('#wpe-product-editor-inline-app');
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
        $container.find('.wpe-btn-open-fullscreen').off('click').on('click', openFullscreen);

        $('#wpe-fullscreen-modal-overlay').find('.wpe-btn-close-fullscreen').off('click').on('click', function () {
            layers.close($('#wpe-fullscreen-modal-overlay'));
        });

        // Save via REST API
        $container.find('.wpe-btn-save-api').off('click').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true).text(config.i18n.saving);

            const payload = {
                expected_version: wpeVariantStore.version,
                product: wpeVariantStore.product,
                descriptive_attributes: wpeVariantStore.descriptiveAttributes,
                variation_options: wpeVariantStore.variationOptions,
                variations: wpeVariantStore.variations
            };

            api.saveData(payload)
                .done(function (res) {
                    if (res.success) {
                        wpeVariantStore.version = res.result.new_version || (wpeVariantStore.version + 1);
                        wpeVariantStore.isDirty = false;
                        wpeVariantStore.notify();
                        const proj = (res.result && res.result.projection) || null;
                        const okMirror = ['mirrored', 'skipped_obsolete_revision'];
                        if (proj && (okMirror.indexOf(proj.legacy_mirror) === -1 || proj.catalog_index !== 'ok')) {
                            showToast(`${config.i18n.projectionWarning} (mirror: ${proj.legacy_mirror}, index: ${proj.catalog_index})`, 'warning');
                        } else {
                            showToast(config.i18n.saveSuccess, 'success');
                        }
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

        // Section A: Add Descriptive Attribute
        $container.find('.wpe-btn-add-desc-attr').off('click').on('click', function () {
            const $select = $container.find('.wpe-add-taxonomy-select');
            const taxSlug = $select.val();
            let attrName = '';
            let taxObj = null;

            if (taxSlug) {
                taxObj = wpeVariantStore.taxonomies.find(t => t.name === taxSlug);
                attrName = taxObj ? (taxObj.label || taxObj.name) : taxSlug;
            } else {
                attrName = prompt('Enter descriptive attribute name (e.g. Material, Brand, Fit):');
                if (!attrName || !attrName.trim()) return;
                attrName = attrName.trim();
            }

            wpeVariantStore.descriptiveAttributes.push({
                name: attrName,
                taxonomy: taxSlug || null,
                is_visible: 1,
                position: wpeVariantStore.descriptiveAttributes.length,
                values: taxObj && taxObj.terms ? taxObj.terms.map(t => t.name) : []
            });

            wpeVariantStore.markDirty();
        });

        // Section A: Delete Descriptive Attribute
        $container.find('.wpe-desc-attr-delete').off('click').on('click', function () {
            const idx = $(this).closest('.wpe-desc-attr-card').data('idx');
            wpeVariantStore.descriptiveAttributes.splice(idx, 1);
            wpeVariantStore.markDirty();
        });

        // Section A: Visibility Toggle
        $container.find('.wpe-desc-visible-toggle').off('change').on('change', function () {
            const idx = $(this).closest('.wpe-desc-attr-card').data('idx');
            wpeVariantStore.descriptiveAttributes[idx].is_visible = $(this).is(':checked') ? 1 : 0;
            wpeVariantStore.markDirty();
        });

        // Section A & B: Chip Input & Remove
        $container.find('.wpe-chip-input').off('keydown blur').on('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const val = $(this).val().trim().replace(/,/g, '');
                if (!val) return;

                const $chipsWrap = $(this).closest('.wpe-chips-container');
                const attrIdx = $chipsWrap.data('attr-idx');
                const optIdx = $chipsWrap.data('opt-idx');

                if (attrIdx !== undefined) {
                    wpeVariantStore.descriptiveAttributes[attrIdx].values = wpeVariantStore.descriptiveAttributes[attrIdx].values || [];
                    wpeVariantStore.descriptiveAttributes[attrIdx].values.push(val);
                } else if (optIdx !== undefined) {
                    wpeVariantStore.variationOptions[optIdx].values = wpeVariantStore.variationOptions[optIdx].values || [];
                    wpeVariantStore.variationOptions[optIdx].values.push({ label: val, value_key: val });
                }

                $(this).val('');
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-chip-remove').off('click').on('click', function (e) {
            e.stopPropagation();
            const $chip = $(this).closest('.wpe-chip');
            const valIdx = $chip.data('val-idx');
            const attrIdx = $chip.data('attr-idx');
            const optIdx = $chip.data('opt-idx');

            if (attrIdx !== undefined) {
                wpeVariantStore.descriptiveAttributes[attrIdx].values.splice(valIdx, 1);
                wpeVariantStore.markDirty();
            } else if (optIdx !== undefined) {
                const opt = wpeVariantStore.variationOptions[optIdx];
                const valObj = opt.values[valIdx];
                const valLabel = typeof valObj === 'object' ? valObj.label : valObj;

                // Check affected variations count
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

                opt.values.splice(valIdx, 1);
                wpeVariantStore.markDirty();
            }
        });

        // Section B: Add Variation Option
        $container.find('.wpe-btn-add-option').off('click').on('click', function () {
            const name = prompt('Enter new variation option name (e.g. Color, Size, Material):');
            if (!name || !name.trim()) return;

            wpeVariantStore.variationOptions.push({
                name: name.trim(),
                position: wpeVariantStore.variationOptions.length,
                values: []
            });
            wpeVariantStore.markDirty();
        });

        // Section B: Rename Option
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

        // Section B: Delete Option
        $container.find('.wpe-btn-delete-option').off('click').on('click', function () {
            const idx = $(this).closest('.wpe-option-card').data('idx');
            const opt = wpeVariantStore.variationOptions[idx];

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

        // Section B: Toggle Bulk Paste Box
        $container.find('.wpe-btn-toggle-bulk-paste').off('click').on('click', function () {
            const $box = $(this).closest('.wpe-option-card').find('.wpe-bulk-paste-box');
            $box.slideToggle(150);
        });

        $container.find('.wpe-btn-cancel-bulk-paste').off('click').on('click', function () {
            $(this).closest('.wpe-bulk-paste-box').slideUp(150);
        });

        $container.find('.wpe-btn-apply-bulk-paste').off('click').on('click', function () {
            const $card = $(this).closest('.wpe-option-card');
            const idx = $card.data('idx');
            const text = $card.find('.wpe-bulk-paste-box textarea').val();
            if (!text || !text.trim()) return;

            const tokens = text.split(/[\n,\t]+/).map(s => s.trim()).filter(s => s.length > 0);
            wpeVariantStore.variationOptions[idx].values = wpeVariantStore.variationOptions[idx].values || [];

            tokens.forEach(t => {
                const exists = wpeVariantStore.variationOptions[idx].values.some(v => (typeof v === 'object' ? v.label : v).toLowerCase() === t.toLowerCase());
                if (!exists) {
                    wpeVariantStore.variationOptions[idx].values.push({ label: t, value_key: t });
                }
            });

            wpeVariantStore.markDirty();
        });

        // Section C: Filters & Search
        $container.find('.wpe-search-input').off('input').on('input', function () {
            wpeVariantStore.filters.search = $(this).val();
            wpeVariantStore.filters.page = 1;
            renderEditor(containerId);
        });

        $container.find('.wpe-filter-status').off('change').on('change', function () {
            wpeVariantStore.filters.status = $(this).val();
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

        // Section C: Pagination Prev/Next
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

        // Section C: Selection checkboxes
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

        $container.find('.wpe-row-check').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            if ($(this).is(':checked')) {
                wpeVariantStore.selection.add(vId);
            } else {
                wpeVariantStore.selection.delete(vId);
            }
            renderEditor(containerId);
        });

        $container.find('.wpe-btn-clear-selection').off('click').on('click', function () {
            wpeVariantStore.selection.clear();
            renderEditor(containerId);
        });

        $container.find('.wpe-btn-select-all-filtered').off('click').on('click', function () {
            wpeVariantStore.variations.forEach(v => wpeVariantStore.selection.add(v.id));
            renderEditor(containerId);
        });

        // Section C: Inline cell editing
        $container.find('.wpe-inline-sku').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.sku = $(this).val().trim();
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-inline-reg-price').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.regular_price = $(this).val();
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-inline-sale-price').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.sale_price = $(this).val();
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-inline-stock-qty').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v && v.manage_stock) {
                v.stock_quantity = parseFloat($(this).val()) || 0;
                v.stock_status = v.stock_quantity > 0 ? 'instock' : 'outofstock';
                wpeVariantStore.markDirty();
            }
        });

        $container.find('.wpe-inline-status').off('change').on('change', function () {
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.status = $(this).val();
                wpeVariantStore.markDirty();
            }
        });

        // Section C: Single Variation Media Picker
        $container.find('.wpe-media-slot').off('click').on('click', function (e) {
            if ($(e.target).hasClass('wpe-media-remove')) return;
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (!v) return;

            const mediaFrame = wp.media({
                title: 'Select Variant Image',
                button: { text: 'Set as Variant Image' },
                multiple: false
            });

            mediaFrame.on('select', function () {
                const attachment = mediaFrame.state().get('selection').first().toJSON();
                v.image_url = attachment.url;
                v.attachment_id = attachment.id;
                wpeVariantStore.markDirty();
            });

            mediaFrame.open();
        });

        // Section C: Single Variation Media Remove (unlink only)
        $container.find('.wpe-media-remove').off('click').on('click', function (e) {
            e.stopPropagation();
            const vId = $(this).closest('tr').data('variant-id');
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (v) {
                v.image_url = null;
                v.attachment_id = null;
                wpeVariantStore.markDirty();
            }
        });

        // Section C: Bulk Assign Media to selected rows
        $container.find('.wpe-btn-bulk-assign-media').off('click').on('click', function () {
            const selectedIds = Array.from(wpeVariantStore.selection);
            if (selectedIds.length === 0) return;

            const mediaFrame = wp.media({
                title: `Assign image to ${selectedIds.length} selected variants`,
                button: { text: 'Assign Image to Selection' },
                multiple: false
            });

            mediaFrame.on('select', function () {
                const attachment = mediaFrame.state().get('selection').first().toJSON();
                selectedIds.forEach(id => {
                    const v = wpeVariantStore.variations.find(item => item.id === id);
                    if (v) {
                        v.image_url = attachment.url;
                        v.attachment_id = attachment.id;
                    }
                });
                wpeVariantStore.markDirty();
            });

            mediaFrame.open();
        });

        // Section C: Delete Single Variation
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

        // Section C: Open Slide-over Drawer
        $container.find('.wpe-btn-open-drawer').off('click').on('click', function () {
            const vId = $(this).closest('tr').data('variant-id');
            renderDrawer(vId);
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
            const v = wpeVariantStore.variations.find(item => item.id === vId);
            if (!v) return;

            v.sku = $('#wpe-drawer-sku').val().trim();
            v.status = $('#wpe-drawer-status').val();
            v.regular_price = $('#wpe-drawer-reg-price').val();
            v.sale_price = $('#wpe-drawer-sale-price').val();
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

            layers.close($('#wpe-variation-drawer-overlay'));
            wpeVariantStore.markDirty();
        });

        // Modal close
        $(document).on('click', '.wpe-btn-close-modal', function () {
            const $own = $(this).closest('.wpe-modal-backdrop');
            layers.close($own.length ? $own : $('.wpe-modal-backdrop').last());
        });

        // Generator Select/Unselect all
        $(document).on('click', '.wpe-btn-gen-select-all', function () {
            $('#wpe-generator-body .wpe-gen-check').prop('checked', true);
        });

        $(document).on('click', '.wpe-btn-gen-unselect-all', function () {
            $('#wpe-generator-body .wpe-gen-check').prop('checked', false);
        });

        // Apply Generator selected combinations
        $(document).on('click', '.wpe-btn-apply-generator', function () {
            const newCombos = $(this).data('new-combos') || [];
            const defPrice = $('#wpe-gen-def-price').val();
            const defSkuPrefix = $('#wpe-gen-def-sku').val().trim();
            const defStock = $('#wpe-gen-def-stock').val();

            let addedCount = 0;
            $('#wpe-generator-body .wpe-gen-check:checked').each(function () {
                const idx = $(this).data('idx');
                const nc = newCombos[idx];
                if (!nc) return;

                const combo = nc.combination || nc.attributes;
                const skuSuffix = Object.values(combo).map(v => String(v).replace(/\s+/g, '-').toUpperCase()).join('-');
                const sku = defSkuPrefix ? `${defSkuPrefix}${skuSuffix}` : '';

                const maxId = wpeVariantStore.variations.reduce((max, v) => Math.max(max, v.id || 0), 0);
                const newVarId = maxId + 1;

                wpeVariantStore.variations.push({
                    id: newVarId,
                    sku: sku,
                    regular_price: defPrice || '',
                    sale_price: '',
                    status: 'publish',
                    position: wpeVariantStore.variations.length,
                    manage_stock: defStock !== '',
                    stock_quantity: defStock !== '' ? parseFloat(defStock) : null,
                    stock_status: defStock !== '' && parseFloat(defStock) <= 0 ? 'outofstock' : 'instock',
                    attributes: combo,
                    combination: combo,
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

        // Bulk Edit Preview Diff
        $(document).on('click', '.wpe-btn-preview-bulk-diff', function () {
            const field = $('#wpe-bulk-field').val();
            const selectedIds = Array.from(wpeVariantStore.selection);
            const actions = {};

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
            } else if (field === 'status') {
                actions.status = $('#wpe-bulk-status-val').val();
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

                if (field === 'regular_price' || field === 'sale_price') {
                    const type = $('#wpe-bulk-price-type').val();
                    const val = parseFloat($('#wpe-bulk-price-val').val()) || 0;
                    const oldVal = parseFloat(v[field]) || 0;
                    let newVal = oldVal;

                    if (type === 'fixed') newVal = val;
                    else if (type === 'increase_fixed') newVal = oldVal + val;
                    else if (type === 'decrease_fixed') newVal = Math.max(0, oldVal - val);
                    else if (type === 'increase_percent') newVal = oldVal * (1 + val / 100);
                    else if (type === 'decrease_percent') newVal = Math.max(0, oldVal * (1 - val / 100));

                    v[field] = newVal > 0 ? String(Math.round(newVal * 100) / 100) : '';
                } else if (field === 'status') {
                    v.status = $('#wpe-bulk-status-val').val();
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

        // Shopify Sticky Contextual Save Bar actions
        $(document).on('click', '.wpe-sticky-btn-save', function () {
            $('.wpe-btn-save-api:not(:disabled)').first().trigger('click');
        });

        $(document).on('click', '.wpe-sticky-btn-discard', function () {
            if (confirm('Discard all unsaved changes and reload from server?')) {
                api.fetchData().done(function (res) {
                    if (res.success && res.data) {
                        applyServerData(res.data);
                        wpeVariantStore.isDirty = false;
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
            if (wpeVariantStore.isLoaded) openFullscreen();
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
                if (wpeVariantStore.isDirty && !isVariableType()) {
                    if (!window.confirm(config.i18n.typeChangeWarning)) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return;
                    }
                }
                submitting = true;
                // If another handler cancels the submit, the guard must re-arm.
                setTimeout(function () { submitting = false; }, 4000);
            }, true);
        }
    }

    /**
     * Box header: unsaved/in-sync badge and Fullscreen button live in the WordPress meta box title bar,
     * so they remain visible when the box is collapsed.
     */
    function ensureBoxHeader() {
        const $header = $('#' + config.boxId + ' .postbox-header');
        if (!$header.length) return null;
        let $extras = $header.find('.wpe-box-header-extras');
        if (!$extras.length) {
            $extras = $(
                '<div class="wpe-box-header-extras">' +
                '<span id="wpe-box-status" class="wpe-box-status" role="status" aria-live="polite"></span>' +
                '<button type="button" id="wpe-box-fullscreen" class="button button-small" disabled>' +
                '<span class="dashicons dashicons-editor-expand" aria-hidden="true"></span> Fullscreen</button>' +
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
        if (!ensureBoxHeader()) return;
        const $status = $('#wpe-box-status');
        let label = '';
        let cls = 'idle';
        if (!isVariableType()) {
            label = '';
        } else if (wpeVariantStore.loadFailed) {
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
        $status.text(label).attr('class', 'wpe-box-status ' + cls);
        $('#wpe-box-fullscreen').prop('disabled', !wpeVariantStore.isLoaded);
    }

    /**
     * Apply a server payload (or a restored draft) to the store.
     */
    function applyServerData(data) {
        wpeVariantStore.version = data.product ? data.product.version : 1;
        wpeVariantStore.product = data.product || {};
        wpeVariantStore.descriptiveAttributes = data.descriptive_attributes || [];
        wpeVariantStore.variationOptions = data.variation_options || [];
        wpeVariantStore.variations = data.variations || [];
        wpeVariantStore.taxonomies = data.taxonomies || data.global_taxonomies || [];
    }

    function applyRestoredDraft(draft) {
        if (!draft || typeof draft !== 'object') return false;
        if (draft.product) wpeVariantStore.product = draft.product;
        if (Array.isArray(draft.descriptive_attributes)) wpeVariantStore.descriptiveAttributes = draft.descriptive_attributes;
        if (Array.isArray(draft.variation_options)) wpeVariantStore.variationOptions = draft.variation_options;
        if (Array.isArray(draft.variations)) wpeVariantStore.variations = draft.variations;
        // Keep the draft's own version: if the server moved on meanwhile, the next save reports the conflict again.
        if (draft.expected_version) wpeVariantStore.version = draft.expected_version;
        wpeVariantStore.isDirty = true;
        return true;
    }

    /**
     * Lazy, idempotent data load. Only variable products (or a restored failed-save draft) fetch anything.
     */
    function loadData() {
        if (wpeVariantStore.isRequested) return;
        wpeVariantStore.isRequested = true;
        wpeVariantStore.isLoading = true;
        wpeVariantStore.loadFailed = false;
        renderEditor('#wpe-product-editor-inline-app');
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

    function failLoad(reason) {
        wpeVariantStore.isRequested = false;
        wpeVariantStore.isLoading = false;
        wpeVariantStore.loadFailed = true;
        $('#wpe-product-editor-inline-app').html(`
            <div class="notice notice-error inline" style="margin:12px;">
                <p>Unable to load variants data: ${escapeHtml(reason)}
                <button type="button" class="button wpe-btn-retry-load" style="margin-left:8px;">Retry</button></p>
            </div>
        `);
        updateBoxHeader();
    }

    /**
     * Handle Product Type Switch in WooCommerce metabox
     */
    function setupProductTypeToggle() {
        const $productTypeSelect = $('#product-type');
        const $metabox = $('#woocommerce-product-data');
        const $box = $('#' + config.boxId);

        function updateType() {
            const isVariable = isVariableType();
            $metabox.toggleClass('wpe-is-variable', isVariable);
            $box.toggleClass('wpe-box-hidden', !isVariable);
            if (isVariable) {
                loadData();
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
        // Subscribe store to rerender active views
        wpeVariantStore.subscribe(() => {
            renderEditor('#wpe-product-editor-inline-app');
            if ($('#wpe-fullscreen-modal-overlay').is(':visible')) {
                renderEditor('#wpe-product-editor-modal-app');
            }
            updateBoxHeader();
        });

        bindGlobalEvents();
        ensureBoxHeader();

        if (config.restoredDraft) {
            // A failed save left a draft: load it even if the type selector currently says otherwise.
            loadData();
        }
        setupProductTypeToggle();
    });

})(jQuery);
