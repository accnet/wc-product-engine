<?php
/**
 * P035.4 — Feed items from native and legacy sources, mapping and validation.
 *
 * Fixture (native, test DB): variable product "Shirt" in category Clothing > Shirts with options
 * Color [Red, Blue] x Size [S]: Red/S priced 20 with an active sale 15 (window known), Blue/S
 * priced 22 managed stock 0 without backorders, a third variation without price; a featured image,
 * a gallery image and a variation primary image. A native simple product without image.
 * A legacy-only (customs prop) simple product read through WooCommerce CRUD.
 *
 * Verifies: item IDs, item_group_id, titles with variation values, deep links, image order, price
 * and sale fields, availability, brand/identifier rules, product_type path and category mapping,
 * eligibility/issue codes, parent mode, exclusions by category/out-of-stock, legacy CRUD path, and
 * parity of native records against wc_get_product() for a sample of catalog products.
 *
 * Run: wp eval-file tests/integration/FeedItemSourceTest.php
 */

use WPE\Application\Feeds\FeedConfig;
use WPE\Application\Feeds\FeedItemSource;
use WPE\Application\Feeds\FeedMapper;
use WPE\Infrastructure\Database\MediaRepository;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('FeedItemSourceTest failed: ' . $msg);
    }
    $checks++;
};

$repo = new ProductRepository($wpdb);
$optRepo = new OptionsRepository($wpdb);
$media = new MediaRepository($wpdb);
$tag = strtolower(wp_generate_password(6, false));
$createdProducts = [];
$createdTerms = [];
$createdAssets = [];
$legacyId = 0;

$feedFor = static function (array $config = []): array {
    return ['id' => 0, 'platform' => 'google', 'format' => 'xml', 'config' => FeedConfig::normalize($config)];
};
$byVariant = static function (array $items): array {
    $out = [];
    foreach ($items as $it) {
        $out[$it['variant_id']] = $it;
    }
    return $out;
};

