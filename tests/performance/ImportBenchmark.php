<?php
/**
 * ImportBenchmark.php — end-to-end CSV Import benchmark.
 *
 * Runs the real ShopifyBatchImporter (initJob + processBatch loop, same code
 * path as the admin screen) and reports throughput, query counts, DNS lookups,
 * peak RAM and row deltas. Refuses to run against a database whose name does
 * not start with "test_", so it can never write to a live site.
 *
 * Usage (from the WordPress root):
 *   wp --require=<file defining DB_NAME 'test_…'> eval-file \
 *     wp-content/plugins/wc-product-engine/tests/performance/ImportBenchmark.php
 *
 * Environment:
 *   WPE_BENCH_CSV     Source Shopify CSV (required).
 *   WPE_BENCH_SUFFIX  Appended to every Handle so the run creates new products
 *                     (omit to measure the update/re-import path).
 *   WPE_BENCH_LABEL   Label stored in the JSON result.
 *   WPE_BENCH_OUT     Write the JSON result to this path as well as stdout.
 *
 * No strict_types declaration: wp eval-file evals this file after its own
 * code, where a declare() statement is not allowed.
 */

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;
use WPE\Compatibility\Gallery\UrlValidator;

if (!defined('ABSPATH')) {
    exit(1);
}

$dbName = defined('DB_NAME') ? (string) DB_NAME : '';
if (strpos($dbName, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run: DB_NAME '{$dbName}' is not a test_* database.\n");
    exit(1);
}

$source = (string) getenv('WPE_BENCH_CSV');
if ($source === '' || !is_readable($source)) {
    fwrite(STDERR, "WPE_BENCH_CSV is missing or unreadable.\n");
    exit(1);
}

$suffix = (string) getenv('WPE_BENCH_SUFFIX');
$label  = (string) (getenv('WPE_BENCH_LABEL') ?: 'run');
$out    = (string) getenv('WPE_BENCH_OUT');

global $wpdb;

// Optionally rewrite Handle values so every product is created fresh.
$csvPath = $source;
if ($suffix !== '') {
    $csvPath = trailingslashit(sys_get_temp_dir()) . 'wpe-bench-' . sanitize_key($suffix) . '.csv';
    $in  = fopen($source, 'rb');
    $dst = fopen($csvPath, 'wb');
    $header = fgetcsv($in);
    $first = $header[0] ?? '';
    if (strncmp($first, "\xEF\xBB\xBF", 3) === 0) {
        $header[0] = substr($first, 3);
    }
    // Find the handle column the way the importer does ("Handle", "URL handle", …).
    $normalizer = \WPE\Compatibility\Csv\Shopify\ShopifyImporter::getInstance();
    $handleIdx = false;
    foreach ($header as $i => $name) {
        if ($normalizer->normalizeHeader((string) $name) === 'handle') {
            $handleIdx = $i;
            break;
        }
    }
    if ($handleIdx === false) {
        fwrite(STDERR, "No handle column found in CSV header.\n");
        exit(1);
    }
    fputcsv($dst, $header);
    while (($row = fgetcsv($in)) !== false) {
        if ($handleIdx !== false && isset($row[$handleIdx]) && $row[$handleIdx] !== '') {
            $row[$handleIdx] .= '-' . $suffix;
        }
        fputcsv($dst, $row);
    }
    fclose($in);
    fclose($dst);
}

// Count every SQL statement sent through wpdb.
$queryCount = 0;
add_filter('query', static function ($sql) use (&$queryCount) {
    $queryCount++;
    return $sql;
});

// UrlValidator is declared inside AssetService.php; load that file first.
class_exists(\WPE\Compatibility\Gallery\AssetService::class);

// Count DNS lookups made by URL validation (same resolution logic as production).
$dnsCount = 0;
$dnsTime  = 0.0;
UrlValidator::setDnsResolver(static function (string $host) use (&$dnsCount, &$dnsTime): array {
    $dnsCount++;
    $t = microtime(true);
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
    }
    $dnsTime += microtime(true) - $t;
    return array_values(array_unique($ips));
});

$tables = [
    'products'   => "{$wpdb->prefix}wpe_products",
    'variants'   => "{$wpdb->prefix}wpe_variants",
    'assets'     => "{$wpdb->prefix}wpe_assets",
    'outbox'     => "{$wpdb->prefix}wpe_outbox",
    'audit'      => "{$wpdb->prefix}wpe_audit",
    'posts'      => $wpdb->posts,
    'postmeta'   => $wpdb->postmeta,
];
$countRows = static function () use ($wpdb, $tables): array {
    $c = [];
    foreach ($tables as $k => $t) {
        $c[$k] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
    }
    return $c;
};

$before = $countRows();

$importer = ShopifyBatchImporter::getInstance();
$queryCount = 0;
$dnsCount = 0;
$dnsTime = 0.0;
$startMem = memory_get_usage(true);
$t0 = microtime(true);

$init = $importer->initJob($csvPath, [
    'original_filename' => basename($csvPath),
    'update_mode'       => 'merge',
    'image_mode'        => 'external',
    'batch_size'        => 25,
]);
$jobId = $init['job_id'];

$batches = [];
do {
    $bt = microtime(true);
    $bq = $queryCount;
    $res = $importer->processBatch($jobId, 25);
    $batches[] = [
        'seconds'  => round(microtime(true) - $bt, 3),
        'products' => (int) ($res['batch_products'] ?? 0),
        'variants' => (int) ($res['batch_variants'] ?? 0),
        'queries'  => $queryCount - $bq,
    ];
    if (!empty($res['busy'])) {
        fwrite(STDERR, "Job reported busy; another worker holds it.\n");
        exit(1);
    }
} while (empty($res['completed']));

$wall = microtime(true) - $t0;
$after = $countRows();

$products = (int) ($res['products_processed'] ?? 0);
$variants = (int) ($res['variants_processed'] ?? 0);
$delta = [];
foreach ($before as $k => $v) {
    $delta[$k] = $after[$k] - $v;
}

$result = [
    'label'              => $label,
    'db'                 => $dbName,
    'csv'                => basename($source),
    'suffix'             => $suffix,
    'job_id'             => $jobId,
    'errors'             => (int) ($res['errors_count'] ?? 0),
    'products'           => $products,
    'variants'           => $variants,
    'wall_seconds'       => round($wall, 2),
    'products_per_sec'   => $wall > 0 ? round($products / $wall, 3) : 0,
    'variants_per_sec'   => $wall > 0 ? round($variants / $wall, 1) : 0,
    'seconds_per_product' => $products > 0 ? round($wall / $products, 2) : 0,
    'queries_total'      => $queryCount,
    'queries_per_product' => $products > 0 ? (int) round($queryCount / $products) : 0,
    'queries_per_variant' => $variants > 0 ? round($queryCount / $variants, 1) : 0,
    'dns_lookups'        => $dnsCount,
    'dns_seconds'        => round($dnsTime, 2),
    'peak_ram_mb'        => round(memory_get_peak_usage(true) / 1048576, 1),
    'ram_growth_mb'      => round((memory_get_usage(true) - $startMem) / 1048576, 1),
    'batches'            => count($batches),
    'max_batch_seconds'  => $batches ? max(array_column($batches, 'seconds')) : 0,
    'row_delta'          => $delta,
];

$json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo $json, "\n";
if ($out !== '') {
    file_put_contents($out, $json . "\n");
}

if ($csvPath !== $source) {
    @unlink($csvPath);
}
