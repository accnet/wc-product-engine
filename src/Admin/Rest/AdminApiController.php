<?php

declare(strict_types=1);

namespace WPE\Admin\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WPE\Admin\SettingsRegistry;
use WPE\Bootstrap\PluginBootstrap;
use WPE\Application\Catalog\CatalogProjector;
use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;
use WPE\Application\ImportExport\CsvStager;

/**
 * AdminApiController
 *
 * Provides REST API endpoints for WC Product Engine administration:
 * - Settings & Feature flags management.
 * - Catalog Generation rebuild.
 * - Shopify CSV upload, initialization, chunk batch processing, pause, resume, cancel.
 * - Persistent log file download.
 */
class AdminApiController
{
    private static ?AdminApiController $instance = null;
    private ShopifyBatchImporter $batchImporter;
    private CsvStager $csvStager;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(
        ?ShopifyBatchImporter $batchImporter = null,
        ?CsvStager $csvStager = null
    ) {
        $this->batchImporter = $batchImporter ?? ShopifyBatchImporter::getInstance();
        $this->csvStager     = $csvStager ?? CsvStager::getInstance();
    }

    public function registerRoutes(): void
    {
        $namespace = 'wpe/v1/admin';

        // Settings endpoints
        register_rest_route($namespace, '/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getSettings'],
                'permission_callback' => [$this, 'checkSettingsPermission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'saveSettings'],
                'permission_callback' => [$this, 'checkSettingsPermission'],
            ],
        ]);

        // Rebuild catalog index
        register_rest_route($namespace, '/rebuild-index', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rebuildIndex'],
            'permission_callback' => [$this, 'checkSettingsPermission'],
        ]);

        // Import CSV endpoints
        register_rest_route($namespace, '/shopify-import/upload', [
            'methods'             => 'POST',
            'callback'            => [$this, 'uploadCsv'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/init', [
            'methods'             => 'POST',
            'callback'            => [$this, 'initImport'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/process-batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'processBatch'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/pause', [
            'methods'             => 'POST',
            'callback'            => [$this, 'pauseImport'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/resume', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resumeImport'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancelImport'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/active-job', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getActiveJob'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);

        register_rest_route($namespace, '/shopify-import/download-log', [
            'methods'             => 'GET',
            'callback'            => [$this, 'downloadLog'],
            'permission_callback' => [$this, 'checkImportPermission'],
        ]);
    }

    public function checkSettingsPermission(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public function checkImportPermission(): bool
    {
        return current_user_can('wpe_import_products') || current_user_can('manage_woocommerce');
    }

    public function getSettings(WP_REST_Request $request): WP_REST_Response
    {
        $bootstrap = PluginBootstrap::instance();
        return new WP_REST_Response([
            'status'                    => 'success',
            'flags'                     => $bootstrap->getFlags(),
            'effective'                 => $this->effectiveFlags($bootstrap),
            'attribute_display_config'  => $bootstrap->getAttributeDisplayConfig(),
            'product_page_settings_ver' => $bootstrap->getProductPageSettingsVersion(),
            'dependencies_satisfied'    => $bootstrap->areDependenciesSatisfied(),
        ], 200);
    }

    /**
     * Save editable feature flags and optional global attribute display config.
     *
     * The payload is validated as a whole: if any key is rejected nothing is
     * written. Authority state (`cohort_mode`) is read-only here by design; it
     * changes only through the governed rollout commands (docs/02-domain.md §4-5).
     */
    public function saveSettings(WP_REST_Request $request): WP_REST_Response
    {
        $bootstrap = PluginBootstrap::instance();
        $params = $request->get_json_params();

        if (!is_array($params) || $params === []) {
            // Form-encoded fallback. Body only: query args (e.g. _wpnonce) are not settings.
            $params = $request->get_body_params();
        }
        if (!is_array($params)) {
            $params = [];
        }

        $result = SettingsRegistry::validate($params);

        if ($result['errors'] !== []) {
            // `code` stays inside the registered error vocabulary (docs/04 §1.9); `reason` carries the detail.
            return new WP_REST_Response([
                'status'  => 'error',
                'code'    => 'invalid_request',
                'reason'  => $result['code'],
                'message' => $result['code'] === 'authority_read_only'
                    ? __('Authority mode cannot be changed from Settings. Use Operations or WP-CLI.', 'wc-product-engine')
                    : __('Some settings are invalid. Nothing was saved.', 'wc-product-engine'),
                'errors'  => $result['errors'],
            ], 400);
        }

        $hasChanges = ($result['values'] !== []) || !empty($result['has_attribute_display_config']);

        // Merge onto the stored option so unrelated keys (e.g. cohort_mode) are preserved untouched.
        // An empty payload is a true no-op: nothing is written.
        if ($result['values'] !== []) {
            $flags = $bootstrap->getFlags();
            foreach ($result['values'] as $key => $value) {
                $flags[$key] = $value;
            }

            update_option(PluginBootstrap::OPTION_FLAGS, $flags);
            $bootstrap->loadFlags();
        }

        if (!empty($result['has_attribute_display_config'])) {
            $bootstrap->saveAttributeDisplayConfig($result['attribute_display_config']);
        } elseif ($hasChanges) {
            $bootstrap->bumpProductPageSettingsVersion();
        }

        return new WP_REST_Response([
            'status'                    => 'success',
            'message'                   => !$hasChanges
                ? __('No changes to save.', 'wc-product-engine')
                : __('Settings saved.', 'wc-product-engine'),
            'flags'                     => $bootstrap->getFlags(),
            'effective'                 => $this->effectiveFlags($bootstrap),
            'attribute_display_config'  => $bootstrap->getAttributeDisplayConfig(),
            'product_page_settings_ver' => $bootstrap->getProductPageSettingsVersion(),
            'dependencies_satisfied'    => $bootstrap->areDependenciesSatisfied(),
        ], 200);
    }

    /**
     * What the runtime will actually honour, as opposed to what is stored.
     *
     * @return array<string,bool>
     */
    private function effectiveFlags(PluginBootstrap $bootstrap): array
    {
        $effective = [];
        foreach (SettingsRegistry::flagKeys() as $key) {
            $effective[$key] = $bootstrap->isFlagEnabled($key);
        }
        return $effective;
    }

    public function rebuildIndex(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;
        try {
            $projector = new CatalogProjector($wpdb);
            $newGeneration = $projector->buildNewGeneration();

            return new WP_REST_Response([
                'status'     => 'success',
                'generation' => $newGeneration,
                'message'    => sprintf('Catalog index rebuilt successfully to Generation #%d.', $newGeneration),
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Failed to rebuild Catalog Index: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function uploadCsv(WP_REST_Request $request): WP_REST_Response
    {
        $files = $request->get_file_params();
        if (empty($files['file'])) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'No uploaded file found in request.',
            ], 400);
        }

        $uploaded = $files['file'];
        if (!empty($uploaded['error'])) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'File upload error: code ' . $uploaded['error'],
            ], 400);
        }

        try {
            $staged = $this->csvStager->storeUploadedFile(
                $uploaded['tmp_name'],
                $uploaded['name'],
                get_current_user_id()
            );

            return new WP_REST_Response([
                'status'            => 'success',
                'staged_file_id'    => $staged['staged_file_id'],
                'file_path'         => $staged['file_path'],
                'original_filename' => $staged['original_filename'],
                'file_size'         => $staged['size'],
                'file_size_fmt'     => size_format($staged['size']),
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function initImport(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();

        $filePath = sanitize_text_field($params['file_path'] ?? '');
        $stagedFileId = sanitize_text_field($params['staged_file_id'] ?? '');

        if ($stagedFileId !== '') {
            try {
                $filePath = $this->csvStager->resolveStagedPath($stagedFileId);
            } catch (\Throwable $e) {
                return new WP_REST_Response([
                    'status'  => 'error',
                    'message' => 'Staged file not found: ' . $e->getMessage(),
                ], 404);
            }
        }

        if ($filePath === '' || !file_exists($filePath)) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'File path does not exist or is not readable: ' . $filePath,
            ], 400);
        }

        $options = [
            'update_mode'       => sanitize_text_field($params['update_mode'] ?? 'merge'),
            'image_mode'        => sanitize_text_field($params['image_mode'] ?? 'external'),
            'batch_size'        => max(5, min(200, (int)($params['batch_size'] ?? 25))),
            'original_filename' => sanitize_text_field($params['original_filename'] ?? basename($filePath)),
        ];

        try {
            $initRes = $this->batchImporter->initJob($filePath, $options);
            return new WP_REST_Response([
                'status'  => 'success',
                'job'     => $initRes,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Failed to initialize import job: ' . $e->getMessage(),
            ], 400);
        }
    }

    public function processBatch(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $jobId = sanitize_text_field($params['job_id'] ?? '');
        $batchSize = max(5, min(200, (int)($params['batch_size'] ?? 25)));

        if ($jobId === '') {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Missing job_id in request.',
            ], 400);
        }

        try {
            $result = $this->batchImporter->processBatch($jobId, $batchSize);
            return new WP_REST_Response($result, 200);
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'status'  => 'error',
                'message' => 'Batch processing error: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function pauseImport(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $jobId = sanitize_text_field($params['job_id'] ?? '');

        if ($jobId === '') {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Missing job_id'], 400);
        }

        $res = $this->batchImporter->pauseJob($jobId);
        return new WP_REST_Response($res, 200);
    }

    public function resumeImport(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $jobId = sanitize_text_field($params['job_id'] ?? '');

        if ($jobId === '') {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Missing job_id'], 400);
        }

        $res = $this->batchImporter->resumeJob($jobId);
        return new WP_REST_Response($res, 200);
    }

    public function cancelImport(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $jobId = sanitize_text_field($params['job_id'] ?? '');

        if ($jobId === '') {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Missing job_id'], 400);
        }

        $res = $this->batchImporter->cancelJob($jobId);
        return new WP_REST_Response($res, 200);
    }

    public function getActiveJob(WP_REST_Request $request): WP_REST_Response
    {
        $job = $this->batchImporter->getActiveJob();
        return new WP_REST_Response([
            'status' => 'success',
            'job'    => $job,
        ], 200);
    }

    public function downloadLog(WP_REST_Request $request): void
    {
        $jobId = sanitize_text_field($request->get_param('job_id') ?? '');
        if ($jobId === '') {
            wp_die('Missing job_id');
        }

        $logFile = $this->batchImporter->getLogFile($jobId);
        if (!$logFile || !file_exists($logFile)) {
            wp_die('Log file not found for this job.');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="import_' . $jobId . '.log"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($logFile));
        readfile($logFile);
        exit;
    }
}
