<?php

/**
 * P004.1 Integration Test — Settings REST hardening.
 *
 * Verifies, through the real REST server (rest_do_request) and the real
 * `wpe_feature_flags` option:
 *  - editable flags save and are returned with their effective state;
 *  - authority (`cohort_mode`) can NOT be written, alone or mixed with valid keys;
 *  - a rejected payload writes nothing (atomic);
 *  - unknown keys and non-boolean values give per-field errors;
 *  - unauthenticated / low-privilege callers are refused;
 *  - the registry matches the stored flag defaults.
 *
 * Run: wp eval-file tests/integration/SettingsApiTest.php
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

use WPE\Admin\SettingsRegistry;
use WPE\Bootstrap\PluginBootstrap;

class SettingsApiTest
{
    private const ROUTE = '/wpe/v1/admin/settings';

    /** @var mixed */
    private $originalOption;
    /** @var mixed */
    private $originalAttrConfig;
    /** @var mixed */
    private $originalSettingsVer;
    private int $originalUser = 0;
    private int $checks = 0;

    public function run(): void
    {
        echo "Running Settings REST Integration Tests...\n";

        $this->originalOption = get_option(PluginBootstrap::OPTION_FLAGS, null);
        $this->originalAttrConfig = get_option(PluginBootstrap::OPTION_ATTR_DISPLAY, null);
        $this->originalSettingsVer = get_option(PluginBootstrap::OPTION_PRODUCT_PAGE_VER, null);
        $this->originalUser = get_current_user_id();

        try {
            $this->asAdmin();
            $this->testRegistryMatchesStoredDefaults();
            $this->testProductPageFlagGroupsAndDefaults();
            $this->testGetReturnsEffectiveState();
            $this->testSaveValidFlagsPreservesAuthority();
            $this->testSaveProductPageFlagsAndAttributeDisplayConfig();
            $this->testInvalidAttributeDisplayConfigRejected();
            $this->testProductPageTabRendersExpectedGroups();
            $this->testAuthorityWriteRejected();
            $this->testMixedPayloadIsAtomic();
            $this->testUnknownKeyRejected();
            $this->testNonBooleanRejected();
            $this->testEmptyPayloadIsNoOp();
            $this->testLowPrivilegeRefused();
            $this->restore();
            echo "All Settings REST tests passed ({$this->checks} checks).\n";
        } catch (\Throwable $e) {
            echo 'FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            $this->restore();
            exit(1);
        }
    }

    private function testRegistryMatchesStoredDefaults(): void
    {
        echo ' registry matches stored defaults...';
        $defaults = array_keys(PluginBootstrap::instance()->getFlags());
        $editable = SettingsRegistry::flagKeys();
        $readOnly = SettingsRegistry::readOnlyKeys();

        $this->check(
            array_diff($defaults, $editable, $readOnly) === [],
            'Every stored flag must be classified as editable or read-only: ' . implode(',', array_diff($defaults, $editable, $readOnly))
        );
        $this->check(array_diff($editable, $defaults) === [], 'Registry lists a flag the bootstrap does not know');
        $this->check(in_array('cohort_mode', $readOnly, true), 'cohort_mode must be read-only');
        $this->check(!in_array('cohort_mode', $editable, true), 'cohort_mode must not be editable');
        echo " OK\n";
    }

    private function testProductPageFlagGroupsAndDefaults(): void
    {
        echo ' product_page flags are grouped under Price Display and Variant Selector and default to false...';
        delete_option(PluginBootstrap::OPTION_FLAGS);
        PluginBootstrap::instance()->loadFlags();
        $flags = PluginBootstrap::instance()->getFlags();

        $priceFlags = SettingsRegistry::flagsByGroup(SettingsRegistry::GROUP_PRODUCT_PAGE, SettingsRegistry::SECTION_PRICE_DISPLAY);
        $selectorFlags = SettingsRegistry::flagsByGroup(SettingsRegistry::GROUP_PRODUCT_PAGE, SettingsRegistry::SECTION_VARIANT_SELECTOR);

        $this->check(array_keys($priceFlags) === ['single_price_display'], 'Price Display section must contain single_price_display');
        $expectedSelector = [
            'show_selected_value',
            'show_help_text',
            'button_selector',
            'color_swatches',
            'image_swatches',
            'mark_unavailable_options',
        ];
        $this->check(array_keys($selectorFlags) === $expectedSelector, 'Variant Selector section keys mismatch');

        foreach (array_merge(['single_price_display'], $expectedSelector) as $key) {
            $this->check(($flags[$key] ?? null) === false, "Default for {$key} must be false to preserve existing behavior");
        }
        echo " OK\n";
    }

    private function testGetReturnsEffectiveState(): void
    {
        echo ' GET returns flags and effective state...';
        $res = $this->request('GET');
        $this->check($res->get_status() === 200, 'GET must return 200, got ' . $res->get_status());
        $data = $res->get_data();
        $this->check(isset($data['flags'], $data['effective']), 'GET must return flags and effective');
        foreach (SettingsRegistry::flagKeys() as $key) {
            $this->check(array_key_exists($key, $data['effective']), "effective is missing {$key}");
            $this->check(is_bool($data['effective'][$key]), "effective[{$key}] must be bool");
        }
        echo " OK\n";
    }

    private function testSaveValidFlagsPreservesAuthority(): void
    {
        echo ' valid save persists flags and preserves cohort_mode...';
        $this->seed(['query_enabled' => false, 'media_enabled' => false, 'resolver_enabled' => false, 'native_writes_enabled' => false, 'cohort_mode' => 'cohort_canary']);

        $res = $this->request('POST', ['query_enabled' => true, 'media_enabled' => '1', 'resolver_enabled' => false]);
        $this->check($res->get_status() === 200, 'Valid save must return 200, got ' . $res->get_status());

        $stored = get_option(PluginBootstrap::OPTION_FLAGS);
        $this->check($stored['query_enabled'] === true, 'query_enabled must be saved as bool true');
        $this->check($stored['media_enabled'] === true, "'1' must be coerced to bool true");
        $this->check($stored['resolver_enabled'] === false, 'resolver_enabled must stay false');
        $this->check($stored['native_writes_enabled'] === false, 'omitted key must be unchanged');
        $this->check($stored['cohort_mode'] === 'cohort_canary', 'cohort_mode must be preserved untouched');

        $data = $res->get_data();
        $this->check($data['flags']['query_enabled'] === true, 'Response must echo saved flags');
        echo " OK\n";
    }

    private function testSaveProductPageFlagsAndAttributeDisplayConfig(): void
    {
        echo ' product_page flags and attribute_display_config persist and bump cache version...';
        delete_option(PluginBootstrap::OPTION_ATTR_DISPLAY);
        $verBefore = PluginBootstrap::instance()->getProductPageSettingsVersion();

        $res = $this->request('POST', [
            'single_price_display'     => true,
            'show_selected_value'      => true,
            'show_help_text'           => true,
            'button_selector'          => true,
            'color_swatches'           => true,
            'image_swatches'           => true,
            'mark_unavailable_options' => true,
            'attribute_display_config' => [
                '12' => [
                    'display_type' => 'color',
                    'help_text'    => 'Choose your primary color',
                    'term_colors'  => [
                        '101' => '#FFFFFF',
                        '102' => '#112233',
                        '103' => '',
                    ],
                ],
                '15' => [
                    'display_type' => 'button',
                    'help_text'    => 'Choose your size',
                    'term_colors'  => [],
                ],
            ],
        ]);
        $this->check($res->get_status() === 200, 'Saving product_page settings must return 200');
        $data = $res->get_data();

        $verAfter = PluginBootstrap::instance()->getProductPageSettingsVersion();
        $this->check($verAfter > $verBefore, 'Saving product_page settings must bump product_page_settings_ver');
        $this->check(($data['flags']['single_price_display'] ?? false) === true, 'single_price_display must be true');
        $this->check(($data['flags']['image_swatches'] ?? false) === true, 'image_swatches must be true');
        $this->check(($data['flags']['mark_unavailable_options'] ?? false) === true, 'mark_unavailable_options must be true');

        $attrCfg = $data['attribute_display_config'] ?? [];
        $this->check(($attrCfg[12]['display_type'] ?? '') === 'color', 'Attr 12 display_type must be color');
        $this->check(($attrCfg[12]['help_text'] ?? '') === 'Choose your primary color', 'Attr 12 help_text mismatch');
        $this->check(($attrCfg[12]['term_colors'][101] ?? '') === '#ffffff', 'Term 101 hex must be normalized to lowercase #ffffff');
        $this->check(!isset($attrCfg[12]['term_colors'][103]), 'Empty term color must be omitted for button fallback');
        $this->check(($attrCfg[15]['display_type'] ?? '') === 'button', 'Attr 15 display_type must be button');
        echo " OK\n";
    }

    private function testInvalidAttributeDisplayConfigRejected(): void
    {
        echo ' invalid attribute_display_config is rejected atomically...';
        $beforeFlags = get_option(PluginBootstrap::OPTION_FLAGS);
        $beforeAttr = get_option(PluginBootstrap::OPTION_ATTR_DISPLAY);

        $res1 = $this->request('POST', [
            'single_price_display'     => false,
            'attribute_display_config' => [
                '12' => [
                    'display_type' => 'invalid_widget',
                    'help_text'    => 'Bad type',
                ],
            ],
        ]);
        $this->check($res1->get_status() === 400, 'Invalid display_type must return 400');
        $this->check(get_option(PluginBootstrap::OPTION_FLAGS) === $beforeFlags, 'Flags must remain untouched on rejected attribute_display_config');
        $this->check(get_option(PluginBootstrap::OPTION_ATTR_DISPLAY) === $beforeAttr, 'Attr config must remain untouched');

        $res2 = $this->request('POST', [
            'attribute_display_config' => [
                '12' => [
                    'display_type' => 'color',
                    'help_text'    => 'Bad hex',
                    'term_colors'  => ['101' => 'not-a-hex'],
                ],
            ],
        ]);
        $this->check($res2->get_status() === 400, 'Invalid hex color must return 400');
        echo " OK\n";
    }

    private function testProductPageTabRendersExpectedGroups(): void
    {
        echo ' SettingsPage tab=product-page renders Price Display and Variant Selector groups...';
        $_GET['tab'] = 'product-page';
        ob_start();
        \WPE\Admin\SettingsPage::render();
        $html = (string)ob_get_clean();
        unset($_GET['tab']);

        $this->check(str_contains($html, 'id="wpe-product-page-panel"'), 'Must render #wpe-product-page-panel');
        $this->check(str_contains($html, 'id="wpe-group-price-display"'), 'Must render Price Display group');
        $this->check(str_contains($html, 'id="wpe-group-variant-selector"'), 'Must render Variant Selector group');
        $this->check(str_contains($html, 'id="wpe-group-attribute-display"'), 'Must render Global Attribute Display Configuration group');
        $this->check(str_contains($html, 'data-flag="single_price_display"'), 'Must render single_price_display toggle');
        $this->check(str_contains($html, 'data-flag="show_selected_value"'), 'Must render show_selected_value toggle');
        $this->check(str_contains($html, 'data-flag="show_help_text"'), 'Must render show_help_text toggle');
        $this->check(str_contains($html, 'data-flag="button_selector"'), 'Must render button_selector toggle');
        $this->check(str_contains($html, 'data-flag="color_swatches"'), 'Must render color_swatches toggle');
        $this->check(str_contains($html, 'data-flag="image_swatches"'), 'Must render image_swatches toggle');
        $this->check(str_contains($html, 'data-flag="mark_unavailable_options"'), 'Must render mark_unavailable_options toggle');
        $this->check(!str_contains($html, 'Product Information'), 'Must NOT render unrequested Product Information group');
        echo " OK\n";
    }

    private function testAuthorityWriteRejected(): void
    {
        echo ' authority write is rejected...';
        $this->seed(['cohort_mode' => 'legacy', 'query_enabled' => false]);

        foreach (['full_native', 'cohort_canary', 'legacy', 'bogus'] as $mode) {
            $res = $this->request('POST', ['cohort_mode' => $mode]);
            $data = $res->get_data();
            $this->check($res->get_status() === 400, "cohort_mode={$mode} must return 400, got " . $res->get_status());
            $this->check(($data['code'] ?? '') === 'invalid_request', 'Expected registered code invalid_request');
            $this->check(($data['reason'] ?? '') === 'authority_read_only', 'Expected reason authority_read_only');
            $this->check(isset($data['errors']['cohort_mode']), 'Expected a field error for cohort_mode');
        }

        $stored = get_option(PluginBootstrap::OPTION_FLAGS);
        $this->check($stored['cohort_mode'] === 'legacy', 'cohort_mode must be unchanged after rejected writes');
        echo " OK\n";
    }

    private function testMixedPayloadIsAtomic(): void
    {
        echo ' rejected payload writes nothing (atomic)...';
        $this->seed(['cohort_mode' => 'legacy', 'query_enabled' => false, 'media_enabled' => false]);
        $before = get_option(PluginBootstrap::OPTION_FLAGS);

        $res = $this->request('POST', ['query_enabled' => true, 'cohort_mode' => 'full_native']);
        $this->check($res->get_status() === 400, 'Mixed payload must be rejected');
        $this->check(get_option(PluginBootstrap::OPTION_FLAGS) === $before, 'Valid keys in a rejected payload must not be written');
        echo " OK\n";
    }

    private function testUnknownKeyRejected(): void
    {
        echo ' unknown key is rejected...';
        $before = get_option(PluginBootstrap::OPTION_FLAGS);
        $res = $this->request('POST', ['query_enabled' => true, 'nope' => true]);
        $data = $res->get_data();
        $this->check($res->get_status() === 400, 'Unknown key must return 400');
        $this->check(($data['code'] ?? '') === 'invalid_request', 'Expected registered code invalid_request');
        $this->check(($data['reason'] ?? '') === 'unknown_setting', 'Expected reason unknown_setting');
        $this->check(isset($data['errors']['nope']), 'Expected field error for unknown key');
        $this->check(get_option(PluginBootstrap::OPTION_FLAGS) === $before, 'Nothing may be written');
        echo " OK\n";
    }

    private function testNonBooleanRejected(): void
    {
        echo ' non-boolean value is rejected...';
        $before = get_option(PluginBootstrap::OPTION_FLAGS);
        foreach (['yes', 2, [], null, 'maybe'] as $bad) {
            $res = $this->request('POST', ['media_enabled' => $bad]);
            $data = $res->get_data();
            $this->check($res->get_status() === 400, 'Non-boolean ' . json_encode($bad) . ' must return 400');
            $this->check(($data['code'] ?? '') === 'invalid_request', 'Expected registered code invalid_request');
            $this->check(($data['reason'] ?? '') === 'invalid_value', 'Expected reason invalid_value');
            $this->check(isset($data['errors']['media_enabled']), 'Expected field error for media_enabled');
        }
        $this->check(get_option(PluginBootstrap::OPTION_FLAGS) === $before, 'Nothing may be written');
        echo " OK\n";
    }

    private function testEmptyPayloadIsNoOp(): void
    {
        echo ' empty payload is a no-op...';
        $before = get_option(PluginBootstrap::OPTION_FLAGS);
        $res = $this->request('POST', []);
        $this->check($res->get_status() === 200, 'Empty payload must return 200, got ' . $res->get_status());
        $this->check(get_option(PluginBootstrap::OPTION_FLAGS) === $before, 'Empty payload must not change the option');
        echo " OK\n";
    }

    private function testLowPrivilegeRefused(): void
    {
        echo ' low-privilege and anonymous callers are refused...';
        $before = get_option(PluginBootstrap::OPTION_FLAGS);

        wp_set_current_user(0);
        $res = $this->request('POST', ['query_enabled' => true]);
        $this->check(in_array($res->get_status(), [401, 403], true), 'Anonymous must be refused, got ' . $res->get_status());

        $uid = wp_insert_user([
            'user_login' => 'wpe_settings_test_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(24),
            'user_email' => 'wpe-settings-test-' . wp_generate_password(6, false) . '@example.invalid',
            'role'       => 'subscriber',
        ]);
        $this->check(!is_wp_error($uid), 'Could not create subscriber for test');
        try {
            wp_set_current_user((int)$uid);
            $res = $this->request('POST', ['query_enabled' => true]);
            $this->check(in_array($res->get_status(), [401, 403], true), 'Subscriber must be refused, got ' . $res->get_status());
        } finally {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int)$uid);
            $this->asAdmin();
        }

        $this->check(get_option(PluginBootstrap::OPTION_FLAGS) === $before, 'Refused calls must not write');
        echo " OK\n";
    }

    // ---- helpers -------------------------------------------------------

    private function request(string $method, ?array $body = null): \WP_REST_Response
    {
        $req = new \WP_REST_Request($method, self::ROUTE);
        if ($body !== null) {
            $req->set_header('content-type', 'application/json');
            $req->set_body((string)wp_json_encode($body));
        }
        $res = rest_do_request($req);
        return rest_ensure_response($res);
    }

    private function asAdmin(): void
    {
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $this->check(!empty($admins), 'An administrator account is required to run this test');
        wp_set_current_user((int)$admins[0]);
        $this->check(current_user_can('manage_woocommerce'), 'Test admin must have manage_woocommerce');
    }

    private function seed(array $flags): void
    {
        update_option(PluginBootstrap::OPTION_FLAGS, $flags);
        PluginBootstrap::instance()->loadFlags();
    }

    private function restore(): void
    {
        if ($this->originalOption === null) {
            delete_option(PluginBootstrap::OPTION_FLAGS);
        } else {
            update_option(PluginBootstrap::OPTION_FLAGS, $this->originalOption);
        }
        if ($this->originalAttrConfig === null) {
            delete_option(PluginBootstrap::OPTION_ATTR_DISPLAY);
        } else {
            update_option(PluginBootstrap::OPTION_ATTR_DISPLAY, $this->originalAttrConfig);
        }
        if ($this->originalSettingsVer === null) {
            delete_option(PluginBootstrap::OPTION_PRODUCT_PAGE_VER);
        } else {
            update_option(PluginBootstrap::OPTION_PRODUCT_PAGE_VER, $this->originalSettingsVer);
        }
        PluginBootstrap::instance()->loadFlags();
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

$test = new SettingsApiTest();
$test->run();
