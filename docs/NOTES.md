# NOTES

## 1. Agent notes

- T01: Baseline inventory captured directly from site1.localhost (WordPress 7.0, WooCommerce 11.1.2, PHP 8.3.31, MariaDB 11.8.6). Verified cart, checkout, gallery, admin, and CSV surfaces operational. Classified active plugins and confirmed unclassified direct SQL writers block native authority migration. Evidence recorded in docs/evidence/T01/result.json.
- T02: Implemented PluginBootstrap with lifecycle hooks, dependency checking against WooCommerce 11.0+, feature flag scoping (query, media, resolver, native writes), and deterministic cohort routing precedence. Verified deactivation retains all tables and data. Evidence recorded in docs/evidence/T02/result.json.
- T03: Sealed database, OpenAPI, API endpoints, event schemas, CSV dialects, and WooCommerce properties mapping (56 properties and 39 Data Store methods verified from runtime reflection). Verified decimal ID and amount string precision, and nullable/absent/blank semantics. Evidence recorded in docs/evidence/T03/result.json.
