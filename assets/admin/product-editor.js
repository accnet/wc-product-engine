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

    /**
     * Unified Reactive Draft Store
     */
    const wpeVariantStore = {
        productId: productId,
        version: 1,
        isDirty: false,
        isLoading: true,
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

        syncHiddenPayload() {
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
     * API Client
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

    /**
     * Toast notifications
     */
    function showToast(message, type = 'success') {
        $('.wpe-toast').remove();
        const $toast = $(`<div class="wpe-toast ${type}"><span class="dashicons dashicons-${type === 'success' ? 'yes' : 'warning'}"></span><span>${escapeHtml(message)}</span></div>`);
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
                    <span>Đang tải thuộc tính và biến thể sản phẩm...</span>
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
                        <h3 class="wpe-top-title">${isModal ? 'Quản lý biến thể (Toàn màn hình)' : 'Quản lý biến thể'}</h3>
                        <span class="wpe-dirty-badge ${wpeVariantStore.isDirty ? 'dirty' : 'clean'}">
                            <span class="dashicons dashicons-${wpeVariantStore.isDirty ? 'warning' : 'yes'}"></span>
                            ${wpeVariantStore.isDirty ? 'Có thay đổi chưa lưu' : 'Đã đồng bộ'}
                        </span>
                    </div>
                    <div class="wpe-top-actions">
                        ${!isModal ? `
                            <button type="button" class="button wpe-btn-open-fullscreen">
                                <span class="dashicons dashicons-editor-expand" style="margin-top:4px;"></span> Mở rộng editor
                            </button>
                        ` : `
                            <button type="button" class="button wpe-btn-close-fullscreen">
                                <span class="dashicons dashicons-editor-contract" style="margin-top:4px;"></span> Thu nhỏ
                            </button>
                        `}
                        <button type="button" class="button button-primary wpe-btn-save-api" ${!wpeVariantStore.isDirty ? 'disabled' : ''}>
                            <span class="dashicons dashicons-saved" style="margin-top:4px;"></span> Lưu thay đổi
                        </button>
                    </div>
                </div>

                <!-- SECTION A: Thuộc tính mô tả -->
                ${renderSectionA()}

                <!-- SECTION B: Options tạo biến thể -->
                ${renderSectionB()}

                <!-- SECTION C: Danh sách biến thể -->
                ${renderSectionC()}
            </div>
        `;

        $container.html(html);
        bindEditorEvents($container);
    }

    /**
     * Render Section A: Thuộc tính mô tả (used_for_variation = 0)
     */
    function renderSectionA() {
        const attrs = wpeVariantStore.descriptiveAttributes;
        let itemsHtml = '';

        if (attrs.length === 0) {
            itemsHtml = `<div style="color:#646970; font-style:italic; padding:8px 0;">Chưa có thuộc tính mô tả (Material, Brand, Fit...). Nhấn "Thêm thuộc tính mô tả" để tạo.</div>`;
        } else {
            attrs.forEach((attr, idx) => {
                const values = (attr.values || []).map(v => typeof v === 'object' ? v.label : v);
                const chipsHtml = values.map((val, vIdx) => `
                    <span class="wpe-chip" data-attr-idx="${idx}" data-val-idx="${vIdx}">
                        <span>${escapeHtml(val)}</span>
                        <span class="wpe-chip-remove" title="Xóa">&times;</span>
                    </span>
                `).join('');

                itemsHtml += `
                    <div class="wpe-desc-attr-card" data-idx="${idx}">
                        <div class="wpe-desc-attr-header">
                            <div class="wpe-desc-attr-name">
                                <span>${escapeHtml(attr.name)}</span>
                                ${attr.taxonomy ? `<span class="wpe-taxonomy-tag">Toàn hệ thống (${escapeHtml(attr.taxonomy)})</span>` : '<span class="wpe-taxonomy-tag" style="background:#f0f0f1; color:#50575e;">Thuộc tính riêng</span>'}
                            </div>
                            <div class="wpe-desc-attr-controls">
                                <label>
                                    <input type="checkbox" class="wpe-desc-visible-toggle" ${attr.is_visible || attr.visible ? 'checked' : ''} />
                                    Hiển thị trên trang sản phẩm
                                </label>
                                <button type="button" class="button-link-delete wpe-desc-attr-delete" title="Xóa thuộc tính này">
                                    <span class="dashicons dashicons-trash" style="font-size:16px;"></span>
                                </button>
                            </div>
                        </div>
                        <div class="wpe-chips-container" data-attr-idx="${idx}">
                            ${chipsHtml}
                            <input type="text" class="wpe-chip-input" placeholder="Gõ giá trị và nhấn Enter..." />
                        </div>
                    </div>
                `;
            });
        }

        // Global taxonomies dropdown options
        let taxonomyOptions = '<option value="">-- Thuộc tính riêng của sản phẩm --</option>';
        wpeVariantStore.taxonomies.forEach(t => {
            taxonomyOptions += `<option value="${escapeHtml(t.slug)}">${escapeHtml(t.name)}</option>`;
        });

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-tag"></span>
                        <h4 class="wpe-section-title">A. Thuộc tính mô tả</h4>
                        <span class="wpe-section-count">${attrs.length}</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <select class="wpe-add-taxonomy-select" style="max-width:200px; font-size:12px;">
                            ${taxonomyOptions}
                        </select>
                        <button type="button" class="button wpe-btn-add-desc-attr">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Thêm thuộc tính
                        </button>
                    </div>
                </div>
                <p class="wpe-section-desc">Thuộc tính mô tả hiển thị thông tin chi tiết trên trang sản phẩm (Material, Brand, Style...) và không tạo ra biến thể.</p>
                <div class="wpe-section-body">
                    <div class="wpe-desc-attr-list">
                        ${itemsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render Section B: Options tạo biến thể (used_for_variation = 1)
     */
    function renderSectionB() {
        const options = wpeVariantStore.variationOptions;
        let cardsHtml = '';

        if (options.length === 0) {
            cardsHtml = `<div style="color:#646970; font-style:italic; padding:8px 0;">Chưa có options biến thể (Color, Size...). Nhấn "Thêm option" để bắt đầu.</div>`;
        } else {
            options.forEach((opt, idx) => {
                const values = (opt.values || []).map(v => typeof v === 'object' ? v.label : v);
                const chipsHtml = values.map((val, vIdx) => `
                    <span class="wpe-chip" data-opt-idx="${idx}" data-val-idx="${vIdx}">
                        <span>${escapeHtml(val)}</span>
                        <span class="wpe-chip-remove" title="Xóa giá trị">&times;</span>
                    </span>
                `).join('');

                cardsHtml += `
                    <div class="wpe-option-card" data-idx="${idx}">
                        <div class="wpe-option-card-header">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span class="dashicons dashicons-menu" style="color:#c3c4c7; cursor:grab;" title="Kéo sắp xếp"></span>
                                <input type="text" class="wpe-option-name-input" value="${escapeHtml(opt.name)}" placeholder="Tên option (vd: Size, Color)" />
                                <span style="font-size:11px; color:#646970;">(${values.length} giá trị)</span>
                            </div>
                            <div class="wpe-option-card-actions">
                                <button type="button" class="button button-small wpe-btn-toggle-bulk-paste">
                                    <span class="dashicons dashicons-clipboard" style="margin-top:2px;"></span> Dán nhiều
                                </button>
                                <button type="button" class="button-link-delete wpe-btn-delete-option" title="Xóa option này">
                                    <span class="dashicons dashicons-trash" style="font-size:16px;"></span>
                                </button>
                            </div>
                        </div>

                        <!-- Bulk paste values box -->
                        <div class="wpe-bulk-paste-box">
                            <p style="margin:0 0 6px 0; font-size:11px; color:#50575e;">Dán các giá trị phân cách bởi dấu phẩy, tab hoặc dòng mới:</p>
                            <textarea rows="2" placeholder="Red, Blue, Green, Yellow..."></textarea>
                            <div style="margin-top:6px; display:flex; justify-content:flex-end; gap:6px;">
                                <button type="button" class="button button-small wpe-btn-cancel-bulk-paste">Đóng</button>
                                <button type="button" class="button button-small button-primary wpe-btn-apply-bulk-paste">Thêm nhanh</button>
                            </div>
                        </div>

                        <div class="wpe-chips-container" data-opt-idx="${idx}">
                            ${chipsHtml}
                            <input type="text" class="wpe-chip-input" placeholder="Nhập giá trị và nhấn Enter..." />
                        </div>
                    </div>
                `;
            });
        }

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <h4 class="wpe-section-title">B. Options tạo biến thể</h4>
                        <span class="wpe-section-count">${options.length}</span>
                    </div>
                    <div>
                        <button type="button" class="button wpe-btn-add-option">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span> Thêm option mới
                        </button>
                    </div>
                </div>
                <p class="wpe-section-desc">Cấu hình các thuộc tính tạo biến thể (Color, Size...). Đổi tên hoặc sắp xếp giữ nguyên variation IDs.</p>
                <div class="wpe-section-body">
                    <div class="wpe-options-list">
                        ${cardsHtml}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render Section C: Danh sách biến thể (Variations Matrix)
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

        // Grouping
        let groups = {};
        if (filters.groupBy && filters.groupBy !== 'none') {
            filtered.forEach(v => {
                const attrs = v.attributes || v.combination || {};
                const groupKey = attrs[filters.groupBy] || 'Chưa chọn';
                if (!groups[groupKey]) groups[groupKey] = [];
                groups[groupKey].push(v);
            });
        } else {
            groups['all'] = filtered;
        }

        // Pagination
        const totalItems = filtered.length;
        const totalPages = Math.ceil(totalItems / filters.pageSize) || 1;
        const startIdx = (filters.page - 1) * filters.pageSize;
        const paginatedList = filtered.slice(startIdx, startIdx + filters.pageSize);

        // Options for Group By dropdown
        let groupByOptions = '<option value="none">-- Không nhóm --</option>';
        options.forEach(opt => {
            groupByOptions += `<option value="${escapeHtml(opt.name)}" ${filters.groupBy === opt.name ? 'selected' : ''}>Nhóm theo ${escapeHtml(opt.name)}</option>`;
        });

        const selectedCount = wpeVariantStore.selection.size;

        return `
            <div class="wpe-section">
                <div class="wpe-section-header">
                    <div class="wpe-section-title-wrap">
                        <span class="dashicons dashicons-grid-view"></span>
                        <h4 class="wpe-section-title">C. Danh sách biến thể</h4>
                        <span class="wpe-section-count">${variations.length}</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="button" class="button button-primary wpe-btn-open-generator">
                            <span class="dashicons dashicons-forms" style="margin-top:3px;"></span> Tạo biến thể
                        </button>
                    </div>
                </div>

                <!-- Matrix Toolbar -->
                <div class="wpe-matrix-toolbar">
                    <div class="wpe-toolbar-left">
                        <input type="text" class="wpe-search-input" placeholder="Tìm theo SKU, tổ hợp, giá..." value="${escapeHtml(filters.search)}" />
                        <select class="wpe-filter-select wpe-filter-status">
                            <option value="">Trạng thái: Tất cả</option>
                            <option value="publish" ${filters.status === 'publish' ? 'selected' : ''}>Đã xuất bản</option>
                            <option value="private" ${filters.status === 'private' ? 'selected' : ''}>Riêng tư</option>
                            <option value="draft" ${filters.status === 'draft' ? 'selected' : ''}>Bản nháp</option>
                        </select>
                        <select class="wpe-filter-select wpe-filter-stock">
                            <option value="">Tồn kho: Tất cả</option>
                            <option value="instock" ${filters.stockStatus === 'instock' ? 'selected' : ''}>Còn hàng</option>
                            <option value="outofstock" ${filters.stockStatus === 'outofstock' ? 'selected' : ''}>Hết hàng</option>
                            <option value="parent_stock" ${filters.stockStatus === 'parent_stock' ? 'selected' : ''}>Dùng tồn chung</option>
                        </select>
                        <select class="wpe-filter-select wpe-group-by-select">
                            ${groupByOptions}
                        </select>
                    </div>
                    <div class="wpe-toolbar-right">
                        <button type="button" class="button wpe-btn-assign-media-by-option">
                            <span class="dashicons dashicons-images-alt2" style="margin-top:3px;"></span> Gán ảnh theo nhóm
                        </button>
                        <button type="button" class="button wpe-btn-open-bulk-edit" ${selectedCount === 0 ? 'disabled' : ''}>
                            <span class="dashicons dashicons-edit" style="margin-top:3px;"></span> Chỉnh sửa hàng loạt (${selectedCount})
                        </button>
                    </div>
                </div>

                <!-- Selection Bar -->
                <div class="wpe-selection-bar ${selectedCount > 0 ? 'active' : ''}">
                    <div>
                        <strong>${selectedCount}</strong> biến thể đã chọn
                        <button type="button" class="button-link wpe-btn-select-all-filtered" style="margin-left:8px;">Chọn toàn bộ ${totalItems} kết quả lọc</button>
                    </div>
                    <div class="wpe-selection-actions">
                        <button type="button" class="button button-small wpe-btn-bulk-assign-media">Gán ảnh chung</button>
                        <button type="button" class="button button-small wpe-btn-clear-selection">Bỏ chọn</button>
                    </div>
                </div>

                <!-- Table Container -->
                <div class="wpe-table-container">
                    <table class="wpe-table">
                        <thead>
                            <tr>
                                <th style="width:30px;"><input type="checkbox" class="wpe-check-all-page" /></th>
                                <th style="width:50px;">Ảnh</th>
                                <th>Tổ hợp biến thể</th>
                                <th style="width:130px;">SKU</th>
                                <th style="width:110px;">Giá thường (${config.currencySymbol})</th>
                                <th style="width:110px;">Giá sale (${config.currencySymbol})</th>
                                <th style="width:130px;">Tồn kho</th>
                                <th style="width:90px;">Trạng thái</th>
                                <th style="width:90px; text-align:right;">Thao tác</th>
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
                        Hiển thị ${totalItems > 0 ? startIdx + 1 : 0} &ndash; ${Math.min(startIdx + filters.pageSize, totalItems)} trong tổng số ${totalItems} biến thể
                    </div>
                    <div class="wpe-pagination-controls">
                        <button type="button" class="button button-small wpe-page-prev" ${filters.page <= 1 ? 'disabled' : ''}>&laquo; Trước</button>
                        <span style="margin:0 8px;">Trang ${filters.page} / ${totalPages}</span>
                        <button type="button" class="button button-small wpe-page-next" ${filters.page >= totalPages ? 'disabled' : ''}>Sau &raquo;</button>
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
            return `<tr><td colspan="9" style="text-align:center; padding:30px; color:#646970;">Không tìm thấy biến thể nào phù hợp.</td></tr>`;
        }

        let html = '';
        let currentGroup = null;

        variations.forEach(v => {
            const attrs = v.attributes || v.combination || {};

            // Group header row
            if (groupBy && groupBy !== 'none') {
                const groupVal = attrs[groupBy] || 'Chưa chọn';
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
                        <div class="wpe-media-slot" title="Click để chọn ảnh">
                            ${v.image_url ? `<img src="${escapeHtml(v.image_url)}" alt="" /><span class="wpe-media-remove" title="Gỡ ảnh">&times;</span>` : '<span class="dashicons dashicons-format-image" style="color:#a7aaad;"></span>'}
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
                            <span class="wpe-stock-pill parent-stock" title="Dùng tồn chung theo sản phẩm cha">Tồn chung</span>
                        ` : `
                            <input type="number" step="any" class="wpe-editable-input wpe-inline-stock-qty" value="${escapeHtml(v.stock_quantity !== null ? v.stock_quantity : '')}" placeholder="SL" style="width:70px; display:inline-block;" />
                            <span class="wpe-stock-pill ${v.stock_status || 'instock'}">${v.stock_status === 'instock' ? 'Còn' : 'Hết'}</span>
                        `}
                    </td>
                    <td>
                        <select class="wpe-editable-input wpe-inline-status" style="padding:2px 4px !important;">
                            <option value="publish" ${v.status === 'publish' ? 'selected' : ''}>Hiện</option>
                            <option value="private" ${v.status === 'private' ? 'selected' : ''}>Ẩn</option>
                            <option value="draft" ${v.status === 'draft' ? 'selected' : ''}>Nháp</option>
                        </select>
                    </td>
                    <td style="text-align:right;">
                        <button type="button" class="button button-small wpe-btn-open-drawer" title="Chỉnh sửa chi tiết">
                            <span class="dashicons dashicons-edit" style="font-size:14px; margin-top:2px;"></span>
                        </button>
                        <button type="button" class="button-link-delete wpe-btn-delete-variant" title="Xóa biến thể">
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
                    <h4 class="wpe-drawer-title">Chi tiết biến thể #${variant.id}</h4>
                    <div style="margin-top:6px;">${badgesHtml}</div>
                </div>
                <button type="button" class="button-link wpe-btn-close-drawer" style="font-size:20px; text-decoration:none;">&times;</button>
            </div>
            <div class="wpe-drawer-body">
                <!-- Basic info -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Giá và SKU</h5>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>SKU</label>
                            <input type="text" id="wpe-drawer-sku" value="${escapeHtml(variant.sku || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Trạng thái</label>
                            <select id="wpe-drawer-status">
                                <option value="publish" ${variant.status === 'publish' ? 'selected' : ''}>Đã xuất bản</option>
                                <option value="private" ${variant.status === 'private' ? 'selected' : ''}>Riêng tư</option>
                                <option value="draft" ${variant.status === 'draft' ? 'selected' : ''}>Bản nháp</option>
                            </select>
                        </div>
                    </div>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>Giá thường (${config.currencySymbol})</label>
                            <input type="number" step="any" id="wpe-drawer-reg-price" value="${escapeHtml(variant.regular_price || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Giá khuyến mãi (${config.currencySymbol})</label>
                            <input type="number" step="any" id="wpe-drawer-sale-price" value="${escapeHtml(variant.sale_price || '')}" />
                        </div>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Quản lý tồn kho</h5>
                    <div style="margin-bottom:12px;">
                        <label>
                            <input type="checkbox" id="wpe-drawer-manage-stock" ${variant.manage_stock ? 'checked' : ''} />
                            Theo dõi số lượng tồn kho riêng cho biến thể này
                        </label>
                    </div>
                    <div class="wpe-form-row" id="wpe-drawer-stock-fields" style="${!variant.manage_stock ? 'display:none;' : ''}">
                        <div class="wpe-form-group">
                            <label>Số lượng trong kho</label>
                            <input type="number" step="any" id="wpe-drawer-stock-qty" value="${escapeHtml(variant.stock_quantity !== null ? variant.stock_quantity : '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Cho phép đặt hàng trước?</label>
                            <select id="wpe-drawer-backorders">
                                <option value="no" ${(variant.inventory && variant.inventory.backorders === 'no') ? 'selected' : ''}>Không cho phép</option>
                                <option value="notify" ${(variant.inventory && variant.inventory.backorders === 'notify') ? 'selected' : ''}>Cho phép, nhưng thông báo</option>
                                <option value="yes" ${(variant.inventory && variant.inventory.backorders === 'yes') ? 'selected' : ''}>Cho phép</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Dimensions & Shipping -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Kích thước và Trọng lượng</h5>
                    <div class="wpe-form-row">
                        <div class="wpe-form-group">
                            <label>Cân nặng (kg)</label>
                            <input type="text" id="wpe-drawer-weight" value="${escapeHtml(variant.weight || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Dài (cm)</label>
                            <input type="text" id="wpe-drawer-length" value="${escapeHtml(variant.length || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Rộng (cm)</label>
                            <input type="text" id="wpe-drawer-width" value="${escapeHtml(variant.width || '')}" />
                        </div>
                        <div class="wpe-form-group">
                            <label>Cao (cm)</label>
                            <input type="text" id="wpe-drawer-height" value="${escapeHtml(variant.height || '')}" />
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="wpe-drawer-section">
                    <h5 class="wpe-drawer-section-title">Mô tả biến thể</h5>
                    <div class="wpe-form-group">
                        <textarea rows="3" id="wpe-drawer-desc">${escapeHtml(variant.description || '')}</textarea>
                    </div>
                </div>
            </div>
            <div class="wpe-drawer-footer">
                <button type="button" class="button wpe-btn-close-drawer">Đóng</button>
                <button type="button" class="button button-primary wpe-btn-save-drawer" data-variant-id="${variant.id}">Áp dụng chi tiết</button>
            </div>
        `;

        $('#wpe-variation-drawer-app').html(html);
        $('#wpe-variation-drawer-overlay').fadeIn(150);
    }

    /**
     * Selective Combination Generator Modal
     */
    function openGeneratorModal() {
        if (wpeVariantStore.variationOptions.length === 0) {
            alert('Vui lòng thêm ít nhất một Option tạo biến thể (vd: Color, Size) ở Mục B trước khi tạo biến thể.');
            return;
        }

        // Show loading modal
        const modalHtml = `
            <div class="wpe-modal-backdrop" id="wpe-generator-modal">
                <div class="wpe-modal-dialog wpe-modal-dialog-lg">
                    <div class="wpe-modal-header">
                        <h4 class="wpe-modal-title">Tạo biến thể có chọn lọc</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body" id="wpe-generator-body">
                        <div style="text-align:center; padding:30px;">
                            <span class="spinner is-active" style="float:none;"></span>
                            <p>Đang phân tích các tổ hợp có thể tạo...</p>
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Hủy</button>
                        <button type="button" class="button button-primary wpe-btn-apply-generator" disabled>Tạo biến thể đã chọn</button>
                    </div>
                </div>
            </div>
        `;
        $('body').append(modalHtml);

        api.previewGenerator(wpeVariantStore.variationOptions)
            .done(function (res) {
                if (!res.success) {
                    $('#wpe-generator-body').html(`<p style="color:#d63638;">Lỗi tạo tổ hợp: ${escapeHtml(res.message)}</p>`);
                    return;
                }

                const result = res.result;
                const existingCombos = result.existing_combinations || [];
                const newCombos = result.new_combinations || [];

                let listHtml = '';
                newCombos.forEach((nc, idx) => {
                    const badgeText = Object.entries(nc.combination || nc.attributes || {}).map(([k, v]) => `${k}: ${v}`).join(' / ');
                    listHtml += `
                        <label style="display:block; padding:6px 8px; border-bottom:1px solid #f0f0f1; cursor:pointer;">
                            <input type="checkbox" class="wpe-gen-check" checked data-idx="${idx}" />
                            <strong>${escapeHtml(badgeText)}</strong>
                            <span style="font-size:11px; color:#046a38; margin-left:8px;">[Mới]</span>
                        </label>
                    `;
                });

                existingCombos.forEach((ec) => {
                    const badgeText = Object.entries(ec.combination || ec.attributes || {}).map(([k, v]) => `${k}: ${v}`).join(' / ');
                    listHtml += `
                        <label style="display:block; padding:6px 8px; border-bottom:1px solid #f0f0f1; color:#8c8f94;">
                            <input type="checkbox" checked disabled />
                            <span>${escapeHtml(badgeText)}</span>
                            <span style="font-size:11px; margin-left:8px;">[Đã tồn tại - SKU: ${escapeHtml(ec.sku || '')}]</span>
                        </label>
                    `;
                });

                const bodyHtml = `
                    <div style="background:#f0f6fc; padding:10px 14px; border-radius:4px; margin-bottom:14px; font-size:12px;">
                        Tổng số tổ hợp: <strong>${result.total_possible || result.total_potential}</strong> |
                        Đã tồn tại: <strong>${result.total_existing}</strong> |
                        Tổ hợp mới có thể tạo: <strong>${result.total_new}</strong>
                    </div>

                    <div style="display:flex; gap:12px; margin-bottom:14px; background:#fafafa; padding:10px; border-radius:4px;">
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600;">Giá thường mặc định (${config.currencySymbol}):</label>
                            <input type="number" step="any" id="wpe-gen-def-price" placeholder="0.00" style="width:100%; font-size:12px;" />
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600;">Tiền tố SKU:</label>
                            <input type="text" id="wpe-gen-def-sku" placeholder="SKU-" style="width:100%; font-size:12px;" />
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:11px; font-weight:600;">Số lượng tồn kho:</label>
                            <input type="number" step="any" id="wpe-gen-def-stock" placeholder="10" style="width:100%; font-size:12px;" />
                        </div>
                    </div>

                    <div style="margin-bottom:8px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; font-size:12px;">Chọn các tổ hợp muốn tạo:</span>
                        <div>
                            <button type="button" class="button-link wpe-btn-gen-select-all" style="font-size:11px;">Chọn tất cả</button> |
                            <button type="button" class="button-link wpe-btn-gen-unselect-all" style="font-size:11px;">Bỏ chọn</button>
                        </div>
                    </div>

                    <div style="max-height:260px; overflow-y:auto; border:1px solid #dcdcde; border-radius:4px; background:#fff;">
                        ${listHtml || '<div style="padding:20px; text-align:center; color:#646970;">Không có tổ hợp mới nào để tạo.</div>'}
                    </div>
                `;

                $('#wpe-generator-body').html(bodyHtml);
                $('#wpe-generator-modal .wpe-btn-apply-generator').data('new-combos', newCombos).prop('disabled', newCombos.length === 0);
            })
            .fail(function (err) {
                $('#wpe-generator-body').html(`<p style="color:#d63638;">Lỗi kết nối máy chủ.</p>`);
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
                        <h4 class="wpe-modal-title">Chỉnh sửa hàng loạt (${selectedIds.length} biến thể đã chọn)</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body" id="wpe-bulk-body">
                        <div style="margin-bottom:14px;">
                            <label style="font-weight:600; display:block; margin-bottom:6px;">Chọn trường muốn cập nhật:</label>
                            <select id="wpe-bulk-field" style="width:100%;">
                                <option value="regular_price">Giá thường</option>
                                <option value="sale_price">Giá khuyến mãi</option>
                                <option value="status">Trạng thái xuất bản</option>
                                <option value="manage_stock">Quản lý tồn kho</option>
                                <option value="stock_quantity">Số lượng tồn kho</option>
                            </select>
                        </div>

                        <div id="wpe-bulk-field-controls" style="margin-bottom:14px;">
                            <!-- Field specific inputs injected here -->
                        </div>

                        <button type="button" class="button wpe-btn-preview-bulk-diff">
                            <span class="dashicons dashicons-visibility" style="margin-top:3px;"></span> Xem trước thay đổi
                        </button>

                        <div id="wpe-bulk-diff-preview" style="margin-top:14px; max-height:200px; overflow-y:auto; display:none;">
                            <!-- Diff table will be rendered here -->
                        </div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Hủy</button>
                        <button type="button" class="button button-primary wpe-btn-apply-bulk">Áp dụng thay đổi</button>
                    </div>
                </div>
            </div>
        `;
        $('body').append(modalHtml);
        renderBulkFieldControls('regular_price');
    }

    function renderBulkFieldControls(field) {
        let html = '';
        if (field === 'regular_price' || field === 'sale_price') {
            html = `
                <div style="display:flex; gap:8px;">
                    <select id="wpe-bulk-price-type" style="width:160px;">
                        <option value="fixed">Đặt giá cố định</option>
                        <option value="increase_fixed">Tăng theo số tiền</option>
                        <option value="decrease_fixed">Giảm theo số tiền</option>
                        <option value="increase_percent">Tăng theo phần trăm (%)</option>
                        <option value="decrease_percent">Giảm theo phần trăm (%)</option>
                    </select>
                    <input type="number" step="any" id="wpe-bulk-price-val" placeholder="Nhập giá trị" style="flex:1;" />
                </div>
            `;
        } else if (field === 'status') {
            html = `
                <select id="wpe-bulk-status-val" style="width:100%;">
                    <option value="publish">Đã xuất bản (Hiện)</option>
                    <option value="private">Riêng tư (Ẩn)</option>
                    <option value="draft">Bản nháp</option>
                </select>
            `;
        } else if (field === 'manage_stock') {
            html = `
                <select id="wpe-bulk-manage-stock-val" style="width:100%;">
                    <option value="1">Bật quản lý tồn kho riêng</option>
                    <option value="0">Tắt (Dùng tồn chung của sản phẩm cha)</option>
                </select>
            `;
        } else if (field === 'stock_quantity') {
            html = `<input type="number" step="any" id="wpe-bulk-stock-qty-val" placeholder="Nhập số lượng" style="width:100%;" />`;
        }
        $('#wpe-bulk-field-controls').html(html);
    }

    /**
     * Bind all DOM events
     */
    function bindEditorEvents($container) {
        // Fullscreen toggle
        $container.find('.wpe-btn-open-fullscreen').off('click').on('click', function () {
            $('#wpe-fullscreen-modal-overlay').fadeIn(150);
            renderEditor('#wpe-product-editor-modal-app');
        });

        $('#wpe-fullscreen-modal-overlay').find('.wpe-btn-close-fullscreen').off('click').on('click', function () {
            $('#wpe-fullscreen-modal-overlay').fadeOut(150);
            renderEditor('#wpe-product-editor-inline-app');
        });

        // Save via REST API
        $container.find('.wpe-btn-save-api').off('click').on('click', function () {
            const $btn = $(this);
            $btn.prop('disabled', true).text('Đang lưu...');

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
                        showToast(config.i18n.saveSuccess, 'success');
                        wpeVariantStore.notify();
                    }
                })
                .fail(function (xhr) {
                    const res = xhr.responseJSON || {};
                    if (xhr.status === 409 && res.code === 'concurrency_conflict') {
                        alert(config.i18n.conflictError + '\n\n' + res.message);
                    } else {
                        alert(config.i18n.saveError + ' ' + (res.message || 'Lỗi lưu biến thể.'));
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="margin-top:4px;"></span> Lưu thay đổi');
                });
        });

        // Section A: Add Descriptive Attribute
        $container.find('.wpe-btn-add-desc-attr').off('click').on('click', function () {
            const $select = $container.find('.wpe-add-taxonomy-select');
            const taxSlug = $select.val();
            let attrName = '';
            let taxObj = null;

            if (taxSlug) {
                taxObj = wpeVariantStore.taxonomies.find(t => t.slug === taxSlug);
                attrName = taxObj ? taxObj.name : taxSlug;
            } else {
                attrName = prompt('Nhập tên thuộc tính mô tả (vd: Material, Brand, Fit):');
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
                    if (!confirm(`Xóa giá trị "${valLabel}" sẽ ảnh hưởng đến ${affected.length} biến thể (${skuList}...). Tiếp tục?`)) {
                        return;
                    }
                }

                opt.values.splice(valIdx, 1);
                wpeVariantStore.markDirty();
            }
        });

        // Section B: Add Variation Option
        $container.find('.wpe-btn-add-option').off('click').on('click', function () {
            const name = prompt('Nhập tên option biến thể mới (vd: Color, Size, Material):');
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
                // Update combination keys in variations preserving IDs
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
                if (!confirm(`Xóa option "${opt.name}" sẽ ảnh hưởng đến ${affected.length} biến thể hiện có. Bạn có chắc chắn muốn xóa?`)) {
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
                title: 'Chọn ảnh biến thể',
                button: { text: 'Gán ảnh này' },
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
                title: `Gán ảnh cho ${selectedIds.length} biến thể đã chọn`,
                button: { text: 'Gán ảnh hàng loạt' },
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
            if (confirm('Bạn có chắc chắn muốn xóa biến thể này?')) {
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
     * Modal: Assign Media by Option Group
     */
    function openMediaByOptionModal() {
        const options = wpeVariantStore.variationOptions;
        if (options.length === 0) {
            alert('Chưa có option nào (Color, Size...) để gán ảnh.');
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
                        <h4 class="wpe-modal-title">Gán ảnh theo nhóm Option</h4>
                        <button type="button" class="button-link wpe-btn-close-modal" style="font-size:20px;">&times;</button>
                    </div>
                    <div class="wpe-modal-body">
                        <div style="margin-bottom:12px;">
                            <label style="font-weight:600; display:block; margin-bottom:4px;">Chọn Option:</label>
                            <select id="wpe-media-group-opt" style="width:100%;">
                                ${optOptions}
                            </select>
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="font-weight:600; display:block; margin-bottom:4px;">Chọn giá trị:</label>
                            <select id="wpe-media-group-val" style="width:100%;"></select>
                        </div>
                        <div id="wpe-media-group-preview" style="margin-top:12px; font-size:12px; color:#50575e; background:#f0f6fc; padding:8px 12px; border-radius:4px;"></div>
                    </div>
                    <div class="wpe-modal-footer">
                        <button type="button" class="button wpe-btn-close-modal">Hủy</button>
                        <button type="button" class="button button-primary wpe-btn-pick-group-media">Chọn ảnh từ thư viện</button>
                    </div>
                </div>
            </div>
        `;
        $('body').append(modalHtml);

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
            $('#wpe-media-group-preview').html(`Có <strong>${matching.length}</strong> biến thể thỏa mãn điều kiện này.`);
        }

        $('#wpe-media-group-opt').on('change', updateValues);
        $('#wpe-media-group-val').on('change', updatePreview);
        updateValues();

        $('.wpe-btn-pick-group-media').on('click', function () {
            const optName = $('#wpe-media-group-opt').val();
            const val = $('#wpe-media-group-val').val();
            const mediaFrame = wp.media({
                title: `Gán ảnh cho nhóm ${optName}: ${val}`,
                button: { text: 'Gán ảnh nhóm này' },
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
                $('#wpe-media-group-modal').remove();
                showToast(`Đã gán ảnh cho ${count} biến thể có ${optName}: ${val}!`, 'success');
                wpeVariantStore.markDirty();
            });

            mediaFrame.open();
        });
    }

    /**
     * Bind Global & Modal Events
     */
    function bindGlobalEvents() {
        // Drawer close
        $(document).on('click', '.wpe-btn-close-drawer, #wpe-variation-drawer-overlay', function (e) {
            if (e.target === this) {
                $('#wpe-variation-drawer-overlay').fadeOut(150);
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

            $('#wpe-variation-drawer-overlay').fadeOut(150);
            wpeVariantStore.markDirty();
        });

        // Modal close
        $(document).on('click', '.wpe-btn-close-modal', function () {
            $('.wpe-modal-backdrop').remove();
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

                // Generate pseudo-id for new variation item
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

            $('#wpe-generator-modal').remove();
            if (addedCount > 0) {
                showToast(`Đã thêm ${addedCount} biến thể mới vào danh sách!`, 'success');
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
                                <thead><tr><th>Biến thể</th><th>Thay đổi dự kiến</th></tr></thead>
                                <tbody>${rowsHtml}</tbody>
                            </table>
                        `;
                        $('#wpe-bulk-diff-preview').html(tableHtml).slideDown(150);
                    }
                })
                .fail(function () {
                    alert('Không thể tạo bản xem trước thay đổi.');
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

            $('#wpe-bulk-modal').remove();
            showToast(`Đã áp dụng thay đổi cho ${selectedIds.length} biến thể!`, 'success');
            wpeVariantStore.markDirty();
        });

        // Dirty guard on leave
        window.addEventListener('beforeunload', function (e) {
            if (wpeVariantStore.isDirty) {
                e.preventDefault();
                e.returnValue = config.i18n.unsavedWarning;
                return config.i18n.unsavedWarning;
            }
        });
    }

    /**
     * Handle Product Type Switch in WooCommerce metabox
     */
    function setupProductTypeToggle() {
        const $productTypeSelect = $('#product-type');
        const $metabox = $('#woocommerce-product-data');

        function updateType() {
            const currentType = $productTypeSelect.val();
            if (currentType === 'variable') {
                $metabox.addClass('wpe-is-variable');
            } else {
                $metabox.removeClass('wpe-is-variable');
            }
        }

        $productTypeSelect.on('change', updateType);
        updateType();
    }

    /**
     * Initialization
     */
    $(document).ready(function () {
        setupProductTypeToggle();
        bindGlobalEvents();

        // Subscribe stores to rerender active views
        wpeVariantStore.subscribe(() => {
            renderEditor('#wpe-product-editor-inline-app');
            if ($('#wpe-fullscreen-modal-overlay').is(':visible')) {
                renderEditor('#wpe-product-editor-modal-app');
            }
        });

        // Load data from REST API
        api.fetchData()
            .done(function (res) {
                if (res.success && res.data) {
                    const data = res.data;
                    wpeVariantStore.version = data.product ? data.product.version : 1;
                    wpeVariantStore.product = data.product || {};
                    wpeVariantStore.descriptiveAttributes = data.descriptive_attributes || [];
                    wpeVariantStore.variationOptions = data.variation_options || [];
                    wpeVariantStore.variations = data.variations || [];
                    wpeVariantStore.taxonomies = data.taxonomies || [];
                    wpeVariantStore.isLoading = false;
                    wpeVariantStore.notify();
                }
            })
            .fail(function (err) {
                $('#wpe-product-editor-inline-app').html(`
                    <div class="notice notice-error inline" style="margin:20px;">
                        <p>Không thể tải dữ liệu biến thể: ${escapeHtml(err.statusText)}</p>
                    </div>
                `);
            });
    });

})(jQuery);
