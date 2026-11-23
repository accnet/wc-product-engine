# P036 — Product feeds update without a system cron

## 1. Goal

A feed is built as soon as it is created, and product changes reach the published feed, even when the system cron `wp wpe feeds tick` is not installed. The cron worker stays the primary runner; nothing changes when it is alive.

## 2. Scope

In scope:
- **Build on create (kept)**: `POST /admin/feeds/{id}/regenerate` mode `continue` advances a requested or running build by one 20-second step and never starts one; the open Edit screen drives it after Create, a render-affecting Save and Full rebuild.
- **Build on product change**: `FeedAutoRunner` runs at the end of web requests (after `fastcgi_finish_request()` so the visitor does not wait) when no fresh worker heartbeat exists and feed work is pending: dirty products, due sale windows, a requested/running rebuild, or a debounce window. It runs the same `FeedScheduler` tick (same per-feed lock, same debounce, publish gate and reconciliation) for at most 20 seconds, one PHP process at a time, at most every 30 seconds; a request that itself changed products skips the 30-second wait.
- Tests on `test_wpe_bench`; docs 10 §12, 08 §3, 04, 09; NOTES.

Out of scope:
- WP-Cron or Action Scheduler (site1 sets `DISABLE_WP_CRON`; page-cached traffic makes them unreliable).
- Changing debounce, gate or reconciliation rules.

## 3. Decisions

- **D01 — Cron first, fallback second.** The fallback runs only while the worker heartbeat is missing or older than 5 minutes, so production with cron keeps all heavy work in WP-CLI. Health keeps warning about the missing worker: the fallback does not write the heartbeat.
- **D02 — Same code path.** The fallback calls `FeedScheduler::tick()`; no second scheduler, no lock bypass. A global `GET_LOCK('…wpe_feed_autorun')` keeps it to one PHP-FPM process.
- **D03 — After the response.** It runs on `shutdown` after `fastcgi_finish_request()` with `ignore_user_abort(true)`; without FastCGI (e.g. CLI) it does nothing. Opt-out: `define('WPE_FEEDS_AUTORUN', false)` or filter `wpe_feeds_autorun`.
- **D04 — Cheap when idle.** A 30-second transient throttle; the pending-work check is one indexed query per enabled feed. When nothing is pending nothing runs, so reconciliation without cron only advances while other feed work exists.
- **D05 — Limits.** Without cron, publishes after the quiet window happen on the next PHP request (wp-admin heartbeat, REST, uncached pages), so the 5-minute max wait can stretch on an idle site.

## 4. Documentation changes

- `docs/10-integrations.md` §12 (update model), `docs/08-operations.md` §3, `docs/04-api-contract.md` (regenerate `continue`), `docs/09-ui.md` §11, `contracts/api/endpoints.json`, `contracts/openapi/wpe-v1.json`, `docs/NOTES.md`.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P036.1",
      "title": "Document build-on-create and the in-request fallback runner for feed updates without a system cron",
      "depends_on": [],
      "read_first": [
        "docs/10-integrations.md",
        "docs/08-operations.md",
        "docs/04-api-contract.md"
      ],
      "files": [
        "docs/10-integrations.md",
        "docs/08-operations.md",
        "docs/04-api-contract.md",
        "docs/09-ui.md",
        "contracts/api/endpoints.json",
        "contracts/openapi/wpe-v1.json",
        "docs/NOTES.md"
      ],
      "dod": [
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ]
      ]
    },
    {
      "id": "P036.2",
      "title": "Keep build-on-create (regenerate continue mode driven by the Edit screen)",
      "depends_on": [
        "P036.1"
      ],
      "read_first": [
        "src/Admin/Rest/FeedsApiController.php",
        "assets/admin/feeds.js"
      ],
      "files": [
        "src/Admin/Rest/FeedsApiController.php",
        "assets/admin/feeds.js",
        "src/Bootstrap/PluginBootstrap.php",
        "tests/integration/FeedsPageTest.php"
      ],
      "dod": [
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedsPageTest.php"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedsApiTest.php"
        ],
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ]
      ]
    },
    {
      "id": "P036.3",
      "title": "Product changes update feeds without a system cron: FeedAutoRunner runs a bounded scheduler tick after the response when no worker heartbeat is fresh",
      "depends_on": [
        "P036.2"
      ],
      "read_first": [
        "src/Application/Feeds/FeedScheduler.php",
        "src/Application/Feeds/FeedChangeTracker.php",
        "src/Bootstrap/PluginBootstrap.php"
      ],
      "files": [
        "src/Application/Feeds/FeedAutoRunner.php",
        "src/Application/Feeds/FeedScheduler.php",
        "src/Application/Feeds/FeedChangeTracker.php",
        "src/Bootstrap/PluginBootstrap.php",
        "tests/integration/FeedAutoRunTest.php"
      ],
      "dod": [
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedAutoRunTest.php"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedIncrementalTest.php"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedReconcileTest.php"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedHealthTest.php"
        ],
        [
          "node",
          "scripts/guard.mjs",
          "--all"
        ],
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ]
      ]
    }
  ]
}
```
