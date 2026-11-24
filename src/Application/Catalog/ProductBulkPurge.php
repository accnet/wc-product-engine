<?php

namespace WPE\Application\Catalog;

use InvalidArgumentException;
use RuntimeException;
use WPE\Compatibility\WooDataStore\ProductLifecycle;
use WPE\Infrastructure\Database\ProductPurger;

/**
 * ProductBulkPurge — permanently delete many products in batches (P039).
 *
 * Selection (`criteria`): `scope` = all | import_job | status | category, plus `import_job` (Shopify
 * import job ID: the handles of its staged CSV), `status` (comma list of post statuses) or
 * `category` (product_cat slug or term ID). Products referenced by order items are skipped unless
 * `include_ordered` is true (D01). Media and taxonomy terms are kept (D02).
 *
 * Per batch: native rows through ProductPurger, variation shells and their meta, term rows and
 * WooCommerce lookup rows set-based, then each parent shell through wp_delete_post inside the
 * projection guard so third-party delete hooks still run while the native lifecycle hook (which
 * would redo the purge product by product) stays out. Term counts are deferred and flushed once;
 * caches are invalidated once at the end (D04).
 */
class ProductBulkPurge
{
    public const JOB_KIND = 'product_purge';
    public const DEFAULT_BATCH = 200;
    private const LOCK_NAME = 'wpe_product_purge';
    private const ACTIVE_IMPORT_WINDOW = 900;
    private const SCOPES = ['all', 'import_job', 'status', 'category'];

    private \wpdb $wpdb;
    private ProductPurger $purger;
    private string $prefix;

    public function __construct(?\wpdb $wpdb = null, ?ProductPurger $purger = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->purger = $purger ?? new ProductPurger($this->wpdb);
        $this->prefix = $this->wpdb->prefix;
    }

    /**
     * Dry run: what a purge with these criteria would delete.
     */
    public function plan(array $criteria): array
    {
        $plan = $this->planFor($this->normalizeCriteria($criteria));
        unset($plan['criteria']['product_ids']);
        return $plan;
    }

