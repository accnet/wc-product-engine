<?php
/**
 * T05 Integration Test — DDL and migration runner verification.
 *
 * Verifies R14 (migration_idempotent), R15 (prefix_multisite),
 * R16 (crash_between_ddl), and R17 (decimal_precision_and_collation).
 */

if (!defined('ABSPATH')) {
    $wp_load = '/var/www/site1.localhost/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

class T05Test
{
    private \WPE\Infrastructure\Database\SchemaMigrator $migrator;
    private \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->migrator = new \WPE\Infrastructure\Database\SchemaMigrator($wpdb);
    }

    public function run(): int
    {
        echo "=== Running T05 Integration Tests ===\n";

        $r14 = $this->testMigrationIdempotent();
        $r15 = $this->testPrefixMultisite();
        $r16 = $this->testCrashBetweenDdl();
        $r17 = $this->testDecimalPrecisionAndCollation();

        echo "\n=== Test Summary ===\n";
        echo "R14 (migration_idempotent): " . ($r14 ? "PASSED" : "FAILED") . "\n";
        echo "R15 (prefix_multisite): " . ($r15 ? "PASSED" : "FAILED") . "\n";
        echo "R16 (crash_between_ddl): " . ($r16 ? "PASSED" : "FAILED") . "\n";
        echo "R17 (decimal_precision_and_collation): " . ($r17 ? "PASSED" : "FAILED") . "\n";

        if ($r14 && $r15 && $r16 && $r17) {
            echo "All T05 tests passed successfully.\n";
            return 0;
        }

        echo "T05 tests failed.\n";
        return 1;
    }

    /**
     * R14: Migration is idempotent and creates all 25 tables.
     */
    private function testMigrationIdempotent(): bool
    {
        echo "[R14] Verifying migration idempotence...\n";

        // First run: fresh migration
        $res1 = $this->migrator->migrate();
        if (!$res1['success']) {
            echo "  [FAIL] First migration run failed: " . ($res1['error'] ?? 'unknown error') . "\n";
            return false;
        }

        // Verify all 25 tables exist
        $verify = $this->migrator->verifySchema();
        if (!$verify['valid'] || $verify['existing_tables_count'] !== 25) {
            echo "  [FAIL] Expected 25 tables, found {$verify['existing_tables_count']}. Missing: " . implode(', ', $verify['missing_tables']) . "\n";
            return false;
        }
        echo "  [OK] First migration successfully created {$verify['existing_tables_count']} tables.\n";

        // Second run: idempotent re-run
        $res2 = $this->migrator->migrate();
        if (!$res2['success']) {
            echo "  [FAIL] Second migration run failed: " . ($res2['error'] ?? 'unknown error') . "\n";
            return false;
        }

        $verify2 = $this->migrator->verifySchema();
        if (!$verify2['valid']) {
            echo "  [FAIL] Schema invalid after second migration run.\n";
            return false;
        }

        echo "  [OK] Migration is strictly idempotent.\n";
        return true;
    }

    /**
     * R15: Tables use dynamic $wpdb->prefix.
     */
    private function testPrefixMultisite(): bool
    {
        echo "[R15] Verifying dynamic table prefixes and multisite compatibility...\n";

        $prefix = $this->wpdb->prefix;
        $products_table = "{$prefix}wpe_products";

        $exists = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $products_table));
        if ($exists !== $products_table) {
            echo "  [FAIL] Expected table {$products_table} with prefix {$prefix} not found.\n";
            return false;
        }

        // Verify statements parsing respects prefix replacement
        $statements = $this->migrator->parseSchemaSql();
        foreach (array_keys($statements) as $table) {
            if (!str_starts_with($table, "{$prefix}wpe_")) {
                echo "  [FAIL] Table {$table} does not start with {$prefix}wpe_\n";
                return false;
            }
        }

        echo "  [OK] All tables dynamically prefixed with {$prefix}wpe_\n";
        return true;
    }

    /**
     * R16: Interrupted migration can resume from checkpoint.
     */
    private function testCrashBetweenDdl(): bool
    {
        echo "[R16] Verifying interrupted migration checkpoint and resume...\n";

        // Simulate a checkpoint halfway through (10 tables applied)
        $statements = $this->migrator->parseSchemaSql();
        $table_names = array_keys($statements);
        $halfway = array_slice($table_names, 0, 10);

        update_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT, $halfway);

        // Resume migration
        $res = $this->migrator->migrate();
        if (!$res['success']) {
            echo "  [FAIL] Resume from checkpoint failed: " . ($res['error'] ?? 'unknown error') . "\n";
            return false;
        }

        $verify = $this->migrator->verifySchema();
        if (!$verify['valid'] || $verify['existing_tables_count'] !== 25) {
            echo "  [FAIL] Schema incomplete after resume.\n";
            return false;
        }

        // Verify checkpoint option was cleared upon complete success
        $chk = get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT);
        if ($chk !== false) {
            echo "  [FAIL] Migration checkpoint was not cleared upon completion.\n";
            return false;
        }

        echo "  [OK] Migration successfully resumed from checkpoint and finished all 25 tables.\n";
        return true;
    }

    /**
     * R17: Decimal precision DECIMAL(19,6) and collation check.
     */
    private function testDecimalPrecisionAndCollation(): bool
    {
        echo "[R17] Verifying decimal precision AMT/QTY and table collation on MariaDB...\n";

        $prefix = $this->wpdb->prefix;
        $products_table = "{$prefix}wpe_products";
        $inventory_table = "{$prefix}wpe_inventory";

        // Check regular_price column type
        $price_col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$products_table}` LIKE 'regular_price'");
        if (!$price_col || stripos($price_col->Type, 'decimal(19,6)') === false) {
            echo "  [FAIL] regular_price column type is not decimal(19,6): " . ($price_col->Type ?? 'null') . "\n";
            return false;
        }
        echo "  [OK] regular_price is decimal(19,6).\n";

        // Check quantity column type in inventory
        $qty_col = $this->wpdb->get_row("SHOW COLUMNS FROM `{$inventory_table}` LIKE 'quantity'");
        if (!$qty_col || stripos($qty_col->Type, 'decimal(19,6)') === false) {
            echo "  [FAIL] quantity column type is not decimal(19,6): " . ($qty_col->Type ?? 'null') . "\n";
            return false;
        }
        echo "  [OK] inventory.quantity is decimal(19,6).\n";

        // Check collation
        $table_status = $this->wpdb->get_row("SHOW TABLE STATUS LIKE '{$products_table}'");
        if (!$table_status || stripos($table_status->Collation, 'utf8mb4') === false) {
            echo "  [FAIL] Table collation is not utf8mb4: " . ($table_status->Collation ?? 'null') . "\n";
            return false;
        }
        echo "  [OK] Table collation verified as {$table_status->Collation}.\n";

        return true;
    }
}

$test = new T05Test();
$exit_code = $test->run();
exit($exit_code);
