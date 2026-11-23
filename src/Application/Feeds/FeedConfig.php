<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * Feed definition rules: platforms, formats, the default configuration, normalization of submitted
 * configuration and the render hash that decides when a full rebuild is needed (10 §12).
 */
final class FeedConfig
{
    public const PLATFORMS = ['google', 'microsoft'];

    /** Formats per platform, preferred first. Microsoft reads TSV, and Google-formatted XML. */
    public const FORMATS = [
        'google'    => ['xml', 'tsv'],
        'microsoft' => ['tsv', 'xml'],
    ];

    public const VARIATION_MODES = ['variants', 'parent'];

    /** Mapped attributes and the sources an operator may choose for each. */
    public const MAPPED_ATTRIBUTES = [
        'brand'                   => ['default', 'static', 'taxonomy', 'option', 'meta', 'none'],
        'gtin'                    => ['default', 'meta', 'option', 'none'],
        'mpn'                     => ['default', 'field', 'meta', 'option', 'none'],
        'condition'               => ['default', 'static', 'meta'],
        'google_product_category' => ['default', 'static', 'none'],
        'product_type'            => ['default', 'taxonomy', 'static', 'none'],
        'color'                   => ['default', 'option', 'taxonomy', 'meta', 'static', 'none'],
        'size'                    => ['default', 'option', 'taxonomy', 'meta', 'static', 'none'],
        'material'                => ['default', 'option', 'taxonomy', 'meta', 'static', 'none'],
        'pattern'                 => ['default', 'option', 'taxonomy', 'meta', 'static', 'none'],
        'gender'                  => ['none', 'static', 'option', 'taxonomy', 'meta'],
        'age_group'               => ['none', 'static', 'option', 'taxonomy', 'meta'],
        'custom_label_0'          => ['none', 'static', 'taxonomy', 'option', 'meta', 'field'],
        'custom_label_1'          => ['none', 'static', 'taxonomy', 'option', 'meta', 'field'],
        'custom_label_2'          => ['none', 'static', 'taxonomy', 'option', 'meta', 'field'],
        'custom_label_3'          => ['none', 'static', 'taxonomy', 'option', 'meta', 'field'],
        'custom_label_4'          => ['none', 'static', 'taxonomy', 'option', 'meta', 'field'],
    ];

    /** Product fields a `field` source may read. */
    public const FIELDS = ['sku', 'title', 'short_description', 'product_id', 'parent_sku'];

    public const CONDITIONS = ['new', 'refurbished', 'used'];
    public const GENDERS = ['male', 'female', 'unisex'];
    public const AGE_GROUPS = ['newborn', 'infant', 'toddler', 'kids', 'adult'];

    public const SCHEDULE_MODES = ['auto', 'manual'];
    public const SHIPPING_MODES = ['account', 'flat'];

    /** Debounce and gate defaults (D06, D09). */
    public const DEFAULT_QUIET_SECONDS = 120;
    public const DEFAULT_MAX_WAIT_SECONDS = 300;
    public const DEFAULT_MIN_INTERVAL_SECONDS = 900;
    public const DEFAULT_MAX_DROP_PERCENT = 50;

    /**
     * Default configuration of a new feed.
     */
    public static function defaults(): array
    {
        $mapping = [];
        foreach (self::MAPPED_ATTRIBUTES as $attr => $sources) {
            $mapping[$attr] = ['source' => $sources[0], 'value' => ''];
        }

        return [
            'filters' => [
                'include_categories'   => [],
                'exclude_categories'   => [],
                'include_out_of_stock' => true,
                'require_image'        => true,
            ],
            'variation_mode' => 'variants',
            'title'          => ['append_variant_values' => true],
            'mapping'        => $mapping,
            'category_map'   => [],
            'identifier_exists_fallback' => true,
            'price'          => ['include_sale' => true],
            'images'         => ['max_additional' => 10],
            'shipping'       => ['mode' => 'account', 'country' => '', 'service' => '', 'price' => '', 'export_weight' => true],
            'market'         => ['country' => self::storeCountry(), 'language' => self::storeLanguage()],
            'schedule'       => [
                'mode'                 => 'auto',
                'quiet_seconds'        => self::DEFAULT_QUIET_SECONDS,
                'max_wait_seconds'     => self::DEFAULT_MAX_WAIT_SECONDS,
                'min_interval_seconds' => self::DEFAULT_MIN_INTERVAL_SECONDS,
                'max_drop_percent'     => self::DEFAULT_MAX_DROP_PERCENT,
            ],
            'compress'       => false,
        ];
    }

