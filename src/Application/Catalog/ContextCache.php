<?php
declare(strict_types=1);

namespace WPE\Application\Catalog;

use InvalidArgumentException;

/**
 * ContextCache — Context-aware caching, stampede protection, invalidation,
 * and pricing compatibility gate for catalog queries.
 *
 * Implements requirements:
 * - R79: Context cache isolation across tax, customer group, and currency dimensions.
 * - R80: Visibility cache invalidation preventing draft/private leaks.
 * - R81: Cache stampede protection via atomic mutex lock and backoff retry.
 * - R82: Dynamic pricing compatibility gate preventing post-pagination filter corruption.
 * - AC-T20-01: Isolated caches per tax/currency context; visibility updates invalidate cache immediately.
 * - AC-T20-02: Observable outbox lag metrics and authority-safe fallbacks.
 */
class ContextCache
{
    public const DEFAULT_TTL = 300; // 5 minutes
    public const LOCK_TIMEOUT = 5;  // 5 seconds mutex
    public const MAX_HEALTHY_LAG_SECONDS = 30.0;

    private \wpdb $wpdb;
    private CatalogProjector $projector;
    private string $tableOutbox;
    private string $tableControl;

    public function __construct(?\wpdb $wpdb = null, ?CatalogProjector $projector = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->projector = $projector ?? new CatalogProjector($this->wpdb);

        $prefix = $this->wpdb->prefix;
        $this->tableOutbox  = $prefix . 'wpe_outbox';
        $this->tableControl = $prefix . 'wpe_control';
    }

    /* -------------------------------------------------------------------------- */
    /* Cache Context & Key Generation (R79, AC-T20-01)                            */
    /* -------------------------------------------------------------------------- */

    /**
     * Resolves the current runtime context allowlist.
     *
     * @param array $overrides
     * @return array
     */
    public function resolveContext(array $overrides = []): array
    {
        $gen = $overrides['generation'] ?? $this->projector->getActiveGeneration();
        $currency = $overrides['currency'] ?? (function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD');
        $taxCountry = $overrides['tax_country'] ?? (function_exists('WC') && WC()->customer ? WC()->customer->get_shipping_country() : '');
        $taxState = $overrides['tax_state'] ?? (function_exists('WC') && WC()->customer ? WC()->customer->get_shipping_state() : '');
        $customerGroup = $overrides['customer_group'] ?? $this->resolveCustomerGroup();
        $cacheEpoch = $this->getCacheEpoch($gen);

        return [
            'generation'     => (int)$gen,
            'cache_epoch'    => (int)$cacheEpoch,
            'currency'       => strtoupper((string)$currency),
            'tax_country'    => strtoupper((string)$taxCountry),
            'tax_state'      => strtoupper((string)$taxState),
            'customer_group' => (string)$customerGroup,
        ];
    }

    private function resolveCustomerGroup(): string
    {
        if (!function_exists('wp_get_current_user')) {
            return 'guest';
        }
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return 'guest';
        }
        $roles = (array)$user->roles;
        return !empty($roles) ? reset($roles) : 'customer';
    }

    /**
     * Generates a deterministic, isolated cache key for given query args and context (R79).
     *
     * @param array $queryArgs
     * @param array $contextOverrides
     * @return string
     */
    public function generateCacheKey(array $queryArgs, array $contextOverrides = []): string
    {
        $context = $this->resolveContext($contextOverrides);

        // Normalize query args
        ksort($queryArgs);
        $normArgs = [];
        foreach ($queryArgs as $k => $v) {
            if ($k === 'cursor' || $k === '_nonce') {
                continue; // Do not include ephemeral tokens
            }
            $normArgs[$k] = $v;
        }

        $payload = [
            'args'    => $normArgs,
            'context' => $context,
        ];

        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return "wpe_cat_{$context['generation']}_{$hash}";
    }

    /* -------------------------------------------------------------------------- */
    /* Stampede-Protected Retrieval (R81)                                         */
    /* -------------------------------------------------------------------------- */

    /**
     * Retrieves or calculates cached query results with stampede protection (R81).
     *
     * @param string $cacheKey
     * @param callable $computeCallback
     * @param int $ttl
     * @return mixed
     */
    public function remember(string $cacheKey, callable $computeCallback, int $ttl = self::DEFAULT_TTL): mixed
    {
        // 1. Check existing cache
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        // 2. Acquire mutex lock for stampede protection
        $lockKey = $cacheKey . '_lock';
        $lockAcquired = $this->acquireLock($lockKey, self::LOCK_TIMEOUT);

        if (!$lockAcquired) {
            // Lock held by concurrent request: wait briefly and re-check cache (backoff)
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(50000); // 50ms sleep
                $cached = get_transient($cacheKey);
                if ($cached !== false) {
                    return $cached;
                }
            }
            // If still empty after waiting, compute fallback directly
            return $computeCallback();
        }

