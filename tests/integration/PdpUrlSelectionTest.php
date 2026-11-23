<?php
/**
 * P035.2 — Product page opens on the variation named by `attribute_*` query arguments.
 *
 * Fixture: Color [Red, Blue] x Size [S, M] with variations Red/M (10), Blue/S (11), Blue/M (12); no Red/S.
 * - no query: config unchanged;
 * - exact link (key, name slug, pa_ prefix, label case) opens on that variation;
 * - a combination that does not exist falls back to the normal opening state;
 * - unknown keys/values are ignored;
 * - a partial link is filled by Auto-Select First Values when enabled, kept partial otherwise;
 * - the shared cached config is never changed by the overlay;
 * - Single Price Display prices the linked variation for the queried product only.
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/PdpUrlSelectionTest.php
 */

use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

global $wpdb, $wp_query;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('PdpUrlSelection failed: ' . $msg);
    }
    $checks++;
};

$repo = new ProductRepository($wpdb);
$optRepo = new OptionsRepository($wpdb);
$originalFlags = get_option(PluginBootstrap::OPTION_FLAGS, []);
$originalGet = $_GET;

$title = 'Deep Link Probe ' . wp_generate_password(8, false);
$created = $repo->create([
    'title'      => $title,
    'slug'       => sanitize_title($title),
    'type'       => 'variable',
    'status'     => 'publish',
    'variations' => [
        ['sku' => 'DLP-RM-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '10.00',
         'combination_key' => hash('sha256', $title . 'rm'), 'attributes' => ['slot' => 'rm'], 'position' => 1],
        ['sku' => 'DLP-BS-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '11.00',
         'combination_key' => hash('sha256', $title . 'bs'), 'attributes' => ['slot' => 'bs'], 'position' => 2],
        ['sku' => 'DLP-BM-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '12.00',
         'combination_key' => hash('sha256', $title . 'bm'), 'attributes' => ['slot' => 'bm'], 'position' => 3],
    ],
]);
$pid = is_array($created) ? (int)($created['id'] ?? 0) : 0;
if ($pid <= 0 || $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) !== $title) {
    throw new \RuntimeException('PdpUrlSelection failed: could not create fixture: ' . wp_json_encode($created));
}

$setFlag = static function (bool $on) use ($originalFlags): void {
    $flags = is_array($originalFlags) ? $originalFlags : [];
    $flags['auto_select_first_values'] = $on;
    update_option(PluginBootstrap::OPTION_FLAGS, $flags);
    PluginBootstrap::instance()->loadFlags();
};
$render = static function (array $query) use ($pid): array {
    wp_cache_flush();
    return (new SelectorAdapter())->getRenderConfig($pid, $query);
};

