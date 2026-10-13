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
    // Features tab
    // ------------------------------------------------------------------
    function initFeatures() {
        var $panel = $('#wpe-settings-form').closest('[data-initial]');
        var $form = $('#wpe-settings-form');
        var $savebar = $('#wpe-savebar');
        var $saveText = $('#wpe-savebar-text');
        var $saveBtn = $('#wpe-save-settings-btn');
        var $discardBtn = $('#wpe-discard-btn');
        var saved = {};

        try {
            saved = JSON.parse($panel.attr('data-initial') || '{}');
        } catch (e) {
            saved = {};
        }

        function $inputs() {
            return $form.find('input[data-flag]');
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

        function clearErrors() {
            $form.find('[data-error-for]').prop('hidden', true).text('');
            $inputs().removeAttr('aria-invalid');
        }

        function refresh() {
            var n = changedKeys().length;
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
                if ($err.length && $input.length) {
                    $err.text(message).prop('hidden', false);
                    $input.attr('aria-invalid', 'true');
                    if (!first) { first = $input; }
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

        $form.on('submit', function (e) {
            e.preventDefault();
            var keys = changedKeys();
            if (keys.length === 0) {
                return;
            }

            var payload = {};
            $.each(keys, function (_, key) {
                payload[key] = $form.find('input[data-flag="' + key + '"]').is(':checked');
            });

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
            clearErrors();
            hideNotice();
            refresh();
            announce(t('discarded', 'Changes discarded.'));
        });

        window.addEventListener('beforeunload', function (e) {
            if (changedKeys().length > 0) {
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

    if ($('#wpe-settings-form').length) {
        initFeatures();
    }
    if ($('#wpe-rebuild-gen-btn').length) {
        initMaintenance();
    }

})(jQuery);