    private function planFor(array $criteria): array
    {
        $where = $this->selectionWhere($criteria, true);
        $whereAll = $this->selectionWhere($criteria, false);
        $posts = $this->wpdb->posts;

        $products = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$posts} p WHERE {$where}");
        $matched = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM {$posts} p WHERE {$whereAll}");
        $native = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} p INNER JOIN {$this->prefix}wpe_products wp ON wp.id = p.ID WHERE {$where}"
        );
        $variations = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} v WHERE v.post_type = 'product_variation'
               AND v.post_parent IN (SELECT p.ID FROM {$posts} p WHERE {$where})"
        );
        $attachments = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} a WHERE a.post_type = 'attachment'
               AND a.post_parent IN (SELECT p.ID FROM {$posts} p WHERE {$where})"
        );
        $samples = $this->wpdb->get_col("SELECT p.post_title FROM {$posts} p WHERE {$where} ORDER BY p.ID LIMIT 5") ?: [];

        return [
            'action'            => 'purge_products',
            'mode'              => 'plan',
            'criteria'          => $criteria,
            'products'          => $products,
            'native_products'   => $native,
            'legacy_products'   => $products - $native,
            'variations'        => $variations,
            'skipped_ordered'   => $matched - $products,
            'attachments_kept'  => $attachments,
            'active_imports'    => $this->activeImportCount(),
            'samples'           => array_map('strval', $samples),
        ];
    }

    /**
     * Create a purge job after checking the confirmation and that no import is running (D03, D05).
     */
    public function start(array $criteria, string $confirm, int $actorId = 0): array
    {
        $plan = $this->planFor($this->normalizeCriteria($criteria));
        if ($plan['active_imports'] > 0) {
            throw new RuntimeException('A Shopify import is pending or running. Wait for it to finish or cancel it before deleting products.');
        }
        if ($plan['products'] <= 0) {
            throw new RuntimeException('No products match the selection.');
        }
        if (trim($confirm) !== (string)$plan['products']) {
            throw new InvalidArgumentException(sprintf('Confirmation must be the number of products to delete (%d).', $plan['products']));
        }

        $jobId = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');
        $config = $plan['criteria'];
        $config['actor_id'] = $actorId;
        $config['planned'] = $plan['products'];
        $this->wpdb->insert("{$this->prefix}wpe_jobs", [
            'id'         => $jobId,
            'kind'       => self::JOB_KIND,
            'status'     => 'in_progress',
            'config'     => (string)wp_json_encode($config),
            'checkpoint' => (string)wp_json_encode(['last_id' => 0]),
            'counts'     => (string)wp_json_encode($this->emptyCounts()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        unset($plan['criteria']['product_ids']);
        $this->audit('product_purge_started', $actorId, ['job_id' => $jobId, 'criteria' => $plan['criteria'], 'planned' => $plan['products']]);

        return array_merge($plan, ['mode' => 'start', 'job_id' => $jobId, 'status' => 'in_progress']);
    }

    /**
     * Run batches of a purge job until it is done, $maxBatches ran or $maxSeconds passed.
     *
     * @param callable|null $progress fn(array $counts): void, after each batch
     */
    public function run(string $jobId, int $batchSize = self::DEFAULT_BATCH, int $maxBatches = 0, float $maxSeconds = 0.0, ?callable $progress = null): array
    {
        $job = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->prefix}wpe_jobs WHERE id = %s AND kind = %s",
            $jobId,
            self::JOB_KIND
        ), ARRAY_A);
        if (!$job) {
            throw new InvalidArgumentException("Unknown product purge job: {$jobId}");
        }
        $config = json_decode((string)$job['config'], true) ?: [];
        $checkpoint = json_decode((string)$job['checkpoint'], true) ?: ['last_id' => 0];
        $counts = array_merge($this->emptyCounts(), json_decode((string)$job['counts'], true) ?: []);
        if ($job['status'] === 'completed') {
            return $this->result($jobId, 'completed', $counts, 0);
        }
        if ($this->activeImportCount() > 0) {
            throw new RuntimeException('A Shopify import is pending or running; the purge is paused until it finishes.');
        }
        if ((int)$this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::LOCK_NAME)) !== 1) {
            throw new RuntimeException('Another product purge is running.');
        }

        $criteria = $this->normalizeCriteria($config);
        $batchSize = max(1, min(1000, $batchSize));
        $started = microtime(true);
        $batches = 0;
        $done = false;
        $deferred = function_exists('wp_defer_term_counting');
        if ($deferred) {
            wp_defer_term_counting(true);
        }

        try {
            while (true) {
                $ids = $this->nextBatch($criteria, (int)$checkpoint['last_id'], $batchSize);
                if ($ids === []) {
                    $done = true;
                    break;
                }
                $batchCounts = $this->purgeBatch($ids);
                foreach ($batchCounts as $k => $v) {
                    $counts[$k] = (int)($counts[$k] ?? 0) + $v;
                }
                $checkpoint['last_id'] = max($ids);
                $batches++;
                $this->wpdb->update("{$this->prefix}wpe_jobs", [
                    'checkpoint' => (string)wp_json_encode($checkpoint),
                    'counts'     => (string)wp_json_encode($counts),
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ], ['id' => $jobId]);
                if ($progress) {
                    $progress($counts);
                }
                if (($maxBatches > 0 && $batches >= $maxBatches) || ($maxSeconds > 0 && (microtime(true) - $started) >= $maxSeconds)) {
                    break;
                }
            }
        } finally {
            if ($deferred) {
                wp_defer_term_counting(false); // flushes the deferred term recounts
            }
            $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
        }

        if ($done) {
            $this->finish();
            $this->wpdb->update("{$this->prefix}wpe_jobs", [
                'status'     => 'completed',
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
            ], ['id' => $jobId]);
            $this->audit('product_purge_completed', (int)($config['actor_id'] ?? 0), ['job_id' => $jobId, 'counts' => $counts]);
        }

        $remaining = $done ? 0 : (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->wpdb->posts} p WHERE " . $this->selectionWhere($criteria, true)
        );
        return $this->result($jobId, $done ? 'completed' : 'in_progress', $counts, $remaining);
    }

    /**
     * Pending or running Shopify import jobs (D03). A job that has not moved for
     * ACTIVE_IMPORT_WINDOW seconds is abandoned and does not block a purge.
     */
    public function activeImportCount(): int
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}wpe_jobs
             WHERE kind = 'shopify_import' AND status IN ('pending', 'in_progress') AND updated_at >= %s",
            gmdate('Y-m-d H:i:s', time() - self::ACTIVE_IMPORT_WINDOW)
        ));
    }

    /**
     * Delete one batch of product IDs (parents). Returns per-batch counts.
     *
     * @param int[] $ids
     */
    private function purgeBatch(array $ids): array
    {
        $posts = $this->wpdb->posts;
        $inP = implode(',', $ids);
        $native = array_map('intval', $this->wpdb->get_col("SELECT id FROM {$this->prefix}wpe_products WHERE id IN ({$inP})") ?: []);
        $nativeVariants = $this->purger->variantIds($native);
        $shellVariations = array_map('intval', $this->wpdb->get_col(
            "SELECT ID FROM {$posts} WHERE post_type = 'product_variation' AND post_parent IN ({$inP})"
        ) ?: []);
        $variations = array_values(array_unique(array_merge($nativeVariants, $shellVariations)));
        $attachments = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'attachment' AND post_parent IN ({$inP})"
        );

        $this->wpdb->query('START TRANSACTION');
        try {
            $this->purger->purge($native, $nativeVariants);
            if ($variations !== []) {
                $this->deleteVariationShells($variations);
            }
            $this->wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }

        // Parent shells: core delete (comments, meta, term rows, third-party hooks) without the
        // per-product native lifecycle, whose work is already done above.
        $deleted = 0;
        foreach ($ids as $id) {
            $ok = ProductLifecycle::wrapProjection(static fn() => wp_delete_post($id, true));
            if ($ok) {
                $deleted++;
            }
            if (in_array($id, $native, true)) {
                do_action('wpe_product_purged', $id);
            }
        }
        if ($this->tableExists("{$this->prefix}wc_product_meta_lookup")) {
            $this->wpdb->query("DELETE FROM {$this->prefix}wc_product_meta_lookup WHERE product_id IN ({$inP})");
        }

        return [
            'products'         => $deleted,
            'native_products'  => count($native),
            'variations'       => count($variations),
            'attachments_kept' => $attachments,
            'failed'           => count($ids) - $deleted,
        ];
    }

    /** @param int[] $variationIds */
    private function deleteVariationShells(array $variationIds): void
    {
        $posts = $this->wpdb->posts;
        foreach (array_chunk($variationIds, 1000) as $chunk) {
            $in = implode(',', $chunk);
            foreach ([
                "DELETE FROM {$this->wpdb->postmeta} WHERE post_id IN ({$in})",
                "DELETE FROM {$this->wpdb->term_relationships} WHERE object_id IN ({$in})",
                "DELETE FROM {$posts} WHERE ID IN ({$in}) AND post_type = 'product_variation'",
            ] as $sql) {
                if ($this->wpdb->query($sql) === false) {
                    throw new RuntimeException('Variation shell delete failed: ' . $this->wpdb->last_error);
                }
            }
            foreach (['wc_product_meta_lookup', 'wc_product_attributes_lookup'] as $lookup) {
                if ($this->tableExists("{$this->prefix}{$lookup}")) {
                    $this->wpdb->query("DELETE FROM {$this->prefix}{$lookup} WHERE product_id IN ({$in})");
                }
            }
            foreach ($chunk as $id) {
                clean_post_cache($id);
            }
        }
    }

    /** Once, after the last batch: caches and term counts (D04). */
    private function finish(): void
    {
        if ($this->tableExists("{$this->prefix}wc_product_attributes_lookup")) {
            $this->wpdb->query(
                "DELETE l FROM {$this->prefix}wc_product_attributes_lookup l
                 LEFT JOIN {$this->wpdb->posts} p ON p.ID = l.product_or_parent_id
                 WHERE p.ID IS NULL"
            );
        }
        try {
            $projector = new CatalogProjector($this->wpdb);
            (new ContextCache($this->wpdb, $projector))->invalidate(null, $projector->getActiveGeneration());
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostic
            error_log('[wc-product-engine] product purge cache invalidation failed: ' . $e->getMessage());
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        if (class_exists('\WC_Cache_Helper')) {
            \WC_Cache_Helper::get_transient_version('product', true);
        }
        if (function_exists('wc_recount_all_terms')) {
            wc_recount_all_terms();
        }
    }

    /** @return int[] next parent IDs after $lastId */
    private function nextBatch(array $criteria, int $lastId, int $limit): array
    {
        $where = $this->selectionWhere($criteria, true);
        return array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT p.ID FROM {$this->wpdb->posts} p WHERE {$where} AND p.ID > %d ORDER BY p.ID LIMIT %d",
            $lastId,
            $limit
        )) ?: []);
    }

    /** SQL condition on `p` (wp_posts) for the selection; $guardOrders excludes ordered products. */
    private function selectionWhere(array $criteria, bool $guardOrders): string
    {
        $parts = ["p.post_type = 'product'"];

        switch ($criteria['scope']) {
            case 'status':
                $parts[] = "p.post_status IN ('" . implode("','", $criteria['status']) . "')";
                break;
            case 'category':
                $tt = (int)$criteria['term_taxonomy_id'];
                $parts[] = "(p.ID IN (SELECT object_id FROM {$this->wpdb->term_relationships} WHERE term_taxonomy_id = {$tt})"
                    . " OR p.ID IN (SELECT product_id FROM {$this->prefix}wpe_product_terms WHERE term_taxonomy_id = {$tt}))";
                break;
            case 'import_job':
                $ids = $criteria['product_ids'] ?: [0];
                $parts[] = 'p.ID IN (' . implode(',', array_map('intval', $ids)) . ')';
                break;
        }

        if ($guardOrders && empty($criteria['include_ordered'])) {
            $itemMeta = "{$this->prefix}woocommerce_order_itemmeta";
            if ($this->tableExists($itemMeta)) {
                $parts[] = "p.ID NOT IN (SELECT CAST(meta_value AS UNSIGNED) FROM {$itemMeta} WHERE meta_key = '_product_id')";
            }
        }
        return implode(' AND ', $parts);
    }

    /** Validate criteria and resolve category / import job into IDs. */
    private function normalizeCriteria(array $criteria): array
    {
        $scope = (string)($criteria['scope'] ?? '');
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Choose what to delete: scope must be one of ' . implode(', ', self::SCOPES) . '.');
        }
        $out = ['scope' => $scope, 'include_ordered' => !empty($criteria['include_ordered'])];

        if ($scope === 'status') {
            $raw = is_array($criteria['status'] ?? null) ? $criteria['status'] : explode(',', (string)($criteria['status'] ?? ''));
            $statuses = array_values(array_unique(array_filter(array_map(
                static fn($s) => preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)$s))),
                $raw
            ))));
            if ($statuses === []) {
                throw new InvalidArgumentException('Status scope needs at least one post status (for example draft,trash).');
            }
            $out['status'] = $statuses;
        }

        if ($scope === 'category') {
            $ttId = (int)($criteria['term_taxonomy_id'] ?? 0);
            $cat = trim((string)($criteria['category'] ?? ''));
            if ($ttId <= 0) {
                $term = ctype_digit($cat) ? get_term((int)$cat, 'product_cat') : get_term_by('slug', sanitize_title($cat), 'product_cat');
                if (!$term || is_wp_error($term)) {
                    throw new InvalidArgumentException("Unknown product category: {$cat}");
                }
                $ttId = (int)$term->term_taxonomy_id;
                $cat = (string)$term->slug;
            }
            $out['category'] = $cat;
            $out['term_taxonomy_id'] = $ttId;
        }

        if ($scope === 'import_job') {
            $jobId = preg_replace('/[^a-f0-9-]/', '', strtolower((string)($criteria['import_job'] ?? '')));
            if ($jobId === '') {
                throw new InvalidArgumentException('Import job scope needs a Shopify import job ID.');
            }
            $out['import_job'] = $jobId;
            $out['product_ids'] = isset($criteria['product_ids']) && is_array($criteria['product_ids'])
                ? array_values(array_map('intval', $criteria['product_ids']))
                : $this->productsOfImportJob($jobId);
        }

        return $out;
    }

    /**
     * Products whose handle (slug) appears in the staged CSV of a Shopify import job.
     *
     * @return int[]
     */
    private function productsOfImportJob(string $jobId): array
    {
        $config = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT config FROM {$this->prefix}wpe_jobs WHERE id = %s AND kind = 'shopify_import'",
            $jobId
        ));
        if (!is_string($config)) {
            throw new InvalidArgumentException("Unknown Shopify import job: {$jobId}");
        }
        $config = json_decode($config, true) ?: [];
        $file = (string)($config['file_path'] ?? '');
        if ($file === '' || !is_readable($file)) {
            throw new RuntimeException('The CSV file of this import job is no longer available, so its products cannot be identified.');
        }
        $col = null;
        foreach ((array)($config['headers'] ?? []) as $i => $h) {
            if (($h['norm'] ?? '') === 'handle') {
                $col = (int)$i;
                break;
            }
        }
        if ($col === null) {
            throw new RuntimeException('The import job has no URL handle column.');
        }

        $handles = [];
        $fh = fopen($file, 'r');
        if (!$fh) {
            throw new RuntimeException('Cannot open the CSV file of this import job.');
        }
        fseek($fh, (int)($config['data_start_offset'] ?? 0));
        if ((int)($config['data_start_offset'] ?? 0) === 0) {
            fgetcsv($fh, 0, ',', '"', '\\'); // header row
        }
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $h = trim((string)($row[$col] ?? ''));
            if ($h !== '') {
                $handles[$h] = true;
            }
        }
        fclose($fh);

        $ids = [];
        foreach (array_chunk(array_keys($handles), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            foreach ($this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->prefix}wpe_products WHERE slug IN ({$placeholders})",
                $chunk
            )) ?: [] as $id) {
                $ids[] = (int)$id;
            }
        }
        sort($ids);
        return array_values(array_unique($ids));
    }

    private function emptyCounts(): array
    {
        return ['products' => 0, 'native_products' => 0, 'variations' => 0, 'attachments_kept' => 0, 'failed' => 0];
    }

    private function result(string $jobId, string $status, array $counts, int $remaining): array
    {
        return [
            'action'    => 'purge_products',
            'mode'      => 'run',
            'job_id'    => $jobId,
            'status'    => $status,
            'counts'    => $counts,
            'remaining' => $remaining,
        ];
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        return $cache[$table] ??= ($this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
    }

    private function audit(string $operation, int $actorId, array $payload): void
    {
        $this->wpdb->insert("{$this->prefix}wpe_audit", [
            'actor_id'   => $actorId ?: (get_current_user_id() ?: null),
            'request_id' => wp_generate_uuid4(),
            'operation'  => $operation,
            'target_id'  => 0,
            'summary'    => (string)wp_json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