    /**
     * Validate a feed's identity fields.
     *
     * @return array{name:string,platform:string,format:string}
     */
    public static function normalizeIdentity(array $input, ?array $current = null): array
    {
        $errors = [];
        $name = array_key_exists('name', $input) ? trim(wp_strip_all_tags((string)$input['name'])) : (string)($current['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 191) {
            $errors[] = ['pointer' => '/name', 'reason' => 'Name is required (at most 191 characters).'];
        }
        $platform = array_key_exists('platform', $input) ? (string)$input['platform'] : (string)($current['platform'] ?? '');
        if (!in_array($platform, self::PLATFORMS, true)) {
            $errors[] = ['pointer' => '/platform', 'reason' => 'Platform must be google or microsoft.'];
            $platform = 'google';
        }
        $format = array_key_exists('format', $input)
            ? (string)$input['format']
            : (string)($current['format'] ?? self::FORMATS[$platform][0]);
        if (!in_array($format, self::FORMATS[$platform], true)) {
            $errors[] = ['pointer' => '/format', 'reason' => sprintf('Format must be one of: %s.', implode(', ', self::FORMATS[$platform]))];
        }
        if ($errors) {
            throw new FeedValidationException('The feed definition is invalid.', $errors);
        }

        return ['name' => $name, 'platform' => $platform, 'format' => $format];
    }

    /**
     * Merge submitted configuration over a base (defaults or the stored configuration) and validate it.
     * Unknown keys are rejected so a typo never silently does nothing.
     *
     * @throws FeedValidationException
     */
    public static function normalize(array $input, ?array $base = null): array
    {
        $base = self::mergeDefaults($base ?? []);
        $errors = [];

        $known = array_keys(self::defaults());
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = ['pointer' => '/config/' . $key, 'reason' => 'Unknown setting.'];
            }
        }

        $c = $base;

        if (isset($input['filters'])) {
            $f = (array)$input['filters'];
            foreach (['include_categories', 'exclude_categories'] as $k) {
                if (array_key_exists($k, $f)) {
                    $c['filters'][$k] = self::idList($f[$k]);
                }
            }
            foreach (['include_out_of_stock', 'require_image'] as $k) {
                if (array_key_exists($k, $f)) {
                    $c['filters'][$k] = (bool)$f[$k];
                }
            }
        }

        if (array_key_exists('variation_mode', $input)) {
            $mode = (string)$input['variation_mode'];
            if (!in_array($mode, self::VARIATION_MODES, true)) {
                $errors[] = ['pointer' => '/config/variation_mode', 'reason' => 'Must be variants or parent.'];
            } else {
                $c['variation_mode'] = $mode;
            }
        }

        if (isset($input['title']['append_variant_values'])) {
            $c['title']['append_variant_values'] = (bool)$input['title']['append_variant_values'];
        }

        if (isset($input['mapping'])) {
            foreach ((array)$input['mapping'] as $attr => $rule) {
                $attr = (string)$attr;
                if (!isset(self::MAPPED_ATTRIBUTES[$attr])) {
                    $errors[] = ['pointer' => '/config/mapping/' . $attr, 'reason' => 'Unknown attribute.'];
                    continue;
                }
                $rule = is_array($rule) ? $rule : ['source' => (string)$rule];
                $source = (string)($rule['source'] ?? 'default');
                $value = trim(sanitize_text_field((string)($rule['value'] ?? '')));
                if (!in_array($source, self::MAPPED_ATTRIBUTES[$attr], true)) {
                    $errors[] = ['pointer' => '/config/mapping/' . $attr . '/source', 'reason' => 'Source not allowed for this attribute.'];
                    continue;
                }
                if (in_array($source, ['static', 'taxonomy', 'option', 'meta', 'field'], true) && $value === '') {
                    $errors[] = ['pointer' => '/config/mapping/' . $attr . '/value', 'reason' => 'A value is required for this source.'];
                    continue;
                }
                if ($source === 'field' && !in_array($value, self::FIELDS, true)) {
                    $errors[] = ['pointer' => '/config/mapping/' . $attr . '/value', 'reason' => 'Unknown product field.'];
                    continue;
                }
                if ($source === 'static') {
                    $allowed = match ($attr) {
                        'condition' => self::CONDITIONS,
                        'gender'    => self::GENDERS,
                        'age_group' => self::AGE_GROUPS,
                        default     => null,
                    };
                    if ($allowed !== null && !in_array($value, $allowed, true)) {
                        $errors[] = ['pointer' => '/config/mapping/' . $attr . '/value', 'reason' => sprintf('Must be one of: %s.', implode(', ', $allowed))];
                        continue;
                    }
                }
                if (mb_strlen($value) > 191) {
                    $errors[] = ['pointer' => '/config/mapping/' . $attr . '/value', 'reason' => 'At most 191 characters.'];
                    continue;
                }
                $c['mapping'][$attr] = ['source' => $source, 'value' => $value];
            }
        }

