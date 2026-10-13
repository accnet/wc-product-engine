# Report

## Summary

P006.1 fixed OD-12: when a product has no object state row, the Variants Manager save and both Shopify importers now use the current system epoch from OutboxWorker::getCurrentEpoch() as the mirror worker epoch instead of a literal 1. LegacyMirror, the epoch fence and the control keys are unchanged. On this site (global authority epoch 2) a new product's variation shells are now mirrored with the right parent and status, and the projection warning notice no longer appears after Update (live run: notice shown is false).

Evidence, all with assertions on: MirrorWorkerEpochTest (20 checks; it failed on the old code with rejected_stale_epoch and also proves the fence still rejects a stale worker), ProductEditorServiceTest (testLegacyMirrorProjection passes again), ProductEditorContractTest, ProductEditorE2ETest (10 acceptance criteria), and the live browser run VariantsBlockLive.mjs (68 checks).

Plan housekeeping: P006.2 listed ShopifyBatchImporterTest in its DoD, but that test fails identically on the unmodified baseline (checked by stashing the change), so the DoD could not pass and a plan cannot be resynced after a task is done. P006.2 stays blocked as superseded and this plan carries the corrected docs and regression work. P006 therefore stays open by design.

Open questions logged: OD-13 (Shopify batch importer test failure, possible counter or batch boundary defect, not touched) and OD-14 (authority_epoch 2 versus current_epoch 9). The Shopify importers still swallow the mirror outcome. This is not a WooCommerce integration certification. No push was performed.
