<?php

declare(strict_types=1);

namespace WPE\Admin\ProductEditor;

use wpdb;
use WPE\Application\Variant\EffectivePriceResolver;
use WPE\Compatibility\WooDataStore\ProductLifecycle;
use WPE\Infrastructure\Database\OptionsRepository;

/**
 * Derived product type and the previewed Simple/Variable transitions of the two-block editor.
 *
 * The type is not chosen by the user. It is derived from the options of the draft: no variation-forming option is
 * Simple, one or more is Variable (descriptive attributes never count). A type changes only on a successful save,
 * never on load, and only when the request carries `variation_options`.
 *
 * Variable to Simple: the user picks the variation that becomes the product (its price and schedule, SKU, weight,
 * dimensions, own stock and image). Stock is never summed. Every variation is then tombstoned through the lifecycle
 * (restorable, order references kept) and its SKU registry row released; with more than one variation this needs the
 * receiver and an explicit confirmation, otherwise the plan is rejected with a preview and nothing is written.
 *
 * Simple to Variable: the simple price is proposed as the common price, SKU and stock stay on the product (stock
 * becomes the shared stock new variations inherit). The type flips only when the same request creates variations;
 * without variations the product stays Simple and the configuration is reported as incomplete.
 *
 * The lifecycle core is reused: `apply()` runs inside the caller's transaction (ProductLifecycle::applyTypeChange
 * does not open one) and `complete()` runs after the commit.
 */
final class ProductTypeTransition
{
    private wpdb $wpdb;
    private OptionsRepository $optionsRepo;
    private ?ProductLifecycle $lifecycle;
    private PricingService $pricing;
    private string $tableProducts;
    private string $tableVariants;
    private string $tableInventory;
    private string $tableSkuRegistry;
    private string $tableProductMedia;
    private string $tableVariantMedia;

    public function __construct(?wpdb $wpdb = null, ?OptionsRepository $optionsRepo = null, ?ProductLifecycle $lifecycle = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->optionsRepo = $optionsRepo ?? new OptionsRepository($this->wpdb);
        $this->lifecycle = $lifecycle;
        $this->pricing = new PricingService($this->wpdb, $this->optionsRepo);
        $prefix = $this->wpdb->prefix;
        $this->tableProducts = "{$prefix}wpe_products";
        $this->tableVariants = "{$prefix}wpe_variants";
        $this->tableInventory = "{$prefix}wpe_inventory";
        $this->tableSkuRegistry = "{$prefix}wpe_sku_registry";
        $this->tableProductMedia = "{$prefix}wpe_product_media";
        $this->tableVariantMedia = "{$prefix}wpe_variant_media";
    }

    /* ---------------------------------------------------------------------- */
    /* Derivation                                                             */
    /* ---------------------------------------------------------------------- */

    /**
     * Type implied by a list of variation-forming options: `variable` when at least one has a name.
     */
    public static function deriveType(?array $variationOptions): string
    {
        foreach ((array)$variationOptions as $opt) {
            if (is_array($opt) && trim((string)($opt['name'] ?? '')) !== '') {
                return 'variable';
            }
        }
        return 'simple';
    }

