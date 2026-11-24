# P054 — Stop native rows leaking from deleted legacy products and leaky tests; residue gate sees orphan native rows

Status: in_progress

- [x] P054.1 — LegacyCapture ignores hooks of posts being permanently deleted and captures a deleted variation's parent once; T37 stops leaking (done)
- [x] P054.2 — T06 and CatalogShellIntegrityTest remove the products and shells they create (done)
- [ ] P054.3 — Residue gate detects orphan native rows; clean test_wpe_bench and purge the 17 live orphans with a backup; native price parity passes (todo)
