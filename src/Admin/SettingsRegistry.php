<?php

declare(strict_types=1);

namespace WPE\Admin;

/**
 * SettingsRegistry
 *
 * Single definition of the feature flags that an administrator may edit from
 * the Settings screen. Used by both the page renderer and the REST validator so
 * the two can never disagree about which keys exist.
 *
 * Authority state (`cohort_mode`, `system_mode`, `authority_epoch`) is
 * deliberately NOT editable here. Authority changes only through the governed
 * rollout commands (docs/02-domain.md §4-5).
 *
 * `runtime` describes what the code in this build does with the flag:
 *  - 'gate'       : read at runtime through PluginBootstrap::isFlagEnabled().
 *  - 'preference' : stored, but no runtime component reads it in this build.
 * Keep this honest: update it when a flag gains (or loses) a runtime reader.
 */
final class SettingsRegistry
{
    public const RUNTIME_GATE = 'gate';
    public const RUNTIME_PREFERENCE = 'preference';

    /**
     * @return array<string, array{label:string, description:string, affects:string, runtime:string}>
     */
    public static function flags(): array
    {
        return [
            'query_enabled' => [
                'label'       => __('Fast catalog queries', 'wc-product-engine'),
                'description' => __('Serve shop listing and filter queries from the engine catalog index instead of postmeta joins.', 'wc-product-engine'),
                'affects'     => __('Storefront', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_PREFERENCE,
            ],
            'media_enabled' => [
                'label'       => __('External image support', 'wc-product-engine'),
                'description' => __('Use CDN image URLs directly instead of downloading files to the server.', 'wc-product-engine'),
                'affects'     => __('Media and imports', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_PREFERENCE,
            ],
            'resolver_enabled' => [
                'label'       => __('Fast variant lookup', 'wc-product-engine'),
                'description' => __('Resolve the selected variation on the server instead of in the browser.', 'wc-product-engine'),
                'affects'     => __('Storefront', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_PREFERENCE,
            ],
            'native_writes_enabled' => [
                'label'       => __('Native data-store writes', 'wc-product-engine'),
                'description' => __('Master gate for registering the engine data stores for product writes. Which products are native is still decided by rollout state, not by this switch.', 'wc-product-engine'),
                'affects'     => __('Product saves', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function flagKeys(): array
    {
        return array_keys(self::flags());
    }

    /**
     * Keys that exist in the stored flag option but must never be written from
     * the Settings screen.
     *
     * @return string[]
     */
    public static function readOnlyKeys(): array
    {
        return ['cohort_mode'];
    }

    /**
     * Validate an incoming settings payload.
     *
     * @param array<string, mixed> $params Decoded request body.
     * @return array{values: array<string,bool>, errors: array<string,string>, code: string}
     *         `code` is '' when valid, otherwise a stable machine-readable reason
     *         (surfaced as `reason`; the response `code` is always the registered `invalid_request`).
     */
    public static function validate(array $params): array
    {
        $values = [];
        $errors = [];
        $code = '';
        $known = self::flagKeys();

        foreach ($params as $key => $value) {
            $key = (string)$key;

            if (in_array($key, self::readOnlyKeys(), true)) {
                $errors[$key] = __('Authority mode cannot be changed from Settings. Use Operations or WP-CLI.', 'wc-product-engine');
                $code = 'authority_read_only';
                continue;
            }

            if (!in_array($key, $known, true)) {
                $errors[$key] = __('Unknown setting.', 'wc-product-engine');
                if ($code === '') {
                    $code = 'unknown_setting';
                }
                continue;
            }

            $bool = self::toBool($value);
            if ($bool === null) {
                $errors[$key] = __('Value must be true or false.', 'wc-product-engine');
                if ($code === '') {
                    $code = 'invalid_value';
                }
                continue;
            }

            $values[$key] = $bool;
        }

        return ['values' => $values, 'errors' => $errors, 'code' => $code];
    }

    /**
     * Strict boolean coercion. Returns null for anything ambiguous so callers
     * can report a field error instead of silently guessing.
     *
     * @param mixed $value
     */
    private static function toBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }
        return null;
    }
}
