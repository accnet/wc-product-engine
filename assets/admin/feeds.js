/**
 * WC Product Engine — Product Feeds screen client (09 §11).
 *
 * - List: search, platform/status filters and sorting on the server-rendered data, enable switch,
 *   row actions (copy URL, publish now, full rebuild, duplicate, delete behind a <dialog>).
 * - Create/Edit: collects every [data-path] control into the FeedWrite body, saves through the
 *   admin REST API with expected_version, shows per-field errors, warns before render-affecting
 *   changes, status card actions, Preview and Diagnostics tabs, polling while a build runs.
 *
 * All server-provided text is inserted with .text(), never as HTML.
 */
(function ($) {
    'use strict';

    var $app = $('#wpe-feeds-app');
    if (!$app.length || typeof window.wpeFeedsConfig === 'undefined') {
        return;
    }

    var config = window.wpeFeedsConfig;
    var i18n = config.i18n || {};
    var $live = $('#wpe-live');
    var $notice = $('#wpe-notice');

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function announce(message) {
        $live.text('');
        window.setTimeout(function () { $live.text(message); }, 30);
    }

    function showNotice(message, type) {
        $notice.removeClass('is-success is-warning is-error').addClass('is-' + type).text(message).prop('hidden', false);
        announce(message);
    }

    function request(path, method, body) {
        var opts = {
            url: config.restUrl + path,
            method: method,
            dataType: 'json',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce); }
        };
        if (body !== undefined) {
            opts.contentType = 'application/json';
            opts.data = JSON.stringify(body);
        }
        return $.ajax(opts);
    }

    function errorMessage(xhr) {
        var data = xhr && xhr.responseJSON;
        return (data && data.message) ? String(data.message) : t('requestFailed', 'The request failed.');
    }

    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try { return JSON.parse(el.textContent || 'null'); } catch (e) { return null; }
    }

    function badgeClass(status) {
        return { ready: 'is-ok', error: 'is-error', building: 'is-warning', 'new': 'is-warning' }[status] || 'is-muted';
    }

    function statusLabel(status) {
        return (i18n.statuses && i18n.statuses[status]) || status;
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        var $tmp = $('<textarea>').val(text).appendTo(document.body).trigger('select');
        document.execCommand('copy');
        $tmp.remove();
        return $.Deferred().resolve().promise();
    }

    function number(n) {
        return Number(n || 0).toLocaleString();
    }

    /* ------------------------------------------------------------------ */
    /* List                                                               */
    /* ------------------------------------------------------------------ */

    var listData = readJson('wpe-feeds-data');
    if (listData) {
        var feeds = listData.feeds || [];
        var sortKey = 'name';
        var sortDir = 1;
        var $rows = $('#wpe-feeds-rows');
        var deleteId = null;

        var itemsOf = function (f) { return (f.stats && f.stats.published && f.stats.published.items) || 0; };
        var totalOf = function (f) { return (f.stats && f.stats.diagnostics && f.stats.diagnostics.total) || 0; };

        var renderList = function () {
            var q = String($('#wpe-feeds-search').val() || '').toLowerCase();
            var platform = String($('#wpe-feeds-platform').val() || '');
            var status = String($('#wpe-feeds-status').val() || '');
            var shown = feeds.filter(function (f) {
                return (!q || String(f.name).toLowerCase().indexOf(q) !== -1)
                    && (!platform || f.platform === platform)
                    && (!status || f.status === status);
            }).sort(function (a, b) {
                var va, vb;
                if (sortKey === 'items') { va = itemsOf(a); vb = itemsOf(b); }
                else if (sortKey === 'published') { va = a.last_published_at || ''; vb = b.last_published_at || ''; }
                else { va = String(a.name).toLowerCase(); vb = String(b.name).toLowerCase(); }
                return va < vb ? -sortDir : (va > vb ? sortDir : 0);
            });

            $rows.empty();
            shown.forEach(function (f) {
                var $tr = $('<tr>').attr('data-feed-id', f.id);
                var $name = $('<td>');
                $('<a>').attr('href', config.listUrl + '&feed=' + f.id).text(f.name).appendTo($name);
                $tr.append($name);
                $('<td>').text((f.platform === 'microsoft' ? 'Microsoft' : 'Google') + ' · ' + String(f.format).toUpperCase()).appendTo($tr);
                var $st = $('<td>');
                $('<span class="wpe-badge">').addClass(badgeClass(f.status)).text(statusLabel(f.status)).appendTo($st);
                if (f.rebuild_pending && f.status !== 'building') {
                    $('<span class="wpe-help">').text(' ' + t('queued', 'Rebuild queued')).appendTo($st);
                }
                $tr.append($st);
                $('<td>').text(number(itemsOf(f)) + (totalOf(f) ? ' / ' + number(totalOf(f)) : '')).appendTo($tr);
                $('<td>').text(f.last_published_at ? String(f.last_published_at).substr(0, 16) + ' UTC' : t('never', 'Never')).appendTo($tr);

                var $en = $('<td>');
                var $sw = $('<span class="wpe-switch">');
                $('<input type="checkbox" role="switch" class="wpe-feed-toggle">')
                    .prop('checked', !!f.enabled).prop('disabled', !config.canManage)
                    .attr('aria-label', f.name).appendTo($sw);
                $('<span class="wpe-switch__track" aria-hidden="true">').appendTo($sw);
                $en.append($sw).appendTo($tr);

                var $act = $('<td class="wpe-feeds-actions">');
                $('<a class="wpe-btn is-ghost wpe-btn--sm">').attr('href', config.listUrl + '&feed=' + f.id).text(t('edit', 'Edit')).appendTo($act);
                if (config.canManage) {
                    if (f.url) {
                        $('<button type="button" class="wpe-btn is-ghost wpe-btn--sm" data-action="copy">').text(t('copyUrl', 'Copy URL')).appendTo($act);
                    }
                    $('<button type="button" class="wpe-btn is-ghost wpe-btn--sm" data-action="publish">').text(t('publishNow', 'Publish now')).appendTo($act);
                    $('<button type="button" class="wpe-btn is-ghost wpe-btn--sm" data-action="rebuild">').text(t('rebuild', 'Full rebuild')).appendTo($act);
                    $('<button type="button" class="wpe-btn is-ghost wpe-btn--sm" data-action="duplicate">').text(t('duplicate', 'Duplicate')).appendTo($act);
                    $('<button type="button" class="wpe-btn is-ghost wpe-btn--sm wpe-feeds-delete" data-action="delete">').text(t('delete', 'Delete')).appendTo($act);
                }
                $tr.append($act);
                $rows.append($tr);
            });

            $('#wpe-feeds-empty').prop('hidden', feeds.length > 0);
            $('#wpe-feeds-nomatch').prop('hidden', feeds.length === 0 || shown.length > 0);
            $('#wpe-feeds-table, #wpe-feeds-toolbar').prop('hidden', feeds.length === 0);
            $('.wpe-sort').each(function () {
                var key = $(this).data('sort');
                $(this).closest('th').attr('aria-sort', key === sortKey ? (sortDir === 1 ? 'ascending' : 'descending') : 'none');
            });
        };

        var feedById = function (id) {
            return feeds.filter(function (f) { return String(f.id) === String(id); })[0];
        };
        var replaceFeed = function (view) {
            feeds = feeds.map(function (f) { return String(f.id) === String(view.id) ? view : f; });
            renderList();
        };

        // Search re-renders on input only: a 'change' on blur would rebuild the rows between
        // mousedown and mouseup and swallow the click on a row action.
        $('#wpe-feeds-search').on('input', renderList);
        $('#wpe-feeds-platform, #wpe-feeds-status').on('change', renderList);
        $('.wpe-sort').on('click', function () {
            var key = $(this).data('sort');
            sortDir = key === sortKey ? -sortDir : 1;
            sortKey = key;
            renderList();
        });

        $rows.on('change', '.wpe-feed-toggle', function () {
            var $cb = $(this);
            var f = feedById($cb.closest('tr').data('feed-id'));
            $cb.prop('disabled', true);
            request('/' + f.id, 'PATCH', { expected_version: f.version, enabled: $cb.is(':checked') })
                .done(function (view) { replaceFeed(view); announce(view.name + ': ' + statusLabel(view.status)); })
                .fail(function (xhr) { $cb.prop('checked', !$cb.is(':checked')); showNotice(errorMessage(xhr), 'error'); })
                .always(function () { $cb.prop('disabled', false); });
        });

        $rows.on('click', '[data-action]', function () {
            var $btn = $(this);
            var action = $btn.data('action');
            var f = feedById($btn.closest('tr').data('feed-id'));
            if (!f) {
                return;
            }
            if (action === 'copy') {
                copyText(f.url).then(function () { showNotice(t('copied', 'Feed URL copied.'), 'success'); });
                return;
            }
            if (action === 'delete') {
                deleteId = f.id;
                $('#wpe-feeds-delete-name').text(f.name);
                document.getElementById('wpe-feeds-delete-dialog').showModal();
                return;
            }
            $btn.prop('disabled', true);
            var call = action === 'duplicate'
                ? request('/' + f.id + '/duplicate', 'POST', {})
                : request('/' + f.id + '/regenerate', 'POST', { mode: action });
            showNotice(action === 'publish' ? t('publishing', 'Publishing…') : (action === 'rebuild' ? t('rebuilding', 'Rebuilding…') : '…'), 'warning');
            call.done(function (res) {
                if (action === 'duplicate') {
                    feeds.push(res);
                    renderList();
                    showNotice(t('duplicated', 'Feed duplicated (disabled).'), 'success');
                    return;
                }
                if (res.feed) { replaceFeed(res.feed); }
                showNotice(res.status === 'queued' ? t('queued', 'Queued') : (action === 'publish' ? t('published', 'Published') : t('rebuilt', 'Rebuilt')), 'success');
            }).fail(function (xhr) {
                if (xhr.responseJSON && xhr.responseJSON.feed) { replaceFeed(xhr.responseJSON.feed); }
                showNotice(errorMessage(xhr), 'error');
            }).always(function () { $btn.prop('disabled', false); });
        });

        $('#wpe-feeds-delete-dialog [data-dialog-cancel]').on('click', function () {
            document.getElementById('wpe-feeds-delete-dialog').close();
        });
        $('#wpe-feeds-delete-confirm').on('click', function () {
            var id = deleteId;
            document.getElementById('wpe-feeds-delete-dialog').close();
            request('/' + id, 'DELETE').done(function () {
                feeds = feeds.filter(function (f) { return String(f.id) !== String(id); });
                renderList();
                showNotice(t('deleted', 'Feed deleted.'), 'success');
            }).fail(function (xhr) { showNotice(errorMessage(xhr), 'error'); });
        });

        renderList();
    }

    /* ------------------------------------------------------------------ */
    /* Create / Edit                                                      */
    /* ------------------------------------------------------------------ */

    var $form = $('#wpe-feed-form');
    var pageData = readJson('wpe-feed-data');
    if (!$form.length || !pageData) {
        return;
    }

    var feedId = String($form.data('feed-id') || '');
    var version = parseInt($form.data('version'), 10) || null;
    var renderPaths = pageData.renderPaths || [];

    function setPath(obj, path, value) {
        var parts = path.split('.');
        var cur = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            cur[parts[i]] = cur[parts[i]] || {};
            cur = cur[parts[i]];
        }
        cur[parts[parts.length - 1]] = value;
    }

    function collect() {
        var body = { config: {} };
        $form.find('[data-path]').each(function () {
            var $el = $(this);
            var path = String($el.data('path'));
            var value;
            if ($el.is(':checkbox')) {
                value = $el.is(':checked');
            } else if ($el.data('type') === 'ids') {
                value = ($el.val() || []).map(function (v) { return parseInt(v, 10); });
            } else if ($el.attr('type') === 'number') {
                value = parseInt($el.val(), 10);
            } else {
                value = String($el.val() || '');
            }
            setPath(body, path, value);
        });
        var map = {};
        $form.find('[data-category-map-row]').each(function () {
            var term = $(this).find('[data-category-map-term]').val();
            var val = String($(this).find('[data-category-map-value]').val() || '').trim();
            if (term && val) { map[term] = val; }
        });
        body.config.category_map = map;
        return body;
    }

    function renderSignature(body) {
        var sig = {};
        renderPaths.forEach(function (p) {
            var parts = p.split('.');
            var cur = body;
            parts.forEach(function (k) { cur = cur ? cur[k] : undefined; });
            sig[p] = cur;
        });
        return JSON.stringify(sig);
    }

    var initialSignature = renderSignature(collect());

    function clearErrors() {
        $form.find('.wpe-field-error').prop('hidden', true).text('');
        $form.find('[aria-invalid]').removeAttr('aria-invalid');
    }

    function showErrors(details) {
        (details || []).forEach(function (d) {
            var pointer = String(d.pointer || '').replace(/^\//, '').replace(/\//g, '.');
            var path = pointer.replace(/\.(source|value)$/, '');
            var $err = $form.find('[data-error-for="' + pointer + '"]');
            if (!$err.length) { $err = $form.find('[data-error-for="' + path + '"]'); }
            $err.text(String(d.reason || '')).prop('hidden', false);
            $form.find('[data-path="' + pointer + '"]').attr('aria-invalid', 'true');
        });
        var $first = $form.find('[aria-invalid="true"]').first();
        if ($first.length) { $first.trigger('focus'); }
    }

    function syncConditional() {
        var platform = String($('#wpe-f-platform').val());
        var $format = $('#wpe-f-format');
        // Both platforms accept XML (Google format) and TSV; Microsoft prefers TSV.
        $format.find('option').prop('disabled', false);
        if (platform === 'microsoft' && !feedId && $format.data('touched') !== true) {
            $format.val('tsv');
        }
        $('#wpe-flat-shipping').prop('hidden', $('#wpe-f-ship-mode').val() !== 'flat');
        var country = String($('#wpe-f-country').val() || '').toUpperCase();
        var exclusive = (pageData.taxExclusiveMarkets || []).indexOf(country) !== -1;
        $('#wpe-tax-warning').prop('hidden', exclusive || !!pageData.storeTaxInclusive || country.length !== 2);
        if (feedId) {
            $('#wpe-feed-rebuild-hint').prop('hidden', renderSignature(collect()) === initialSignature);
        }
    }

    $('#wpe-f-format').on('change', function () { $(this).data('touched', true); });
    $form.on('input change', '[data-path], [data-category-map-term], [data-category-map-value]', syncConditional);

    $('#wpe-category-map-add').on('click', function () {
        var tpl = document.getElementById('wpe-category-map-template');
        $('#wpe-category-map-rows').append($(tpl.innerHTML));
        $('#wpe-category-map-rows [data-category-map-term]').last().trigger('focus');
    });
    $form.on('click', '[data-category-map-remove]', function () {
        $(this).closest('[data-category-map-row]').remove();
        syncConditional();
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        clearErrors();
        var body = collect();
        var $save = $('#wpe-feed-save').prop('disabled', true);
        showNotice(t('saving', 'Saving…'), 'warning');
        var call;
        if (feedId) {
            body.expected_version = version;
            call = request('/' + feedId, 'PATCH', body);
        } else {
            call = request('', 'POST', body);
        }
        call.done(function (view) {
            if (!feedId) {
                window.location.href = config.listUrl + '&feed=' + view.id + '&created=1';
                return;
            }
            version = view.version;
            initialSignature = renderSignature(collect());
            syncConditional();
            showNotice(view.rebuild_required ? t('queued', 'Rebuild queued') : t('saved', 'Feed saved.'), 'success');
            refreshStatus(view);
        }).fail(function (xhr) {
            var data = xhr.responseJSON || {};
            showErrors(data.details);
            showNotice(data.code === 'validation_failed' ? t('saveFailed', 'Check the highlighted fields.') : errorMessage(xhr), 'error');
        }).always(function () { $save.prop('disabled', false); });
    });

    if (!feedId) {
        syncConditional();
        return;
    }

    /* Status card, tabs, preview, diagnostics ---------------------------- */


    function refreshStatus(view) {
        if (!view) { return; }
        var $badge = $('#wpe-feed-status [data-status-badge]');
        $badge.removeClass('is-ok is-error is-warning is-muted').addClass(badgeClass(view.status)).text(statusLabel(view.status));
        var pub = view.stats && view.stats.published;
        if (pub) {
            $('#wpe-feed-last-file').text(number(pub.items) + ' ' + t('items', 'items') + ' · ' + (Math.round((pub.bytes || 0) / 104857.6) / 10) + ' MB · ' + pub.at + ' UTC');
        }
        var build = view.stats && view.stats.build;
        $('#wpe-feed-progress').text(build ? (t('building', 'Building') + ': ' + number(build.done) + ' / ' + number(build.total)) : (view.rebuild_pending ? t('starting', 'Starting build…') : t('upToDate', 'Up to date')));
        if (view.url) { $('#wpe-feed-url').val(view.url); }
        if ((view.building || view.rebuild_pending) && config.canManage) { driveBuild(); }
    }

    /*
     * While this screen is open, a requested or running build advances in time-boxed `continue`
     * steps, so a new or re-configured feed builds and publishes right away instead of waiting for
     * the worker. A step held by the worker (409) is retried; `continue` never starts a new build.
     */
    var driving = false;
    function driveBuild() {
        if (driving) { return; }
        driving = true;
        var step = function () {
            request('/' + feedId + '/regenerate', 'POST', { mode: 'continue' }).done(function (res, _s, xhr) {
                if (res.feed) { version = res.feed.version; }
                if (xhr.status === 202) {
                    if (res.feed) { refreshStatus(res.feed); }
                    window.setTimeout(step, 300);
                    return;
                }
                driving = false;
                if (res.feed) { refreshStatus(res.feed); }
                if (res.status === 'completed') {
                    var pub = res.result && res.result.published;
                    if (pub && pub.status !== 'published') {
                        showNotice((res.feed && res.feed.last_error) || String(pub.reason || t('requestFailed', 'The request failed.')), 'error');
                    } else {
                        showNotice(t('rebuilt', 'Rebuilt'), 'success');
                    }
                }
            }).fail(function (xhr) {
                if (xhr.status === 409) { window.setTimeout(step, 5000); return; }
                driving = false;
                showNotice(errorMessage(xhr), 'error');
            });
        };
        step();
    }

    refreshStatus(pageData.feed);
    if (/[?&]created=1/.test(window.location.search)) {
        showNotice(t('created', 'Feed created.'), 'success');
    }

    $('[data-copy]').on('click', function () {
        copyText(String($($(this).data('copy')).val())).then(function () { showNotice(t('copied', 'Feed URL copied.'), 'success'); });
    });

    $('#wpe-feed-rotate').on('click', function () {
        if (!window.confirm(t('confirmRotate', 'Create a new URL?'))) { return; }
        request('/' + feedId + '/rotate-token', 'POST', {}).done(function (view) {
            version = view.version;
            refreshStatus(view);
            showNotice(t('rotated', 'New URL created.'), 'success');
        }).fail(function (xhr) { showNotice(errorMessage(xhr), 'error'); });
    });

    $('[data-regenerate]').on('click', function () {
        var $btn = $(this);
        var mode = $btn.data('regenerate');
        $('[data-regenerate]').prop('disabled', true);
        showNotice(mode === 'publish' ? t('publishing', 'Publishing…') : t('rebuilding', 'Rebuilding…'), 'warning');
        request('/' + feedId + '/regenerate', 'POST', { mode: mode }).done(function (res) {
            refreshStatus(res.feed);
            showNotice(res.status === 'queued' ? t('queued', 'Queued') : (mode === 'publish' ? t('published', 'Published') : t('rebuilt', 'Rebuilt')), 'success');
        }).fail(function (xhr) {
            if (xhr.responseJSON && xhr.responseJSON.feed) { refreshStatus(xhr.responseJSON.feed); }
            showNotice(errorMessage(xhr), 'error');
        }).always(function () { $('[data-regenerate]').prop('disabled', false); });
    });

    var loaded = {};
    $('[data-feed-tab]').on('click', function (e) {
        e.preventDefault();
        var tab = $(this).data('feed-tab');
        $('[data-feed-tab]').removeClass('is-active').removeAttr('aria-current');
        $(this).addClass('is-active').attr('aria-current', 'page');
        $('[data-feed-panel]').prop('hidden', true);
        $('[data-feed-panel="' + tab + '"]').prop('hidden', false);
        if (tab === 'preview' && !loaded.preview) { loadPreview(); }
        if (tab === 'diagnostics' && !loaded.diagnostics) { loadDiagnostics(); }
    });

    function headRow(labels) {
        var $tr = $('<tr>');
        labels.forEach(function (l) { $('<th scope="col">').text(l).appendTo($tr); });
        return $('<thead>').append($tr);
    }

    function issueList(issues) {
        var $ul = $('<ul class="wpe-issue-list">');
        (issues || []).forEach(function (i) {
            $('<li>').addClass('is-' + i.severity).text(i.code).appendTo($ul);
        });
        return $ul;
    }

    function loadPreview() {
        loaded.preview = true;
        var $body = $('#wpe-preview-body');
        request('/' + feedId + '/preview', 'GET').done(function (res) {
            $body.empty();
            if (!res.items || !res.items.length) {
                $('<p class="wpe-help">').text(t('noItems', 'No items yet.')).appendTo($body);
                return;
            }
            if (res.source === 'live') {
                $('<p class="wpe-help">').text(t('live', 'Rendered live.')).appendTo($body);
            }
            var $wrap = $('<div class="wpe-feeds-table-wrap">').appendTo($body);
            var $table = $('<table class="wpe-feeds-table">').appendTo($wrap);
            headRow(['ID', t('colTitle', 'Title'), t('colPrice', 'Price'), t('colAvailability', 'Availability'), t('colIssues', 'Issues')]).appendTo($table);
            var $tb = $('<tbody>').appendTo($table);
            res.items.forEach(function (it) {
                var p = it.payload || {};
                var $tr = $('<tr>').toggleClass('is-ineligible', !it.eligible);
                var $id = $('<td>');
                if (it.edit_url) { $('<a>').attr('href', it.edit_url).text(it.item_id).appendTo($id); } else { $id.text(it.item_id); }
                $tr.append($id);
                $('<td>').text(p.title || '').appendTo($tr);
                $('<td>').text((p.sale_price ? p.sale_price + ' / ' : '') + (p.price || '')).appendTo($tr);
                $('<td>').text(p.availability || '').appendTo($tr);
                $('<td>').append(issueList(it.issues)).appendTo($tr);
                $tb.append($tr);
            });
            $('<pre class="wpe-feed-source">').text(res.rendered || '').appendTo($body);
        }).fail(function (xhr) { loaded.preview = false; $body.empty().append($('<p class="wpe-field-error">').text(errorMessage(xhr))); });
    }

    function loadDiagnostics() {
        loaded.diagnostics = true;
        var $body = $('#wpe-diagnostics-body');
        request('/' + feedId + '/diagnostics', 'GET').done(function (res) {
            $body.empty();
            (res.warnings || []).forEach(function (w) {
                $('<div class="wpe-notice is-warning">').text(w.message).appendTo($body);
            });
            var totals = res.totals || {};
            $('<p>').text(number(totals.eligible) + ' / ' + number(totals.items) + ' ' + t('itemsExported', 'items exported') + (totals.at ? ' · ' + totals.at + ' UTC' : '') + ' · ' + number(res.dirty) + ' ' + t('productsWaiting', 'products waiting')).appendTo($body);

            var codes = Object.keys(res.issues || {});
            if (!codes.length) {
                $('<p class="wpe-help">').text(t('noIssues', 'No issues.')).appendTo($body);
            } else {
                var $wrap = $('<div class="wpe-feeds-table-wrap">').appendTo($body);
                var $table = $('<table class="wpe-feeds-table">').appendTo($wrap);
                headRow([t('colIssue', 'Issue'), t('colSeverity', 'Severity'), t('colItems', 'Items'), t('colExamples', 'Examples')]).appendTo($table);
                var $tb = $('<tbody>').appendTo($table);
                codes.forEach(function (code) {
                    var row = res.issues[code];
                    var $tr = $('<tr>');
                    $('<td>').append($('<code>').text(code)).appendTo($tr);
                    $('<td>').append($('<span class="wpe-badge">').addClass(row.severity === 'error' ? 'is-error' : (row.severity === 'warning' ? 'is-warning' : 'is-muted')).text(row.severity)).appendTo($tr);
                    $('<td>').text(number(row.count)).appendTo($tr);
                    var $ex = $('<td>');
                    (row.samples || []).forEach(function (s, i) {
                        if (i) { $ex.append(', '); }
                        if (s.edit_url) { $('<a>').attr('href', s.edit_url).text(s.item_id).appendTo($ex); } else { $ex.append(document.createTextNode(s.item_id)); }
                    });
                    $tr.append($ex);
                    $tb.append($tr);
                });
            }

            $('<h4>').text(t('runs', 'Runs')).appendTo($body);
            if (!res.runs || !res.runs.length) {
                $('<p class="wpe-help">').text(t('noRuns', 'No runs.')).appendTo($body);
            } else {
                var $rw = $('<div class="wpe-feeds-table-wrap">').appendTo($body);
                var $rt = $('<table class="wpe-feeds-table">').appendTo($rw);
                headRow([t('colRun', 'Run'), t('colStatus', 'Status'), t('colItems', 'Items'), t('colStarted', 'Started'), t('colFinished', 'Finished')]).appendTo($rt);
                var $rb = $('<tbody>').appendTo($rt);
                res.runs.forEach(function (r) {
                    var c = r.counts || {};
                    var $tr = $('<tr>');
                    $('<td>').text(r.kind).appendTo($tr);
                    $('<td>').append($('<span class="wpe-badge">').addClass(r.status === 'completed' ? 'is-ok' : (r.status === 'failed' ? 'is-error' : 'is-warning')).text(r.status + (r.error_code ? ' · ' + r.error_code : ''))).appendTo($tr);
                    $('<td>').text(c.items !== undefined ? number(c.items) : (c.done !== undefined ? number(c.done) + ' / ' + number(c.total) : '')).appendTo($tr);
                    $('<td>').text(String(r.created_at || '').substr(0, 19)).appendTo($tr);
                    $('<td>').text(String(r.updated_at || '').substr(0, 19)).appendTo($tr);
                    $rb.append($tr);
                });
            }
            var w = res.worker || {};
            $('<p class="wpe-help">').text(t('worker', 'Worker') + ': ' + (w.at ? w.at + ' UTC (' + w.age + 's)' : t('never', 'Never'))).appendTo($body);
        }).fail(function (xhr) { loaded.diagnostics = false; $body.empty().append($('<p class="wpe-field-error">').text(errorMessage(xhr))); });
    }

    syncConditional();
})(jQuery);
