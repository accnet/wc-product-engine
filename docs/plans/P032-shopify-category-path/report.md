# Report

## Summary

Shopify imports can now turn `Product category` (`A > B > C`) into a WooCommerce category tree, opt-in per job (`category_mode=path`; default `none` keeps the column unmapped and reported). Parent category archives served by the engine now list products of their child categories (`include_children`), which they did not before.

| Task | Change |
|---|---|
| P032.1 | docs/10 §7: opt-in path mapping, leaf assignment, default dropped, parent archives include children |
| P032.2 | `ShopifyBatchImporter` `category_mode` + `applyCategoryPath()`; REST whitelist; import screen select + JS; `CatalogAdapters::expandCategoryTerms()`; `ShopifyImportCategoryPathTest.php`, `CatalogCategoryChildrenTest.php` |
| P032.3 | Backfill of the 21/11 12:29 import from its CSV (9,699/9,699): Apparel & Accessories > Clothing > Shirts & Tops > All Over Print Hoodie; WooCommerce term recount; `ShopifyCategoryPathLive.php`; NOTES |

Storefront after backfill: every level of the tree lists 9,699 products, Uncategorized 1,439, shop 11,138.

Not covered: Vendor/Type mapping; Shopify standard taxonomy IDs; `T18Test` R71 (pre-existing, unchanged with the pre-P032 adapter).
