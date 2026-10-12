/**
 * WC Product Engine — Operations & Health Dashboard JavaScript.
 *
 * Implements:
 * - Real-time health counters polling.
 * - Action buttons dispatching to unified operations REST API.
 * - Confirmation barrier for authority rollbacks.
 * - Secure rendering with zero leakage of private secrets or local filesystem paths (AC-T41-02).
 */
(function ($) {
    'use strict';

    if (typeof window.wpeOpsConfig === 'undefined') {
        return;
    }

    var config = window.wpeOpsConfig;

    function refreshHealth() {
        $.ajax({
            url: config.restUrl + 'health',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce);
            },
            success: function (data) {
                if (!data) return;

                var banner = $('#wpe-health-banner');
                var statusText = $('#wpe-status-text');

                banner.removeClass('notice-success notice-error notice-warning');
                if (data.status === 'healthy') {
                    banner.addClass('notice-success');
                } else if (data.status === 'degraded') {
                    banner.addClass('notice-warning');
                } else {
                    banner.addClass('notice-error');
                }

                statusText.text(data.status);
            },
            error: function () {
                // Ignore transient polling errors
            }
        });
    }

    function triggerAction(action, params) {
        var resultDiv = $('#wpe-action-result');
        resultDiv.removeClass('notice-success notice-error').addClass('notice-info').show().text('Processing action: ' + action + '...');

        $.ajax({
            url: config.restUrl + 'action',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: action,
                params: params || {}
            }),
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', config.nonce);
            },
            success: function (res) {
                resultDiv.removeClass('notice-info notice-error').addClass('notice-success');
                resultDiv.text('Action completed: ' + JSON.stringify(res));
                refreshHealth();
            },
            error: function (xhr) {
                resultDiv.removeClass('notice-info notice-success').addClass('notice-error');
                var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Action failed.';
                resultDiv.text('Error: ' + msg);
            }
        });
    }

    $(document).ready(function () {
        $('.wpe-action-btn').on('click', function (e) {
            e.preventDefault();
            var action = $(this).data('action');

            if (action === 'rollback') {
                if (!confirm('WARNING: Are you sure you want to initiate an Authority Rollback back to Legacy CPT? Current native catalog data will be reconciled before switching.')) {
                    return;
                }
            }

            triggerAction(action, {});
        });

        // Initial poll and recurring health check every 15 seconds
        refreshHealth();
        setInterval(refreshHealth, 15000);
    });

})(jQuery);
