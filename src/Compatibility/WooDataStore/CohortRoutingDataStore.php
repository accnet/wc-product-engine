<?php

declare(strict_types=1);

namespace WPE\Compatibility\WooDataStore;

use WC_Product;
use WC_Product_Data_Store_CPT;
use WC_Product_Variable_Data_Store_CPT;
use WC_Product_Variation_Data_Store_CPT;
use WPE\Support\AuthorityResolver;

/**
 * CohortRoutingDataStore — Per-product router between native engine DataStores
 * and standard WooCommerce CPT DataStores.
 *
 * WooCommerce instantiates one DataStore per product type ('product', 'product-simple',
 * 'product-variable', 'product-variation'). During canary rollouts or on stores that mix
 * native-authority simple/variable products with legacy-authority products (such as products
 * carrying legacy-only customs props or unmigrated cohorts), each DataStore call must route
 * deterministically by the target product's authority.
 */
class CohortRoutingDataStore extends WC_Product_Data_Store_CPT
{
    protected ?ProductDataStore $nativeStore = null;

    public static function registerDataStores(array $stores): array
    {
        $stores['product']           = self::class;
        $stores['product-simple']    = self::class;
        $stores['product-variable']  = CohortRoutingVariableDataStore::class;
        $stores['product-variation'] = CohortRoutingVariationDataStore::class;
        return $stores;
    }

    protected function native(): ProductDataStore
    {
        if ($this->nativeStore === null) {
            $this->nativeStore = new ProductDataStore();
        }
        return $this->nativeStore;
    }

    protected function isNativeProduct($product, string $op = 'read'): bool
    {
        if ($product instanceof WC_Product) {
            return AuthorityResolver::shouldUseNativeForProduct($product, $op);
        }
        if (is_numeric($product) && (int)$product > 0) {
            return AuthorityResolver::isNativeObject((int)$product);
        }
        return false;
    }

    public function create(&$product): void
    {
        if ($this->isNativeProduct($product, 'create')) {
            $this->native()->create($product);
            return;
        }
        parent::create($product);
    }

    public function read(&$product): void
    {
        if ($this->isNativeProduct($product, 'read')) {
            $this->native()->read($product);
            return;
        }
        parent::read($product);
    }

