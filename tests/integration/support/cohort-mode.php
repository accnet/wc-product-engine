<?php
/**
 * Set or check the test database cohort mode (P048).
 *
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/support/cohort-mode.php set cohort_canary
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/support/cohort-mode.php expect native_bridge
 *
 * `expect` exits 1 when the stored mode differs (a test left its mode behind).
 */

if (strpos((string)DB_NAME, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run outside a test_* database (DB_NAME=" . DB_NAME . ").\n");
    exit(1);
}

$action = (string)($args[0] ?? '');
$mode = (string)($args[1] ?? '');
$modes = ['legacy', 'cohort_canary', 'native_bridge', 'full_native'];
if (!in_array($action, ['set', 'expect'], true) || !in_array($mode, $modes, true)) {
    fwrite(STDERR, "Usage: cohort-mode.php set|expect <" . implode('|', $modes) . ">\n");
    exit(1);
}

$option = \WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS;
$flags = get_option($option, \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS);
if (!is_array($flags)) {
    $flags = \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS;
}

if ($action === 'set') {
    $flags['cohort_mode'] = $mode;
    update_option($option, $flags);
    echo "cohort_mode set to {$mode}\n";
    exit(0);
}

$current = (string)($flags['cohort_mode'] ?? '');
if ($current !== $mode) {
    fwrite(STDERR, "cohort_mode is {$current}, expected {$mode}\n");
    exit(1);
}
echo "cohort_mode is {$mode}\n";
