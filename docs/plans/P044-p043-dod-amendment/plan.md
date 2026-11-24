# P044 — P043 amendment: complete the mirror shell change with an assertion-enabled DoD

## 1. Goal

P043.1 is blocked. Its approved DoD runs `tests/integration/MirrorWorkerEpochTest.php` through plain `wp eval-file`. That test refuses to run when `zend.assertions` is off (the WP-CLI default on this host) and exits 1, before it checks anything. With `php -d zend.assertions=1 -d assert.exception=1` it passes all 23 checks on the P043 code. An approved plan cannot be resynced, so this amendment completes the same change with that command corrected, following the P007 precedent.

The business content is P043's unchanged (`docs/plans/P043-mirror-unchanged-shells/plan.md` §1–§3):
- `LegacyMirror` writes a variation shell only when its title, status or parent changes.
- It primes the shells in one query before `clean_post_cache()`.
- The P017 budget stays `3 × variations + 60`.

## 2. Scope

In scope: the P043.1 files and checks, with every DoD command runnable as written. P043 stays blocked and points here.

Out of scope: anything beyond P043 §2.

## 3. Decisions

- **D01** — Tests that refuse to run without assertions are run with `php -d zend.assertions=1 -d assert.exception=1 /usr/local/bin/wp`, as in P006/P007.
- **D02** — P043 is not closed. It remains blocked with this plan named as its continuation, because its sealed DoD cannot pass as written.

## 4. Documentation changes

- `docs/NOTES.md`
- `docs/plans/P044-p043-dod-amendment/plan.md`
- `docs/plans/P044-p043-dod-amendment/report.md`

## 5. Tasks

```json
{
  "tasks": [
    {
      "id": "P044.1",
      "title": "Complete P043.1 (unchanged variation shells skipped, shells primed before clean_post_cache) with assertion-enabled mirror epoch checks",
      "depends_on": [],
      "read_first": [
        "docs/plans/P043-mirror-unchanged-shells/plan.md",
        "src/Application/Migration/LegacyMirror.php",
        "tests/integration/ImportPerformanceTest.php"
      ],
      "files": [
        "src/Application/Migration/LegacyMirror.php",
        "tests/integration/ImportPerformanceTest.php",
        "docs/NOTES.md"
      ],
      "dod": [
        ["php", "-l", "src/Application/Migration/LegacyMirror.php"],
        ["php", "-l", "tests/integration/ImportPerformanceTest.php"],
        ["wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ImportPerformanceTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/MirrorWorkerEpochTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/OutboxProjectionWorkerTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/PostShellProjectionTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ShopifyBatchImporterTest.php"],
        ["php", "-d", "zend.assertions=1", "-d", "assert.exception=1", "/usr/local/bin/wp", "--path=/var/www/site1.localhost", "--require=tests/performance/use-test-db.php", "eval-file", "tests/integration/ProductEditorServiceTest.php"],
        ["node", "scripts/check-docs.mjs", "--strict"],
        ["node", "scripts/guard.mjs", "--all"]
      ]
    }
  ]
}
```
