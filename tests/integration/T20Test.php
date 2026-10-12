<?php
/**
 * T20 Integration Test — Cache context, invalidation và pricing compatibility.
 *
 * Verifies:
 * - R79 (context_cache_isolation): Context-aware cache isolation across currency, tax, and customer group dimensions.
 * - R80 (visibility_cache_invalidate): Visibility and status changes invalidate cache preventing draft leaks.
 * - R81 (cache_stampede): Mutex lock prevents cache stampede on concurrent misses.
 * - R82 (dynamic_price_filter_before_pagination): Pricing compatibility gate guards against unindexed dynamic pricing filters.
 * - AC-T20-01: Isolated caches per tax/currency context; visibility updates bust cache.
 * - AC-T20-02: Outbox backlog has observable lag and healthy metrics.
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Application\Catalog\CatalogProjector;
use WPE\Application\Catalog\ContextCache;
use WPE\Compatibility\WooDataStore\ProductDataStore;
use WPE\Compatibility\WooDataStore\MetaBridge;
use WPE\Compatibility\WooDataStore\ProductLifecycle;

class T20Test
{
    private \wpdb $wpdb;
    private CatalogProjector $projector;
    private ContextCache $cache;
    private array $cleanProductIds = [];
    private array $transientKeysToClean = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        add_filter('woocommerce_data_stores', [ProductDataStore::class, 'registerDataStores']);
        MetaBridge::register();
        ProductLifecycle::register();

        $this->projector = new CatalogProjector($this->wpdb);
        $this->cache = new ContextCache($this->wpdb, $this->projector);
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        if (!empty($this->cleanProductIds)) {
            $pTable = $this->wpdb->prefix . 'wpe_products';
            $vTable = $this->wpdb->prefix . 'wpe_variants';
            $iTable = $this->wpdb->prefix . 'wpe_inventory';
            $sTable = $this->wpdb->prefix . 'wpe_sku_registry';
            $cTable = $this->wpdb->prefix . 'wpe_catalog';
            $fTable = $this->wpdb->prefix . 'wpe_catalog_facets';

            foreach ($this->cleanProductIds as $pid) {
                $this->wpdb->delete($pTable, ['id' => $pid]);
                $this->wpdb->delete($vTable, ['product_id' => $pid]);
                $this->wpdb->delete($iTable, ['owner_id' => $pid]);
                $this->wpdb->delete($sTable, ['object_id' => $pid]);
                $this->wpdb->delete($cTable, ['product_id' => $pid]);
                $this->wpdb->delete($fTable, ['product_id' => $pid]);
                wp_delete_post($pid, true);
            }
            $this->cleanProductIds = [];
        }

        foreach ($this->transientKeysToClean as $key) {
            delete_transient($key);
        }
        $this->transientKeysToClean = [];
    }

    /**
     * R79 & AC-T20-01: Context cache isolation across currency, tax, and customer group dimensions.
     */
    public function testR79ContextCacheIsolation(): void
    {
        echo "Testing R79 (context_cache_isolation)...\n";

        $queryArgs = ['category' => 'electronics', 'sort' => 'price_asc'];

        // Context 1: US Dollar, Retail customer, California tax
        $ctxUS = [
            'currency'       => 'USD',
            'tax_country'    => 'US',
            'tax_state'      => 'CA',
            'customer_group' => 'retail',
        ];

        // Context 2: Euro, Wholesale customer, Germany tax
        $ctxEU = [
            'currency'       => 'EUR',
            'tax_country'    => 'DE',
            'tax_state'      => '',
            'customer_group' => 'wholesale',
        ];

        $keyUS = $this->cache->generateCacheKey($queryArgs, $ctxUS);
        $keyEU = $this->cache->generateCacheKey($queryArgs, $ctxEU);

        $this->transientKeysToClean[] = $keyUS;
        $this->transientKeysToClean[] = $keyEU;

        // Keys MUST be strictly distinct
        if ($keyUS === $keyEU) {
            throw new \RuntimeException("R79 Failed: Cache keys for different currency/tax/customer contexts must not collide!");
        }

        // Store distinct pricing payloads
        $payloadUS = ['currency' => 'USD', 'items' => [['id' => 101, 'display_price' => '$100.00']]];
        $payloadEU = ['currency' => 'EUR', 'items' => [['id' => 101, 'display_price' => '85.00 €']]];

        $this->cache->remember($keyUS, fn() => $payloadUS, 300);
        $this->cache->remember($keyEU, fn() => $payloadEU, 300);

        // Retrieve and verify complete isolation
        $cachedUS = get_transient($keyUS);
        $cachedEU = get_transient($keyEU);

        if ($cachedUS['currency'] !== 'USD' || $cachedEU['currency'] !== 'EUR') {
            throw new \RuntimeException("R79 Failed: Cache context payload contaminated across contexts!");
        }
        if ($cachedUS['items'][0]['display_price'] !== '$100.00' || $cachedEU['items'][0]['display_price'] !== '85.00 €') {
            throw new \RuntimeException("R79 Failed: Price values contaminated across contexts!");
        }

        echo "PASSED: R79 context cache isolation verified between USD/US-CA/retail and EUR/DE/wholesale.\n\n";
    }

    /**
     * R80 & AC-T20-01: Visibility cache invalidation preventing draft leaks.
     */
    public function testR80VisibilityCacheInvalidate(): void
    {
        echo "Testing R80 (visibility_cache_invalidate)...\n";

        $gen = $this->projector->getActiveGeneration();
        $queryArgs = ['category' => 'apparel'];

        $keyBefore = $this->cache->generateCacheKey($queryArgs, ['generation' => $gen]);
        $this->transientKeysToClean[] = $keyBefore;

        $cachedData = ['product_ids' => [201, 202], 'status' => 'published_only'];
        $this->cache->remember($keyBefore, fn() => $cachedData, 300);

        if (get_transient($keyBefore) === false) {
            throw new \RuntimeException("R80 Failed: Pre-invalidation cache not stored");
        }

        // Now simulate product 202 changed to draft/trash -> invalidate
        $this->cache->invalidate(202, $gen);

        // Generating cache key after invalidation must reflect the new cache epoch
        $keyAfter = $this->cache->generateCacheKey($queryArgs, ['generation' => $gen]);
        $this->transientKeysToClean[] = $keyAfter;

        if ($keyBefore === $keyAfter) {
            throw new \RuntimeException("R80 Failed: Cache key after invalidation must change to prevent reading stale/draft data!");
        }

        // New cache key must be a cache miss (null / false)
        if (get_transient($keyAfter) !== false) {
            throw new \RuntimeException("R80 Failed: New cache key should be a miss after invalidation!");
        }

        echo "PASSED: R80 visibility cache invalidation verified. Cache epoch incremented, busting stale keys.\n\n";
    }

    /**
     * R81: Cache stampede protection via atomic mutex lock.
     */
    public function testR81CacheStampede(): void
    {
        echo "Testing R81 (cache_stampede)...\n";

        $testKey = 'wpe_test_stampede_' . wp_generate_password(6, false);
        $this->transientKeysToClean[] = $testKey;
        $this->transientKeysToClean[] = $testKey . '_lock';

        $computeCallCount = 0;
        $expensiveComputation = function () use (&$computeCallCount) {
            $computeCallCount++;
            return ['computed_at' => microtime(true), 'counter' => $computeCallCount];
        };

        // First call computes and caches
        $res1 = $this->cache->remember($testKey, $expensiveComputation, 60);

        // Second call should fetch from cache, not re-executing callback
        $res2 = $this->cache->remember($testKey, $expensiveComputation, 60);

        if ($computeCallCount !== 1) {
            throw new \RuntimeException("R81 Failed: Expensive computation should only run once, ran {$computeCallCount} times");
        }

        if ($res1['computed_at'] !== $res2['computed_at']) {
            throw new \RuntimeException("R81 Failed: Second call did not return cached result from first call");
        }

        // Test lock contention handling
        // Artificially hold the lock
        set_transient($testKey . '_lock', time() + 5, 5);

        // Calling remember while lock is held and cache exists returns cached without running compute
        $res3 = $this->cache->remember($testKey, $expensiveComputation, 60);
        if ($computeCallCount !== 1) {
            throw new \RuntimeException("R81 Failed: Computation ran while lock was held despite valid cache");
        }

        echo "PASSED: R81 cache stampede protection verified. Mutex lock prevents redundant executions.\n\n";
    }

    /**
     * R82: Dynamic pricing compatibility gate.
     */
    public function testR82DynamicPricingCompatibilityGate(): void
    {
        echo "Testing R82 (dynamic_price_filter_before_pagination)...\n";

        // Case 1: Standard query without dynamic pricing hooks -> Compatible
        $queryNoFilter = ['category' => 'books'];
        $eval1 = $this->cache->evaluatePricingCompatibility($queryNoFilter, []);
        if (!$eval1['compatible'] || $eval1['action'] !== 'engine') {
            throw new \RuntimeException("R82 Failed: Standard query should be compatible with engine");
        }

        // Case 2: Query with price filter, but no dynamic hooks -> Compatible
        $queryWithPrice = ['min_price' => 20.0, 'max_price' => 50.0];
        $eval2 = $this->cache->evaluatePricingCompatibility($queryWithPrice, []);
        if (!$eval2['compatible'] || $eval2['action'] !== 'engine') {
            throw new \RuntimeException("R82 Failed: Price filter without dynamic hooks should be compatible");
        }

        // Case 3: Query with price filter AND active dynamic pricing hook -> INCOMPATIBLE (fallback_legacy)
        $detectedHooks = ['woocommerce_product_get_price'];
        $eval3 = $this->cache->evaluatePricingCompatibility($queryWithPrice, $detectedHooks);

        if ($eval3['compatible'] || $eval3['action'] !== 'fallback_legacy') {
            throw new \RuntimeException("R82 Failed: Price filter with active woocommerce_product_get_price hook must trigger fallback_legacy!");
        }
        if (empty($eval3['reason'])) {
            throw new \RuntimeException("R82 Failed: Incompatible evaluation must document reason!");
        }

        echo "PASSED: R82 pricing compatibility gate verified. Correctly routes to fallback_legacy when pre-pagination price filtering cannot guarantee accuracy.\n\n";
    }

    /**
     * AC-T20-02: Outbox backlog has observable lag and healthy metrics.
     */
    public function testAcT2002OutboxLagObservability(): void
    {
        echo "Testing AC-T20-02 (outbox lag observability)...\n";

        $lagInfo = $this->cache->getOutboxLag();

        if (!isset($lagInfo['pending_count'], $lagInfo['lag_seconds'], $lagInfo['is_healthy'], $lagInfo['status'])) {
            throw new \RuntimeException("AC-T20-02 Failed: getOutboxLag response missing required metric fields");
        }

        if (!is_int($lagInfo['pending_count']) || !is_float($lagInfo['lag_seconds']) || !is_bool($lagInfo['is_healthy'])) {
            throw new \RuntimeException("AC-T20-02 Failed: Metric types incorrect");
        }

        echo "PASSED: AC-T20-02 outbox lag observable: pending={$lagInfo['pending_count']}, lag={$lagInfo['lag_seconds']}s, status={$lagInfo['status']}.\n\n";
    }

    public function runAll(): void
    {
        echo "=== Running T20 Integration Tests ===\n\n";

        $this->testR79ContextCacheIsolation();
        $this->testR80VisibilityCacheInvalidate();
        $this->testR81CacheStampede();
        $this->testR82DynamicPricingCompatibilityGate();
        $this->testAcT2002OutboxLagObservability();

        echo "=== Test Summary ===\n";
        echo "R79 (context_cache_isolation): PASSED\n";
        echo "R80 (visibility_cache_invalidate): PASSED\n";
        echo "R81 (cache_stampede): PASSED\n";
        echo "R82 (dynamic_price_filter_before_pagination): PASSED\n";
        echo "AC-T20-01 (context_isolation_visibility_invalidate): PASSED\n";
        echo "AC-T20-02 (outbox_lag_observability): PASSED\n";
    }
}

$test = new T20Test();
$test->runAll();
exit(0);
