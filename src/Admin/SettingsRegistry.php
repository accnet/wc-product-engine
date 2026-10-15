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

    public const GROUP_FEATURES = 'features';
    public const GROUP_PRODUCT_PAGE = 'product_page';

    public const SECTION_PRICE_DISPLAY = 'price_display';
    public const SECTION_VARIANT_SELECTOR = 'variant_selector';

    public const ALLOWED_DISPLAY_TYPES = ['auto', 'dropdown', 'button', 'color', 'image'];

    /**
     * @return array<string, array{label:string, description:string, affects:string, runtime:string, group:string, section?:string}>
     */
    public static function flags(): array
    {
        return [
            'variants_ui_enabled' => [
                'label'       => __('Custom variants manager UI', 'wc-product-engine'),
                'description' => __('Use the WC Product Engine Variants Manager on the product edit screen instead of the default WooCommerce Variations tab.', 'wc-product-engine'),
                'affects'     => __('Product editor', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_FEATURES,
            ],
            'query_enabled' => [
                'label'       => __('Fast catalog queries', 'wc-product-engine'),
                'description' => __('Serve shop listing and filter queries from the engine catalog index instead of postmeta joins.', 'wc-product-engine'),
                'affects'     => __('Storefront', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_PREFERENCE,
                'group'       => self::GROUP_FEATURES,
            ],
            'media_enabled' => [
                'label'       => __('External image support', 'wc-product-engine'),
                'description' => __('Use CDN image URLs directly instead of downloading files to the server.', 'wc-product-engine'),
                'affects'     => __('Media and imports', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_PREFERENCE,
                'group'       => self::GROUP_FEATURES,
            ],
            'resolver_enabled' => [
                'label'       => __('Fast variant lookup', 'wc-product-engine'),
                'description' => __('Resolve the selected variation on the server instead of in the browser.', 'wc-product-engine'),
                'affects'     => __('Storefront', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_PREFERENCE,
                'group'       => self::GROUP_FEATURES,
            ],
            'native_writes_enabled' => [
                'label'       => __('Native data-store writes', 'wc-product-engine'),
                'description' => __('Master gate for registering the engine data stores for product writes. Which products are native is still decided by rollout state, not by this switch.', 'wc-product-engine'),
                'affects'     => __('Product saves', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_FEATURES,
            ],
            'single_price_display' => [
                'label'       => __('Single Price Display', 'wc-product-engine'),
                'description' => __('Replace the variable price range in the main price area and on catalog cards with the lowest effective price of valid purchasable variations, and update the main price area when a variation is resolved.', 'wc-product-engine'),
                'affects'     => __('Storefront price & catalog cards', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_PRICE_DISPLAY,
            ],
            'show_selected_value' => [
                'label'       => __('Show Selected Value', 'wc-product-engine'),
                'description' => __('Display the selected option value label alongside the attribute name (e.g. "Color: Black") and update immediately on change or reset.', 'wc-product-engine'),
                'affects'     => __('Storefront variant labels', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_VARIANT_SELECTOR,
            ],
            'show_help_text' => [
                'label'       => __('Show Help Text', 'wc-product-engine'),
                'description' => __('Display per-attribute guidance text below the attribute label (linked via aria-describedby) when configured.', 'wc-product-engine'),
                'affects'     => __('Storefront variant labels', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_VARIANT_SELECTOR,
            ],
            'button_selector' => [
                'label'       => __('Button Selector', 'wc-product-engine'),
                'description' => __('Render attributes configured as Button as accessible option buttons with selected, unselected, unavailable, and focus states. Falls back to dropdown when disabled.', 'wc-product-engine'),
                'affects'     => __('Storefront variant selector', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_VARIANT_SELECTOR,
            ],
            'color_swatches' => [
                'label'       => __('Color Swatches', 'wc-product-engine'),
                'description' => __('Render attributes configured as Color Swatch as color chips with contrasting borders for light colors, accessible labels, and button fallback when a value has no color code. Falls back to dropdown when disabled.', 'wc-product-engine'),
                'affects'     => __('Storefront variant selector', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_VARIANT_SELECTOR,
            ],
            'image_swatches' => [
                'label'       => __('Image Swatches', 'wc-product-engine'),
                'description' => __('Render attributes configured as Image Swatch (and by default the first variation option when unconfigured) with dynamic variation thumbnails and labels. Falls back to dropdown when disabled.', 'wc-product-engine'),
                'affects'     => __('Storefront variant selector & gallery', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_VARIANT_SELECTOR,
            ],
            'mark_unavailable_options' => [
                'label'       => __('Mark Unavailable Options', 'wc-product-engine'),
                'description' => __('Visually dim and cross out unavailable option values while keeping their label and thumbnail legible. Unpurchasable combinations are always blocked regardless of this toggle.', 'wc-product-engine'),
                'affects'     => __('Storefront unavailable options', 'wc-product-engine'),
                'runtime'     => self::RUNTIME_GATE,
                'group'       => self::GROUP_PRODUCT_PAGE,
                'section'     => self::SECTION_VARIANT_SELECTOR,
            ],
        ];
    }

    /**
     * Filter flags by group and optional section.
     *
     * @return array<string, array{label:string, description:string, affects:string, runtime:string, group:string, section?:string}>
     */
    public static function flagsByGroup(string $group, ?string $section = null): array
    {
        $filtered = [];
        foreach (self::flags() as $key => $def) {
            if (($def['group'] ?? self::GROUP_FEATURES) !== $group) {
                continue;
            }
            if ($section !== null && ($def['section'] ?? '') !== $section) {
                continue;
            }
            $filtered[$key] = $def;
        }
        return $filtered;
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
     * @return array{
     *     values: array<string,bool>,
     *     attribute_display_config: array<int, array{display_type:string, help_text:string, term_colors:array<int,string>}>,
     *     has_attribute_display_config: bool,
     *     errors: array<string,string>,
     *     code: string
     * }
     *         `code` is '' when valid, otherwise a stable machine-readable reason
     *         (surfaced as `reason`; the response `code` is always the registered `invalid_request`).
     */
    public static function validate(array $params): array
    {
        $values = [];
        $attributeDisplay = [];
        $hasAttributeDisplay = false;
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

            if ($key === 'attribute_display_config') {
                $hasAttributeDisplay = true;
                $validatedConfig = self::validateAttributeDisplayConfig($value, $errors);
                if ($validatedConfig === null) {
                    if ($code === '') {
                        $code = 'invalid_value';
                    }
                    continue;
                }
                $attributeDisplay = $validatedConfig;
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

        return [
            'values'                       => $values,
            'attribute_display_config'     => $attributeDisplay,
            'has_attribute_display_config' => $hasAttributeDisplay && !isset($errors['attribute_display_config']),
            'errors'                       => $errors,
            'code'                         => $code,
        ];
    }

    /**
     * Validate global attribute display configuration map.
     *
     * @param mixed $raw
     * @param array<string,string> $errors
     * @return array<int|string, array{display_type:string, help_text:string, term_colors:array<int|string,string>}>|null
     */
    private static function validateAttributeDisplayConfig($raw, array &$errors): ?array
    {
        if (!is_array($raw)) {
            $errors['attribute_display_config'] = __('Attribute display configuration must be an object.', 'wc-product-engine');
            return null;
        }

        $validated = [];
        foreach ($raw as $attrIdKey => $entry) {
            if (!is_array($entry)) {
                $errors['attribute_display_config'] = __('Invalid attribute ID or entry in attribute display configuration.', 'wc-product-engine');
                return null;
            }
            if (is_numeric($attrIdKey) && (int)$attrIdKey > 0) {
                $attrId = (int)$attrIdKey;
            } elseif (is_string($attrIdKey) && preg_match('/^opt_[a-z0-9_-]{1,64}$/', $attrIdKey)) {
                $attrId = $attrIdKey;
            } else {
                $errors['attribute_display_config'] = __('Invalid attribute ID or entry in attribute display configuration.', 'wc-product-engine');
                return null;
            }
            $displayType = isset($entry['display_type']) ? (string)$entry['display_type'] : 'auto';
            if (!in_array($displayType, self::ALLOWED_DISPLAY_TYPES, true)) {
                $errors['attribute_display_config'] = sprintf(
                    /* translators: %s: invalid display type */
                    __('Invalid attribute display type "%s".', 'wc-product-engine'),
                    $displayType
                );
                return null;
            }

            $helpTextRaw = $entry['help_text'] ?? '';
            if (!is_scalar($helpTextRaw) && $helpTextRaw !== null) {
                $errors['attribute_display_config'] = __('Attribute help text must be a string.', 'wc-product-engine');
                return null;
            }
            $helpText = mb_substr(sanitize_text_field((string)$helpTextRaw), 0, 255);

            $termColors = [];
            if (isset($entry['term_colors'])) {
                if (!is_array($entry['term_colors'])) {
                    $errors['attribute_display_config'] = __('Term colors must be an object mapping term IDs to hex colors.', 'wc-product-engine');
                    return null;
                }
                foreach ($entry['term_colors'] as $termIdKey => $hexVal) {
                    if (!is_scalar($hexVal) && $hexVal !== null) {
                        $errors['attribute_display_config'] = __('Invalid term color entry.', 'wc-product-engine');
                        return null;
                    }
                    if (is_numeric($termIdKey) && (int)$termIdKey > 0) {
                        $tid = (int)$termIdKey;
                    } elseif (is_string($termIdKey) && preg_match('/^[a-z0-9_-]{1,64}$/', $termIdKey)) {
                        $tid = $termIdKey;
                    } else {
                        $errors['attribute_display_config'] = __('Invalid term color entry.', 'wc-product-engine');
                        return null;
                    }
                    $hexStr = strtolower(trim((string)$hexVal));
                    if ($hexStr === '') {
                        continue;
                    }
                    if (!preg_match('/^#[0-9a-f]{6}$/', $hexStr)) {
                        $errors['attribute_display_config'] = sprintf(
                            /* translators: %s: invalid hex color */
                            __('Invalid hex color "%s". Use #RRGGBB format.', 'wc-product-engine'),
                            $hexStr
                        );
                        return null;
                    }
                    $termColors[$tid] = $hexStr;
                }
            }

            $validated[$attrId] = [
                'display_type' => $displayType,
                'help_text'    => $helpText,
                'term_colors'  => $termColors,
            ];
        }

        return $validated;
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
