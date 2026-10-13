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
        $ac = $this->testAcceptanceCriteria();

        echo "\n=== Test Summary ===\n";
        echo "R14 (migration_idempotent): " . ($r14 ? "PASSED" : "FAILED") . "\n";
        echo "R15 (prefix_multisite): " . ($r15 ? "PASSED" : "FAILED") . "\n";
        echo "R16 (crash_between_ddl): " . ($r16 ? "PASSED" : "FAILED") . "\n";
        echo "R17 (decimal_precision_and_collation): " . ($r17 ? "PASSED" : "FAILED") . "\n";

        foreach ($ac as $id => $row) {
            echo "{$id} ({$row[0]}): " . ($row[1] ? "PASSED" : "FAILED") . "\n";
        }

        if ($r14 && $r15 && $r16 && $r17 && !in_array(false, array_column($ac, 1), true)) {
            echo "All T05 tests passed successfully.\n";
            return 0;
        }

        echo "T05 tests failed.\n";
        return 1;
    }

    /**
     * Number of tables declared by contracts/database/schema-contract.json; schema.sql must declare the same set.
     */
    private function contractTableCount(): int
    {
        $contract = json_decode((string) file_get_contents($this->root() . '/contracts/database/schema-contract.json'), true);
        $names = array_map(static fn ($t) => $t['name'], $contract['tables'] ?? []);
        $sqlNames = array_map(
            static fn ($t) => preg_replace('/^.*?wpe_/', '', $t),
            array_keys($this->migrator->parseSchemaSql())
        );
        sort($names);
        sort($sqlNames);
        if ($names !== $sqlNames) {
            throw new RuntimeException('schema.sql and schema-contract.json declare different table sets');
        }
        return count($names);
    }

    /**
     * R14: Migration is idempotent and creates every table declared in the schema contract.
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

        // Verify every table of the contract exists; the expected count comes from the contract, not a literal.
        $expectedCount = $this->contractTableCount();
        $verify = $this->migrator->verifySchema();
        if (!$verify['valid'] || $verify['existing_tables_count'] !== $expectedCount || $verify['expected_tables_count'] !== $expectedCount) {
            echo "  [FAIL] Expected {$expectedCount} tables, found {$verify['existing_tables_count']}. Missing: " . implode(', ', $verify['missing_tables']) . "\n";
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
        if (!$verify['valid'] || $verify['existing_tables_count'] !== $this->contractTableCount()) {
            echo "  [FAIL] Schema incomplete after resume.\n";
            return false;
        }

        // Verify checkpoint option was cleared upon complete success
        $chk = get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT);
        if ($chk !== false) {
            echo "  [FAIL] Migration checkpoint was not cleared upon completion.\n";
            return false;
        }

        echo "  [OK] Migration successfully resumed from checkpoint and finished all contract tables.\n";
        return true;
    }

    /**
     * AC-T05-01..03 on a throw-away table prefix, so the live wpe_ tables are never dropped or altered.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    private function testAcceptanceCriteria(): array
    {
        echo "[AC-T05] Verifying migration acceptance criteria on a disposable prefix...\n";
        $wpdb = $this->wpdb;
        $livePrefix = $wpdb->prefix;
        $tmpPrefix = 'wpet05_';
        $liveVersion = get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION, null);
        $liveCheckpoint = get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT, null);
        $coreTables = [$wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users];
        $fingerprint = function () use ($wpdb, $coreTables): string {
            $parts = [];
            foreach ($coreTables as $table) {
                $parts[] = preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N)[1] ?? ''));
            }
            return hash('sha256', implode('|', $parts));
        };
        $dropTmp = function () use ($wpdb, $tmpPrefix): void {
            foreach ($wpdb->get_col("SHOW TABLES LIKE '" . $tmpPrefix . "wpe\\_%'") as $table) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        };
        $ok1 = $ok2 = $ok3 = false;
        $dropTmp();
        $coreBefore = $fingerprint();
        $ddl = (string) file_get_contents($this->root() . '/contracts/database/schema.sql');
        try {
            $wpdb->prefix = $tmpPrefix;
            $migrator = new \WPE\Infrastructure\Database\SchemaMigrator($wpdb);
            $expected = count($migrator->parseSchemaSql());

            // Fresh install.
            delete_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION);
            $started = microtime(true);
            $fresh = $migrator->migrate();
            $elapsed = microtime(true) - $started;
            $freshOk = $fresh['success'] && $migrator->verifySchema()['existing_tables_count'] === $expected
                && (int) get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION) === \WPE\Infrastructure\Database\SchemaMigrator::SCHEMA_VERSION;

            // Upgrade from N-1: version 0, two tables missing, existing data must survive.
            $wpdb->query($wpdb->prepare("INSERT INTO `{$tmpPrefix}wpe_control` (`key`, `value`, `updated_at`) VALUES (%s, %s, %s)", 'ac-t05-sentinel', 'keep', gmdate('Y-m-d H:i:s.u')));
            $wpdb->query("DROP TABLE `{$tmpPrefix}wpe_audit`");
            update_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION, 0);
            $upgrade = $migrator->migrate();
            $upgradeOk = $upgrade['success'] && $migrator->verifySchema()['valid']
                && (int) get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION) === \WPE\Infrastructure\Database\SchemaMigrator::SCHEMA_VERSION;
            $survived = $wpdb->get_var("SELECT `value` FROM `{$tmpPrefix}wpe_control` WHERE `key` = 'ac-t05-sentinel'") === 'keep';

            // Interrupted migration: a broken statement stops the run, the engine is not enabled, a corrected run resumes.
            $dropTmp();
            delete_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION);
            $broken = sys_get_temp_dir() . '/wpe-t05-broken-' . uniqid() . '.sql';
            $chunks = preg_split('/;\s*\n/', $ddl);
            $chunks[4] = 'CREATE TABLE IF NOT EXISTS `{prefix}wpe_broken_ac_t05` (`id` INT NOT NULL, BROKEN SYNTAX HERE)';
            file_put_contents($broken, implode(";\n", $chunks));
            $wpdb->suppress_errors(true);
            $partial = (new \WPE\Infrastructure\Database\SchemaMigrator($wpdb, $broken))->migrate();
            $wpdb->suppress_errors(false);
            @unlink($broken);
            $checkpoint = get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT, []);
            $partialBlocked = !$partial['success'] && !empty($checkpoint) && count($checkpoint) < $expected
                && (int) get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION, 0) !== \WPE\Infrastructure\Database\SchemaMigrator::SCHEMA_VERSION
                && !$migrator->verifySchema()['valid'];
            $resumed = $migrator->migrate();
            $resumeOk = $resumed['success'] && $migrator->verifySchema()['valid'] && get_option(\WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT, false) === false;

            $ok1 = $freshOk && $upgradeOk && $survived && $partialBlocked && $resumeOk;
            // Budget: DDL on empty new tables only; a fresh 25-table install must finish well under 5 seconds.
            $noLockStatements = !preg_match('/\b(LOCK\s+TABLES|ALTER\s+TABLE|DROP\s+TABLE|RENAME\s+TABLE|TRUNCATE)\b/i', $ddl);
            $ok2 = $elapsed < 5.0 && $noLockStatements && $partialBlocked;
            echo $ok1 ? "  [OK] Fresh install, N-1 upgrade (data kept) and interrupted-migration resume all pass.\n" : "  [FAIL] Migration paths: fresh=" . var_export($freshOk, true) . " upgrade=" . var_export($upgradeOk, true) . " survived=" . var_export($survived, true) . " partialBlocked=" . var_export($partialBlocked, true) . " resume=" . var_export($resumeOk, true) . "\n";
            echo $ok2 ? sprintf("  [OK] Fresh install took %.3fs, no lock/alter statements, partial schema keeps the engine disabled.\n", $elapsed) : "  [FAIL] Migration exceeded budget, uses locking DDL or enabled the engine on a partial schema.\n";
        } finally {
            $wpdb->prefix = $livePrefix;
            $dropTmp();
            foreach ([\WPE\Infrastructure\Database\SchemaMigrator::OPTION_SCHEMA_VERSION => $liveVersion, \WPE\Infrastructure\Database\SchemaMigrator::OPTION_MIGRATION_CHECKPOINT => $liveCheckpoint] as $option => $value) {
                $value === null ? delete_option($option) : update_option($option, $value);
            }
        }

        // AC-T05-03: only wpe_ tables are created and core table definitions are untouched.
        preg_match_all('/^\s*(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b[^`]*`([^`]+)`/mi', $ddl, $statements, PREG_SET_ORDER);
        $onlyWpe = !empty($statements);
        foreach ($statements as $statement) {
            $onlyWpe = $onlyWpe && strtoupper($statement[1]) === 'CREATE' && str_starts_with($statement[2], '{prefix}wpe_');
        }
        $ok3 = $onlyWpe && $coreBefore === $fingerprint();
        echo $ok3 ? "  [OK] Migration only creates wpe_ tables; core table definitions unchanged.\n" : "  [FAIL] Migration touched core tables or non-wpe_ objects.\n";

        return [
            'AC-T05-01' => ['fresh_upgrade_resume', $ok1],
            'AC-T05-02' => ['no_long_lock_partial_schema_blocked', $ok2],
            'AC-T05-03' => ['core_tables_untouched', $ok3],
        ];
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
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
