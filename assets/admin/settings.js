/**
 * WC Product Engine — Settings Page JavaScript.
 */
(function ($) {
    'use strict';

    if (typeof window.wpeAdminConfig === 'undefined') {
        return;
    }

    var config = window.wpeAdminConfig;

    $(document).ready(function () {
        var form = $('#wpe-settings-form');
        var notice = $('#wpe-settings-notice');
        var saveBtn = $('#wpe-save-settings-btn');
        var spinner = $('#wpe-save-spinner');
        var rebuildBtn = $('#wpe-rebuild-gen-btn');
        var rebuildStatus = $('#wpe-rebuild-status');

        function showNotice(msg, type) {
            notice.removeClass('notice-success notice-error notice-warning')
                  .addClass('notice-' + type)
                  .html('<p>' + msg + '</p>')
                  .show();
            $('html, body').animate({ scrollTop: notice.offset().top - 40 }, 200);
        }

        // Form Submit
        form.on('submit', function (e) {
            e.preventDefault();
            saveBtn.prop('disabled', true);
            spinner.addClass('is-active');

            var payload = {
                cohort_mode: $('#cohort_mode').val(),
                query_enabled: $('input[name="query_enabled"]').is(':checked'),
                media_enabled: $('input[name="media_enabled"]').is(':checked'),
                resolver_enabled: $('input[name="resolver_enabled"]').is(':checked'),
                native_writes_enabled: $('input[name="native_writes_enabled"]').is(':checked')
            };

            $.ajax({
                url: config.restUrl + 'settings',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                },
                success: function (res) {
                    saveBtn.prop('disabled', false);
                    spinner.removeClass('is-active');
                    showNotice(res.message || 'Settings saved successfully.', 'success');
                },
                error: function (xhr) {
                    saveBtn.prop('disabled', false);
                    spinner.removeClass('is-active');
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error saving settings.';
                    showNotice(msg, 'error');
                }
            });
        });

        // Rebuild Index Generation
        rebuildBtn.on('click', function (e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to rebuild the entire Catalog Index Generation?')) {
                return;
            }

            rebuildBtn.prop('disabled', true);
            rebuildStatus.html('<span class="spinner is-active" style="float:none;"></span> Rebuilding catalog generation...');

            $.ajax({
                url: config.restUrl + 'rebuild-index',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                },
                success: function (res) {
                    rebuildBtn.prop('disabled', false);
                    if (res.status === 'success') {
                        $('#wpe-current-gen').text('#' + res.generation);
                        rebuildStatus.html('<span style="color:#008a20; font-weight:bold;">✓ ' + res.message + '</span>');
                    } else {
                        rebuildStatus.html('<span style="color:#d63638;">✕ ' + (res.message || 'Unknown error') + '</span>');
                    }
                },
                error: function (xhr) {
                    rebuildBtn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Rebuild failed.';
                    rebuildStatus.html('<span style="color:#d63638;">✕ Error: ' + msg + '</span>');
                }
            });
        });
    });

})(jQuery);