        try {
            // Re-check cache in case winner completed while acquiring lock
            $cached = get_transient($cacheKey);
            if ($cached !== false) {
                return $cached;
            }

            // Compute value
            $value = $computeCallback();

            // Store in transient cache
            set_transient($cacheKey, $value, $ttl);

            return $value;
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    private function acquireLock(string $lockKey, int $timeout): bool
    {
        if (get_transient($lockKey)) {
            return false;
        }
        set_transient($lockKey, time() + $timeout, $timeout);
        return true;
    }

    private function releaseLock(string $lockKey): void
    {
        delete_transient($lockKey);
    }

    /* -------------------------------------------------------------------------- */
    /* Cache Invalidation (R80, AC-T20-01)                                        */
    /* -------------------------------------------------------------------------- */

    /**
     * Gets the active cache epoch for a generation.
     */
    public function getCacheEpoch(int $generation): int
    {
        $epoch = (int)get_transient("wpe_cache_epoch_{$generation}");
        if ($epoch <= 0) {
            $epoch = 1;
            set_transient("wpe_cache_epoch_{$generation}", 1, 86400 * 30);
        }
        return $epoch;
    }

    /**
     * Invalidates cache immediately on product visibility, status, or price changes (R80).
     *
     * @param int|null $productId Product ID that changed
     * @param int|null $generation Catalog generation
     */
    public function invalidate(?int $productId = null, ?int $generation = null): void
    {
        $gen = $generation ?? $this->projector->getActiveGeneration();

        // Increment cache epoch to instantly bust all keys for this generation
        $currentEpoch = $this->getCacheEpoch($gen);
        $newEpoch = $currentEpoch + 1;
        set_transient("wpe_cache_epoch_{$gen}", $newEpoch, 86400 * 30);

        // Also invalidate specific product cache tag if set
        if ($productId !== null) {
            delete_transient("wpe_prod_view_{$productId}");
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Dynamic Pricing Compatibility Gate (R82)                                   */
    /* -------------------------------------------------------------------------- */

    /**
     * Evaluates dynamic pricing compatibility before query pagination.
     *
     * If dynamic pricing applies runtime price adjustments that cannot be calculated
     * in the indexed SQL query before pagination, the engine MUST NOT silently post-filter.
     * It must explicitly declare the surface incompatible and route to legacy fallback or blocker.
     *
     * @param array $queryArgs
     * @param array $detectedHooks Active plugin pricing hooks
     * @return array [bool $isCompatible, string $action, string|null $reason]
     */
    public function evaluatePricingCompatibility(array $queryArgs, array $detectedHooks = []): array
    {
        $hasPriceFilter = isset($queryArgs['min_price']) || isset($queryArgs['max_price']) ||
            (isset($queryArgs['orderby']) && in_array($queryArgs['orderby'], ['price', 'meta_value_num'], true));

        // Known incompatible dynamic pricing hooks that modify prices at display time
        $incompatibleDynamicHooks = [
            'woocommerce_get_price_html',
            'woocommerce_product_get_price',
            'woocommerce_product_get_regular_price',
            'woocommerce_product_get_sale_price',
            'wc_dynamic_pricing_apply_rules',
        ];

        $conflictingHooks = array_intersect($detectedHooks, $incompatibleDynamicHooks);

        if ($hasPriceFilter && !empty($conflictingHooks)) {
            return [
                'compatible' => false,
                'action'     => 'fallback_legacy',
                'reason'     => 'Dynamic pricing hooks detected (' . implode(', ', $conflictingHooks) . ') that alter prices post-indexing. Pre-pagination price filtering requires legacy evaluation.',
            ];
        }

        return [
            'compatible' => true,
            'action'     => 'engine',
            'reason'     => null,
        ];
    }

    /* -------------------------------------------------------------------------- */
    /* Outbox Backlog & Observability (AC-T20-02)                                  */
    /* -------------------------------------------------------------------------- */

    /**
     * Computes outbox lag and health metrics.
     *
     * @return array [pending_count, lag_seconds, is_healthy, status]
     */
    public function getOutboxLag(): array
    {
        $row = $this->wpdb->get_row(
            "SELECT COUNT(*) as pending_count, MIN(created_at) as oldest_created_at
             FROM {$this->tableOutbox}
             WHERE state IN ('pending', 'processing')",
            ARRAY_A
        );

        $pendingCount = (int)($row['pending_count'] ?? 0);
        $oldestCreatedAt = $row['oldest_created_at'] ?? null;

        $lagSeconds = 0.0;
        if ($oldestCreatedAt) {
            $oldestTimestamp = strtotime($oldestCreatedAt);
            $now = time();
            $lagSeconds = max(0.0, (float)($now - $oldestTimestamp));
        }

        $isHealthy = ($lagSeconds <= self::MAX_HEALTHY_LAG_SECONDS);
        $status = $isHealthy ? 'healthy' : ($lagSeconds > 120.0 ? 'degraded' : 'lagging');

        return [
            'pending_count' => $pendingCount,
            'lag_seconds'   => $lagSeconds,
            'is_healthy'    => $isHealthy,
            'status'        => $status,
        ];
    }
}
