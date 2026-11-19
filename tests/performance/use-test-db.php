<?php
/**
 * Point WordPress at a disposable test_* database.
 *
 * Load with `wp --require=tests/performance/use-test-db.php …` so benchmarks
 * and integration tests never write to the live site database. The database
 * name comes from WPE_TEST_DB (default test_wpe_bench) and must start with
 * "test_". Create it as a copy of the site DB first, e.g.:
 *   wp db query "CREATE DATABASE test_wpe_bench"
 *   wp db export - | wp --require=tests/performance/use-test-db.php db query
 */

$wpeTestDb = getenv('WPE_TEST_DB') ?: 'test_wpe_bench';
if (strpos($wpeTestDb, 'test_') !== 0) {
    fwrite(STDERR, "WPE_TEST_DB must start with test_.\n");
    exit(1);
}
if (defined('DB_NAME')) {
    fwrite(STDERR, "DB_NAME is already defined; load this file with wp --require.\n");
    exit(1);
}
define('DB_NAME', $wpeTestDb);
