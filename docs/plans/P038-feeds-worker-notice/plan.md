# P038 — Feed worker notice reflects the cron-less fallback

## 1. Goal

Since P036, feeds keep updating from site requests when the cron worker is missing. The Product Feeds worker notice ("Changes are not published until it runs again") and the Overview item ("feeds are not updated") are now wrong. They must say what actually happens, and when the fallback last ran, while still asking for the cron line.

## 2. Scope

In scope:
- `FeedAutoRunner` records its last run in `wpe_control` (key `feed_autorun_last`: time, duration, feeds). This is separate from the worker heartbeat, so health still sees the missing worker.
- The Product Feeds notice and the Overview "Needs attention" item use two texts:
  - fallback on: updates come from site requests, the last fallback run, publishing can be late on a quiet site, cron recommended;
  - fallback off (`WPE_FEEDS_AUTORUN` false or the filter): the previous wording.
- Tests, plus docs 10 §12 and 09 §11.

Out of scope: any change to scheduling, thresholds or warning levels.

## 3. Decisions

- **D01** — The warning level stays "warning": without cron, timing is best-effort.
- **D02** — The last-run row is written once per fallback tick (at most every 30 s), after the tick.

## 4. Documentation changes

- `docs/10-integrations.md` §12, `docs/09-ui.md` §11.

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P038.1",
      "title": "Worker notice and Overview item describe the in-request fallback and its last run instead of saying changes are not published",
      "depends_on": [],
      "read_first": [
        "docs/10-integrations.md",
        "docs/09-ui.md",
        "src/Application/Feeds/FeedAutoRunner.php",
        "src/Admin/FeedsPage.php",
        "src/Admin/SettingsPage.php"
      ],
      "files": [
        "docs/10-integrations.md",
        "docs/09-ui.md",
        "src/Application/Feeds/FeedAutoRunner.php",
        "src/Admin/FeedsPage.php",
        "src/Admin/SettingsPage.php",
        "tests/integration/FeedsPageTest.php",
        "tests/integration/FeedHealthTest.php",
        "tests/integration/FeedAutoRunTest.php"
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
          "tests/integration/FeedHealthTest.php"
        ],
        [
          "wp",
          "--path=/var/www/site1.localhost",
          "--require=tests/performance/use-test-db.php",
          "eval-file",
          "tests/integration/FeedAutoRunTest.php"
        ],
        [
          "node",
          "scripts/check-docs.mjs",
          "--strict"
        ],
        [
          "node",
          "scripts/guard.mjs",
          "--all"
        ]
      ]
    }
  ]
}
```
