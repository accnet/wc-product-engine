<?php

namespace WPE\Infrastructure\Database;

class SchemaMigrator
{
    /**
     * Version of contracts/database/schema.sql. Bump it whenever schema.sql changes: sites whose
     * stored version is lower re-run migrate() on load (PluginBootstrap::ensureSchema()).
     *
     * 1: T05, 25 tables.
     * 2: P011 media library tables wpe_asset_storage and wpe_upload_items (audit DB-01: sites
     *    installed before P011 stayed at 1 and never got them).
     * 3: P028 workload sort indexes on wpe_catalog (idx_cat_menu_order, idx_cat_rating,
     *    idx_cat_title, idx_cat_price) per docs/03-database-schema.md §4.
     */
    public const SCHEMA_VERSION = 3;
    public const OPTION_SCHEMA_VERSION = 'wpe_schema_version';
    public const OPTION_MIGRATION_CHECKPOINT = 'wpe_migration_checkpoint';
    /** @deprecated 1.1.1 The migration lock is a MySQL named lock now; kept for callers that reference it. */
    public const LOCK_TRANSIENT = 'wpe_migration_lock';

    private \wpdb $wpdb;
    private string $schemaFile;

    public function __construct(?\wpdb $wpdb = null, ?string $schemaFile = null)
    {
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->schemaFile = $schemaFile ?? dirname(__DIR__, 3) . '/contracts/database/schema.sql';
    }

    /**
     * Acquire the migration lock without waiting.
     *
     * A MySQL named lock (GET_LOCK) instead of a transient: taking it is atomic, so two requests
     * cannot both pass a get-then-set check, and the server frees it if the PHP process dies
     * (audit DB-01). The name includes the table prefix so sites sharing a database do not block
     * each other.
     *
     * @param int $timeout Unused; kept for backward compatibility. The lock lives as long as the connection.
     */
    public function acquireLock(int $timeout = 60): bool
    {
        return (int)$this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, 0)', $this->lockName())) === 1;
    }

    /**
     * Release migration lock.
     */
    public function releaseLock(): void
    {
        $this->wpdb->query($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
    }

    private function lockName(): string
    {
        // MySQL lock names are limited to 64 characters.
        return substr($this->wpdb->prefix . 'wpe_schema_migration', 0, 64);
    }

    /**
     * Parse schema.sql into individual table DDL statements.
     *
     * @return array<string, string> Array mapping table_name => create_sql
     */
    public function parseSchemaSql(): array
    {
        if (!file_exists($this->schemaFile)) {
            throw new \RuntimeException("Schema file not found at: {$this->schemaFile}");
        }

        $sql = (string)file_get_contents($this->schemaFile);
        $prefix = $this->wpdb->prefix;
        $sql = str_replace('{prefix}', $prefix, $sql);

        $statements = [];
        $chunks = preg_split('/;\s*\n/', $sql);

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if (empty($chunk)) {
                continue;
            }
            if (preg_match('/CREATE TABLE IF NOT EXISTS `([^`]+)`/i', $chunk, $matches)) {
                $tableName = $matches[1];
                $statements[$tableName] = $chunk . ';';
            }
        }

        return $statements;
    }

    /**
     * Run migration to target version.
     */
    public function migrate(): array
    {
        if (!$this->acquireLock()) {
            return [
                'success' => false,
                'error' => 'Migration currently locked by another process.',
                'applied' => []
            ];
        }

        $applied = [];
        try {
            $statements = $this->parseSchemaSql();
            $checkpoint = get_option(self::OPTION_MIGRATION_CHECKPOINT, []);
            if (!is_array($checkpoint)) {
                $checkpoint = [];
            }

            foreach ($statements as $tableName => $createSql) {
                if (in_array($tableName, $checkpoint, true)) {
                    // Check if table physically exists
                    $exists = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $tableName));
                    if ($exists === $tableName) {
                        continue;
                    }
                }

                // Execute DDL statement
                $res = $this->wpdb->query($createSql);
                if ($res === false) {
                    throw new \RuntimeException("Failed to create table {$tableName}: " . $this->wpdb->last_error);
                }

                $checkpoint[] = $tableName;
                update_option(self::OPTION_MIGRATION_CHECKPOINT, array_unique($checkpoint));
                $applied[] = $tableName;
            }

            $this->ensureCatalogWorkloadIndexes($applied);

            update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
            delete_option(self::OPTION_MIGRATION_CHECKPOINT);

            return [
                'success' => true,
                'schema_version' => self::SCHEMA_VERSION,
                'applied' => $applied,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'applied' => $applied,
            ];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Ensure workload B-Tree sort indexes exist on {prefix}wpe_catalog (P028 D03).
     *
     * @param string[] $applied
     */
    private function ensureCatalogWorkloadIndexes(array &$applied): void
    {
        $catalogTable = $this->wpdb->prefix . 'wpe_catalog';
        $exists = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $catalogTable));
        if ($exists !== $catalogTable) {
            return;
        }

        $existing = [];
        $rows = $this->wpdb->get_results("SHOW INDEX FROM `{$catalogTable}`", ARRAY_A) ?: [];
        foreach ($rows as $row) {
            if (!empty($row['Key_name'])) {
                $existing[(string)$row['Key_name']] = true;
            }
        }

        $indexes = [
            'idx_cat_menu_order' => '(`generation`, `status`, `menu_order`, `product_id`)',
            'idx_cat_rating'     => '(`generation`, `status`, `rating_average`, `product_id`)',
            'idx_cat_title'      => '(`generation`, `status`, `title_sort`, `product_id`)',
            'idx_cat_price'      => '(`generation`, `status`, `min_price`, `product_id`)',
        ];

        foreach ($indexes as $indexName => $columnsSql) {
            if (isset($existing[$indexName])) {
                continue;
            }
            $res = $this->wpdb->query("ALTER TABLE `{$catalogTable}` ADD INDEX `{$indexName}` {$columnsSql}");
            if ($res === false) {
                throw new \RuntimeException("Failed to create index {$indexName} on {$catalogTable}: " . $this->wpdb->last_error);
            }
            $applied[] = "{$catalogTable}.{$indexName}";
        }
    }

    /**
     * Verify that every table declared in the schema contract exists in the database.
     */
    public function verifySchema(): array
    {
        $statements = $this->parseSchemaSql();
        $expectedTables = array_keys($statements);
        $missing = [];

        foreach ($expectedTables as $table) {
            $exists = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists !== $table) {
                $missing[] = $table;
            }
        }

        $currentVersion = (int)get_option(self::OPTION_SCHEMA_VERSION, 0);

        return [
            'valid' => empty($missing),
            'schema_version' => $currentVersion,
            'expected_tables_count' => count($expectedTables),
            'existing_tables_count' => count($expectedTables) - count($missing),
            'missing_tables' => $missing,
        ];
    }

    /**
     * Repair schema by creating any missing tables.
     */
    public function repairSchema(): array
    {
        return $this->migrate();
    }
}
