<?php

namespace WPE\Bootstrap;

class FixtureHarness
{
    private int $seed;

    public function __construct(int $seed = 12345)
    {
        $this->seed = $seed;
    }

    /**
     * Generate reproducible product fixture dataset based on seed.
     */
    public function generateProductFixture(string $type = 'simple', array $overrides = []): array
    {
        mt_srand($this->seed);

        $id = $overrides['id'] ?? mt_rand(100000, 999999);
        $sku = $overrides['sku'] ?? 'WPE-FIXTURE-' . $id;

        $fixture = [
            'id' => (string)$id,
            'type' => $type,
            'status' => 'publish',
            'name' => $overrides['name'] ?? 'Test Product ' . $id . ' (Tiếng Việt có dấu: Áo thun)',
            'slug' => 'test-product-' . $id,
            'sku' => $sku,
            'regular_price' => $overrides['regular_price'] ?? '250000.00',
            'sale_price' => $overrides['sale_price'] ?? null,
            'virtual' => $overrides['virtual'] ?? false,
            'downloadable' => $overrides['downloadable'] ?? false,
            'manage_stock' => $overrides['manage_stock'] ?? true,
            'stock_quantity' => $overrides['stock_quantity'] ?? '100.000000',
            'stock_status' => 'instock',
            'gallery_image_ids' => $overrides['gallery_image_ids'] ?? ['101', '102', '103'],
            'created_at' => '2026-10-12T00:00:00Z',
            'updated_at' => '2026-10-12T01:23:45Z',
        ];

        if ($type === 'variable') {
            $fixture['options'] = $overrides['options'] ?? [
                [
                    'name' => 'Color',
                    'values' => ['Black', 'White', 'Blue'],
                ],
                [
                    'name' => 'Size',
                    'values' => ['M', 'L', 'XL'],
                ]
            ];

            $variations = [];
            $pos = 1;
            foreach ($fixture['options'][0]['values'] as $color) {
                foreach ($fixture['options'][1]['values'] as $size) {
                    $var_id = (string)($id + $pos);
                    $variations[] = [
                        'id' => $var_id,
                        'parent_id' => (string)$id,
                        'sku' => "{$sku}-{$color}-{$size}",
                        'attributes' => [
                            'Color' => $color,
                            'Size' => $size,
                        ],
                        'regular_price' => $overrides['regular_price'] ?? '250000.00',
                        'sale_price' => null,
                        'manage_stock' => true,
                        'stock_quantity' => '25.000000',
                        'position' => $pos++,
                        'created_at' => '2026-10-12T00:00:00Z',
                        'updated_at' => '2026-10-12T02:00:00Z',
                    ];
                }
            }
            $fixture['variations'] = $overrides['variations'] ?? $variations;
        }

        return array_merge($fixture, $overrides);
    }

    /**
     * Canonical snapshot normalizer.
     * Separates volatile timestamps while preserving IDs, stock, prices, attributes, and gallery order.
     */
    public function normalizeSnapshot(array $snapshot): array
    {
        $normalized = $snapshot;

        // Volatile fields that do not determine semantic equivalence
        unset($normalized['created_at'], $normalized['updated_at'], $normalized['deleted_at']);

        // Normalize variations if variable product
        if (!empty($normalized['variations']) && is_array($normalized['variations'])) {
            $vars = [];
            foreach ($normalized['variations'] as $v) {
                unset($v['created_at'], $v['updated_at'], $v['deleted_at']);
                $vars[] = $v;
            }
            $normalized['variations'] = $vars;
        }

        return $normalized;
    }

    /**
     * Differential comparator.
     * Detects semantic differences in prices, stock quantities, attributes, or gallery order.
     */
    public function compareSnapshots(array $baseline, array $candidate): array
    {
        $norm_base = $this->normalizeSnapshot($baseline);
        $norm_cand = $this->normalizeSnapshot($candidate);

        $differences = [];

        $critical_fields = [
            'id', 'type', 'status', 'sku', 'regular_price', 'sale_price',
            'manage_stock', 'stock_quantity', 'stock_status', 'gallery_image_ids'
        ];

        foreach ($critical_fields as $field) {
            $base_val = $norm_base[$field] ?? null;
            $cand_val = $norm_cand[$field] ?? null;

            if ($base_val !== $cand_val) {
                $differences[] = [
                    'field' => $field,
                    'baseline' => $base_val,
                    'candidate' => $cand_val,
                    'type' => 'field_mismatch'
                ];
            }
        }

        // Compare variations count and content
        $base_vars = $norm_base['variations'] ?? [];
        $cand_vars = $norm_cand['variations'] ?? [];

        if (count($base_vars) !== count($cand_vars)) {
            $differences[] = [
                'field' => 'variations_count',
                'baseline' => count($base_vars),
                'candidate' => count($cand_vars),
                'type' => 'variation_count_mismatch'
            ];
        } else {
            foreach ($base_vars as $idx => $bv) {
                $cv = $cand_vars[$idx] ?? [];
                if (($bv['sku'] ?? '') !== ($cv['sku'] ?? '')) {
                    $differences[] = [
                        'field' => "variations[{$idx}].sku",
                        'baseline' => $bv['sku'] ?? null,
                        'candidate' => $cv['sku'] ?? null,
                        'type' => 'variant_sku_mismatch'
                    ];
                }
                if (($bv['regular_price'] ?? '') !== ($cv['regular_price'] ?? '')) {
                    $differences[] = [
                        'field' => "variations[{$idx}].regular_price",
                        'baseline' => $bv['regular_price'] ?? null,
                        'candidate' => $cv['regular_price'] ?? null,
                        'type' => 'variant_price_mismatch'
                    ];
                }
                if (($bv['stock_quantity'] ?? '') !== ($cv['stock_quantity'] ?? '')) {
                    $differences[] = [
                        'field' => "variations[{$idx}].stock_quantity",
                        'baseline' => $bv['stock_quantity'] ?? null,
                        'candidate' => $cv['stock_quantity'] ?? null,
                        'type' => 'variant_stock_mismatch'
                    ];
                }
            }
        }

        return [
            'identical' => empty($differences),
            'differences' => $differences,
        ];
    }

    /**
     * Scale dataset manifest definition.
     */
    public function getScaleManifest(string $scale = '100k'): array
    {
        $manifests = [
            '100k' => [
                'scale' => '100k',
                'seed' => 424242,
                'target_site' => 'site1.localhost',
                'parent_products' => 100000,
                'distribution' => [
                    'simple' => 0.60,
                    'variable_2_options' => 0.30,
                    'variable_3plus_options' => 0.10,
                ],
                'average_variations_per_parent' => 8,
            ],
            '1M' => [
                'scale' => '1M',
                'seed' => 999999,
                'target_site' => 'site1.localhost',
                'parent_products' => 1000000,
                'distribution' => [
                    'simple' => 0.60,
                    'variable_2_options' => 0.30,
                    'variable_3plus_options' => 0.10,
                ],
                'average_variations_per_parent' => 8,
            ],
        ];

        return $manifests[$scale] ?? $manifests['100k'];
    }
}