try {
    echo "=== FeedItemSourceTest ===\n";

    // Categories.
    $parent = wp_insert_term("Clothing {$tag}", 'product_cat');
    $child = wp_insert_term("Shirts {$tag}", 'product_cat', ['parent' => $parent['term_id']]);
    $other = wp_insert_term("Other {$tag}", 'product_cat');
    $createdTerms = [(int)$child['term_id'], (int)$parent['term_id'], (int)$other['term_id']];

    // Native variable product.
    $title = "Shirt {$tag}";
    $created = $repo->create([
        'title' => $title, 'slug' => sanitize_title($title), 'type' => 'variable', 'status' => 'publish', 'sku' => "SHIRT-{$tag}",
        'description' => '<p>Soft <strong>cotton</strong> [gallery] shirt.</p>',
        'weight' => '0.4',
        'variations' => [
            ['sku' => "SH-RS-{$tag}", 'status' => 'publish', 'regular_price' => '20.00', 'combination_key' => hash('sha256', $tag . 'rs'), 'attributes' => ['slot' => 'rs'], 'position' => 1],
            ['sku' => "SH-BS-{$tag}", 'status' => 'publish', 'regular_price' => '22.00', 'combination_key' => hash('sha256', $tag . 'bs'), 'attributes' => ['slot' => 'bs'], 'position' => 2],
            ['sku' => "SH-NP-{$tag}", 'status' => 'publish', 'combination_key' => hash('sha256', $tag . 'np'), 'attributes' => ['slot' => 'np'], 'position' => 3],
        ],
    ]);
    $pid = (int)$created['id'];
    $check($pid > 0 && $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) === $title, 'variable fixture');
    $createdProducts[] = $pid;
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $pid]);
    wp_set_object_terms($pid, [(int)$child['term_id']], 'product_cat');

    [$rs, $bs, $np] = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d ORDER BY position", $pid)));
    $color = $optRepo->createOption($pid, ['key' => 'color', 'name' => 'Color', 'used_for_variation' => 1, 'position' => 0,
        'values' => [['value_key' => 'red', 'label' => 'Red'], ['value_key' => 'blue', 'label' => 'Blue']]]);
    $size = $optRepo->createOption($pid, ['key' => 'size', 'name' => 'Size', 'used_for_variation' => 1, 'position' => 1,
        'values' => [['value_key' => 's', 'label' => 'S'], ['value_key' => 'm', 'label' => 'M']]]);
    $optRepo->assignVariantCombination($pid, $rs, [(int)$color['id'] => (int)$color['values'][0]['id'], (int)$size['id'] => (int)$size['values'][0]['id']]);
    $optRepo->assignVariantCombination($pid, $bs, [(int)$color['id'] => (int)$color['values'][1]['id'], (int)$size['id'] => (int)$size['values'][0]['id']]);
    $optRepo->assignVariantCombination($pid, $np, [(int)$color['id'] => (int)$color['values'][0]['id'], (int)$size['id'] => (int)$size['values'][1]['id']]);

    $saleFrom = gmdate('Y-m-d H:i:s', time() - 86400);
    $saleTo = gmdate('Y-m-d H:i:s', time() + 86400 * 3);
    $wpdb->update("{$prefix}wpe_variants", ['sale_price' => '15.00', 'sale_from' => $saleFrom, 'sale_to' => $saleTo], ['id' => $rs]);
    $bsOwner = (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(stock_owner_id, id) FROM {$prefix}wpe_variants WHERE id = %d", $bs));
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$prefix}wpe_inventory (owner_id, manage_stock, quantity, backorders, stock_status, version, updated_at)
         VALUES (%d, 1, 0, 'no', 'instock', 1, %s)
         ON DUPLICATE KEY UPDATE manage_stock = 1, quantity = 0, backorders = 'no', stock_status = 'instock'",
        $bsOwner,
        gmdate('Y-m-d H:i:s')
    ));

    foreach (['featured', 'gallery', 'variant'] as $kind) {
        $asset = $media->createAsset(['source_url' => "https://cdn.example.com/{$tag}-{$kind}.jpg?X-Amz-Signature=secret&w=800", 'alt_text' => $kind]);
        $createdAssets[$kind] = (int)$asset['id'];
    }
    $media->setFeaturedImage($pid, $createdAssets['featured']);
    $media->setGallery($pid, [$createdAssets['gallery']]);
    $media->setVariantPrimaryImage($bs, $createdAssets['variant']);

    // Native simple product, no image, in another category.
    $sTitle = "Mug {$tag}";
    $simple = $repo->create(['title' => $sTitle, 'slug' => sanitize_title($sTitle), 'type' => 'simple', 'status' => 'publish', 'sku' => "MUG-{$tag}", 'regular_price' => '9.50']);
    $sid = (int)$simple['id'];
    $check($sid > 0 && $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $sid)) === $sTitle, 'simple fixture');
    $createdProducts[] = $sid;
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $sid]);
    wp_set_object_terms($sid, [(int)$other['term_id']], 'product_cat');

    $source = new FeedItemSource($wpdb);
    $records = $source->load([$pid, $sid]);
    $check(isset($records[$pid], $records[$sid]), 'both native records must load');
    $check($records[$pid]['authority'] === 'native' && $records[$sid]['authority'] === 'native', 'fixtures must be read through the native path');
    $check($records[$pid]['category_path'] === "Clothing {$tag} > Shirts {$tag}", 'category path must be root > leaf: ' . $records[$pid]['category_path']);
    $check(in_array((int)$parent['term_id'], $records[$pid]['category_ids'], true), 'category ids must include ancestors');
    echo "  [OK] native records load with category path and ancestors\n";

    // Default mapping, variants mode.
    $mapper = new FeedMapper($feedFor(['mapping' => ['brand' => ['source' => 'static', 'value' => 'Acme']],
        'category_map' => [(string)$parent['term_id'] => 'Apparel & Accessories > Clothing > Shirts & Tops']]));
    $items = $byVariant($mapper->map($pid, $records[$pid]));
    $check(count($items) === 3, 'variable product must give one item per variation');

    $red = $items[$rs]['payload'];
    $check($red['id'] === "SH-RS-{$tag}" && $red['item_group_id'] === "SHIRT-{$tag}", 'id/item_group_id from SKUs');
    $check($red['title'] === "{$title} - Red / S", 'title must append variation values: ' . $red['title']);
    $check($red['description'] === 'Soft cotton shirt.', 'description must be plain text without shortcodes: ' . $red['description']);
    $check(str_contains($red['link'], 'attribute_color=red') && str_contains($red['link'], 'attribute_size=s'), 'link must deep-link the variation: ' . $red['link']);
    $check($red['image_link'] === "https://cdn.example.com/{$tag}-featured.jpg?w=800", 'image must be the featured image without signature: ' . $red['image_link']);
    $check($red['additional_image_link'] === ["https://cdn.example.com/{$tag}-gallery.jpg?w=800"], 'gallery must follow');
    $check($red['price'] === '20.00 USD' && $red['sale_price'] === '15.00 USD', 'price/sale_price: ' . $red['price'] . ' / ' . ($red['sale_price'] ?? ''));
    $check(($red['sale_price_effective_date'] ?? '') === gmdate('Y-m-d\TH:i:s\Z', strtotime($saleFrom . ' UTC')) . '/' . gmdate('Y-m-d\TH:i:s\Z', strtotime($saleTo . ' UTC')), 'sale window must be ISO 8601 interval');
    $check($items[$rs]['next_change'] !== null && str_starts_with($items[$rs]['next_change'], substr($saleTo, 0, 10)), 'next_change must be the sale end');
    $check($red['availability'] === 'in_stock' && $red['color'] === 'Red' && $red['size'] === 'S', 'availability/color/size');
    $check($red['brand'] === 'Acme' && $red['mpn'] === "SH-RS-{$tag}" && !isset($red['identifier_exists']), 'brand static + MPN from SKU means identifiers exist');
    $check($red['product_type'] === "Clothing {$tag} > Shirts {$tag}", 'product_type from category path');
    $check($red['google_product_category'] === 'Apparel & Accessories > Clothing > Shirts & Tops', 'category map must apply from the nearest mapped ancestor');
    $check($red['shipping_weight'] === '0.4 ' . (get_option('woocommerce_weight_unit', 'kg') === 'lbs' ? 'lb' : get_option('woocommerce_weight_unit', 'kg')), 'shipping weight from product: ' . $red['shipping_weight']);
    $check($items[$rs]['eligible'] === true && $items[$rs]['issues'] === [], 'Red/S must be clean: ' . implode(',', $items[$rs]['issues']));

    $blue = $items[$bs]['payload'];
    $check($blue['availability'] === 'out_of_stock', 'managed stock 0 without backorders is out_of_stock');
    $check($blue['image_link'] === "https://cdn.example.com/{$tag}-variant.jpg?w=800", 'variation primary image first');
    $check($blue['additional_image_link'][0] === "https://cdn.example.com/{$tag}-featured.jpg?w=800", 'featured image moves to additional images');
    $check(!isset($blue['sale_price']), 'no sale on Blue/S');

    $check($items[$np]['eligible'] === false && in_array('missing_price', $items[$np]['issues'], true), 'variation without price must be ineligible');
    echo "  [OK] variants mode: ids, titles, deep links, images, price/sale, availability, mapping, issues\n";

    // Simple product: no image -> excluded (require_image default), missing brand warning.
    $simpleItems = $mapper->map($sid, $records[$sid]);
    $check(count($simpleItems) === 1 && $simpleItems[0]['variant_id'] === 0, 'simple product gives one item');
    $check($simpleItems[0]['payload']['price'] === '9.50 USD', 'simple price');
    $check(in_array('excluded_no_image', $simpleItems[0]['issues'], true) && $simpleItems[0]['eligible'] === false, 'item without image must be excluded');
    $check(in_array('description_from_title', $simpleItems[0]['issues'], true), 'empty description must fall back to title with a warning');
    $noBrand = (new FeedMapper($feedFor(['filters' => ['require_image' => false]])))->map($sid, $records[$sid])[0];
    $check(in_array('missing_image', $noBrand['issues'], true) && in_array('missing_brand', $noBrand['issues'], true), 'require_image off reports missing_image as an error');
    echo "  [OK] simple product: price, image exclusion, description fallback, brand warning\n";

    // Parent mode.
    $parentItems = (new FeedMapper($feedFor(['variation_mode' => 'parent'])))->map($pid, $records[$pid]);
    $check(count($parentItems) === 1 && $parentItems[0]['variant_id'] === 0, 'parent mode gives one item');
    $pp = $parentItems[0]['payload'];
    $check($pp['id'] === "SHIRT-{$tag}" && !isset($pp['item_group_id']), 'parent item id from product SKU, no group');
    $check($pp['price'] === '20.00 USD' && ($pp['sale_price'] ?? '') === '15.00 USD', 'parent price must be the lowest active price variation (Red/S on sale)');
    $check($pp['availability'] === 'in_stock', 'parent in stock when any variation is');
    echo "  [OK] parent mode: one item, lowest active price, aggregated availability\n";

    // Filters.
    $excluded = (new FeedMapper($feedFor(['filters' => ['exclude_categories' => [(int)$parent['term_id']], 'include_out_of_stock' => false]])))->map($pid, $records[$pid]);
    $check($excluded === [], 'a product in an excluded (ancestor) category must store no items');
    $oos = $byVariant((new FeedMapper($feedFor(['filters' => ['include_out_of_stock' => false]])))->map($pid, $records[$pid]));
    $check(in_array('excluded_out_of_stock', $oos[$bs]['issues'], true) && $oos[$rs]['eligible'], 'out-of-stock exclusion applies to Blue/S only');
    $none = (new FeedMapper($feedFor(['mapping' => ['brand' => ['source' => 'none'], 'mpn' => ['source' => 'none']]])))->map($pid, $records[$pid]);
    $check(($none[0]['payload']['identifier_exists'] ?? '') === 'no' && in_array('identifier_exists_no', $none[0]['issues'], true), 'no GTIN/MPN/brand must set identifier_exists=no');
    echo "  [OK] filters: excluded categories (with ancestors), out-of-stock, identifier_exists fallback\n";

    // Legacy CRUD path: a product stored only by the WooCommerce CPT store (no native record).
    $legacy = new \WC_Product_Simple();
    $legacy->set_name("Legacy Lamp {$tag}");
    $legacy->set_status('publish');
    $legacy->set_sku("LAMP-{$tag}");
    $legacy->set_regular_price('30');
    $legacy->set_sale_price('25');
    (new \WC_Product_Data_Store_CPT())->create($legacy);
    $legacyId = (int)$legacy->get_id();
    $check($legacyId > 0 && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}wpe_products WHERE id = %d", $legacyId)), 'legacy fixture must have no native record');
    \WPE\Support\AuthorityResolver::flushAll();
    $lrec = $source->load([$legacyId]);
    $check(isset($lrec[$legacyId]), 'legacy record must load');
    $check($lrec[$legacyId]['authority'] === 'legacy', 'CPT-only product must be read through WooCommerce CRUD');
    $litem = (new FeedMapper($feedFor(['filters' => ['require_image' => false]])))->map($legacyId, $lrec[$legacyId])[0];
    $check($litem['payload']['price'] === '30.00 USD' && $litem['payload']['sale_price'] === '25.00 USD', 'legacy price/sale through CRUD');
    $check($litem['payload']['id'] === "LAMP-{$tag}" && $litem['payload']['link'] === get_permalink($legacyId), 'legacy id/link');
    echo "  [OK] legacy product read through WooCommerce CRUD ({$lrec[$legacyId]['authority']})\n";

    // Missing product: no items.
    $check((new FeedMapper($feedFor()))->map(999999999, null) === [], 'missing product gives no items');

    // Parity with wc_get_product() on catalog samples.
    $sample = array_map('intval', $wpdb->get_col(
        "SELECT p.id FROM {$prefix}wpe_products p JOIN {$wpdb->posts} s ON s.ID = p.id
         WHERE p.deleted_at IS NULL AND p.status = 'publish' AND p.type IN ('simple','variable') ORDER BY p.id DESC LIMIT 25"
    ));
    $recs = $source->load($sample);
    $compared = 0;
    foreach ($recs as $id => $rec) {
        if ($rec['authority'] !== 'native') {
            continue;
        }
        $wc = wc_get_product($id);
        $check($wc instanceof \WC_Product, "wc_get_product({$id})");
        $check((string)$wc->get_sku('edit') === $rec['sku'], "sku parity for {$id}");
        foreach (array_slice($rec['variants'], 0, 5) as $v) {
            $wv = wc_get_product($v['id']);
            if (!$wv instanceof \WC_Product_Variation) {
                continue;
            }
            $check((string)$wv->get_sku('edit') === $v['sku'], "variation sku parity {$v['id']}");
            $wcRegular = $wv->get_regular_price('edit');
            $check(($wcRegular === '' ? null : (float)$wcRegular) === ($v['price']['regular'] !== null ? (float)$v['price']['regular'] : null), "regular price parity {$v['id']}");
            $wcStatus = $wv->get_stock_status('edit');
            $expect = ['instock' => 'in_stock', 'outofstock' => 'out_of_stock', 'onbackorder' => 'backorder'][$wcStatus] ?? $wcStatus;
            if ($v['stock']['availability'] !== 'backorder' && !($wv->get_manage_stock() && (float)$wv->get_stock_quantity() <= 0)) {
                $check($expect === $v['stock']['availability'], "availability parity {$v['id']}: wc={$wcStatus} feed={$v['stock']['availability']}");
            }
            $compared++;
        }
    }
    $check($compared > 0, 'parity must compare at least one variation');
    echo "  [OK] parity with wc_get_product(): {$compared} variations (sku, regular price, availability)\n";

    echo "ALL FEED ITEM SOURCE ASSERTIONS PASSED ({$checks} checks)\n";
} finally {
    foreach ($createdProducts as $id) {
        wp_delete_post($id, true);
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}wpe_products WHERE id = %d", $id))) {
            $repo->delete($id, true);
        }
        $wpdb->delete("{$prefix}wpe_outbox", ['aggregate_id' => $id]);
    }
    if ($legacyId > 0 && get_post_type($legacyId) === 'product') {
        wp_delete_post($legacyId, true);
    }
    foreach ($createdAssets as $aid) {
        $wpdb->delete("{$prefix}wpe_product_media", ['asset_id' => $aid]);
        $wpdb->delete("{$prefix}wpe_variant_media", ['asset_id' => $aid]);
        $wpdb->delete("{$prefix}wpe_assets", ['id' => $aid]);
    }
    foreach ($createdTerms as $t) {
        wp_delete_term($t, 'product_cat');
    }
}
