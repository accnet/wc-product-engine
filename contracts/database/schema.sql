-- WC Product Engine Table Definitions
-- Target Database: MariaDB (InnoDB, utf8mb4)
-- Placeholder {prefix} will be dynamically replaced by WordPress $wpdb->prefix at runtime

CREATE TABLE IF NOT EXISTS `{prefix}wpe_products` (
  `id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'simple',
  `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
  `slug` VARCHAR(200) NOT NULL,
  `title` TEXT NOT NULL,
  `description` LONGTEXT NOT NULL,
  `short_description` TEXT NOT NULL,
  `catalog_visibility` VARCHAR(20) NOT NULL DEFAULT 'visible',
  `sku` VARCHAR(191) NULL DEFAULT NULL,
  `regular_price` DECIMAL(19,6) NULL DEFAULT NULL,
  `sale_price` DECIMAL(19,6) NULL DEFAULT NULL,
  `sale_from` DATETIME(6) NULL DEFAULT NULL,
  `sale_to` DATETIME(6) NULL DEFAULT NULL,
  `virtual` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `downloadable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `tax_status` VARCHAR(20) NOT NULL DEFAULT 'taxable',
  `tax_class` VARCHAR(200) NOT NULL DEFAULT '',
  `sold_individually` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `purchase_note` TEXT NOT NULL,
  `menu_order` INT NOT NULL DEFAULT 0,
  `featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `shipping_class_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `weight` DECIMAL(19,6) NULL DEFAULT NULL,
  `length` DECIMAL(19,6) NULL DEFAULT NULL,
  `width` DECIMAL(19,6) NULL DEFAULT NULL,
  `height` DECIMAL(19,6) NULL DEFAULT NULL,
  `stock_owner_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `default_selection` LONGTEXT NOT NULL,
  `extra_props` LONGTEXT NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `deleted_at` DATETIME(6) NULL DEFAULT NULL,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_status_created_id` (`status`, `created_at`, `id`),
  KEY `idx_slug_id` (`slug`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_variants` (
  `id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'publish',
  `sku` VARCHAR(191) NULL DEFAULT NULL,
  `combination_key` BINARY(32) NOT NULL,
  `active_combination_key` BINARY(32) NULL DEFAULT NULL,
  `regular_price` DECIMAL(19,6) NULL DEFAULT NULL,
  `sale_price` DECIMAL(19,6) NULL DEFAULT NULL,
  `sale_from` DATETIME(6) NULL DEFAULT NULL,
  `sale_to` DATETIME(6) NULL DEFAULT NULL,
  `tax_class` VARCHAR(200) NULL DEFAULT NULL,
  `virtual` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `downloadable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `weight` DECIMAL(19,6) NULL DEFAULT NULL,
  `length` DECIMAL(19,6) NULL DEFAULT NULL,
  `width` DECIMAL(19,6) NULL DEFAULT NULL,
  `height` DECIMAL(19,6) NULL DEFAULT NULL,
  `shipping_class_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `description` TEXT NOT NULL,
  `stock_owner_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `extra_props` LONGTEXT NOT NULL,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `deleted_at` DATETIME(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product_status_pos_id` (`product_id`, `status`, `position`, `id`),
  UNIQUE KEY `uniq_product_active_combination` (`product_id`, `active_combination_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_sku_registry` (
  `normalized_sku` VARBINARY(764) NOT NULL,
  `object_id` BIGINT UNSIGNED NOT NULL,
  `object_kind` VARCHAR(16) NOT NULL,
  `original_sku` VARCHAR(191) NOT NULL,
  PRIMARY KEY (`normalized_sku`),
  UNIQUE KEY `uniq_object_id` (`object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_options` (
  `id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `key` VARCHAR(191) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `taxonomy` VARCHAR(64) NULL DEFAULT NULL,
  `position` SMALLINT NOT NULL DEFAULT 0,
  `visible` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  `used_for_variation` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  `default_value_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_product_key` (`product_id`, `key`),
  UNIQUE KEY `uniq_product_position` (`product_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_option_values` (
  `id` BIGINT UNSIGNED NOT NULL,
  `option_id` BIGINT UNSIGNED NOT NULL,
  `value_key` VARCHAR(191) NOT NULL,
  `label` TEXT NOT NULL,
  `term_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `swatch` LONGTEXT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_option_value_key` (`option_id`, `value_key`),
  KEY `idx_option_position_id` (`option_id`, `position`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_variant_values` (
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `option_id` BIGINT UNSIGNED NOT NULL,
  `value_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `is_any` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`variant_id`, `option_id`),
  KEY `idx_option_value_variant` (`option_id`, `value_id`, `variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_product_terms` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `term_taxonomy_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`product_id`, `term_taxonomy_id`),
  KEY `idx_term_product` (`term_taxonomy_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_meta` (
  `meta_id` BIGINT UNSIGNED NOT NULL,
  `object_id` BIGINT UNSIGNED NOT NULL,
  `object_kind` VARCHAR(16) NOT NULL,
  `meta_key` VARCHAR(255) NOT NULL,
  `meta_value` LONGTEXT NOT NULL,
  PRIMARY KEY (`meta_id`),
  KEY `idx_object_meta_key` (`object_id`, `meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_product_links` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`, `kind`, `target_id`),
  KEY `idx_target_kind` (`target_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_downloads` (
  `object_id` BIGINT UNSIGNED NOT NULL,
  `download_id` VARCHAR(64) NOT NULL,
  `name` TEXT NOT NULL,
  `file_url` TEXT NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`object_id`, `download_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_inventory` (
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `manage_stock` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `quantity` DECIMAL(19,6) NULL DEFAULT NULL,
  `backorders` VARCHAR(16) NOT NULL DEFAULT 'no',
  `stock_status` VARCHAR(20) NOT NULL DEFAULT 'instock',
  `low_stock_amount` DECIMAL(19,6) NULL DEFAULT NULL,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_stock_operations` (
  `operation_key` VARBINARY(191) NOT NULL,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `order_item_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `kind` VARCHAR(24) NOT NULL,
  `delta` DECIMAL(19,6) NOT NULL,
  `quantity_after` DECIMAL(19,6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`operation_key`),
  KEY `idx_owner_created` (`owner_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_reservation_bridge` (
  `order_id` BIGINT UNSIGNED NOT NULL,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `quantity` DECIMAL(19,6) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'active',
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`order_id`, `owner_id`),
  KEY `idx_owner_state_expires` (`owner_id`, `state`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` VARCHAR(16) NOT NULL DEFAULT 'image',
  `source_url` TEXT NOT NULL,
  `url_key` BINARY(32) NOT NULL,
  `provider` VARCHAR(64) NOT NULL DEFAULT 'default',
  `provider_key` TEXT NULL DEFAULT NULL,
  `mime` VARCHAR(100) NULL DEFAULT NULL,
  `width` INT NULL DEFAULT NULL,
  `height` INT NULL DEFAULT NULL,
  `alt_text` TEXT NOT NULL,
  `title` TEXT NOT NULL,
  `caption` TEXT NOT NULL,
  `attachment_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `delivery_policy` LONGTEXT NOT NULL,
  `renditions` LONGTEXT NOT NULL,
  `health` VARCHAR(16) NOT NULL DEFAULT 'healthy',
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attachment_id` (`attachment_id`),
  KEY `idx_url_key_id` (`url_key`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_asset_storage` (
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `storage_provider` VARCHAR(32) NOT NULL,
  `bucket` VARCHAR(191) NOT NULL DEFAULT '',
  `object_key` TEXT NOT NULL,
  `object_key_hash` BINARY(32) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content_hash` BINARY(32) NOT NULL,
  `etag` VARCHAR(128) NULL DEFAULT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'active',
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `deleted_at` DATETIME(6) NULL DEFAULT NULL,
  PRIMARY KEY (`asset_id`),
  UNIQUE KEY `uniq_storage_object` (`storage_provider`, `bucket`, `object_key_hash`),
  KEY `idx_state_created` (`state`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_upload_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_key` VARCHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `session_id` VARCHAR(64) NOT NULL,
  `product_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'receiving',
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `mime` VARCHAR(100) NULL DEFAULT NULL,
  `size_bytes` BIGINT UNSIGNED NULL DEFAULT NULL,
  `content_hash` BINARY(32) NULL DEFAULT NULL,
  `storage_provider` VARCHAR(32) NULL DEFAULT NULL,
  `bucket` VARCHAR(191) NOT NULL DEFAULT '',
  `object_key` TEXT NULL DEFAULT NULL,
  `object_key_hash` BINARY(32) NULL DEFAULT NULL,
  `asset_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `error_reason` VARCHAR(64) NULL DEFAULT NULL,
  `error_message` TEXT NULL DEFAULT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_upload_key` (`user_id`, `upload_key`),
  KEY `idx_session_state` (`session_id`, `state`),
  KEY `idx_state_updated` (`state`, `updated_at`),
  KEY `idx_product_state` (`product_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_product_media` (
  `product_id` BIGINT UNSIGNED NOT NULL,
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(16) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `alt_override` TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`product_id`, `asset_id`, `role`),
  UNIQUE KEY `uniq_product_role_position` (`product_id`, `role`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_variant_media` (
  `variant_id` BIGINT UNSIGNED NOT NULL,
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `is_primary` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `alt_override` TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`variant_id`, `asset_id`),
  UNIQUE KEY `uniq_variant_position` (`variant_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_catalog` (
  `generation` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `source_version` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(20) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `visibility` VARCHAR(20) NOT NULL,
  `in_stock` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  `featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `on_sale` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `min_price` DECIMAL(19,6) NULL DEFAULT NULL,
  `max_price` DECIMAL(19,6) NULL DEFAULT NULL,
  `total_sales` DECIMAL(19,6) NOT NULL DEFAULT 0.000000,
  `rating_average` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `rating_count` BIGINT NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL,
  `menu_order` INT NOT NULL DEFAULT 0,
  `title_sort` VARCHAR(191) NOT NULL DEFAULT '',
  `image_asset_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `indexed_at` DATETIME(6) NOT NULL,
  `next_price_change` DATETIME(6) NULL DEFAULT NULL,
  PRIMARY KEY (`generation`, `product_id`),
  KEY `idx_cat_filter` (`generation`, `status`, `visibility`, `in_stock`, `min_price`, `product_id`),
  KEY `idx_cat_created` (`generation`, `status`, `created_at`, `product_id`),
  KEY `idx_cat_sales` (`generation`, `status`, `total_sales`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_catalog_facets` (
  `generation` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `variant_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `facet_key` VARBINARY(191) NOT NULL,
  `value_key` VARBINARY(191) NOT NULL,
  PRIMARY KEY (`generation`, `facet_key`, `value_key`, `product_id`, `variant_id`),
  KEY `idx_generation_product_variant` (`generation`, `product_id`, `variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_object_state` (
  `object_id` BIGINT UNSIGNED NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `authority` VARCHAR(16) NOT NULL DEFAULT 'legacy',
  `epoch` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `legacy_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `native_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `legacy_applied` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `native_applied` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `legacy_hash` BINARY(32) NULL DEFAULT NULL,
  `native_hash` BINARY(32) NULL DEFAULT NULL,
  `tombstone` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`object_id`),
  KEY `idx_authority_parent_object` (`authority`, `parent_id`, `object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_outbox` (
  `event_id` CHAR(36) NOT NULL,
  `aggregate_id` BIGINT UNSIGNED NOT NULL,
  `revision` BIGINT UNSIGNED NOT NULL,
  `epoch` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `attempts` INT NOT NULL DEFAULT 0,
  `available_at` DATETIME(6) NOT NULL,
  `lease_owner` VARCHAR(100) NULL DEFAULT NULL,
  `lease_until` DATETIME(6) NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `last_error` TEXT NULL DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `uniq_agg_epoch_rev_type` (`aggregate_id`, `epoch`, `revision`, `event_type`),
  KEY `idx_state_available_lease` (`state`, `available_at`, `lease_until`, `event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_jobs` (
  `id` CHAR(36) NOT NULL,
  `kind` VARCHAR(24) NOT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `config` LONGTEXT NOT NULL,
  `checkpoint` LONGTEXT NOT NULL,
  `counts` LONGTEXT NOT NULL,
  `lease_owner` VARCHAR(100) NULL DEFAULT NULL,
  `lease_until` DATETIME(6) NULL DEFAULT NULL,
  `cancel_requested` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `error_code` VARCHAR(64) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_lease_id` (`status`, `lease_until`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_import_rows` (
  `job_id` CHAR(36) NOT NULL,
  `row_number` BIGINT NOT NULL,
  `group_key` VARBINARY(191) NOT NULL,
  `raw_fields` LONGTEXT NOT NULL,
  `mapped_payload` LONGTEXT NULL DEFAULT NULL,
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `errors` LONGTEXT NOT NULL,
  `warnings` LONGTEXT NOT NULL,
  `target_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `content_hash` BINARY(32) NOT NULL,
  PRIMARY KEY (`job_id`, `row_number`),
  KEY `idx_job_group_row` (`job_id`, `group_key`, `row_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_external_ids` (
  `source` VARCHAR(64) NOT NULL,
  `source_scope` VARBINARY(191) NOT NULL,
  `external_key` VARBINARY(191) NOT NULL,
  `object_kind` VARCHAR(16) NOT NULL,
  `object_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`source`, `source_scope`, `external_key`, `object_kind`),
  KEY `idx_object_source` (`object_id`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_control` (
  `key` VARBINARY(191) NOT NULL,
  `value` LONGTEXT NOT NULL,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}wpe_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `request_id` CHAR(36) NOT NULL,
  `operation` VARCHAR(64) NOT NULL,
  `target_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `job_id` CHAR(36) NULL DEFAULT NULL,
  `before_revision` BIGINT UNSIGNED NULL DEFAULT NULL,
  `after_revision` BIGINT UNSIGNED NULL DEFAULT NULL,
  `summary` LONGTEXT NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_target_created_id` (`target_id`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
