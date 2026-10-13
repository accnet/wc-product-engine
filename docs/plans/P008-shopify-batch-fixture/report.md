# Report

## Summary

OD-13 is resolved: ShopifyBatchImporterTest was failing because of its own fixture, not because of an importer defect. The hand-written CSV rows were shifted against the 48-column header, so the second product's grams value 500 was read as its variant SKU and collided with a real product that owns SKU 500 on this site ("Duplicate SKU '500' already registered"). The fixture is now built from a map keyed by header names with an unknown-column guard. Data and every assertion are unchanged, the importer and its counters were not touched, and the test passes (initJob, processBatch with checkpoint, pause and resume, cancel).

Follow-up: the test still leaves some residue (WordPress variation shells and inventory rows) that its teardown does not remove. OD-14 (authority_epoch versus current_epoch) remains the only open question. This is not a WooCommerce integration certification. No push was performed.
