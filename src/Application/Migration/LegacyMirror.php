<?php

declare(strict_types=1);

namespace WPE\Application\Migration;

use InvalidArgumentException;
use RuntimeException;
use wpdb;
use WPE\Infrastructure\Database\ProductRepository;

/**
 * LegacyMirror
 *
 * Implements:
 * - Native-to-legacy projection writer projecting CPT, postmeta, taxonomy, and variants (R157, AC-T39-01).
 * - Out-of-order and restart safety: skips obsolete revisions (R158, AC-T39-01).
 * - Write ownership fence: dual-writer attempts under native authority are rejected or routed (R159).
 * - Origin guards preventing recursive projection loops and duplicate business side-effects (R160).
 * - Epoch fence preventing stale workers after authority switches (AC-T39-02).
 * - Projection lag metrics and dashboard reporting.
 */
class LegacyMirror
{
    private static ?LegacyMirror $instance = null;

    private wpdb $wpdb;
    private LegacyCapture $legacyCapture;
    private ProductRepository $productRepo;

    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableObjectState;
    private string $tableMeta;
    private string $tableProductTerms;
    private string $tableStockOperations;
    private string $tableAudit;
    private string $tableControl;

    public function __construct(
        ?wpdb $wpdb = null,
        ?LegacyCapture $legacyCapture = null,
        ?ProductRepository $productRepo = null
    ) {
        global $wpdb;
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $prefix = $this->wpdb->prefix;

        $this->tableProducts        = "{$prefix}wpe_products";
        $this->tableVariants        = "{$prefix}wpe_variants";
        $this->tableInventory       = "{$prefix}wpe_inventory";
        $this->tableObjectState     = "{$prefix}wpe_object_state";
        $this->tableMeta            = "{$prefix}wpe_meta";
        $this->tableProductTerms    = "{$prefix}wpe_product_terms";
        $this->tableStockOperations = "{$prefix}wpe_stock_operations";
        $this->tableAudit           = "{$prefix}wpe_audit";
        $this->tableControl         = "{$prefix}wpe_control";

        $this->legacyCapture = $legacyCapture ?? LegacyCapture::getInstance();
        $this->productRepo   = $productRepo ?? new ProductRepository($this->wpdb);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Project native aggregate state into legacy WordPress tables (R157, R160, AC-T39-01).
     *
     * @param int $aggregateId Parent product ID
     * @param int $sourceRevision Current native revision
     * @param int $workerEpoch Epoch claimed by the projecting worker
     * @param array $options Additional projection options
     * @return array Result summary
     */
    public function mirrorAggregate(int $aggregateId, int $sourceRevision, int $workerEpoch, array $options = []): array
    {
        // 1. Epoch fence check (AC-T39-02)
        $state = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableObjectState} WHERE object_id = %d",
            $aggregateId
        ), ARRAY_A);

        $currentEpoch = $state ? (int)$state['epoch'] : $this->getGlobalEpoch();
        if ($workerEpoch < $currentEpoch) {
            $this->logAuditAlert('epoch_fence_rejected', $aggregateId, [
                'worker_epoch'  => $workerEpoch,
                'current_epoch' => $currentEpoch,
                'message'       => 'Stale worker execution rejected by epoch fence',
            ]);

            return [
                'status'        => 'rejected_stale_epoch',
                'aggregate_id'  => $aggregateId,
                'worker_epoch'  => $workerEpoch,
                'current_epoch' => $currentEpoch,
            ];
        }

        // 2. Out-of-order revision check (R158, AC-T39-01)
        $nativeApplied = $state ? (int)$state['native_applied'] : 0;
        if ($sourceRevision <= $nativeApplied) {
            return [
                'status'           => 'skipped_obsolete_revision',
                'aggregate_id'     => $aggregateId,
                'source_revision'  => $sourceRevision,
                'native_applied'   => $nativeApplied,
            ];
        }

        // 3. Load full native product aggregate (R157)
        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableProducts} WHERE id = %d",
            $aggregateId
        ), ARRAY_A);

        if (!$product) {
            return [
                'status'       => 'product_not_found',
                'aggregate_id' => $aggregateId,
            ];
        }

        $inventory = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
            $aggregateId
        ), ARRAY_A);

        $variants = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY id ASC",
            $aggregateId
        ), ARRAY_A);

        $terms = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT term_taxonomy_id, role FROM {$this->tableProductTerms} WHERE product_id = %d",
            $aggregateId
        ), ARRAY_A);

        $metaRows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->tableMeta} WHERE object_id = %d",
            $aggregateId
        ), ARRAY_A);

        // 4. Execute legacy write strictly inside origin guard (R160)
        LegacyCapture::withOrigin('native_projection', function () use ($aggregateId, $product, $inventory, $variants, $terms, $metaRows) {
            $now = current_time('mysql');
            $nowGmt = gmdate('Y-m-d H:i:s');

            $postType = !empty($variants) ? 'product' : 'product';
            $postStatus = $product['status'] ?? 'publish';

            // Check if post shell exists in wp_posts
            $existingPost = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT ID FROM {$this->wpdb->posts} WHERE ID = %d",
                $aggregateId
            ));

            if ($existingPost) {
                $this->wpdb->update($this->wpdb->posts, [
                    'post_title'        => $product['title'],
                    'post_name'         => $product['slug'],
                    'post_content'      => $product['description'] ?? '',
                    'post_excerpt'      => $product['short_description'] ?? '',
                    'post_status'       => $postStatus,
                    'post_modified'     => $now,
                    'post_modified_gmt' => $nowGmt,
                ], ['ID' => $aggregateId]);
            } else {
                $this->wpdb->insert($this->wpdb->posts, [
                    'ID'                => $aggregateId,
                    'post_author'       => 1,
                    'post_date'         => $now,
                    'post_date_gmt'     => $nowGmt,
                    'post_title'        => $product['title'],
                    'post_name'         => $product['slug'],
                    'post_content'      => $product['description'] ?? '',
                    'post_excerpt'      => $product['short_description'] ?? '',
                    'post_status'       => $postStatus,
                    'post_type'         => 'product',
                    'post_modified'     => $now,
                    'post_modified_gmt' => $nowGmt,
                ]);
            }

            // Project core product metadata
            $regularPrice = $product['regular_price'] !== null ? (string)(float)$product['regular_price'] : '';
            $salePrice    = $product['sale_price'] !== null ? (string)(float)$product['sale_price'] : '';
            $price        = ($salePrice !== '') ? $salePrice : $regularPrice;

            $metaToUpdate = [
                '_sku'           => $product['sku'] ?? '',
                '_regular_price' => $regularPrice,
                '_sale_price'    => $salePrice,
                '_price'         => $price,
                '_weight'        => $product['weight'] !== null ? (string)$product['weight'] : '',
            ];

            if ($inventory) {
                $metaToUpdate['_manage_stock'] = $inventory['manage_stock'] ? 'yes' : 'no';
                $metaToUpdate['_stock']        = $inventory['quantity'] !== null ? (string)(float)$inventory['quantity'] : '';
                $metaToUpdate['_stock_status'] = $inventory['stock_status'] ?? 'instock';
                $metaToUpdate['_backorders']   = $inventory['backorders'] ?? 'no';
            }

            foreach ($metaToUpdate as $mKey => $mVal) {
                $this->updatePostMetaDirect($aggregateId, $mKey, (string)$mVal);
            }

            // Project featured and gallery images
            $featuredImg = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT a.attachment_id FROM {$this->wpdb->prefix}wpe_product_media pm
                 JOIN {$this->wpdb->prefix}wpe_assets a ON pm.asset_id = a.id
                 WHERE pm.product_id = %d AND pm.role = 'featured'
                 LIMIT 1",
                $aggregateId
            ), ARRAY_A);
            if ($featuredImg && !empty($featuredImg['attachment_id'])) {
                $this->updatePostMetaDirect($aggregateId, '_thumbnail_id', (string)$featuredImg['attachment_id']);
            }

            $galleryImgs = $this->wpdb->get_col($this->wpdb->prepare(
                "SELECT a.attachment_id FROM {$this->wpdb->prefix}wpe_product_media pm
                 JOIN {$this->wpdb->prefix}wpe_assets a ON pm.asset_id = a.id
                 WHERE pm.product_id = %d AND pm.role = 'gallery'
                 ORDER BY pm.position ASC",
                $aggregateId
            ));
            if (!empty($galleryImgs)) {
                $galleryClean = array_filter($galleryImgs);
                if (!empty($galleryClean)) {
                    $this->updatePostMetaDirect($aggregateId, '_product_image_gallery', implode(',', $galleryClean));
                }
            }

            // Project attributes
            $options = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, name, position, visible, used_for_variation, taxonomy
                 FROM {$this->wpdb->prefix}wpe_options
                 WHERE product_id = %d
                 ORDER BY position ASC",
                $aggregateId
            ), ARRAY_A);

            if (!empty($options)) {
                $productAttributes = [];
                foreach ($options as $opt) {
                    $vals = $this->wpdb->get_col($this->wpdb->prepare(
                        "SELECT label FROM {$this->wpdb->prefix}wpe_option_values
                         WHERE option_id = %d
                         ORDER BY position ASC",
                        $opt['id']
                    ));
                    $attrKey = sanitize_title($opt['name']);
                    $productAttributes[$attrKey] = [
                        'name'         => $opt['name'],
                        'value'        => implode(' | ', $vals),
                        'position'     => (int)$opt['position'],
                        'is_visible'   => (int)$opt['visible'],
                        'is_variation' => (int)$opt['used_for_variation'],
                        'is_taxonomy'  => !empty($opt['taxonomy']) ? 1 : 0,
                    ];
                }
                $this->updatePostMetaDirect($aggregateId, '_product_attributes', serialize($productAttributes));
            }

            // Project product_type taxonomy relationship (variable vs simple)
            $targetType = !empty($product['type']) ? $product['type'] : (!empty($variants) ? 'variable' : 'simple');
            $termRow = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT tt.term_taxonomy_id FROM {$this->wpdb->terms} t
                 JOIN {$this->wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                 WHERE t.slug = %s AND tt.taxonomy = 'product_type' LIMIT 1",
                $targetType
            ), ARRAY_A);
            if ($termRow) {
                $allTypeTermTaxIds = $this->wpdb->get_col(
                    "SELECT term_taxonomy_id FROM {$this->wpdb->term_taxonomy} WHERE taxonomy = 'product_type'"
                );
                if (!empty($allTypeTermTaxIds)) {
                    $idList = implode(',', array_map('intval', $allTypeTermTaxIds));
                    $this->wpdb->query($this->wpdb->prepare(
                        "DELETE FROM {$this->wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id IN ({$idList})",
                        $aggregateId
                    ));
                }
                $this->wpdb->replace($this->wpdb->term_relationships, [
                    'object_id'        => $aggregateId,
                    'term_taxonomy_id' => (int)$termRow['term_taxonomy_id'],
                    'term_order'       => 0,
                ]);
                if (function_exists('clean_object_term_cache')) {
                    clean_object_term_cache($aggregateId, 'product_type');
                }
            }

            // Calculate variable product price ranges
            if (!empty($variants)) {
                $prices = [];
                $regPrices = [];
                foreach ($variants as $v) {
                    $vReg  = $v['regular_price'] !== null ? (float)$v['regular_price'] : null;
                    $vSale = $v['sale_price'] !== null ? (float)$v['sale_price'] : null;
                    $vP    = ($vSale !== null && $vSale > 0) ? $vSale : $vReg;
                    if ($vP !== null) {
                        $prices[] = $vP;
                    }
                    if ($vReg !== null) {
                        $regPrices[] = $vReg;
                    }
                }
                if (!empty($prices)) {
                    $minP = min($prices);
                    $maxP = max($prices);
                    $this->updatePostMetaDirect($aggregateId, '_price', (string)$minP);
                    $this->updatePostMetaDirect($aggregateId, '_min_variation_price', (string)$minP);
                    $this->updatePostMetaDirect($aggregateId, '_max_variation_price', (string)$maxP);
                }
                if (!empty($regPrices)) {
                    $this->updatePostMetaDirect($aggregateId, '_min_variation_regular_price', (string)min($regPrices));
                    $this->updatePostMetaDirect($aggregateId, '_max_variation_regular_price', (string)max($regPrices));
                }
            }

            // Project extension / arbitrary metadata from wpe_meta
            if (!empty($metaRows)) {
                foreach ($metaRows as $mr) {
                    $this->updatePostMetaDirect($aggregateId, $mr['meta_key'], $mr['meta_value']);
                }
            }

            // Project taxonomy terms
            if (!empty($terms)) {
                $this->wpdb->delete($this->wpdb->term_relationships, ['object_id' => $aggregateId]);
                foreach ($terms as $t) {
                    $this->wpdb->replace($this->wpdb->term_relationships, [
                        'object_id'        => $aggregateId,
                        'term_taxonomy_id' => (int)$t['term_taxonomy_id'],
                        'term_order'       => 0,
                    ]);
                }
            }

            // Project variations
            if (!empty($variants)) {
                foreach ($variants as $v) {
                    $varId = (int)$v['id'];
                    $vReg  = $v['regular_price'] !== null ? (string)(float)$v['regular_price'] : '';
                    $vSale = $v['sale_price'] !== null ? (string)(float)$v['sale_price'] : '';
                    $vPrice = ($vSale !== '') ? $vSale : $vReg;

                    $vExists = $this->wpdb->get_var($this->wpdb->prepare(
                        "SELECT ID FROM {$this->wpdb->posts} WHERE ID = %d",
                        $varId
                    ));

                    if ($vExists) {
                        $this->wpdb->update($this->wpdb->posts, [
                            'post_title'        => "Variation for {$product['title']}",
                            'post_status'       => $v['status'] ?? 'publish',
                            'post_parent'       => $aggregateId,
                            'post_modified'     => $now,
                            'post_modified_gmt' => $nowGmt,
                        ], ['ID' => $varId]);
                    } else {
                        $this->wpdb->insert($this->wpdb->posts, [
                            'ID'                => $varId,
                            'post_author'       => 1,
                            'post_date'         => $now,
                            'post_date_gmt'     => $nowGmt,
                            'post_title'        => "Variation for {$product['title']}",
                            'post_status'       => $v['status'] ?? 'publish',
                            'post_type'         => 'product_variation',
                            'post_parent'       => $aggregateId,
                            'post_modified'     => $now,
                            'post_modified_gmt' => $nowGmt,
                        ]);
                    }

                    $this->updatePostMetaDirect($varId, '_sku', $v['sku'] ?? '');
                    $this->updatePostMetaDirect($varId, '_regular_price', $vReg);
                    $this->updatePostMetaDirect($varId, '_sale_price', $vSale);
                    $this->updatePostMetaDirect($varId, '_price', $vPrice);

                    // Variation inventory
                    $vInv = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT * FROM {$this->tableInventory} WHERE owner_id = %d",
                        $varId
                    ), ARRAY_A);
                    if ($vInv) {
                        $this->updatePostMetaDirect($varId, '_manage_stock', $vInv['manage_stock'] ? 'yes' : 'no');
                        $this->updatePostMetaDirect($varId, '_stock', $vInv['quantity'] !== null ? (string)(float)$vInv['quantity'] : '');
                        $this->updatePostMetaDirect($varId, '_stock_status', $vInv['stock_status'] ?? 'instock');
                    }

                    // Variation attributes
                    $vValues = $this->wpdb->get_results($this->wpdb->prepare(
                        "SELECT o.name, ov.label
                         FROM {$this->wpdb->prefix}wpe_variant_values vv
                         JOIN {$this->wpdb->prefix}wpe_options o ON vv.option_id = o.id
                         JOIN {$this->wpdb->prefix}wpe_option_values ov ON vv.value_id = ov.id
                         WHERE vv.variant_id = %d",
                        $varId
                    ), ARRAY_A);

                    foreach ($vValues as $vv) {
                        $attrKey = 'attribute_' . sanitize_title($vv['name']);
                        $this->updatePostMetaDirect($varId, $attrKey, $vv['label']);
                    }

                    // Variation image
                    $vImg = $this->wpdb->get_row($this->wpdb->prepare(
                        "SELECT a.attachment_id FROM {$this->wpdb->prefix}wpe_variant_media vm
                         JOIN {$this->wpdb->prefix}wpe_assets a ON vm.asset_id = a.id
                         WHERE vm.variant_id = %d AND vm.is_primary = 1
                         LIMIT 1",
                        $varId
                    ), ARRAY_A);
                    if ($vImg && !empty($vImg['attachment_id'])) {
                        $this->updatePostMetaDirect($varId, '_thumbnail_id', (string)$vImg['attachment_id']);
                    }
                }
            }

            clean_post_cache($aggregateId);
            wp_cache_delete($aggregateId, 'post_meta');
            if (!empty($variants)) {
                foreach ($variants as $v) {
                    $vId = (int)$v['id'];
                    clean_post_cache($vId);
                    wp_cache_delete($vId, 'post_meta');
                }
            }
        });

        // 5. Update object state: record native_applied revision and updated legacy_hash
        $newLegacyHash = $this->legacyCapture->computeLegacyHash($aggregateId);
        $nowStamp = gmdate('Y-m-d H:i:s.u');

        $this->wpdb->update($this->tableObjectState, [
            'native_applied' => $sourceRevision,
            'legacy_applied' => $sourceRevision,
            'legacy_hash'    => hex2bin($newLegacyHash),
            'updated_at'     => $nowStamp,
        ], ['object_id' => $aggregateId]);

        return [
            'status'           => 'mirrored',
            'aggregate_id'     => $aggregateId,
            'applied_revision' => $sourceRevision,
            'epoch'            => $workerEpoch,
            'legacy_hash'      => $newLegacyHash,
        ];
    }

    /**
     * Intercept and validate a legacy write attempt against native write authority (R159).
     *
     * @param int $aggregateId Product or variation ID
     * @param string $metaKey Metadata key or property being written
     * @param mixed $value New value
     * @param int $workerEpoch Claimed epoch
     * @param bool $isCertifiedPlugin Whether caller is a certified adapter
     * @return array Status of write interception
     */
    public function interceptLegacyWrite(
        int $aggregateId,
        string $metaKey,
        $value,
        int $workerEpoch,
        bool $isCertifiedPlugin = false
    ): array {
        // 1. Check epoch fence (AC-T39-02)
        $currentEpoch = $this->getAggregateEpoch($aggregateId);
        if ($workerEpoch < $currentEpoch) {
            $this->logAuditAlert('dual_writer_rejected', $aggregateId, [
                'reason'        => 'stale_epoch',
                'meta_key'      => $metaKey,
                'worker_epoch'  => $workerEpoch,
                'current_epoch' => $currentEpoch,
            ]);

            return [
                'status'  => 'rejected',
                'reason'  => 'stale_epoch',
                'blocked' => true,
            ];
        }

        // 2. Check authority: if native, reject unauthorized dual-writers (R159)
        $authority = $this->getAggregateAuthority($aggregateId);
        if ($authority === 'native') {
            if (!$isCertifiedPlugin) {
                $this->logAuditAlert('dual_writer_rejected', $aggregateId, [
                    'reason'   => 'unauthorized_legacy_write_under_native_authority',
                    'meta_key' => $metaKey,
                    'value'    => is_scalar($value) ? (string)$value : json_encode($value),
                ]);

                return [
                    'status'  => 'rejected',
                    'reason'  => 'unauthorized_dual_writer',
                    'blocked' => true,
                ];
            }

            // Certified plugin write routed to native storage
            $existingMetaId = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT meta_id FROM {$this->tableMeta} WHERE object_id = %d AND meta_key = %s LIMIT 1",
                $aggregateId,
                $metaKey
            ));

            $metaVal = is_scalar($value) ? (string)$value : maybe_serialize($value);

            if ($existingMetaId) {
                $this->wpdb->update($this->tableMeta, [
                    'meta_value' => $metaVal,
                ], ['meta_id' => $existingMetaId]);
            } else {
                $maxMetaId = (int)$this->wpdb->get_var("SELECT COALESCE(MAX(meta_id), 0) FROM {$this->tableMeta}");
                $maxPostmetaId = (int)$this->wpdb->get_var("SELECT COALESCE(MAX(meta_id), 0) FROM {$this->wpdb->postmeta}");
                $newMetaId = max($maxMetaId, $maxPostmetaId) + 1;

                $this->wpdb->insert($this->tableMeta, [
                    'meta_id'     => $newMetaId,
                    'object_id'   => $aggregateId,
                    'object_kind' => 'product',
                    'meta_key'    => $metaKey,
                    'meta_value'  => $metaVal,
                ]);
            }

            return [
                'status'  => 'routed_native',
                'blocked' => false,
            ];
        }

        return [
            'status'  => 'allowed_legacy',
            'blocked' => false,
        ];
    }

    /**
     * Get projection lag metrics and dashboard health (AC-T39-01).
     */
    public function getProjectionLag(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT object_id, native_revision, native_applied, updated_at\n" .
            "FROM {$this->tableObjectState}\n" .
            "WHERE authority = 'native' AND native_revision > native_applied",
            ARRAY_A
        );

        $pendingCount = count($rows);
        $maxLagSec = 0.0;
        $now = time();

        foreach ($rows as $r) {
            $updatedTime = strtotime($r['updated_at']);
            if ($updatedTime > 0) {
                $lag = max(0, $now - $updatedTime);
                if ($lag > $maxLagSec) {
                    $maxLagSec = (float)$lag;
                }
            }
        }

        $totalMirrored = (int)$this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->tableObjectState}\n" .
            "WHERE authority = 'native' AND native_applied >= native_revision"
        );

        return [
            'pending_projections' => $pendingCount,
            'max_lag_seconds'     => round($maxLagSec, 2),
            'total_mirrored'      => $totalMirrored,
            'status'              => ($maxLagSec < 30.0 && $pendingCount < 50) ? 'healthy' : 'lagging',
        ];
    }

    /**
     * Switch authority epoch for an aggregate (AC-T39-02).
     */
    public function switchAuthorityEpoch(int $aggregateId, string $newAuthority, int $newEpoch): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $this->wpdb->update($this->tableObjectState, [
            'authority'  => $newAuthority,
            'epoch'      => $newEpoch,
            'updated_at' => $now,
        ], ['object_id' => $aggregateId]);

        $this->setGlobalEpoch($newEpoch);
    }

    public function getAggregateEpoch(int $aggregateId): int
    {
        $epoch = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT epoch FROM {$this->tableObjectState} WHERE object_id = %d",
            $aggregateId
        ));
        return $epoch !== null ? (int)$epoch : $this->getGlobalEpoch();
    }

    public function getAggregateAuthority(int $aggregateId): string
    {
        $auth = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT authority FROM {$this->tableObjectState} WHERE object_id = %d",
            $aggregateId
        ));
        return $auth ?: 'legacy';
    }

    public function getGlobalEpoch(): int
    {
        $epoch = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT value FROM {$this->tableControl} WHERE `key` = %s",
            'authority_epoch'
        ));
        return $epoch !== null ? (int)$epoch : 1;
    }

    public function setGlobalEpoch(int $epoch): void
    {
        $this->wpdb->replace($this->tableControl, [
            'key'        => 'authority_epoch',
            'value'      => (string)$epoch,
            'version'    => 1,
            'updated_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }

    private function updatePostMetaDirect(int $postId, string $key, string $value): void
    {
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta_id FROM {$this->wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $postId,
            $key
        ));

        if ($existing) {
            $this->wpdb->update($this->wpdb->postmeta, [
                'meta_value' => $value,
            ], ['meta_id' => $existing]);
        } else {
            $this->wpdb->insert($this->wpdb->postmeta, [
                'post_id'    => $postId,
                'meta_key'   => $key,
                'meta_value' => $value,
            ]);
        }

        wp_cache_delete($postId, 'post_meta');
    }

    public function logAuditAlert(string $operation, int $targetId, array $payload): void
    {
        $this->wpdb->insert($this->tableAudit, [
            'actor_id'   => get_current_user_id() ?: null,
            'request_id' => wp_generate_uuid4(),
            'operation'  => $operation,
            'target_id'  => $targetId,
            'summary'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s.u'),
        ]);
    }
}
