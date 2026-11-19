<?php
/**
 * ImportPerformanceTest — P015 import correctness, idempotency and cost guards.
 *
 * Runs the real Shopify batch importer on a small generated CSV with many
 * variants per product and repeated image URLs, then re-imports the same file
 * and checks nothing is duplicated. Writes rows, so it only runs against a
 * test_* database:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/ImportPerformanceTest.php
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run with wp eval-file.\n");
    exit(1);
}

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;
use WPE\Compatibility\Gallery\UrlValidator;

final class ImportPerformanceTest
{
    private \wpdb $wpdb;
    private string $suffix;
    private string $csvPath = '';
    private int $dnsLookups = 0;
    private int $queries = 0;

    /** @var array<string, array{options: array<string, string[]>, skus: bool, images: string[], variantImages: string[]}> */
    private array $products;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->suffix = strtolower(wp_generate_password(8, false));
        $this->products = [
            "perf-many-variants-{$this->suffix}" => [
                'options'       => ['Size' => ['S', 'M', 'L', 'XL'], 'Color' => ['Red', 'Blue', 'Green']],
                'skus'          => false,
                'images'        => ['https://cdn.example.com/a1.jpg', 'https://cdn.example.com/a2.jpg', 'https://cdn.example.com/a3.jpg'],
                'variantImages' => ['https://cdn.example.com/a1.jpg', 'https://cdn.example.com/a2.jpg', 'https://cdn.example.com/a3.jpg'],
            ],
            "perf-sku-variants-{$this->suffix}" => [
                'options'       => ['Size' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
                'skus'          => true,
                'images'        => ['https://cdn.example.com/b1.jpg'],
                'variantImages' => ['https://cdn.example.com/b1.jpg'],
            ],
        ];
    }

    public function run(): void
    {
        $db = defined('DB_NAME') ? (string) DB_NAME : '';
        if (strpos($db, 'test_') !== 0) {
            fwrite(STDERR, "Refusing to run: DB_NAME '{$db}' is not a test_* database.\n");
            exit(1);
        }

        echo "Running ImportPerformanceTest on {$db}...\n";
        try {
            $this->setUp();
            $first = $this->testFirstImportCreatesEveryVariantOnce();
            $this->testReimportIsIdempotent($first);
            $this->testDnsIsResolvedOncePerHost();
            echo "All ImportPerformanceTest tests passed.\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->tearDown();
            exit(1);
        }
        $this->tearDown();
    }

    private function setUp(): void
    {
        UrlValidator::setDnsResolver(function (string $host): array {
            $this->dnsLookups++;
            return ['93.184.215.14'];
        });
        add_filter('query', function ($sql) {
            $this->queries++;
            return $sql;
        });

        $headers = ['Handle', 'Title', 'Body (HTML)', 'Published', 'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value',
            'Variant SKU', 'Variant Grams', 'Variant Inventory Qty', 'Variant Inventory Policy', 'Variant Price', 'Variant Compare At Price',
            'Image Src', 'Image Position', 'Image Alt Text', 'Variant Image', 'Variant Weight Unit', 'Status'];

        $this->csvPath = trailingslashit(sys_get_temp_dir()) . "wpe-perf-{$this->suffix}.csv";
        $fh = fopen($this->csvPath, 'wb');
        fputcsv($fh, $headers);

        foreach ($this->products as $handle => $spec) {
            $names = array_keys($spec['options']);
            $combos = [[]];
            foreach ($spec['options'] as $name => $values) {
                $next = [];
                foreach ($combos as $c) {
                    foreach ($values as $v) {
                        $next[] = $c + [$name => $v];
                    }
                }
                $combos = $next;
            }

            foreach ($combos as $i => $combo) {
                $row = array_fill_keys($headers, '');
                $row['Handle'] = $handle;
                if ($i === 0) {
                    $row['Title'] = 'Perf ' . $handle;
                    $row['Body (HTML)'] = '<p>Perf fixture</p>';
                    $row['Published'] = 'true';
                    $row['Status'] = 'active';
                }
                foreach ($names as $n => $name) {
                    $row['Option' . ($n + 1) . ' Name'] = $name;
                    $row['Option' . ($n + 1) . ' Value'] = $combo[$name];
                }
                $row['Variant SKU'] = $spec['skus'] ? strtoupper("PERF-{$this->suffix}-" . implode('-', $combo)) : '';
                $row['Variant Grams'] = '200';
                $row['Variant Inventory Qty'] = (string) (10 + $i);
                $row['Variant Inventory Policy'] = 'deny';
                $row['Variant Price'] = (string) (20 + $i) . '.00';
                $row['Variant Compare At Price'] = (string) (30 + $i) . '.00';
                if (isset($spec['images'][$i])) {
                    $row['Image Src'] = $spec['images'][$i];
                    $row['Image Position'] = (string) ($i + 1);
                    $row['Image Alt Text'] = 'Alt ' . ($i + 1);
                }
                $row['Variant Image'] = $spec['variantImages'][$i % count($spec['variantImages'])];
                $row['Variant Weight Unit'] = 'g';
                fputcsv($fh, array_values($row));
            }
        }
        fclose($fh);
    }

    /** @return array<string, array{product_id: int, variant_ids: int[]}> */
    private function runImport(): array
    {
        $importer = ShopifyBatchImporter::getInstance();
        $job = $importer->initJob($this->csvPath, ['original_filename' => basename($this->csvPath), 'update_mode' => 'merge', 'image_mode' => 'external']);
        $guard = 0;
        do {
            $res = $importer->processBatch($job['job_id'], 25);
            if (++$guard > 50) {
                throw new RuntimeException('Import did not complete within 50 batches.');
            }
        } while (empty($res['completed']));

        $this->assert((int) $res['errors_count'] === 0, 'Import reported errors: ' . wp_json_encode($res['logs'] ?? []));
        $this->wpdb->delete("{$this->wpdb->prefix}wpe_jobs", ['id' => $job['job_id']]);
        @unlink(WP_CONTENT_DIR . "/uploads/wpe-imports/logs/import_{$job['job_id']}.log");

        $state = [];
        foreach (array_keys($this->products) as $handle) {
            $pid = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_products WHERE slug = %s AND deleted_at IS NULL",
                $handle
            ));
            $this->assert($pid > 0, "Product {$handle} was not imported.");
            $vids = array_map('intval', $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}wpe_variants WHERE product_id = %d AND deleted_at IS NULL ORDER BY id",
                $pid
            )));
            $state[$handle] = ['product_id' => $pid, 'variant_ids' => $vids];
        }
        return $state;
    }

    private function testFirstImportCreatesEveryVariantOnce(): array
    {
        $state = $this->runImport();
        $p = $this->wpdb->prefix;

        foreach ($this->products as $handle => $spec) {
            $expected = array_product(array_map('count', $spec['options']));
            $pid = $state[$handle]['product_id'];
            $vids = $state[$handle]['variant_ids'];
            $this->assert(count($vids) === $expected, "{$handle}: expected {$expected} variants, got " . count($vids));

            $in = implode(',', $vids);
            $distinctKeys = (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT active_combination_key) FROM {$p}wpe_variants WHERE id IN ({$in}) AND active_combination_key IS NOT NULL");
            $this->assert($distinctKeys === $expected, "{$handle}: expected {$expected} distinct combination keys, got {$distinctKeys}");

            $withImage = (int) $this->wpdb->get_var("SELECT COUNT(DISTINCT variant_id) FROM {$p}wpe_variant_media WHERE variant_id IN ({$in})");
            $this->assert($withImage === $expected, "{$handle}: expected every variant to have an image, got {$withImage}");

            $gallery = (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$p}wpe_product_media WHERE product_id = %d", $pid));
            $this->assert($gallery === count($spec['images']), "{$handle}: expected " . count($spec['images']) . " product images, got {$gallery}");

            $prices = $this->wpdb->get_col("SELECT regular_price FROM {$p}wpe_variants WHERE id IN ({$in}) ORDER BY position");
            $this->assert(count(array_unique($prices)) === $expected, "{$handle}: variant prices were not kept per variant");

            if ($spec['skus']) {
                $registered = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}wpe_sku_registry WHERE object_kind = 'variation' AND object_id IN ({$in})");
                $this->assert($registered === $expected, "{$handle}: expected {$expected} SKU registry rows, got {$registered}");
            }

            $shells = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = 'product_variation' AND post_parent = {$pid}");
            $this->assert($shells === $expected, "{$handle}: expected {$expected} mirrored variation posts, got {$shells}");
        }

        $urls = [];
        foreach ($this->products as $spec) {
            $urls = array_merge($urls, $spec['images'], $spec['variantImages']);
        }
        $urls = array_values(array_unique($urls));
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));
        $assets = (int) $this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$p}wpe_assets WHERE source_url IN ({$placeholders})", ...$urls));
        $this->assert($assets === count($urls), 'Expected one asset per unique URL, got ' . $assets);

        echo "  ✓ first import creates every variant, image and SKU exactly once\n";
        return $state;
    }

    private function testReimportIsIdempotent(array $first): void
    {
        $p = $this->wpdb->prefix;
        $assetsBefore = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}wpe_assets");
        $second = $this->runImport();

        foreach ($first as $handle => $s) {
            $this->assert($second[$handle]['product_id'] === $s['product_id'], "{$handle}: re-import created a new product.");
            $this->assert($second[$handle]['variant_ids'] === $s['variant_ids'], "{$handle}: re-import changed the variant id set.");
        }
        $assetsAfter = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$p}wpe_assets");
        $this->assert($assetsAfter === $assetsBefore, "Re-import created " . ($assetsAfter - $assetsBefore) . ' new assets.');

        echo "  ✓ re-import keeps product ids, variant ids and assets\n";
    }

    private function testDnsIsResolvedOncePerHost(): void
    {
        $this->dnsLookups = 0;
        $this->runImport();
        // One host (cdn.example.com); a batch request may resolve it once.
        $this->assert($this->dnsLookups <= 1, "Expected at most 1 DNS lookup per request for one host, got {$this->dnsLookups}");

        // Swapping the resolver must drop cached answers (tests rely on this).
        UrlValidator::setDnsResolver(static fn (string $host): array => ['10.0.0.5']);
        $v = (new UrlValidator())->validateUrl('https://cdn.example.com/private.jpg');
        $this->assert(!$v['valid'], 'A resolver swap must not reuse the cached public IP (SSRF check).');

        echo "  ✓ DNS resolved at most once per host per request; resolver swap clears the cache\n";
    }

    private function tearDown(): void
    {
        UrlValidator::setDnsResolver(null);
        if ($this->csvPath !== '' && file_exists($this->csvPath)) {
            @unlink($this->csvPath);
        }
    }

    private function assert(bool $cond, string $message): void
    {
        if (!$cond) {
            throw new RuntimeException($message);
        }
    }
}

class_exists(\WPE\Compatibility\Gallery\AssetService::class);
(new ImportPerformanceTest())->run();
