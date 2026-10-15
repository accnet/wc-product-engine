/**
 * WC Product Engine — Settings screen client.
 *
 * - Features tab: dirty tracking, discard, per-field errors, aria-live announcements,
 *   unsaved-changes guard. Only changed flags are sent. Authority is never sent.
 * - Maintenance tab: catalog index rebuild behind a native <dialog> confirmation.
 *
 * All server-provided text is inserted with .text(), never as HTML.
 */
(function ($) {
    'use strict';

    var $app = $('#wpe-settings-app');
    if (!$app.length || typeof window.wpeAdminConfig === 'undefined') {
        return;
    }

    var config = window.wpeAdminConfig;
    var i18n = config.i18n || {};
    var $live = $('#wpe-live');
    var $notice = $('#wpe-notice');

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function format(template, n) {
        return String(template).replace('%d', String(n));
    }

    function announce(message) {
        // Clear first so repeated identical messages are still announced.
        $live.text('');
        window.setTimeout(function () { $live.text(message); }, 30);
    }

    function showNotice(message, type) {
        $notice
            .removeClass('is-success is-warning is-error')
            .addClass('is-' + type)
            .text(message)
            .prop('hidden', false);
        announce(message);
    }

    function hideNotice() {
        $notice.prop('hidden', true).text('');
    }

    function request(path, method, body) {
        var opts = {
            url: config.restUrl + path,
            method: method,
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce);
            }
        };
        if (body !== undefined) {
            opts.contentType = 'application/json';
            opts.data = JSON.stringify(body);
        }
        return $.ajax(opts);
    }

    function responseMessage(xhr, fallback) {
        return (xhr && xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : fallback;
    }

    // ------------------------------------------------------------------
    // Features & Product Page tabs
    // ------------------------------------------------------------------
    function initFeatures() {
        var $panel = $('#wpe-settings-form').closest('[data-initial]');
        var $form = $('#wpe-settings-form');
        var $savebar = $('#wpe-savebar');
        var $saveText = $('#wpe-savebar-text');
        var $saveBtn = $('#wpe-save-settings-btn');
        var $discardBtn = $('#wpe-discard-btn');
        var saved = {};
        var savedAttrConfig = {};
        var hasAttrConfigPanel = $panel.is('[data-initial-attr-config]');

        try {
            saved = JSON.parse($panel.attr('data-initial') || '{}');
        } catch (e) {
            saved = {};
        }

        if (hasAttrConfigPanel) {
            try {
                savedAttrConfig = JSON.parse($panel.attr('data-initial-attr-config') || '{}');
            } catch (e2) {
                savedAttrConfig = {};
            }
        }

        function $inputs() {
            return $form.find('input[data-flag]');
        }

        function readCurrentAttrConfig() {
            var current = {};
            $form.find('[data-attr-config-row]').each(function () {
                var $row = $(this);
                var attrId = String($row.attr('data-attr-config-row') || '');
                if (!attrId) {
                    return;
                }
                var displayType = String($row.find('[data-attr-display-type]').val() || 'auto');
                var helpText = String($row.find('[data-attr-help-text]').val() || '').trim();
                var termColors = {};
                $row.find('[data-attr-term-color]').each(function () {
                    var tid = String($(this).attr('data-term-id') || '');
                    var hex = String($(this).val() || '').trim().toLowerCase();
                    if (tid && hex !== '') {
                        termColors[tid] = hex;
                    }
                });
                current[attrId] = {
                    display_type: displayType,
                    help_text: helpText,
                    term_colors: termColors
                };
            });
            return current;
        }

        function normalizeAttrEntry(entry) {
            var e = entry || {};
            var colors = {};
            if (e.term_colors && typeof e.term_colors === 'object') {
                var tids = Object.keys(e.term_colors).sort();
                for (var i = 0; i < tids.length; i++) {
                    var k = tids[i];
                    var val = String(e.term_colors[k] || '').trim().toLowerCase();
                    if (val !== '') {
                        colors[String(k)] = val;
                    }
                }
            }
            return JSON.stringify({
                display_type: String(e.display_type || 'auto'),
                help_text: String(e.help_text || '').trim(),
                term_colors: colors
            });
        }

        function changedAttrIds() {
            if (!hasAttrConfigPanel) {
                return [];
            }
            var current = readCurrentAttrConfig();
            var ids = [];
            $.each(current, function (attrId, entry) {
                if (normalizeAttrEntry(entry) !== normalizeAttrEntry(savedAttrConfig[attrId])) {
                    ids.push(attrId);
                }
            });
            return ids;
        }

        function changedKeys() {
            var keys = [];
            $inputs().each(function () {
                var key = $(this).data('flag');
                if ($(this).is(':checked') !== !!saved[key]) {
                    keys.push(key);
                }
            });
            return keys;
        }

        function totalChangesCount() {
            return changedKeys().length + changedAttrIds().length;
        }

        function clearErrors() {
            $form.find('[data-error-for]').prop('hidden', true).text('');
            $inputs().removeAttr('aria-invalid');
        }

        function refresh() {
            var n = totalChangesCount();
            var wasHidden = $savebar.prop('hidden');
            $savebar.prop('hidden', n === 0);
            if (n > 0) {
                $saveText.text(format(n === 1 ? t('unsavedOne', '%d unsaved change') : t('unsavedMany', '%d unsaved changes'), n));
                if (wasHidden) {
                    announce($saveText.text());
                }
            }
        }

        function showFieldErrors(errors) {
            var first = null;
            var unmatched = [];
            $.each(errors || {}, function (key, message) {
                var $err = $form.find('[data-error-for="' + key + '"]');
                var $input = $form.find('input[data-flag="' + key + '"]');
                if ($err.length) {
                    $err.text(message).prop('hidden', false);
                    if ($input.length) {
                        $input.attr('aria-invalid', 'true');
                        if (!first) { first = $input; }
                    }
                } else {
                    unmatched.push(message);
                }
            });
            if (first) { first.trigger('focus'); }
            return unmatched;
        }

        function applyServerState(res) {
            if (res && res.flags) {
                $.each(saved, function (key) {
                    if (Object.prototype.hasOwnProperty.call(res.flags, key)) {
                        saved[key] = !!res.flags[key];
                    }
                });
            }
            if (hasAttrConfigPanel && res && res.attribute_display_config) {
                savedAttrConfig = $.extend(true, {}, savedAttrConfig, res.attribute_display_config);
            }
            // Show "on but inactive" when stored on yet not effective at runtime.
            $form.find('[data-flag-row]').each(function () {
                var key = $(this).data('flag-row');
                var inactive = !!(res && res.flags && res.effective && res.flags[key] && res.effective[key] === false);
                $(this).find('[data-inactive-badge]').prop('hidden', !inactive);
            });
        }

        $form.on('change', 'input[data-flag]', function () {
            $(this).removeAttr('aria-invalid');
            $form.find('[data-error-for="' + $(this).data('flag') + '"]').prop('hidden', true).text('');
            refresh();
        });

        $form.on('change', '[data-attr-display-type]', function () {
            var attrId = String($(this).attr('data-attr-display-type') || '');
            var val = String($(this).val() || 'auto');
            var $colorsRow = $form.find('[data-attr-colors-row="' + attrId + '"]');
            if ($colorsRow.length) {
                $colorsRow.prop('hidden', val !== 'color');
            }
            $form.find('[data-error-for="attribute_display_config"]').prop('hidden', true).text('');
            refresh();
        });

        $form.on('input change', '[data-attr-help-text], [data-attr-term-color]', function () {
            if ($(this).is('[data-attr-term-color]')) {
                var hexId = String($(this).attr('data-color-hex-id') || '');
                var hexVal = String($(this).val() || '').trim();
                if (hexId && /^#[0-9a-fA-F]{6}$/.test(hexVal)) {
                    $form.find('[data-color-picker-for="' + hexId + '"]').val(hexVal.toLowerCase());
                }
            }
            $form.find('[data-error-for="attribute_display_config"]').prop('hidden', true).text('');
            refresh();
        });

        $form.on('input change', '[data-color-picker-for]', function () {
            var hexId = String($(this).attr('data-color-picker-for') || '');
            var val = String($(this).val() || '').toLowerCase();
            if (hexId && val) {
                $form.find('[data-color-hex-id="' + hexId + '"]').val(val).trigger('change');
            }
        });

        $form.on('submit', function (e) {
            e.preventDefault();
            var keys = changedKeys();
            var attrIds = changedAttrIds();
            if (keys.length === 0 && attrIds.length === 0) {
                return;
            }

            var payload = {};
            $.each(keys, function (_, key) {
                payload[key] = $form.find('input[data-flag="' + key + '"]').is(':checked');
            });

            if (attrIds.length > 0) {
                var currentAttr = readCurrentAttrConfig();
                payload.attribute_display_config = {};
                $.each(attrIds, function (_, id) {
                    payload.attribute_display_config[id] = currentAttr[id];
                });
            }

            hideNotice();
            clearErrors();
            $saveBtn.prop('disabled', true).text(t('saving', 'Saving…'));

            request('settings', 'POST', payload)
                .done(function (res) {
                    applyServerState(res);
                    refresh();
                    showNotice(res.message || t('saved', 'Settings saved.'), 'success');
                })
                .fail(function (xhr) {
                    var data = xhr.responseJSON || {};
                    var unmatched = showFieldErrors(data.errors);
                    var message = responseMessage(xhr, t('saveFailed', 'Settings could not be saved.'));
                    if (unmatched.length) {
                        message += ' ' + unmatched.join(' ');
                    }
                    showNotice(message, 'error');
                })
                .always(function () {
                    $saveBtn.prop('disabled', false).text(t('saveLabel', 'Save changes'));
                });
        });

        $discardBtn.on('click', function () {
            $inputs().each(function () {
                $(this).prop('checked', !!saved[$(this).data('flag')]);
            });
            if (hasAttrConfigPanel) {
                $form.find('[data-attr-config-row]').each(function () {
                    var $row = $(this);
                    var attrId = String($row.attr('data-attr-config-row') || '');
                    var orig = savedAttrConfig[attrId] || { display_type: 'auto', help_text: '', term_colors: {} };
                    var dtype = String(orig.display_type || 'auto');
                    $row.find('[data-attr-display-type]').val(dtype);
                    $row.find('[data-attr-help-text]').val(String(orig.help_text || ''));
                    var $colorsRow = $row.find('[data-attr-colors-row="' + attrId + '"]');
                    if ($colorsRow.length) {
                        $colorsRow.prop('hidden', dtype !== 'color');
                    }
                    var origColors = orig.term_colors || {};
                    $row.find('[data-attr-term-color]').each(function () {
                        var tid = String($(this).attr('data-term-id') || '');
                        var hex = String(origColors[tid] || '');
                        $(this).val(hex);
                        var hexId = String($(this).attr('data-color-hex-id') || '');
                        if (hexId) {
                            $row.find('[data-color-picker-for="' + hexId + '"]').val(/^#[0-9a-fA-F]{6}$/.test(hex) ? hex : '#000000');
                        }
                    });
                });
            }
            clearErrors();
            hideNotice();
            refresh();
            announce(t('discarded', 'Changes discarded.'));
        });

        window.addEventListener('beforeunload', function (e) {
            if (totalChangesCount() > 0) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });

        refresh();
    }

    // ------------------------------------------------------------------
    // Maintenance tab
    // ------------------------------------------------------------------
    function initMaintenance() {
        var $btn = $('#wpe-rebuild-gen-btn');
        var $status = $('#wpe-rebuild-status');
        var dialog = document.getElementById('wpe-confirm-dialog');

        function setStatus(kind, message) {
            $status
                .removeClass('is-success is-error is-busy')
                .addClass('is-' + kind)
                .text(message)
                .prop('hidden', false);
            announce(message);
        }

        function run() {
            $btn.prop('disabled', true).attr('aria-busy', 'true');
            setStatus('busy', t('rebuilding', 'Rebuilding catalog index…'));

            request('rebuild-index', 'POST')
                .done(function (res) {
                    if (res && res.status === 'success') {
                        $('#wpe-current-gen').text('#' + res.generation);
                        setStatus('success', res.message || t('rebuilt', 'Catalog index rebuilt.'));
                    } else {
                        setStatus('error', (res && res.message) || t('rebuildFailed', 'Rebuild failed.'));
                    }
                })
                .fail(function (xhr) {
                    setStatus('error', responseMessage(xhr, t('rebuildFailed', 'Rebuild failed.')));
                })
                .always(function () {
                    $btn.prop('disabled', false).removeAttr('aria-busy');
                });
        }

        $btn.on('click', function (e) {
            e.preventDefault();
            if (dialog && typeof dialog.showModal === 'function') {
                // Native modal: focus trap, Esc to close and focus restore come for free.
                dialog.returnValue = '';
                dialog.showModal();
                $('#wpe-confirm-cancel').trigger('focus');
            } else if (window.confirm(t('confirmRebuild', 'Rebuild the catalog index now?'))) {
                run();
            }
        });

        if (dialog) {
            dialog.addEventListener('close', function () {
                if (dialog.returnValue === 'confirm') {
                    run();
                }
            });
        }
    }

    // ------------------------------------------------------------------
    // Operations & Health tab
    // ------------------------------------------------------------------
    function initOperations() {
        var opsUrl = config.opsRestUrl || (config.restUrl ? config.restUrl.replace(/admin\/$/, 'operations/') : '');
        var $banner = $('#wpe-health-banner');
        var $icon = $('#wpe-health-icon');
        var $statusText = $('#wpe-status-text');
        var $result = $('#wpe-action-result');
        var $refreshBtn = $('#wpe-ops-refresh-btn');
        var $actionBtns = $('.wpe-action-btn');
        var $checkpointsBody = $('#wpe-ops-checkpoints-body');
        var rollbackDialog = document.getElementById('wpe-rollback-dialog');

        function opsRequest(path, method, body) {
            var opts = {
                url: opsUrl + path,
                method: method,
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                }
            };
            if (body !== undefined) {
                opts.contentType = 'application/json';
                opts.data = JSON.stringify(body);
            }
            return $.ajax(opts);
        }

        function renderCheckpoints(checkpoints) {
            $checkpointsBody.empty();
            if (!Array.isArray(checkpoints) || checkpoints.length === 0) {
                var $emptyTr = $('<tr>');
                $('<td colspan="5" class="wpe-help">').text(t('noCheckpoints', 'No recent background jobs recorded.')).appendTo($emptyTr);
                $checkpointsBody.append($emptyTr);
                return;
            }

            $.each(checkpoints, function (_, cp) {
                var $tr = $('<tr>');
                var cpStatus = String(cp.status || 'unknown');
                var badgeCls = 'wpe-badge';
                if (cpStatus === 'completed') {
                    badgeCls += ' is-ok';
                } else if (cpStatus === 'failed' || cpStatus === 'paused') {
                    badgeCls += ' is-warning';
                }

                $('<td>').append($('<code>').text(String(cp.id || ''))).appendTo($tr);
                $('<td>').text(String(cp.kind || '')).appendTo($tr);

                var $statusTd = $('<td>');
                $('<span>').addClass(badgeCls).text(cpStatus).appendTo($statusTd);
                if (cp.error) {
                    $('<small class="wpe-help">').text(' (' + String(cp.error) + ')').appendTo($statusTd);
                }
                $statusTd.appendTo($tr);

                $('<td>').text(String(cp.updated_at || '—')).appendTo($tr);

                var $actionTd = $('<td>');
                if (cp.can_resume && config.canManageOps !== false) {
                    $('<button type="button" class="wpe-btn is-secondary wpe-btn--sm wpe-resume-job-btn">')
                        .attr('data-job-id', String(cp.id || ''))
                        .text(t('resumeLabel', 'Resume'))
                        .appendTo($actionTd);
                } else {
                    $('<span class="wpe-help">').text('—').appendTo($actionTd);
                }
                $actionTd.appendTo($tr);
                $checkpointsBody.append($tr);
            });
        }

        function applyHealth(data) {
            if (!data) {
                return;
            }
            var st = String(data.status || 'unknown');
            $banner.removeClass('is-ok is-warning is-error');
            $icon.removeClass('dashicons-yes-alt dashicons-warning');
            if (st === 'healthy') {
                $banner.addClass('is-ok');
                $icon.addClass('dashicons-yes-alt');
            } else if (st === 'degraded') {
                $banner.addClass('is-warning');
                $icon.addClass('dashicons-warning');
            } else {
                $banner.addClass('is-error');
                $icon.addClass('dashicons-warning');
            }
            $statusText.text(st);

            if (data.system_mode !== undefined) {
                $('#wpe-ops-mode').text(String(data.system_mode));
            }
            if (data.authority_epoch !== undefined) {
                $('#wpe-ops-epoch').text(data.authority_epoch === null ? '—' : String(data.authority_epoch));
            }
            if (data.timestamp) {
                $('#wpe-ops-updated').text(String(data.timestamp));
            }

            var counts = data.counts || {};
            if (counts.native_products !== undefined) {
                $('#wpe-ops-native-products').text(String(counts.native_products));
            }
            if (counts.native_authority !== undefined) {
                $('#wpe-ops-native-auth').text('Authority: ' + String(counts.native_authority));
            }
            if (counts.legacy_products !== undefined) {
                $('#wpe-ops-legacy-products').text(String(counts.legacy_products));
            }
            if (counts.legacy_authority !== undefined) {
                $('#wpe-ops-legacy-auth').text('Authority: ' + String(counts.legacy_authority));
            }

            var mismatches = Number(data.mismatches || 0);
            $('#wpe-ops-mismatches')
                .removeClass('is-ok is-error')
                .addClass(mismatches > 0 ? 'is-error' : 'is-ok')
                .text(String(mismatches));

            var dlq = Number(data.dlq_count || 0);
            $('#wpe-ops-dlq')
                .removeClass('is-ok is-error')
                .addClass(dlq > 0 ? 'is-error' : 'is-ok')
                .text(String(dlq));

            var lag = (data.projection_lag && data.projection_lag.pending_projections !== undefined)
                ? Number(data.projection_lag.pending_projections)
                : 0;
            $('#wpe-ops-lag').text(String(lag));
            if (data.outbox_pending !== undefined) {
                $('#wpe-ops-outbox').text('Pending legacy syncs (Outbox: ' + String(data.outbox_pending) + ')');
            }

            var starv = data.worker_starvation || {};
            var $starvAlert = $('#wpe-starvation-alert');
            if (starv.alert) {
                $('#wpe-starvation-text').text(
                    'Active job ' + String(starv.starved_job_id || 'unknown') +
                    ' has recorded no heartbeat or checkpoint for over ' + String(starv.threshold_sec || 120) + 's.'
                );
                $starvAlert.prop('hidden', false);
            } else {
                $starvAlert.prop('hidden', true);
            }

            if (Array.isArray(data.checkpoints)) {
                renderCheckpoints(data.checkpoints);
            }
        }

        function refreshHealth() {
            return opsRequest('health', 'GET').done(applyHealth);
        }

        function formatActionSummary(res) {
            if (!res || typeof res !== 'object') {
                return 'Operation completed.';
            }
            if (res.action === 'audit') {
                return 'Shadow diff audit completed: checked ' + String(res.total_checked || 0) +
                    ' product(s), ' + String(res.mismatch_count || 0) + ' mismatch(es) found.';
            }
            if (res.action === 'rebuild') {
                return 'Catalog index rebuilt: active generation #' + String(res.generation || '') + '.';
            }
            if (res.action === 'migrate') {
                return 'Keyset backfill batch dispatched (Job ID: ' + String(res.job_id || '') + ').';
            }
            if (res.action === 'rollback') {
                return 'Authority rollback completed (status: ' + String(res.status || 'completed') + ').';
            }
            if (res.action === 'resume_job') {
                return 'Job ' + String(res.job_id || '') + ' resumed.';
            }
            return 'Action completed: ' + JSON.stringify(res);
        }

        function showResult(kind, text) {
            $result
                .removeClass('is-success is-warning is-error')
                .addClass(kind === 'ok' ? 'is-success' : (kind === 'error' ? 'is-error' : ''))
                .text(text)
                .prop('hidden', false);
            announce(text);
        }

        function triggerAction(action, params) {
            var busyMsg = String(t('opsRunning', 'Processing operation: %s…')).replace('%s', action);
            showResult('info', busyMsg);
            $actionBtns.prop('disabled', true);

            opsRequest('action', 'POST', { action: action, params: params || {} })
                .done(function (res) {
                    showResult('ok', formatActionSummary(res));
                    refreshHealth();
                })
                .fail(function (xhr) {
                    showResult('error', responseMessage(xhr, t('opsFailed', 'Operation failed.')));
                })
                .always(function () {
                    if (config.canManageOps !== false) {
                        $actionBtns.prop('disabled', false);
                    }
                });
        }

        $actionBtns.on('click', function (e) {
            e.preventDefault();
            var action = String($(this).data('action') || '');
            if (!action) {
                return;
            }
            if (action === 'rollback') {
                if (rollbackDialog && typeof rollbackDialog.showModal === 'function') {
                    rollbackDialog.returnValue = '';
                    rollbackDialog.showModal();
                    $('#wpe-rollback-cancel').trigger('focus');
                } else if (window.confirm(t('confirmRollback', 'WARNING: Are you sure you want to initiate an Authority Rollback back to Legacy CPT?'))) {
                    triggerAction('rollback', {});
                }
                return;
            }
            triggerAction(action, {});
        });

        if (rollbackDialog) {
            rollbackDialog.addEventListener('close', function () {
                if (rollbackDialog.returnValue === 'confirm') {
                    triggerAction('rollback', {});
                }
            });
        }

        $checkpointsBody.on('click', '.wpe-resume-job-btn', function (e) {
            e.preventDefault();
            var jobId = String($(this).attr('data-job-id') || '');
            if (jobId) {
                triggerAction('resume_job', { job_id: jobId });
            }
        });

        $refreshBtn.on('click', function (e) {
            e.preventDefault();
            $refreshBtn.prop('disabled', true);
            refreshHealth().always(function () {
                $refreshBtn.prop('disabled', false);
            });
        });

        refreshHealth();
        window.setInterval(refreshHealth, 15000);
    }

    if ($('#wpe-settings-form').length) {
        initFeatures();
    }
    if ($('#wpe-rebuild-gen-btn').length) {
        initMaintenance();
    }
    if ($('#wpe-operations-panel').length) {
        initOperations();
    }

})(jQuery);
