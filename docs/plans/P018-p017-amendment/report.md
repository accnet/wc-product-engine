# Report — P018: P017 amendment, register WPE_VERSION

## Summary

Completed `P018.1`. Documentation only, no code change.

- `docs/01-architecture-and-stack.md` §9 has a new `WPE_VERSION` row. It is the plugin version constant defined by `wc-product-engine.php`, not a site setting. P017 D03 puts it in the Shopify import fingerprint so a plugin update never reuses an old fingerprint.

## Why

P017 was approved with `WPE_VERSION` in its plan text. `node scripts/check-docs.mjs --strict` rejects unregistered constant-like names and exits 1 on that warning, which blocked the P017.2 and P017.3 DoD. P017 is active and cannot be resynced, so the name is registered in docs/01 instead.

## Evidence

- `node scripts/check-docs.mjs --strict`: exit 0, errors 0, warnings 0.
- `node scripts/plan.mjs check`: exit 0, errors 0.
