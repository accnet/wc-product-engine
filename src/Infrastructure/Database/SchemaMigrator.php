<?php

namespace WPE\Infrastructure\Database;

class SchemaMigrator
{
    public const SCHEMA_VERSION = 1;
    public const OPTION_SCHEMA_VERSION = 'wpe_schema_version';
    public const OPTION_MIGRATION_CHECKPOINT = 'wpe_migration_checkpoint';
    public const LOCK_TRANSIENT = 'wpe_migration_lock';

    private \wpdb $wpdb;
    private string $schemaFile;

    public function __construct(?\wpdb $wpdb = null, ?string $schemaFile = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->schemaFile = $schemaFile ?? dirname(__DIR__, 3) . '/contracts/database/schema.sql';
    }

    /**
     * Acquire migration lock.
     */
    public function acquireLock(int $timeout = 60): bool
    {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, time() + $timeout, $timeout);
        return true;
    }

    /**
     * Release migration lock.
     */
    public function releaseLock(): void
    {
        delete_transient(self::LOCK_TRANSIENT);
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
     * Verify that all 25 tables exist in database.
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
