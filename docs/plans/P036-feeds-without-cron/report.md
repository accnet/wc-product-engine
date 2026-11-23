# Report — P036: Product feeds update without a system cron

## Summary

Feeds now build right after they are created, and product changes reach the published feed, even when the system cron `wp wpe feeds tick` is not installed. The cron worker stays the primary runner, and nothing changes while its heartbeat is fresh.

### Changes delivered
- **P036.1 Docs**: docs/10 §12 (update model), docs/08 §3 (an intended exception to the rule that no work depends on page traffic), docs/04 and the contracts (`continue` mode), docs/09 §11, NOTES. Amendment P037 registers `WPE_FEEDS_AUTORUN` in docs/01 §9.
- **P036.2 Build on create**: `regenerate` mode `continue` advances a requested or running build by one 20-second step and never starts a new one. The Edit screen drives it after Create, after a Save that changes rendering, and after Full rebuild.
- **P036.3 Build on product change**: `FeedAutoRunner` hooks `shutdown` and, after `fastcgi_finish_request()`, runs one `FeedScheduler::tick(20, heartbeat: false)`. It only does so while the worker heartbeat is stale and work is pending (a build, dirty products, a due price change or a due publish). A global named lock allows one process at a time, a 30-second throttle applies (a request that changed products skips it), and nothing runs under WP-CLI. `FeedScheduler::publishBlocker()` was extracted from `maybePublish()` so the fallback uses the same debounce rules.

### Verification
- FeedAutoRunTest 21 checks, FeedIncremental 20, FeedReconcile 13, FeedHealth 7, FeedsPage 58, FeedsApi 45, FeedBuildPublish 26, FeedEndpoint 19, on `test_wpe_bench`. `guard --all` and `check-docs --strict` both pass.
- Live site1 with no cron, temporary feed 13:
  - Anonymous `admin-ajax.php` requests every 12 seconds built and published the full catalog in about 90 seconds.
  - 30 dirty marks were processed after a request whose response took 0.49 s.
  - The worker heartbeat stayed unchanged.
  - The feed was deleted afterwards.

### Limits
- Without cron, nothing runs while no request reaches PHP, so a publish after the quiet window can be later than 5 minutes on an idle site. Reconciliation only advances alongside other pending work.
- The worker warning stays visible on purpose. Production should still install the cron line.
