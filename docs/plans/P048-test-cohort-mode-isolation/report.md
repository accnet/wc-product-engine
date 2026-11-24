# Report

## Summary

Completed P048.1: twelve integration tests that silently depended on the cohort mode the previous test left in `test_wpe_bench` now pin the mode they were written for through `tests/integration/support/CohortModeFixture.php`, and T40Test (whose rollback steps change the mode) restores the flags. Restoration runs at shutdown, so failing or fatal tests cannot leak the mode. The DoD runs each pinned test while the DB is in the other mode and checks with `tests/integration/support/cohort-mode.php expect` that nothing leaked; all passed. No assertion or production code changed. Other failures seen in the same full run fail under both modes and are out of scope (listed in plan section 2 and NOTES). Not a WooCommerce integration certification.
