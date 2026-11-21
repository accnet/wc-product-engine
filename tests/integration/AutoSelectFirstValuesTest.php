<?php
/**
 * P029 — Auto-Select First Values (`auto_select_first_values`).
 *
 * Fixture: Color [Red, Blue] x Size [S, M] with variations Red/M, Blue/S, Blue/M (no Red/S).
 * - off: no autoSelection, nothing changes;
 * - on: first AVAILABLE value per option in saved order -> Red + M (S is not available with Red);
 * - a configured default wins and the walk respects it (Size=S -> Blue + S);
 * - an unpurchasable variation is skipped (Red/M without price -> Blue + S);
 * - Single Price Display prices the opening variation; defaultSelection/initialResolution stay
 *   the configured state that Clear returns to.
 *
 * Run: wp eval-file wp-content/plugins/wc-product-engine/tests/integration/AutoSelectFirstValuesTest.php
 */

use WPE\Bootstrap\PluginBootstrap;
use WPE\Compatibility\StoreApi\SelectorAdapter;
use WPE\Infrastructure\Database\OptionsRepository;
use WPE\Infrastructure\Database\ProductRepository;

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    }
}

global $wpdb;
$prefix = $wpdb->prefix;
$checks = 0;
$check = static function (bool $ok, string $msg) use (&$checks): void {
    if (!$ok) {
        throw new \RuntimeException('AutoSelectFirstValues failed: ' . $msg);
    }
    $checks++;
};

$repo = new ProductRepository($wpdb);
$optRepo = new OptionsRepository($wpdb);
$originalFlags = get_option(PluginBootstrap::OPTION_FLAGS, []);

$title = 'Auto Select Probe ' . wp_generate_password(8, false);
$created = $repo->create([
    'title'      => $title,
    'slug'       => sanitize_title($title),
    'type'       => 'variable',
    'status'     => 'publish',
    'variations' => [
        ['sku' => 'ASP-RM-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '10.00',
         'combination_key' => hash('sha256', $title . 'rm'), 'attributes' => ['slot' => 'rm'], 'position' => 1],
        ['sku' => 'ASP-BS-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '11.00',
         'combination_key' => hash('sha256', $title . 'bs'), 'attributes' => ['slot' => 'bs'], 'position' => 2],
        ['sku' => 'ASP-BM-' . wp_generate_password(6, false), 'status' => 'publish', 'regular_price' => '12.00',
         'combination_key' => hash('sha256', $title . 'bm'), 'attributes' => ['slot' => 'bm'], 'position' => 3],
    ],
]);
$pid = is_array($created) ? (int)($created['id'] ?? 0) : 0;
// Every write below targets $pid: refuse unless it is the product just created.
if ($pid <= 0 || $wpdb->get_var($wpdb->prepare("SELECT title FROM {$prefix}wpe_products WHERE id = %d", $pid)) !== $title) {
    throw new \RuntimeException('AutoSelectFirstValues failed: could not create fixture: ' . wp_json_encode($created));
}

$setFlag = static function (bool $on) use ($originalFlags): void {
    $flags = is_array($originalFlags) ? $originalFlags : [];
    $flags['auto_select_first_values'] = $on;
    update_option(PluginBootstrap::OPTION_FLAGS, $flags);
    PluginBootstrap::instance()->loadFlags();
};
$config = static function () use ($pid): array {
    wp_cache_flush();
    return (new SelectorAdapter())->getSelectorConfig($pid);
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

    // 1. Flag off: unchanged behavior.
    $setFlag(false);
    $cfg = $config();
    $check($cfg['autoSelection'] === null && $cfg['autoResolution'] === null, 'flag off must not produce an auto selection');
    $check($cfg['defaultSelection'] === [], 'fixture has no configured default');
    echo "  [OK] off: no auto selection\n";

    // 2. Flag on: first available value per option, in saved order.
    $setFlag(true);
    $cfg = $config();
    $check($cfg['flags']['auto_select_first_values'] === true, 'flag must reach the selector config');
    $check($cfg['autoSelection'] == [$cId => $red, $sId => $medium],
        'expected Red + M (S unavailable with Red), got ' . wp_json_encode($cfg['autoSelection']));
    $check(($cfg['autoResolution']['state'] ?? '') === 'exact', 'auto selection must resolve exactly');
    $check((int)($cfg['autoResolution']['variant']['id'] ?? 0) === $rm, 'auto selection must resolve to Red/M');
    $check($cfg['defaultSelection'] === [] && ($cfg['initialResolution']['state'] ?? '') !== 'exact',
        'Clear target (defaultSelection/initialResolution) must stay the configured, empty state');
    echo "  [OK] on: Red + M (first available), resolves to variation {$rm}; Clear target stays empty\n";

    // 3. SSR price of the opening state (Single Price Display path).
    $html = (new SelectorAdapter())->buildSinglePriceDisplayHtml($pid, true);
    $check(str_contains($html, 'data-wpe-price-state="resolved"') && str_contains($html, '10'),
        'Single Price Display must price the auto-selected variation: ' . $html);
    echo "  [OK] Single Price Display renders the auto-selected variation price\n";

    // 4. A configured default wins; the walk respects it.
    $optRepo->updateOption((int)$sId, ['default_value_id' => (int)$small]);
    $cfg = $config();
    $check(($cfg['defaultSelection'][$sId] ?? null) === $small, 'configured default must be kept');
    $check($cfg['autoSelection'] == [$cId => $blue, $sId => $small],
        'expected Blue + S with Size default S, got ' . wp_json_encode($cfg['autoSelection']));
    $optRepo->updateOption((int)$sId, ['default_value_id' => null]);
    echo "  [OK] configured default (Size=S) wins: Blue + S\n";

    // 5. An unpurchasable variation is skipped.
    $wpdb->update("{$prefix}wpe_variants", ['regular_price' => null, 'version' => 99], ['id' => $rm]);
    $cfg = $config();
    $check($cfg['autoSelection'] == [$cId => $blue, $sId => $small],
        'Red/M has no price: expected Blue + S, got ' . wp_json_encode($cfg['autoSelection']));
    echo "  [OK] unpurchasable Red/M is skipped: Blue + S\n";
} finally {
    update_option(PluginBootstrap::OPTION_FLAGS, $originalFlags);
    PluginBootstrap::instance()->loadFlags();
    wp_delete_post($pid, true);
    wp_cache_flush();
}

echo "ALL AUTO SELECT FIRST VALUES ASSERTIONS PASSED ({$checks} checks)\n";
