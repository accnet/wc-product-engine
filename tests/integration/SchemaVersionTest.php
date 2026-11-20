<?php

/**
 * DB-01 regression tests for schema versioning.
 *
 * - A site whose stored wpe_schema_version is 1 (installed before P011) and lacks the media tables
 *   gets them from PluginBootstrap::ensureSchema() on load, and ends at SCHEMA_VERSION.
 * - The migration lock is exclusive across database connections and released afterwards.
 *
 * Tables are created under a disposable prefix (as T05Test does) and dropped at the end. The only
 * live row touched is the wpe_schema_version option, which is put back to its previous value.
 *
 * Run: wp eval-file tests/integration/SchemaVersionTest.php
 */

if (!defined('ABSPATH')) {
    $wp_load = (getenv('WPE_WP_PATH') ?: '/var/www/site1.localhost') . '/wp-load.php';
    if (file_exists($wp_load)) {
        require_once $wp_load;
    } else {
        fwrite(STDERR, "Error: WordPress environment not found.\n");
        exit(1);
    }
}

require_once dirname(__DIR__, 2) . '/wc-product-engine.php';

use WPE\Bootstrap\PluginBootstrap;
use WPE\Infrastructure\Database\SchemaMigrator;

class SchemaVersionTest
{
    private const TMP_PREFIX = 'wpetdb01_';
    private const MEDIA_TABLES = ['wpe_asset_storage', 'wpe_upload_items'];

    private int $checks = 0;

    public function run(): void
    {
        echo "Running schema version tests (DB-01)...\n";
        global $wpdb;
        $livePrefix = $wpdb->prefix;
        $liveVersion = get_option(SchemaMigrator::OPTION_SCHEMA_VERSION, null);

        try {
            $this->check(SchemaMigrator::SCHEMA_VERSION >= 2, 'SCHEMA_VERSION must include the P011 media tables (>= 2)');
            $this->testPreP011SiteGetsMediaTables();
            $this->testLockIsExclusiveAcrossConnections();
            echo "All schema version tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $exit = 1;
        } finally {
            $wpdb->prefix = $livePrefix;
            $this->dropTmpTables();
            $liveVersion === null
                ? delete_option(SchemaMigrator::OPTION_SCHEMA_VERSION)
                : update_option(SchemaMigrator::OPTION_SCHEMA_VERSION, $liveVersion);
        }
        if (!empty($exit)) {
            exit(1);
        }
    }

    private function testPreP011SiteGetsMediaTables(): void
    {
        echo ' a version-1 site without the media tables is upgraded on load...';
        global $wpdb;
        $livePrefix = $wpdb->prefix;
        $this->dropTmpTables();
        try {
            $wpdb->prefix = self::TMP_PREFIX;
            $fresh = (new SchemaMigrator($wpdb))->migrate();
            $this->check(!empty($fresh['success']), 'Fresh install on the disposable prefix failed: ' . ($fresh['error'] ?? ''));

            // Recreate the pre-P011 state: version 1 and no media tables.
            foreach (self::MEDIA_TABLES as $table) {
                $wpdb->query('DROP TABLE IF EXISTS `' . self::TMP_PREFIX . $table . '`');
            }
            update_option(SchemaMigrator::OPTION_SCHEMA_VERSION, 1);

            PluginBootstrap::ensureSchema();

            foreach (self::MEDIA_TABLES as $table) {
                $name = self::TMP_PREFIX . $table;
                $this->check($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name, "{$name} must be created by ensureSchema()");
            }
            $this->check(
                (int)get_option(SchemaMigrator::OPTION_SCHEMA_VERSION) === SchemaMigrator::SCHEMA_VERSION,
                'Stored schema version must reach ' . SchemaMigrator::SCHEMA_VERSION
            );
            $this->check((new SchemaMigrator($wpdb))->verifySchema()['valid'], 'Every contract table must exist after the upgrade');
        } finally {
            $wpdb->prefix = $livePrefix;
        }
        echo " ok\n";
    }

    private function testLockIsExclusiveAcrossConnections(): void
    {
        echo ' the migration lock is exclusive across connections...';
        global $wpdb;
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->set_prefix($wpdb->prefix);

        $a = new SchemaMigrator($wpdb);
        $b = new SchemaMigrator($other);
        $this->check($a->acquireLock(), 'First connection must get the lock');
        try {
            $this->check(!$b->acquireLock(), 'Second connection must not get a held lock');
            $blocked = $b->migrate();
            $this->check(empty($blocked['success']), 'migrate() must refuse to run while another connection holds the lock');
        } finally {
            $a->releaseLock();
        }
        $this->check($b->acquireLock(), 'Lock must be free after release');
        $b->releaseLock();
        $other->close();
        echo " ok\n";
    }

    private function dropTmpTables(): void
    {
        global $wpdb;
        foreach ($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::TMP_PREFIX . 'wpe_') . '%')) as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    private function check(bool $condition, string $message): void
    {
        $this->checks++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

(new SchemaVersionTest())->run();