    public function update(&$product): void
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->update($product);
            return;
        }
        parent::update($product);
    }

    public function delete(&$product, $args = []): void
    {
        if ($this->isNativeProduct($product, 'delete')) {
            $this->native()->delete($product, $args);
            return;
        }
        parent::delete($product, $args);
    }

    public function read_meta(&$object)
    {
        if ($this->isNativeProduct($object, 'read')) {
            return $this->native()->read_meta($object);
        }
        return parent::read_meta($object);
    }

    public function add_meta(&$object, $meta)
    {
        if ($this->isNativeProduct($object, 'update')) {
            return $this->native()->add_meta($object, $meta);
        }
        return parent::add_meta($object, $meta);
    }

    public function update_meta(&$object, $meta)
    {
        if ($this->isNativeProduct($object, 'update')) {
            $this->native()->update_meta($object, $meta);
            return;
        }
        parent::update_meta($object, $meta);
    }

    public function delete_meta(&$object, $meta)
    {
        if ($this->isNativeProduct($object, 'update')) {
            $this->native()->delete_meta($object, $meta);
            return;
        }
        parent::delete_meta($object, $meta);
    }

    public function update_product_stock($product_id_with_stock, $stock_quantity = null, $operation = 'set')
    {
        if (AuthorityResolver::isNativeObject((int)$product_id_with_stock)) {
            return $this->native()->update_product_stock($product_id_with_stock, $stock_quantity, $operation);
        }
        return parent::update_product_stock($product_id_with_stock, $stock_quantity, $operation);
    }

    public function read_stock_quantity(&$product, $new_stock = null)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->read_stock_quantity($product, $new_stock);
        }
        return parent::read_stock_quantity($product, $new_stock);
    }

    public function update_product_sales($product_id, $quantity = null, $operation = 'set'): void
    {
        if (AuthorityResolver::isNativeObject((int)$product_id)) {
            $this->native()->update_product_sales($product_id, $quantity, $operation);
            return;
        }
        parent::update_product_sales($product_id, $quantity, $operation);
    }

    public function get_product_type($product_id)
    {
        if (AuthorityResolver::isNativeObject((int)$product_id)) {
            $type = $this->native()->get_product_type($product_id);
            if ($type !== false) {
                return $type;
            }
        }
        return parent::get_product_type($product_id);
    }

    public function get_product_id_by_sku($sku)
    {
        $nativeId = $this->native()->get_product_id_by_sku($sku);
        if ($nativeId > 0 && AuthorityResolver::isNativeObject($nativeId)) {
            return $nativeId;
        }
        return parent::get_product_id_by_sku($sku);
    }

    public function is_existing_sku($product_id, $sku)
    {
        if (AuthorityResolver::isNativeObject((int)$product_id)) {
            if ($this->native()->is_existing_sku($product_id, $sku)) {
                return true;
            }
        }
        return parent::is_existing_sku($product_id, $sku);
    }

    public function get_query_for_stock($product_id)
    {
        if (AuthorityResolver::isNativeObject((int)$product_id)) {
            return $this->native()->get_query_for_stock($product_id);
        }
        return parent::get_query_for_stock($product_id);
    }

    public function update_average_rating($product)
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->update_average_rating($product);
            return;
        }
        parent::update_average_rating($product);
    }

    public function update_rating_counts($product)
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->update_rating_counts($product);
            return;
        }
        parent::update_rating_counts($product);
    }

    public function update_review_count($product)
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->update_review_count($product);
            return;
        }
        parent::update_review_count($product);
    }
}

class CohortRoutingVariableDataStore extends WC_Product_Variable_Data_Store_CPT
{
    protected ?ProductVariableDataStore $nativeStore = null;

    protected function native(): ProductVariableDataStore
    {
        if ($this->nativeStore === null) {
            $this->nativeStore = new ProductVariableDataStore();
        }
        return $this->nativeStore;
    }

    protected function isNativeProduct($product, string $op = 'read'): bool
    {
        if ($product instanceof WC_Product) {
            return AuthorityResolver::shouldUseNativeForProduct($product, $op);
        }
        if (is_numeric($product) && (int)$product > 0) {
            return AuthorityResolver::isNativeObject((int)$product);
        }
        return false;
    }

    public function create(&$product): void
    {
        if ($this->isNativeProduct($product, 'create')) {
            $this->native()->create($product);
            return;
        }
        parent::create($product);
    }

    public function read(&$product): void
    {
        if ($this->isNativeProduct($product, 'read')) {
            $this->native()->read($product);
            return;
        }
        parent::read($product);
    }

