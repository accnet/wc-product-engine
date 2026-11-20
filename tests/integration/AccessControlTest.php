<?php

/**
 * Access control regression tests for REFACTOR_REPORT.md security findings.
 *
 * - SEC-01: GET /wpe/v1/catalog is limited to users who can edit products. Anonymous and
 *   low-privilege callers are refused, so draft/private products in the index never leak.
 * - SEC-02: shop managers (manage_woocommerce) read health/status but cannot run operations
 *   actions or change the engine gates of Settings -> Features; administrators can.
 *
 * Runs through the real REST server (rest_do_request). Creates throw-away users and deletes them.
 *
 * Run: wp eval-file tests/integration/AccessControlTest.php
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

use WPE\Admin\OperationsPage;
use WPE\Admin\SettingsRegistry;
use WPE\Application\Catalog\CatalogQuery;
use WPE\Bootstrap\PluginBootstrap;

class AccessControlTest
{
    private int $originalUser = 0;
    private int $checks = 0;
    /** @var int[] */
    private array $users = [];

    public function run(): void
    {
        echo "Running access control tests...\n";
        $this->originalUser = get_current_user_id();

        // The catalog route is only registered when query_enabled is on; register it for the test.
        add_action('rest_api_init', [CatalogQuery::class, 'registerRestRoutes']);

        try {
            $this->testCatalogRefusesAnonymous();
            $this->testCatalogRefusesSubscriber();
            $this->testCatalogAllowsProductEditors();
            $this->testShopManagerReadsHealthOnly();
            $this->testAdministratorRunsOperations();
            $this->testEngineGatesAreAdminOnly();
            $this->cleanup();
            echo "All access control tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->cleanup();
            exit(1);
        }
    }

    private function testCatalogRefusesAnonymous(): void
    {
        echo ' SEC-01 anonymous catalog request is refused...';
        wp_set_current_user(0);
        foreach ([[], ['status' => 'draft'], ['visibility' => 'hidden'], ['generation' => 1]] as $params) {
            $status = $this->catalog($params)->get_status();
            $this->check($status === 401, 'Anonymous GET /catalog ' . wp_json_encode($params) . ' must be 401, got ' . $status);
        }
        echo " ok\n";
    }

    private function testCatalogRefusesSubscriber(): void
    {
        echo ' SEC-01 subscriber catalog request is refused...';
        wp_set_current_user($this->makeUser('subscriber'));
        $status = $this->catalog(['status' => 'draft'])->get_status();
        $this->check($status === 403, 'Subscriber GET /catalog must be 403, got ' . $status);
        echo " ok\n";
    }

    private function testCatalogAllowsProductEditors(): void
    {
        echo ' SEC-01 shop manager and administrator can query the catalog...';
        foreach (['shop_manager', 'administrator'] as $role) {
            wp_set_current_user($this->makeUser($role));
            $this->check(current_user_can('edit_products'), "{$role} must have edit_products");
            $status = $this->catalog(['limit' => 1])->get_status();
            $this->check(!in_array($status, [401, 403], true), "{$role} GET /catalog must not be refused, got {$status}");
        }
        echo " ok\n";
    }

    private function testShopManagerReadsHealthOnly(): void
    {
        echo ' SEC-02 shop manager reads health/status but cannot run actions...';
        $uid = $this->makeUser('shop_manager');
        wp_set_current_user($uid);
        $this->check(current_user_can('manage_woocommerce'), 'shop_manager must have manage_woocommerce');
        $this->check(!current_user_can('wpe_manage_operations'), 'shop_manager must not have wpe_manage_operations');

        $status = $this->rest('GET', '/wpe/v1/operations/health')->get_status();
        $this->check($status === 200, 'Shop manager GET /operations/health must be 200, got ' . $status);
        $status = $this->rest('GET', '/wpe/v1/status')->get_status();
        $this->check($status === 200, 'Shop manager GET /status must be 200, got ' . $status);

        // 'mode' without a target only reports the current mode: harmless if the check ever regresses.
        $status = $this->rest('POST', '/wpe/v1/operations/action', ['action' => 'mode', 'params' => []])->get_status();
        $this->check($status === 403, 'Shop manager POST /operations/action must be 403, got ' . $status);

        $refused = false;
        try {
            OperationsPage::getInstance()->handleAction('mode', [], $uid);
        } catch (\RuntimeException $e) {
            $refused = str_contains($e->getMessage(), 'Unauthorized');
        }
        $this->check($refused, 'handleAction must refuse a shop manager actor');
        echo " ok\n";
    }

    private function testAdministratorRunsOperations(): void
    {
        echo ' SEC-02 administrator runs operations actions...';
        $uid = $this->makeUser('administrator');
        PluginBootstrap::instance()->registerCapabilities();
        wp_set_current_user($uid);
        $this->check(current_user_can('wpe_manage_operations'), 'administrator must have wpe_manage_operations');
        $res = $this->rest('POST', '/wpe/v1/operations/action', ['action' => 'mode', 'params' => []]);
        $this->check($res->get_status() === 200, 'Administrator POST /operations/action mode must be 200, got ' . $res->get_status());
        echo " ok\n";
    }

    private function testEngineGatesAreAdminOnly(): void
    {
        echo ' SEC-02 engine gates in Settings are administrator-only...';
        $this->check(
            SettingsRegistry::adminOnlyKeys() === ['variants_ui_enabled', 'native_writes_enabled'],
            'Unexpected admin-only keys: ' . wp_json_encode(SettingsRegistry::adminOnlyKeys())
        );
        $original = get_option(PluginBootstrap::OPTION_FLAGS, null);
        $flags = PluginBootstrap::instance()->getFlags();
        $native = (bool)($flags['native_writes_enabled'] ?? false);
        $variants = (bool)($flags['variants_ui_enabled'] ?? false);
        $spd = (bool)($flags['single_price_display'] ?? false);

        try {
            wp_set_current_user($this->makeUser('shop_manager'));
            $res = $this->rest('POST', '/wpe/v1/admin/settings', ['native_writes_enabled' => !$native]);
            $this->check($res->get_status() === 403, 'Shop manager flipping native_writes_enabled must be 403, got ' . $res->get_status());
            $this->check(get_option(PluginBootstrap::OPTION_FLAGS, null) == $original, 'A refused save must not write the flags option');

            // Repeating stored gate values alongside other settings is allowed (the screen may send them).
            $res = $this->rest('POST', '/wpe/v1/admin/settings', [
                'native_writes_enabled' => $native,
                'single_price_display'  => $spd,
            ]);
            $this->check($res->get_status() === 200, 'Shop manager save without gate change must be 200, got ' . $res->get_status());

            // Administrators may change a gate; variants_ui_enabled only affects the editor, and is restored below.
            wp_set_current_user($this->makeUser('administrator'));
            $res = $this->rest('POST', '/wpe/v1/admin/settings', ['variants_ui_enabled' => !$variants]);
            $this->check($res->get_status() === 200, 'Administrator flipping variants_ui_enabled must be 200, got ' . $res->get_status());
        } finally {
            if ($original === null) {
                delete_option(PluginBootstrap::OPTION_FLAGS);
            } else {
                update_option(PluginBootstrap::OPTION_FLAGS, $original);
            }
            PluginBootstrap::instance()->loadFlags();
        }
        echo " ok\n";
    }

    private function rest(string $method, string $route, ?array $body = null): \WP_REST_Response
    {
        $req = new \WP_REST_Request($method, $route);
        if ($body !== null) {
            $req->set_header('content-type', 'application/json');
            $req->set_body((string)wp_json_encode($body));
        }
        return rest_ensure_response(rest_do_request($req));
    }

    private function catalog(array $params): \WP_REST_Response
    {
        $req = new \WP_REST_Request('GET', '/wpe/v1/catalog');
        $req->set_query_params($params);
        return rest_ensure_response(rest_do_request($req));
    }

    private function makeUser(string $role): int
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $uid = wp_insert_user([
            'user_login' => "wpe_acl_{$role}_{$suffix}",
            'user_pass'  => wp_generate_password(24),
            'user_email' => "wpe-acl-{$role}-{$suffix}@example.invalid",
            'role'       => $role,
        ]);
        $this->check(!is_wp_error($uid), "Could not create {$role} test user");
        $this->users[] = (int)$uid;
        return (int)$uid;
    }

    private function cleanup(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->users as $uid) {
            wp_delete_user($uid);
        }
        $this->users = [];
        wp_set_current_user($this->originalUser);
    }

    private function check(bool $condition, string $message): void
    {
        $this->checks++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

$test = new AccessControlTest();
$test->run();
