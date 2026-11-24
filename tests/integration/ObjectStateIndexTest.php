<?php
/**
 * Integration Test — P042 contract/code agreement for the 2026-11-24 import changes.
 *
 * Run on a test_* copy of the site DB:
 *   wp --require=tests/performance/use-test-db.php eval-file tests/integration/ObjectStateIndexTest.php
 *
 *  - idx_parent_kind (parent_id, kind) on wpe_object_state is declared in schema.sql,
 *    schema-contract.json and docs/03.
 *  - SchemaMigrator::migrate() adds it to an existing table once and records schema version 5.
 *  - The mirror's per-parent variation query uses it.
 *  - Shopify category_mode defaults to `path` (missing/unknown values), `none` is kept (docs/10 §7).
 */

use WPE\Compatibility\Csv\Shopify\ShopifyBatchImporter;
use WPE\Infrastructure\Database\SchemaMigrator;

global $wpdb;

if (strpos((string)DB_NAME, 'test_') !== 0) {
    fwrite(STDERR, "Refusing to run outside a test_* database (DB_NAME=" . DB_NAME . ").\n");
    exit(1);
}

// zend.assertions is -1 for WP-CLI on this host, so checks throw instead of using assert().
$must = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$checks = 0;
$ok = static function (string $msg) use (&$checks): void {
    $checks++;
    echo "  [OK] {$msg}\n";
};

$root = dirname(__DIR__, 2);
$table = $wpdb->prefix . 'wpe_object_state';
$indexColumns = static function () use ($wpdb, $table): array {
    $cols = [];
    foreach ($wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_parent_kind'", ARRAY_A) ?: [] as $row) {
        $cols[(int)$row['Seq_in_index']] = (string)$row['Column_name'];
    }
    ksort($cols);
    return array_values($cols);
};

echo "=== P042 ObjectStateIndexTest ===\n";

/* 1. Contract, machine contract and docs agree */
$schemaSql = (string)file_get_contents($root . '/contracts/database/schema.sql');
$must(preg_match('/CREATE TABLE IF NOT EXISTS `\{prefix\}wpe_object_state`(.*?)\) ENGINE/s', $schemaSql, $m) === 1, 'wpe_object_state missing from schema.sql');
$must(strpos($m[1], 'KEY `idx_parent_kind` (`parent_id`, `kind`)') !== false, 'schema.sql does not declare idx_parent_kind (parent_id, kind)');
$must(strpos($m[1], 'KEY `idx_authority_parent_object` (`authority`, `parent_id`, `object_id`)') !== false, 'schema.sql lost idx_authority_parent_object');

$contract = json_decode((string)file_get_contents($root . '/contracts/database/schema-contract.json'), true);
$must(is_array($contract), 'schema-contract.json is not valid JSON');
$objectState = null;
$stack = [$contract];
while ($stack) {
    $node = array_pop($stack);
    if (is_array($node)) {
        if (($node['name'] ?? null) === 'object_state' && isset($node['keys_contract'])) {
            $objectState = $node;
            break;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $stack[] = $child;
            }
        }
    }
}
$must($objectState !== null, 'object_state entry missing from schema-contract.json');
$must(strpos($objectState['keys_contract'], '`(parent_id,kind)`') !== false, 'schema-contract.json keys_contract lacks (parent_id,kind): ' . $objectState['keys_contract']);

$docs03 = (string)file_get_contents($root . '/docs/03-database-schema.md');
$must(preg_match('/^\| `object_state` \|.*$/m', $docs03, $row) === 1, 'object_state row missing from docs/03');
$docKeys = trim((string)array_slice(explode('|', trim($row[0], '|')), -1)[0]);
$must($docKeys === $objectState['keys_contract'], "docs/03 and schema-contract.json keys differ:\ndocs/03:  {$docKeys}\ncontract: {$objectState['keys_contract']}");
$ok('schema.sql, schema-contract.json and docs/03 declare idx_parent_kind (parent_id, kind) with the same keys text');

/* 2. Migrator adds the index to an existing table, once */
if ($indexColumns() !== []) {
    $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `idx_parent_kind`");
}
$must($indexColumns() === [], 'Could not drop idx_parent_kind for the migration check');

$migrator = new SchemaMigrator($wpdb);
$first = $migrator->migrate();
$must(!empty($first['success']), 'migrate() failed: ' . wp_json_encode($first));
$must(in_array($table . '.idx_parent_kind', (array)$first['applied'], true), 'migrate() did not report adding idx_parent_kind: ' . wp_json_encode($first['applied']));
$must($indexColumns() === ['parent_id', 'kind'], 'idx_parent_kind columns: ' . wp_json_encode($indexColumns()));
$must((int)get_option(SchemaMigrator::OPTION_SCHEMA_VERSION) === 5 && SchemaMigrator::SCHEMA_VERSION === 5, 'Schema version is not 5');

$second = $migrator->migrate();
$must(!empty($second['success']) && !in_array($table . '.idx_parent_kind', (array)$second['applied'], true), 'Second migrate() re-applied the index: ' . wp_json_encode($second));
$must($indexColumns() === ['parent_id', 'kind'], 'Index changed after second migrate()');
$ok('migrate() adds idx_parent_kind to an existing table, records schema version 5 and is idempotent');

/* 3. The mirror's per-parent query uses it */
$parentId = (int)$wpdb->get_var("SELECT parent_id FROM `{$table}` WHERE kind = 'variation' AND parent_id IS NOT NULL LIMIT 1");
$plan = $wpdb->get_results($wpdb->prepare(
    "EXPLAIN SELECT object_id FROM `{$table}` WHERE parent_id = %d AND kind = 'variation' AND native_revision > native_applied",
    $parentId > 0 ? $parentId : 1
), ARRAY_A) ?: [];
$must(isset($plan[0]) && (string)$plan[0]['key'] === 'idx_parent_kind', 'Mirror query does not use idx_parent_kind: ' . wp_json_encode($plan));
$rows = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
$ok("mirror per-parent query uses idx_parent_kind ({$plan[0]['type']}, {$plan[0]['rows']} of {$rows} rows)");

/* 4. Shopify category_mode default matches docs/10 §7 */
foreach ([null, '', 'path', 'PATH', 'tree', 'whatever'] as $in) {
    $must(ShopifyBatchImporter::normalizeCategoryMode($in) === 'path', 'normalizeCategoryMode(' . var_export($in, true) . ') !== path');
}
$must(ShopifyBatchImporter::normalizeCategoryMode('none') === 'none', "normalizeCategoryMode('none') !== none");
$docs10 = (string)file_get_contents($root . '/docs/10-integrations.md');
$must(strpos($docs10, 'map thành Woo category theo mặc định (`category_mode=path`') !== false, 'docs/10 §7 no longer states the path default');
$ok('category_mode: missing/unknown values map to path, none is kept, as docs/10 §7 states');

echo "ALL P042 OBJECT STATE INDEX / CONTRACT ASSERTIONS PASSED ({$checks} checks)\n";
