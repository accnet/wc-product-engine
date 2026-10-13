# Report

## Summary

OD-14 is resolved and the P006 fix is corrected. The contract (docs/02-domain.md) makes authority_epoch the epoch every command must carry. P006.1 had taken the fallback worker epoch from the outbox counter current_epoch, a separate counter whose only writer has no caller in src; it worked on this site only because 9 is not below 2 and would have brought rejected_stale_epoch back once an authority flip raised authority_epoch above it. The Variants Manager save and both Shopify importers now use LegacyMirror::getAggregateEpoch (object state epoch, otherwise the global authority epoch), which is exactly what the epoch fence compares against. LegacyMirror, the fence and both control keys are unchanged.

Evidence, all with assertions on: MirrorWorkerEpochTest (23 checks; it raises authority_epoch above both 1 and current_epoch for the run, restores the original control row verbatim, failed on the P006 source and passes now), ProductEditorServiceTest, ProductEditorContractTest, ProductEditorE2ETest (10 acceptance criteria), ShopifyBatchImporterTest, and the live browser run VariantsBlockLive.mjs (68 checks, no projection warning after Update).

P009.2 made the Shopify batch test teardown remove the native rows, object state, terms, inventory, options and WordPress shells that the import creates; row counts measured before and after a run are identical. Residue from earlier runs (19 Batch Test Product posts, 170 variation shells with parent 0, orphaned object state rows) was not deleted. No open questions remain. This is not a WooCommerce integration certification. No push was performed.