        if (array_key_exists('category_map', $input)) {
            $map = [];
            foreach ((array)$input['category_map'] as $termId => $category) {
                $termId = (int)$termId;
                $category = trim(sanitize_text_field((string)$category));
                if ($termId > 0 && $category !== '') {
                    $map[(string)$termId] = mb_substr($category, 0, 250);
                }
            }
            $c['category_map'] = $map;
        }

        if (array_key_exists('identifier_exists_fallback', $input)) {
            $c['identifier_exists_fallback'] = (bool)$input['identifier_exists_fallback'];
        }
        if (isset($input['price']['include_sale'])) {
            $c['price']['include_sale'] = (bool)$input['price']['include_sale'];
        }
        if (isset($input['images']['max_additional'])) {
            $max = (int)$input['images']['max_additional'];
            if ($max < 0 || $max > 10) {
                $errors[] = ['pointer' => '/config/images/max_additional', 'reason' => 'Must be between 0 and 10.'];
            } else {
                $c['images']['max_additional'] = $max;
            }
        }

        if (isset($input['shipping'])) {
            $s = (array)$input['shipping'];
            if (array_key_exists('mode', $s)) {
                if (!in_array((string)$s['mode'], self::SHIPPING_MODES, true)) {
                    $errors[] = ['pointer' => '/config/shipping/mode', 'reason' => 'Must be account or flat.'];
                } else {
                    $c['shipping']['mode'] = (string)$s['mode'];
                }
            }
            foreach (['country', 'service'] as $k) {
                if (array_key_exists($k, $s)) {
                    $c['shipping'][$k] = mb_substr(trim(sanitize_text_field((string)$s[$k])), 0, 100);
                }
            }
            if (array_key_exists('price', $s)) {
                $c['shipping']['price'] = trim((string)$s['price']);
            }
            if (array_key_exists('export_weight', $s)) {
                $c['shipping']['export_weight'] = (bool)$s['export_weight'];
            }
        }
        if ($c['shipping']['mode'] === 'flat') {
            if (!preg_match('/^[A-Z]{2}$/', strtoupper($c['shipping']['country']))) {
                $errors[] = ['pointer' => '/config/shipping/country', 'reason' => 'Flat shipping needs a two-letter country code.'];
            }
            if (!is_numeric($c['shipping']['price']) || (float)$c['shipping']['price'] < 0) {
                $errors[] = ['pointer' => '/config/shipping/price', 'reason' => 'Flat shipping needs a price of 0 or more.'];
            }
            $c['shipping']['country'] = strtoupper($c['shipping']['country']);
        }

        if (isset($input['market'])) {
            $m = (array)$input['market'];
            if (array_key_exists('country', $m)) {
                $country = strtoupper(trim((string)$m['country']));
                if (!preg_match('/^[A-Z]{2}$/', $country)) {
                    $errors[] = ['pointer' => '/config/market/country', 'reason' => 'Use a two-letter country code.'];
                } else {
                    $c['market']['country'] = $country;
                }
            }
            if (array_key_exists('language', $m)) {
                $lang = strtolower(trim((string)$m['language']));
                if (!preg_match('/^[a-z]{2}$/', $lang)) {
                    $errors[] = ['pointer' => '/config/market/language', 'reason' => 'Use a two-letter language code.'];
                } else {
                    $c['market']['language'] = $lang;
                }
            }
        }

