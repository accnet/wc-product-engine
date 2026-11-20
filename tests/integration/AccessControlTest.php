<?php

/**
 * Access control regression tests for REFACTOR_REPORT.md security findings.
 *
 * - SEC-01: GET /wpe/v1/catalog is limited to users who can edit products. Anonymous and
 *   low-privilege callers are refused, so draft/private products in the index never leak.
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

use WPE\Application\Catalog\CatalogQuery;

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