try {
    [$rm, $bs, $bm] = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$prefix}wpe_variants WHERE product_id = %d ORDER BY position ASC",
        $pid
    )));
    $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $pid, 'post_type' => 'product']);

    $color = $optRepo->createOption($pid, [
        'key' => 'color', 'name' => 'Color', 'visible' => 1, 'used_for_variation' => 1, 'position' => 0,
        'values' => [['value_key' => 'red', 'label' => 'Red'], ['value_key' => 'blue', 'label' => 'Blue']],
    ]);
    $size = $optRepo->createOption($pid, [
        'key' => 'size', 'name' => 'Size', 'visible' => 1, 'used_for_variation' => 1, 'position' => 1,
        'values' => [['value_key' => 's', 'label' => 'S'], ['value_key' => 'm', 'label' => 'M']],
    ]);
    $cId = (string)$color['id'];
    $sId = (string)$size['id'];
    [$red, $blue] = [(string)$color['values'][0]['id'], (string)$color['values'][1]['id']];
    [$small, $medium] = [(string)$size['values'][0]['id'], (string)$size['values'][1]['id']];
    foreach ([[$rm, $red, $medium], [$bs, $blue, $small], [$bm, $blue, $medium]] as [$vid, $c, $s]) {
        $optRepo->assignVariantCombination($pid, $vid, [(int)$cId => (int)$c, (int)$sId => (int)$s]);
    }

    $setFlag(false);

    // 1. No attribute arguments: the shared config is returned as is.
    $cfg = $render(['utm_source' => 'google']);
    $check(empty($cfg['requestSelection']) && $cfg['autoSelection'] === null, 'no attribute args must not change the config');
    echo "  [OK] no attribute arguments: config unchanged\n";

    // 2. Exact link by option key and value key.
    $cfg = $render(['attribute_color' => 'blue', 'attribute_size' => 's']);
    $check(!empty($cfg['requestSelection']), 'exact link must be applied');
    $check($cfg['autoSelection'] == [$cId => $blue, $sId => $small], 'expected Blue + S, got ' . wp_json_encode($cfg['autoSelection']));
    $check(($cfg['autoResolution']['state'] ?? '') === 'exact', 'linked selection must resolve exactly');
    $check((int)($cfg['autoResolution']['variant']['id'] ?? 0) === $bs, 'linked selection must resolve to Blue/S');
    $check($cfg['defaultSelection'] === [], 'Clear target must stay the configured (empty) defaults');
    echo "  [OK] attribute_color=blue&attribute_size=s opens on variation {$bs}\n";

    // 3. Name slug, pa_ prefix and label matching (case-insensitive).
    $cfg = $render(['attribute_Color' => 'Blue', 'attribute_pa_size' => 'M']);
    $check((int)($cfg['autoResolution']['variant']['id'] ?? 0) === $bm, 'label/prefix matching must resolve Blue/M, got ' . wp_json_encode($cfg['autoSelection']));
    echo "  [OK] attribute_Color=Blue&attribute_pa_size=M opens on variation {$bm}\n";

    // 4. Combination that does not exist (Red/S) falls back to the normal opening state.
    $cfg = $render(['attribute_color' => 'red', 'attribute_size' => 's']);
    $check(empty($cfg['requestSelection']) && $cfg['autoSelection'] === null, 'non-existent combination must fall back, got ' . wp_json_encode($cfg['autoSelection']));
    echo "  [OK] non-existent Red/S falls back to the normal opening state\n";

    // 5. Unknown keys/values are ignored.
    $cfg = $render(['attribute_color' => 'green', 'attribute_flavour' => 'mint']);
    $check(empty($cfg['requestSelection']), 'unknown values must be ignored');
    echo "  [OK] unknown attribute keys/values are ignored\n";

    // 6. Partial link: kept partial with Auto-Select off, filled with it on.
    $cfg = $render(['attribute_color' => 'blue']);
    $check($cfg['autoSelection'] == [$cId => $blue] && ($cfg['autoResolution']['state'] ?? '') !== 'exact',
        'partial link without Auto-Select must stay partial, got ' . wp_json_encode($cfg['autoSelection']));
    $setFlag(true);
    $cfg = $render(['attribute_color' => 'blue']);
    $check($cfg['autoSelection'] == [$cId => $blue, $sId => $small] && (int)($cfg['autoResolution']['variant']['id'] ?? 0) === $bs,
        'partial link with Auto-Select must fill Blue + S, got ' . wp_json_encode($cfg['autoSelection']));
    $setFlag(false);
    echo "  [OK] partial link stays partial (Auto-Select off) and is filled to Blue + S (Auto-Select on)\n";

    // 7. The shared, cached config is never changed by an overlay.
    $adapter = new SelectorAdapter();
    $adapter->getRenderConfig($pid, ['attribute_color' => 'blue', 'attribute_size' => 'm']);
    $shared = $adapter->getSelectorConfig($pid);
    $check(empty($shared['requestSelection']) && $shared['autoSelection'] === null, 'overlay must not leak into the shared config');
    echo "  [OK] shared selector config stays untouched\n";

    // 8. Single Price Display prices the linked variation for the queried product only.
    $savedQuery = $wp_query;
    $wp_query = new \WP_Query();
    $wp_query->queried_object = get_post($pid);
    $wp_query->queried_object_id = $pid;
    $_GET = ['attribute_color' => 'blue', 'attribute_size' => 'm'];
    wp_cache_flush();
    $html = (new SelectorAdapter())->buildSinglePriceDisplayHtml($pid, true);
    $check(str_contains($html, 'data-wpe-price-state="resolved"') && str_contains($html, '12'),
        'Single Price Display must price the linked Blue/M variation: ' . $html);
    $wp_query->queried_object_id = $pid + 1000000;
    wp_cache_flush();
    $html = (new SelectorAdapter())->buildSinglePriceDisplayHtml($pid, true);
    $check(!str_contains($html, 'data-wpe-price-state="resolved"'), 'a product that is not the queried one must ignore the link: ' . $html);
    $wp_query = $savedQuery;
    $_GET = $originalGet;
    echo "  [OK] Single Price Display prices the linked variation only for the queried product\n";
} finally {
    $_GET = $originalGet;
    update_option(PluginBootstrap::OPTION_FLAGS, $originalFlags);
    PluginBootstrap::instance()->loadFlags();
    wp_delete_post($pid, true);
    if ((string)$wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) === $title) {
        $repo->delete($pid, true);
    }
    wp_cache_flush();
}

echo "ALL PDP URL SELECTION ASSERTIONS PASSED ({$checks} checks)\n";