    public function update(&$product): void
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->update($product);
            return;
        }
        parent::update($product);
    }

    public function delete(&$product, $args = []): void
    {
        if ($this->isNativeProduct($product, 'delete')) {
            $this->native()->delete($product, $args);
            return;
        }
        parent::delete($product, $args);
    }

    public function read_children(&$product, $force_read = false)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->read_children($product, $force_read);
        }
        return parent::read_children($product, $force_read);
    }

    public function read_variation_attributes(&$product)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->read_variation_attributes($product);
        }
        return parent::read_variation_attributes($product);
    }

    public function read_price_data(&$product, $for_display = false)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->read_price_data($product, $for_display);
        }
        return parent::read_price_data($product, $for_display);
    }

    public function sync_price(&$product)
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->sync_price($product);
            return;
        }
        parent::sync_price($product);
    }

    public function sync_variation_names(&$product, $previous_name = '', $new_name = '')
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->sync_variation_names($product, $previous_name, $new_name);
            return;
        }
        parent::sync_variation_names($product, $previous_name, $new_name);
    }

    public function sync_managed_variation_stock_status(&$product)
    {
        if ($this->isNativeProduct($product, 'update')) {
            $this->native()->sync_managed_variation_stock_status($product);
            return;
        }
        parent::sync_managed_variation_stock_status($product);
    }

    public function child_has_weight($product)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->child_has_weight($product);
        }
        return parent::child_has_weight($product);
    }

    public function child_has_dimensions($product)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->child_has_dimensions($product);
        }
        return parent::child_has_dimensions($product);
    }

    public function child_is_in_stock($product)
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->child_is_in_stock($product);
        }
        return parent::child_is_in_stock($product);
    }

    public function delete_variations($product_id, $force_delete = false)
    {
        if (AuthorityResolver::isNativeObject((int)$product_id)) {
            $this->native()->delete_variations($product_id, $force_delete);
            return;
        }
        parent::delete_variations($product_id, $force_delete);
    }

    public function untrash_variations($product_id)
    {
        if (AuthorityResolver::isNativeObject((int)$product_id)) {
            $this->native()->untrash_variations($product_id);
            return;
        }
        parent::untrash_variations($product_id);
    }

    public function find_matching_product_variation($product, $match_attributes = [])
    {
        if ($this->isNativeProduct($product, 'read')) {
            return $this->native()->find_matching_product_variation($product, $match_attributes);
        }
        return parent::find_matching_product_variation($product, $match_attributes);
    }

    public function sort_all_product_variations($parent_id)
    {
        if (AuthorityResolver::isNativeObject((int)$parent_id)) {
            $this->native()->sort_all_product_variations($parent_id);
            return;
        }
        parent::sort_all_product_variations($parent_id);
    }

    public function create_all_product_variations($product, $limit = -1, $default_values = [], $metadata = [])
    {
        if ($this->isNativeProduct($product, 'update')) {
            return $this->native()->create_all_product_variations($product, $limit, $default_values, $metadata);
        }
        return parent::create_all_product_variations($product, $limit, $default_values, $metadata);
    }
}

class CohortRoutingVariationDataStore extends WC_Product_Variation_Data_Store_CPT
{
    protected ?ProductVariationDataStore $nativeStore = null;

    protected function native(): ProductVariationDataStore
    {
        if ($this->nativeStore === null) {
            $this->nativeStore = new ProductVariationDataStore();
        }
        return $this->nativeStore;
    }

    protected function isNativeVariation($product, string $op = 'read'): bool
    {
        if ($product instanceof WC_Product) {
            return AuthorityResolver::shouldUseNativeForProduct($product, $op);
        }
        if (is_numeric($product) && (int)$product > 0) {
            return AuthorityResolver::isNativeObject((int)$product);
        }
        return false;
    }

    public function create(&$product): void
    {
        if ($this->isNativeVariation($product, 'create')) {
            $this->native()->create($product);
            return;
        }
        parent::create($product);
    }

    public function read(&$product): void
    {
        if ($this->isNativeVariation($product, 'read')) {
            $this->native()->read($product);
            return;
        }
        parent::read($product);
    }

    public function update(&$product): void
    {
        if ($this->isNativeVariation($product, 'update')) {
            $this->native()->update($product);
            return;
        }
        parent::update($product);
    }

    public function delete(&$product, $args = []): void
    {
        if ($this->isNativeVariation($product, 'delete')) {
            $this->native()->delete($product, $args);
            return;
        }
        parent::delete($product, $args);
    }

    public function update_product_stock($product_id_with_stock, $stock_quantity = null, $operation = 'set')
    {
        if (AuthorityResolver::isNativeObject((int)$product_id_with_stock)) {
            return $this->native()->update_product_stock($product_id_with_stock, $stock_quantity, $operation);
        }
        return parent::update_product_stock($product_id_with_stock, $stock_quantity, $operation);
    }

    public function read_stock_quantity(&$product, $new_stock = null)
    {
        if ($this->isNativeVariation($product, 'read')) {
            return $this->native()->read_stock_quantity($product, $new_stock);
        }
        return parent::read_stock_quantity($product, $new_stock);
    }
}