        if (isset($input['schedule'])) {
            $s = (array)$input['schedule'];
            if (array_key_exists('mode', $s)) {
                if (!in_array((string)$s['mode'], self::SCHEDULE_MODES, true)) {
                    $errors[] = ['pointer' => '/config/schedule/mode', 'reason' => 'Must be auto or manual.'];
                } else {
                    $c['schedule']['mode'] = (string)$s['mode'];
                }
            }
            $ranges = [
                'quiet_seconds'        => [30, 3600],
                'max_wait_seconds'     => [60, 86400],
                'min_interval_seconds' => [60, 86400],
                'max_drop_percent'     => [1, 100],
            ];
            foreach ($ranges as $k => [$min, $max]) {
                if (array_key_exists($k, $s)) {
                    $v = (int)$s[$k];
                    if ($v < $min || $v > $max) {
                        $errors[] = ['pointer' => '/config/schedule/' . $k, 'reason' => sprintf('Must be between %d and %d.', $min, $max)];
                    } else {
                        $c['schedule'][$k] = $v;
                    }
                }
            }
            if ($c['schedule']['max_wait_seconds'] < $c['schedule']['quiet_seconds']) {
                $errors[] = ['pointer' => '/config/schedule/max_wait_seconds', 'reason' => 'Max wait must not be shorter than the quiet window.'];
            }
        }

        if (array_key_exists('compress', $input)) {
            $c['compress'] = (bool)$input['compress'];
        }

        if ($errors) {
            throw new FeedValidationException('The feed configuration is invalid.', $errors);
        }

        return $c;
    }

    /**
     * Fill keys added after a feed was saved, so older stored configs keep working.
     */
    public static function mergeDefaults(array $config): array
    {
        $merged = self::defaults();
        foreach ($merged as $key => $default) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $merged[$key] = (is_array($default) && is_array($config[$key]) && !array_is_list($default) && $key !== 'category_map')
                ? array_replace($default, $config[$key])
                : $config[$key];
        }
        foreach (self::MAPPED_ATTRIBUTES as $attr => $sources) {
            if (!isset($merged['mapping'][$attr]) || !is_array($merged['mapping'][$attr])) {
                $merged['mapping'][$attr] = ['source' => $sources[0], 'value' => ''];
            }
        }
        return $merged;
    }

    /**
     * Hash of everything that changes rendered items. Schedule, name and enabled are excluded:
     * changing them never needs a full rebuild.
     */
    public static function renderHash(string $platform, string $format, array $config): string
    {
        $config = self::mergeDefaults($config);
        unset($config['schedule'], $config['compress']);
        $canonical = self::canonicalize(['platform' => $platform, 'format' => $format, 'config' => $config]);
        return hash('sha256', (string)wp_json_encode($canonical), true);
    }

    /**
     * Hash of store settings that change rendered items (currency, weight unit, permalinks, price decimals).
     */
    public static function storeHash(): string
    {
        return hash('sha256', (string)wp_json_encode([
            'currency'    => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : (string)get_option('woocommerce_currency', 'USD'),
            'weight_unit' => (string)get_option('woocommerce_weight_unit', 'kg'),
            'permalinks'  => (string)get_option('permalink_structure', ''),
            'product_base' => (string)(get_option('woocommerce_permalinks', [])['product_base'] ?? ''),
            'decimals'    => function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2,
            'home'        => home_url('/'),
        ]));
    }

    public static function storeCurrency(): string
    {
        return function_exists('get_woocommerce_currency') ? (string)get_woocommerce_currency() : (string)get_option('woocommerce_currency', 'USD');
    }

    public static function storeCountry(): string
    {
        $base = (string)get_option('woocommerce_default_country', 'US');
        $country = strtoupper(substr($base, 0, 2));
        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'US';
    }

    public static function storeLanguage(): string
    {
        $lang = strtolower(substr((string)(function_exists('get_locale') ? get_locale() : 'en_US'), 0, 2));
        return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'en';
    }

    /**
     * Markets where Google and Microsoft expect prices that include VAT/GST.
     */
    public static function marketExpectsTaxInclusivePrices(string $country): bool
    {
        return !in_array(strtoupper($country), ['US', 'CA', 'IN'], true);
    }

    /** @return int[] */
    private static function idList(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$value), static fn(int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map([self::class, 'canonicalize'], $value);
    }
}
