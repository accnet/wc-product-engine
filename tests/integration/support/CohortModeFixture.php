<?php
/**
 * Integration test fixture: pin the cohort mode a test needs and restore it (P048).
 *
 * The integration tests share one test database, and `cohort_mode` in the feature flags option
 * changes how new shells, captures and meta reads behave. A test that depends on a mode must
 * pin it instead of relying on what the previous test left:
 *
 *   require_once __DIR__ . '/support/CohortModeFixture.php';
 *   WpeCohortModeFixture::pin('native_bridge');
 *
 * A test that changes the mode itself (rollback, cutover) calls preserve(). Either way the flags
 * option is restored exactly, also when the option did not exist, from a shutdown function, so a
 * failing assertion or a fatal error cannot leave the mode behind.
 */

if (!class_exists('WpeCohortModeFixture')) {
    final class WpeCohortModeFixture
    {
        /** @var array|false|null Flags option as found before the test; null until preserve(). */
        private static $snapshot = null;

        public static function preserve(): void
        {
            if (self::$snapshot !== null) {
                return;
            }
            self::$snapshot = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, false);
            register_shutdown_function([self::class, 'restore']);
        }

        public static function pin(string $mode): void
        {
            self::preserve();
            $flags = get_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS);
            if (!is_array($flags)) {
                $flags = \WPE\Bootstrap\PluginBootstrap::DEFAULT_FLAGS;
            }
            $flags['cohort_mode'] = $mode;
            update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, $flags);
            self::reload();
        }

        public static function restore(): void
        {
            if (self::$snapshot === null) {
                return;
            }
            if (self::$snapshot === false) {
                delete_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS);
            } else {
                update_option(\WPE\Bootstrap\PluginBootstrap::OPTION_FLAGS, self::$snapshot);
            }
            self::$snapshot = null;
            self::reload();
        }

        private static function reload(): void
        {
            \WPE\Bootstrap\PluginBootstrap::instance()->loadFlags();
            \WPE\Support\AuthorityResolver::flushAll();
        }
    }
}
