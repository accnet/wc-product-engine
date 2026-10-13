# Report

## Summary

P005 moved the Variants Manager from a WooCommerce Product data tab into its own meta box (id wpe_variants_manager) below Product data, following the user's decisions: the tab is removed entirely, the box keeps normal WordPress collapse, hide and drag behavior, and the three pre-existing gaps were fixed in the same plan.

What changed:
- Bridge: the box is registered with default priority on add_meta_boxes priority 40 (a high priority box renders above any box a user has re-ordered; found in the live run). It is shown only for variable products and kept in sync with the type selector. Legacy Attributes and Variations tabs stay hidden for variable products. Fullscreen modal and drawer are rendered in the admin footer with dialog semantics.
- Saving: Update and Publish still carry the draft in a hidden payload, now filled only while the draft is dirty and the type is variable. A rejected or failed variants save shows an error notice, keeps the draft in a short-lived per-user transient and restores it once as an unsaved draft. The save result reports the legacy mirror and catalog index outcome and the UI warns when it is incomplete.
- Editor: header badge and Fullscreen button in the box title bar (visible when collapsed), lazy data load only for variable products, a layer manager (focus trap, Escape, focus return) for modal, drawer and dialogs, a submit guard for unsaved drafts under a non-variable type, no unload prompt on a normal Update.
- Service defects fixed: missing taxonomies key (empty global attribute dropdown), descriptive attributes leaking into variation combinations (generator never matched existing combinations), bulk edit preview contract mismatch.

Findings to be aware of:
- The P003 tests ran with assertions compiled out under WP-CLI on this host, so they passed vacuously. The editor tests now fail fast when assertions are off and all results below were produced with assertions on.
- ProductEditorServiceTest::testLegacyMirrorProjection still fails on this site because the editor passes worker epoch 1 while the site is at epoch 2 in legacy_restored mode, so the legacy mirror is rejected and variation post shells stay unmirrored. It is not edited and is not part of any P005 DoD; logged as OD-12 in docs/QUESTIONS.md. A projection warning notice therefore appears after Update on this site. Epoch semantics were not changed.
- A cross-task CSS rule for the hidden box was added during P005.2 and finalized in P005.3.

Evidence (all with assertions on): ProductEditorContractTest 53 checks, ProductEditorBridgeTest, ProductEditorE2ETest 10 acceptance criteria, tests/e2e/VariantsBlockLive.mjs 68 live browser checks (placement, tabs, collapse, shared draft, focus trap, Escape, failed-save restore, type-change guard, lazy load, responsive widths) with screenshots named p005_* in the conversation artifacts. These are not a WooCommerce integration certification. No push was performed.
