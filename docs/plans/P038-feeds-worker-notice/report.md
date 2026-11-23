# Report — P038: Feed worker notice reflects the cron-less fallback

## Summary

Since P036 the old worker notice ("Changes are not published until it runs again") was wrong, because feeds keep updating from site requests. With the fallback on, the notice and the Overview item now say that feeds update from site requests and that publishing can be late while the site is quiet. They show the last fallback update, which `FeedAutoRunner` now records in `wpe_control` under `feed_autorun_last`, and they still ask for the cron line. With the fallback off, the previous wording is kept.

### Verification
- On `test_wpe_bench`: FeedsPageTest 60 checks, FeedHealthTest 9, FeedAutoRunTest 22. `check-docs --strict` and `guard --all` pass.
- Live site1 notice: "No system cron runs the feed worker. Last worker run 9 hours ago. Feeds are updated from site requests instead, so publishing can be late while the site is quiet. For reliable timing, add this line to the system crontab: …"
