/**
 * WC Product Engine — Shopify CSV Batch Import JavaScript.
 *
 * Implements:
 * - Drag & drop and server path file staging.
 * - Chunked asynchronous batch loop ($O(1)$ memory, no timeouts).
 * - Pause & Resume state machine with byte-offset checkpoints.
 * - Real-time progress metrics (speed, elapsed time, counts).
 * - Live terminal log console with auto-scrolling.
 * - Persistent log file download.
 */
(function ($) {
    'use strict';

    if (typeof window.wpeImportConfig === 'undefined') {
        return;
    }

    var config = window.wpeImportConfig;

    var state = {
        jobId: null,
        stagedFileId: null,
        filePath: null,
        fileName: null,
        isProcessing: false,
        isPaused: false,
        isCancelled: false,
        isCompleted: false,
        batchSize: 25,
        startTime: null,
        timerInterval: null,
        totalProducts: 0,
        totalVariants: 0,
        totalErrors: 0
    };

    $(document).ready(function () {
        var dropzone = $('#wpe-dropzone');
        var fileInput = $('#wpe-csv-file-input');
        var serverPathInput = $('#wpe-server-path-input');
        var checkServerBtn = $('#wpe-use-server-file-btn');
        var selectedInfo = $('#wpe-selected-file-info');
        var selectedFilename = $('#wpe-selected-filename');
        var selectedMeta = $('#wpe-selected-meta');
        var startBtn = $('#wpe-start-import-btn');

        var setupSection = $('#wpe-import-setup-section');
        var progressSection = $('#wpe-import-progress-section');
        var statusDesc = $('#wpe-job-status-desc');
        var progressSpinner = $('#wpe-progress-spinner');
        var progressBarFill = $('#wpe-progress-bar-fill');
        var percentText = $('#wpe-percent-text');

        var metricProducts = $('#wpe-metric-products');
        var metricVariants = $('#wpe-metric-variants');
        var metricSpeed = $('#wpe-metric-speed');
        var metricTime = $('#wpe-metric-time');
        var metricErrors = $('#wpe-metric-errors');

        var pauseBtn = $('#wpe-pause-btn');
        var resumeBtn = $('#wpe-resume-btn');
        var cancelBtn = $('#wpe-cancel-btn');
        var downloadLogBtn = $('#wpe-download-log-btn');

        var terminalBody = $('#wpe-terminal-body');
        var autoScrollCb = $('#wpe-terminal-autoscroll');
        var clearConsoleBtn = $('#wpe-clear-console-btn');

        var resumeActiveJobBtn = $('#wpe-resume-active-job-btn');
        var discardActiveJobBtn = $('#wpe-discard-active-job-btn');

        // Check if there is an active job from page load
        if (resumeActiveJobBtn.length && resumeActiveJobBtn.data('job-id')) {
            var pendingJobId = resumeActiveJobBtn.data('job-id');
            resumeActiveJobBtn.on('click', function () {
                $('#wpe-active-job-banner').slideUp();
                state.jobId = pendingJobId;
                setupSection.slideUp();
                progressSection.slideDown();
                resumeImport();
            });

            discardActiveJobBtn.on('click', function () {
                if (confirm('Are you sure you want to discard this previous job and start a new import?')) {
                    $.ajax({
                        url: config.restUrl + 'shopify-import/cancel',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ job_id: pendingJobId }),
                        beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce); },
                        success: function () {
                            $('#wpe-active-job-banner').slideUp();
                            setupSection.slideDown();
                        }
                    });
                }
            });
        }

        // Dropzone interactions
        dropzone.on('click', function () {
            fileInput.trigger('click');
        });

        dropzone.on('dragover dragenter', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.addClass('dragover');
        });

        dropzone.on('dragleave dragend drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.removeClass('dragover');
        });

        dropzone.on('drop', function (e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFileUpload(files[0]);
            }
        });

        fileInput.on('change', function () {
            if (this.files.length > 0) {
                handleFileUpload(this.files[0]);
            }
        });

        // Server path check button
        checkServerBtn.on('click', function () {
            var path = $.trim(serverPathInput.val());
            if (!path) {
                alert('Please enter a valid file path on the server.');
                return;
            }

            state.filePath = path;
            state.stagedFileId = null;
            state.fileName = path.split('/').pop().split('\\').pop();

            selectedFilename.text('Server File: ' + state.fileName);
            selectedMeta.text('Path: ' + path);
            selectedInfo.slideDown();
            startBtn.prop('disabled', false);
        });

        // Auto-check default server path if filled
        if (serverPathInput.val()) {
            checkServerBtn.trigger('click');
        }

        function handleFileUpload(file) {
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert('Please select a valid .csv file.');
                return;
            }

            selectedFilename.text('Uploading: ' + file.name + ' (' + formatBytes(file.size) + ')...');
            selectedMeta.text('Please wait...');
            selectedInfo.slideDown();
            startBtn.prop('disabled', true);

            var formData = new FormData();
            formData.append('file', file);

            $.ajax({
                url: config.restUrl + 'shopify-import/upload',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                },
                success: function (res) {
                    if (res.status === 'success') {
                        state.stagedFileId = res.staged_file_id;
                        state.filePath = res.file_path;
                        state.fileName = res.original_filename;

                        selectedFilename.text('Uploaded: ' + res.original_filename);
                        selectedMeta.text('Size: ' + res.file_size_fmt + ' — Staged securely in temp directory');
                        startBtn.prop('disabled', false);
                    } else {
                        selectedFilename.text('Upload error: ' + res.message);
                    }
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Unknown error uploading file.';
                    selectedFilename.text('Upload error: ' + msg);
                }
            });
        }

        // Start Import Button Click
        startBtn.on('click', function (e) {
            e.preventDefault();
            if (!state.filePath && !state.stagedFileId) {
                alert('Please select or upload a CSV file first.');
                return;
            }

            startBtn.prop('disabled', true);
            var updateMode = $('#wpe-opt-update-mode').val();
            var imageMode = $('#wpe-opt-image-mode').val();
            state.batchSize = parseInt($('#wpe-opt-batch-size').val(), 10) || 25;

            var initPayload = {
                file_path: state.filePath,
                staged_file_id: state.stagedFileId,
                original_filename: state.fileName,
                update_mode: updateMode,
                image_mode: imageMode,
                batch_size: state.batchSize
            };

            $.ajax({
                url: config.restUrl + 'shopify-import/init',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(initPayload),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                },
                success: function (res) {
                    if (res.status === 'success') {
                        state.jobId = res.job.job_id;
                        setupSection.slideUp();
                        progressSection.slideDown();

                        if (res.job.initial_log) {
                            terminalBody.html(escapeHtml(res.job.initial_log));
                        }

                        startProcessingLoop();
                    } else {
                        startBtn.prop('disabled', false);
                        alert('Import initialization failed: ' + res.message);
                    }
                },
                error: function (xhr) {
                    startBtn.prop('disabled', false);
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Could not initialize import.';
                    alert('Error: ' + msg);
                }
            });
        });

        function startProcessingLoop() {
            state.isProcessing = true;
            state.isPaused = false;
            state.isCancelled = false;
            state.isCompleted = false;
            state.startTime = Date.now();

            progressSpinner.addClass('is-active');
            progressBarFill.addClass('wpe-progress-pulsing');
            statusDesc.text('Processing batch chunks (' + state.batchSize + ' products/batch)...');

            pauseBtn.show();
            resumeBtn.hide();

            startTimer();
            processNextBatch();
        }

        function processNextBatch() {
            if (!state.isProcessing || state.isPaused || state.isCancelled || state.isCompleted) {
                return;
            }

            $.ajax({
                url: config.restUrl + 'shopify-import/process-batch',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    job_id: state.jobId,
                    batch_size: state.batchSize
                }),
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', config.nonce);
                },
                success: function (res) {
                    handleBatchResponse(res);
                },
                error: function (xhr) {
                    appendLog('error', '[SYSTEM] HTTP connection error calling batch: ' + (xhr.responseJSON ? xhr.responseJSON.message : xhr.statusText));
                    // If network glitch, retry after 3 seconds instead of failing completely
                    if (!state.isPaused && !state.isCancelled) {
                        appendLog('warning', '[SYSTEM] Retrying next batch in 3 seconds...');
                        setTimeout(processNextBatch, 3000);
                    }
                }
            });
        }

        function handleBatchResponse(res) {
            if (res.logs && res.logs.length > 0) {
                for (var i = 0; i < res.logs.length; i++) {
                    appendLog(res.logs[i].level, res.logs[i].message);
                }
            }

            // Update Metrics
            state.totalProducts = res.products_processed || 0;
            state.totalVariants = res.variants_processed || 0;
            state.totalErrors = res.errors_count || 0;

            metricProducts.text(state.totalProducts.toLocaleString());
            metricVariants.text(state.totalVariants.toLocaleString());
            metricErrors.text(state.totalErrors.toLocaleString());
            if (state.totalErrors > 0) {
                metricErrors.css('color', '#d63638');
            }

            // Speed
            var elapsedSec = Math.max(1, (Date.now() - state.startTime) / 1000);
            var speed = (state.totalProducts / elapsedSec).toFixed(1);
            metricSpeed.text(speed + ' prod/s');

            // Progress bar
            var pct = res.percent || 0;
            if (res.completed) {
                pct = 100.0;
            }
            percentText.text(pct.toFixed(1) + '%');
            progressBarFill.css('width', pct + '%');

            if (res.completed) {
                // Done!
                state.isCompleted = true;
                state.isProcessing = false;
                stopTimer();
                progressSpinner.removeClass('is-active');
                progressBarFill.removeClass('wpe-progress-pulsing');
                statusDesc.html('<strong style="color: #008a20;">✓ Import and catalog rebuild completed successfully!</strong>');
                pauseBtn.hide();
                resumeBtn.hide();
                cancelBtn.hide();
                return;
            }

            if (res.status === 'paused') {
                state.isPaused = true;
                state.isProcessing = false;
                stopTimer();
                progressSpinner.removeClass('is-active');
                progressBarFill.removeClass('wpe-progress-pulsing');
                statusDesc.text('Process paused at checkpoint.');
                pauseBtn.hide();
                resumeBtn.show();
                return;
            }

            if (res.status === 'cancelled') {
                state.isCancelled = true;
                state.isProcessing = false;
                stopTimer();
                progressSpinner.removeClass('is-active');
                progressBarFill.removeClass('wpe-progress-pulsing');
                statusDesc.text('Process cancelled by operator.');
                pauseBtn.hide();
                resumeBtn.hide();
                return;
            }

            // Schedule immediate next batch
            setTimeout(processNextBatch, 50);
        }

        // Pause Action
        pauseBtn.on('click', function () {
            state.isPaused = true;
            statusDesc.text('Requesting pause...');
            pauseBtn.prop('disabled', true);

            $.ajax({
                url: config.restUrl + 'shopify-import/pause',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ job_id: state.jobId }),
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce); },
                success: function () {
                    pauseBtn.prop('disabled', false).hide();
                    resumeBtn.show();
                    progressSpinner.removeClass('is-active');
                    progressBarFill.removeClass('wpe-progress-pulsing');
                    statusDesc.text('Paused. Checkpoint saved securely.');
                    appendLog('warning', '[SYSTEM] Import paused.');
                }
            });
        });

        // Resume Action
        resumeBtn.on('click', function () {
            resumeImport();
        });

        function resumeImport() {
            state.isPaused = false;
            state.isProcessing = true;
            resumeBtn.prop('disabled', true);
            statusDesc.text('Resuming import...');

            $.ajax({
                url: config.restUrl + 'shopify-import/resume',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ job_id: state.jobId }),
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce); },
                success: function () {
                    resumeBtn.prop('disabled', false).hide();
                    pauseBtn.show();
                    progressSpinner.addClass('is-active');
                    progressBarFill.addClass('wpe-progress-pulsing');
                    appendLog('info', '[SYSTEM] Resuming import from checkpoint...');
                    startTimer();
                    processNextBatch();
                }
            });
        }

        // Cancel Action
        cancelBtn.on('click', function () {
            if (!confirm('Are you sure you want to cancel this import process? Previously committed products will be preserved.')) {
                return;
            }

            state.isCancelled = true;
            state.isProcessing = false;
            stopTimer();

            $.ajax({
                url: config.restUrl + 'shopify-import/cancel',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ job_id: state.jobId }),
                beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', config.nonce); },
                success: function () {
                    progressSpinner.removeClass('is-active');
                    progressBarFill.removeClass('wpe-progress-pulsing');
                    statusDesc.text('Process has been cancelled.');
                    pauseBtn.hide();
                    resumeBtn.hide();
                    cancelBtn.hide();
                    appendLog('warning', '[SYSTEM] Import process cancelled.');
                }
            });
        });

        // Download Log File
        downloadLogBtn.on('click', function () {
            if (!state.jobId) {
                alert('No active job information available.');
                return;
            }
            var downloadUrl = config.restUrl + 'shopify-import/download-log?job_id=' + encodeURIComponent(state.jobId) + '&_wpnonce=' + encodeURIComponent(config.nonce);
            window.location.href = downloadUrl;
        });

        // Clear Console
        clearConsoleBtn.on('click', function () {
            terminalBody.empty();
        });

        function appendLog(level, message) {
            var colorClass = 'wpe-log-info';
            if (level === 'success') colorClass = 'wpe-log-success';
            if (level === 'warning') colorClass = 'wpe-log-warning';
            if (level === 'error') colorClass = 'wpe-log-error';

            var lineEl = $('<div></div>').addClass(colorClass).text(message);
            terminalBody.append(lineEl);

            if (autoScrollCb.is(':checked')) {
                terminalBody.scrollTop(terminalBody.prop('scrollHeight'));
            }
        }

        function startTimer() {
            if (state.timerInterval) return;
            state.timerInterval = setInterval(function () {
                if (state.startTime) {
                    var diffSec = Math.floor((Date.now() - state.startTime) / 1000);
                    var mins = Math.floor(diffSec / 60);
                    var secs = diffSec % 60;
                    var str = (mins < 10 ? '0' + mins : mins) + ':' + (secs < 10 ? '0' + secs : secs);
                    metricTime.text(str);
                }
            }, 1000);
        }

        function stopTimer() {
            if (state.timerInterval) {
                clearInterval(state.timerInterval);
                state.timerInterval = null;
            }
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            var k = 1024;
            var sizes = ['B', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function escapeHtml(text) {
            return $('<div>').text(text).html();
        }
    });

})(jQuery);