    /**
     * Derived type and configuration state of a draft.
     *
     * `state` is `simple`, `variable`, or `incomplete` (variation options exist but no variation does, so the
     * product is not purchasable as variable yet). When the request carries no option list the saved options count.
     *
     * @return array{derived:string,state:string,variation_count:int}
     */
    public function derive(int $productId, array $payload): array
    {
        if (isset($payload['variation_options']) && is_array($payload['variation_options'])) {
            $derived = self::deriveType($payload['variation_options']);
        } else {
            $saved = (int)$this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(1) FROM " . $this->wpdb->prefix . "wpe_options WHERE product_id = %d AND used_for_variation = 1",
                $productId
            ));
            $derived = $saved > 0 ? 'variable' : 'simple';
        }

        $count = (isset($payload['variations']) && is_array($payload['variations']))
            ? count($payload['variations'])
            : count($this->existingVariations($productId));

        $state = $derived === 'simple' ? 'simple' : ($count > 0 ? 'variable' : 'incomplete');
        return ['derived' => $derived, 'state' => $state, 'variation_count' => $count];
    }

    /* ---------------------------------------------------------------------- */
    /* Preview                                                                */
    /* ---------------------------------------------------------------------- */

    /**
     * What a transition to `$target` would do. Reads only.
     *
     * @return array<string,mixed>
     */
    public function preview(int $productId, string $target): array
    {
        $product = $this->productRow($productId);
        $from = (string)$product['type'];

        if ($from === $target) {
            return ['from' => $from, 'to' => $target, 'changed' => false];
        }

        if ($from === 'variable' && $target === 'simple') {
            $rows = $this->existingVariations($productId);
            $variations = [];
            foreach ($rows as $id => $row) {
                $price = EffectivePriceResolver::resolve($row);
                $inv = $this->inventoryRow($id);
                $variations[] = [
                    'id'           => $id,
                    'label'        => $this->pricing->labelFor($productId, $id),
                    'sku'          => (string)($row['sku'] ?? ''),
                    'status'       => (string)$row['status'],
                    'regular'      => $price['regular'],
                    'sale'         => $price['sale'],
                    'active_price' => $price['active_price'],
                    'priced'       => $price['state'] === EffectivePriceResolver::STATE_PRICED,
                    'manage_stock' => !empty($inv['manage_stock']),
                    'quantity'     => ($inv && $inv['quantity'] !== null) ? (float)$inv['quantity'] : null,
                    'image_asset_id' => $this->primaryImage($id),
                ];
            }
            $count = count($variations);
            return [
                'from'                  => $from,
                'to'                    => $target,
                'changed'               => true,
                'variations'            => $variations,
                'receiver_required'     => $count > 1,
                'proposed_receiver_id'  => $count > 0 ? $variations[0]['id'] : null,
                'requires_confirmation' => $count > 1,
                'will_tombstone'        => array_column($variations, 'id'),
                'receiver_supplies'     => ['price_and_schedule', 'sku', 'weight', 'dimensions', 'own_stock', 'image'],
                'stock_policy'          => 'receiver_only',
                'restorable'            => true,
            ];
        }

        if ($from === 'simple' && $target === 'variable') {
            return [
                'from'                  => $from,
                'to'                    => $target,
                'changed'               => true,
                'common_price_proposal' => $this->commonProposal($product),
                'keeps_on_product'      => ['sku', 'stock'],
                'requires_variations'   => true,
                'stock_policy'          => 'shared_product_stock',
            ];
        }

        throw new EditorValidationException('validation_failed', "Unsupported type change {$from} to {$target}.", [['pointer' => '/type', 'reason' => 'unsupported_transition']]);
    }

    /* ---------------------------------------------------------------------- */
    /* Plan                                                                   */
    /* ---------------------------------------------------------------------- */

    /**
     * Decide the type change a save request implies, validating the user decisions it needs.
     *
     * Nothing is written. Request keys used: `variation_options`, `variations` and, for Variable to Simple with
     * several variations, `type_transition` = {receiver_id, confirm_tombstone}.
     *
     * @return array{from:string,to:string,changed:bool,state:string,receiver_id:?int,tombstone_ids:int[],common_price_patch:?array}
     * @throws EditorValidationException
     */
    public function plan(int $productId, array $productRow, array $payload): array
    {
        $from = (string)$productRow['type'];
        $none = ['from' => $from, 'to' => $from, 'changed' => false, 'state' => $from, 'receiver_id' => null, 'tombstone_ids' => [], 'common_price_patch' => null];

        $supported = in_array($from, ['simple', 'variable'], true);
        if (!$supported || !isset($payload['variation_options']) || !is_array($payload['variation_options'])) {
            return $none;
        }

        $derivation = $this->derive($productId, $payload);
        $derived = $derivation['derived'];

        if ($derived === $from) {
            $none['state'] = $from === 'variable' && $derivation['state'] === 'incomplete' ? 'incomplete' : $from;
            return $none;
        }

        if ($from === 'simple' && $derived === 'variable') {
            if ($derivation['variation_count'] < 1) {
                // No variation is created in this request: the product stays Simple.
                $none['state'] = 'incomplete';
                return $none;
            }
            return [
                'from' => 'simple', 'to' => 'variable', 'changed' => true, 'state' => 'variable',
                'receiver_id' => null, 'tombstone_ids' => [],
                'common_price_patch' => $this->commonProposal($productRow),
            ];
        }

        // Variable to Simple
        if (!empty($payload['variations']) && is_array($payload['variations'])) {
            throw new EditorValidationException('validation_failed', 'Variations need at least one variation option.', [['pointer' => '/variations', 'reason' => 'variations_without_options']]);
        }

        $existing = $this->existingVariations($productId);
        $ids = array_keys($existing);
        $receiver = null;
        $errors = [];

        if (count($ids) === 1) {
            $receiver = $ids[0];
        } elseif (count($ids) > 1) {
            $decision = isset($payload['type_transition']) && is_array($payload['type_transition']) ? $payload['type_transition'] : [];
            if (!isset($decision['receiver_id'])) {
                $errors[] = ['pointer' => '/type_transition/receiver_id', 'reason' => 'receiver_required'];
            } elseif (!in_array((int)$decision['receiver_id'], $ids, true)) {
                $errors[] = ['pointer' => '/type_transition/receiver_id', 'reason' => 'receiver_invalid'];
            } else {
                $receiver = (int)$decision['receiver_id'];
            }
            if (($decision['confirm_tombstone'] ?? false) !== true) {
                $errors[] = ['pointer' => '/type_transition/confirm_tombstone', 'reason' => 'confirmation_required'];
            }
        }

        if ($errors) {
            throw new EditorValidationException('validation_failed', 'Choose the variation that becomes the product and confirm the removal of the others.', $errors, ['preview' => $this->preview($productId, 'simple')]);
        }

        return [
            'from' => 'variable', 'to' => 'simple', 'changed' => true, 'state' => 'simple',
            'receiver_id' => $receiver, 'tombstone_ids' => $ids, 'common_price_patch' => null,
        ];
    }

    /* ---------------------------------------------------------------------- */
    /* Apply                                                                  */
    /* ---------------------------------------------------------------------- */

    /**
     * Write the planned change inside the caller's open transaction.
     *
     * The product version is not touched (the caller writes it once for the whole save). After the commit the
     * caller must call complete().
     *
     * @return array{changed:bool,from:string,to:string,tombstoned:int[],receiver_id:?int,sku:?string,warnings:string[]}
     */
    public function apply(int $productId, array $plan, string $now): array
    {
        $report = ['changed' => false, 'from' => $plan['from'], 'to' => $plan['to'], 'tombstoned' => [], 'receiver_id' => null, 'sku' => null, 'warnings' => []];
        if (empty($plan['changed'])) {
            return $report;
        }

        $product = $this->productRow($productId);
        $extra = json_decode((string)($product['extra_props'] ?? ''), true) ?: [];

        $receiverRow = null;
        $receiverInventory = null;
        $receiverImage = null;
        if ($plan['to'] === 'simple' && $plan['receiver_id'] !== null) {
            $receiverRow = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableVariants} WHERE id = %d", $plan['receiver_id']), ARRAY_A);
            $receiverInventory = $this->inventoryRow((int)$plan['receiver_id']);
            $receiverImage = $this->primaryImage((int)$plan['receiver_id']);
        }

        $core = $this->lifecycle()->applyTypeChange($productId, $plan['to'], ['bump_version' => false]);
        $report['changed'] = !empty($core['changed']);
        $report['tombstoned'] = array_map('intval', $core['tombstoned_variations'] ?? []);

        if ($plan['to'] === 'simple') {
            $columns = ['updated_at' => $now];
            unset($extra['common_price']);
            $columns['extra_props'] = json_encode($extra, JSON_UNESCAPED_UNICODE);

            if ($receiverRow !== null) {
                $report['receiver_id'] = (int)$receiverRow['id'];
                foreach (['regular_price', 'sale_price', 'sale_from', 'sale_to', 'weight', 'length', 'width', 'height'] as $k) {
                    $columns[$k] = $receiverRow[$k] ?? null;
                }
                if ($receiverRow['regular_price'] === null && $receiverRow['sale_price'] === null) {
                    $report['warnings'][] = 'receiver_unpriced';
                }
                if ($receiverRow['sku'] !== null && $receiverRow['sku'] !== '') {
                    $columns['sku'] = $receiverRow['sku'];
                    $report['sku'] = (string)$receiverRow['sku'];
                }
            }
            $this->wpdb->update($this->tableProducts, $columns, ['id' => $productId]);

            // Stock: only the receiver's own stock replaces the product stock; nothing is summed.
            if ($receiverInventory !== null && !empty($receiverInventory['manage_stock'])) {
                $this->writeProductInventory($productId, $receiverInventory, $now);
            }

            // Release the SKUs of the tombstoned variations, then register the product under the receiver SKU.
            if ($report['tombstoned']) {
                $list = implode(',', $report['tombstoned']);
                $this->wpdb->query("DELETE FROM {$this->tableSkuRegistry} WHERE object_id IN ({$list})");
            }
            if ($report['sku'] !== null) {
                (new ProductInfoService($this->wpdb))->applySkuRegistry($productId, $report['sku']);
            }

            if ($receiverImage !== null) {
                $this->carryImage($productId, $receiverImage);
            }
        } else {
            // The parent price columns of a variable product are not a selling price; the simple price travels as
            // the proposed common price (the caller stores it through PricingService).
            $this->wpdb->update($this->tableProducts, [
                'regular_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'updated_at' => $now,
            ], ['id' => $productId]);
        }

        return $report;
    }

    /**
     * Post-commit part of the type change (cache invalidation, shell projection, lifecycle hooks).
     */
    public function complete(int $productId, array $plan): void
    {
        if (!empty($plan['changed'])) {
            wp_set_object_terms($productId, (string)$plan['to'], 'product_type');
            $this->lifecycle()->completeTypeChange($productId, (string)$plan['from'], (string)$plan['to']);
        }
    }

    /* ---------------------------------------------------------------------- */
    /* Helpers                                                                */
    /* ---------------------------------------------------------------------- */

    private function lifecycle(): ProductLifecycle
    {
        return $this->lifecycle ??= new ProductLifecycle();
    }

    private function productRow(int $productId): array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableProducts} WHERE id = %d AND deleted_at IS NULL", $productId), ARRAY_A);
        if (!$row) {
            throw new \InvalidArgumentException("Product not found: {$productId}");
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> non-deleted variations by id in saved order */
    private function existingVariations(int $productId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->tableVariants} WHERE product_id = %d AND deleted_at IS NULL ORDER BY position ASC, id ASC",
            $productId
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['id']] = $row;
        }
        return $out;
    }

    private function inventoryRow(int $ownerId): ?array
    {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableInventory} WHERE owner_id = %d", $ownerId), ARRAY_A) ?: null;
    }

    private function primaryImage(int $variantId): ?int
    {
        $id = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT asset_id FROM {$this->tableVariantMedia} WHERE variant_id = %d ORDER BY is_primary DESC, position ASC, asset_id ASC LIMIT 1",
            $variantId
        ));
        return $id !== null ? (int)$id : null;
    }

    /**
     * The simple price as a common price proposal, or null when the product has no price.
     *
     * @return array{regular:?string,sale:?string,from:?string,to:?string}|null
     */
    private function commonProposal(array $product): ?array
    {
        $price = EffectivePriceResolver::resolve($product);
        if ($price['regular'] === null && $price['sale'] === null) {
            return null;
        }
        $instant = static fn($v) => empty($v) ? null : substr((string)$v, 0, 19);
        return [
            'regular' => $price['regular'],
            'sale'    => $price['sale'],
            'from'    => $instant($product['sale_from'] ?? null),
            'to'      => $instant($product['sale_to'] ?? null),
        ];
    }

    private function writeProductInventory(int $productId, array $inv, string $now): void
    {
        $row = [
            'manage_stock'     => (int)$inv['manage_stock'],
            'quantity'         => $inv['quantity'],
            'stock_status'     => $inv['stock_status'],
            'backorders'       => $inv['backorders'],
            'low_stock_amount' => $inv['low_stock_amount'] ?? null,
            'updated_at'       => $now,
        ];
        $version = $this->wpdb->get_var($this->wpdb->prepare("SELECT version FROM {$this->tableInventory} WHERE owner_id = %d", $productId));
        if ($version === null) {
            $this->wpdb->insert($this->tableInventory, $row + ['owner_id' => $productId, 'version' => 1]);
        } else {
            $this->wpdb->update($this->tableInventory, $row + ['version' => (int)$version + 1], ['owner_id' => $productId]);
        }
    }

    /**
     * Make the receiver image the featured image. The previous featured image moves to the front of the gallery so
     * no linked image is lost; an image already in the gallery is lifted out of it.
     */
    private function carryImage(int $productId, int $assetId): void
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT asset_id, role, position, alt_override FROM {$this->tableProductMedia} WHERE product_id = %d AND role IN ('featured','gallery') ORDER BY role ASC, position ASC",
            $productId
        ), ARRAY_A);

        $featured = null;
        $gallery = [];
        $alts = [];
        foreach ($rows as $r) {
            $aid = (int)$r['asset_id'];
            $alts[$aid] ??= $r['alt_override'];
            if ($r['role'] === 'featured') {
                $featured = $aid;
            } else {
                $gallery[] = $aid;
            }
        }
        if ($featured === $assetId) {
            return;
        }

        $newGallery = [];
        if ($featured !== null) {
            $newGallery[] = $featured;
        }
        foreach ($gallery as $g) {
            if ($g !== $assetId && $g !== $featured) {
                $newGallery[] = $g;
            }
        }

        $this->wpdb->query($this->wpdb->prepare("DELETE FROM {$this->tableProductMedia} WHERE product_id = %d AND role IN ('featured','gallery')", $productId));
        $this->wpdb->insert($this->tableProductMedia, ['product_id' => $productId, 'asset_id' => $assetId, 'role' => 'featured', 'position' => 0, 'alt_override' => $alts[$assetId] ?? null]);
        $pos = 1;
        foreach ($newGallery as $g) {
            $this->wpdb->insert($this->tableProductMedia, ['product_id' => $productId, 'asset_id' => $g, 'role' => 'gallery', 'position' => $pos++, 'alt_override' => $alts[$g] ?? null]);
        }
    }
}
