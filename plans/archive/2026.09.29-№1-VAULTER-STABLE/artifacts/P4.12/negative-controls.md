# P4.12 — negative controls (D51: bounded, SQLite)

Each control injects one defect, runs the named test file on SQLite (`env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <file>`, cwd = package), then restores the exact bytes (sha256 verified after restore). Run 2026-10-08 by claude/claude-opus-5-5/high, session 1ea5f8c8-31ea-42e1-9135-f6611f77b9e6.

| # | Guard | Injection | Test file | Result with defect | Restored sha256 (prefix) |
|---|---|---|---|---|---|
| NC1 | P4-I19 pin re-check under the F/R locks | `DeleteFileRevisionAction`: drop the `keep_forever` refusal | drive `tests/Drive/QuotaLedgerTest.php` | exit 1 — 1 failed (refuses to delete the head or a pinned revision) | d7187387074a |
| NC2 | P4-I19 head refusal | `DeleteFileRevisionAction`: drop `throw CannotDeleteHeadRevisionException` | drive `tests/Drive/QuotaLedgerTest.php` | exit 1 — 1 failed | d7187387074a |
| NC3 | P4-I18 purge frees every retained revision | `FileContentLifecycle::preparePurge`: `$bytes =` instead of `+=` | drive `tests/Drive/QuotaLedgerTest.php` | exit 1 — 2 failed (every path; purged subtree) | 2b8aeac1fb21 |
| NC4 | P4-I18 a new revision is charged in full | `IngestFileAction`: reserve `size − previous head` | drive `tests/Drive/QuotaLedgerTest.php` | exit 1 — 6 failed | b2fb1b161925 |
| NC5 | minNoncurrentAgeDays protection | `PruneFileRevisionsJob::candidates`: age check disabled | drive `tests/Drive/RevisionRetentionTest.php` | exit 1 — 1 failed (never prunes a revision superseded less than …) | c20afb740af4 |
| NC6 | D49 'global' refused | `MediaConfig::dedupScope`: no 'global' branch | media `tests/Configuration/MediaConfigTest.php` | exit 1 — 1 failed | 37b292661605 |
| NC7 | owning correction: `vaulter:scan-rescan --activate-policy` | `RescanFileRevisionsCommand.php` reverted to HEAD (bool `$activate` shadows the injected action) | drive `tests/Uploads/P3/ScanWorkRecoveryTest.php --filter=activate-policy` | exit 1 — 1 failed | restored copy verified with `sha256sum -c` |

All seven controls were green again after the restore (the same files pass in the focused runs listed in check-profile.json).
