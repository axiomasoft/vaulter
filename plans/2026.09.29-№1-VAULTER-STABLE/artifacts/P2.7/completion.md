# P2.7 — Canonical 0.3 schema and historical upgrade

Fresh canonical tables are created independently of module flags. All five affected
packages use drives, file_revisions and file_revision_blobs and the canonical persisted
attributes. The node head/document fields explicitly reserved for P4.1 stay historical.
Config::table is prefix + canonical name; legacyTable resolves only upgrade names.

Five new guarded migrations implement preflight, table/attribute/index/FK normalization,
revision changes, grants/shares cleanup and event/subscription conversion. P1 №3/№6/№7
use the actual ordered schema. D18 records owner-approved 72 historical + 2 root repair
activity entries and full upgrade-batch rollback rather than the invalid step=5.
Both bigint/ULID fixtures preserve expected data, owner IDs, revision numbers and scan
defaults, convert every historical event, replay each new up, rollback and re-upgrade.
Customized nodes/revision table names and historically disabled comments are checked.
Schema parity is exact, including PostgreSQL expressions/predicates and serial names;
the measured P2.1 baseline has zero differences for both strategies.

REST keys remain old until P6.1 while resources read new attributes. Removed storage
metadata returns null. UPGRADE explains backup, irreversible dropped metadata/grants,
share revocation, historical event maps, rollback and deferred node/document work.
API golden records EventType values, UpgradePreflightException and legacyTable.

## Validation

PostgreSQL: 477 passed across 10 completed checks, zero failures and
zero skipped PostgreSQL acceptance cases. The supplemental full Media CAS command
reports four explicitly SQLite-only premise checks skipped on PostgreSQL; all four
execute in the green full SQLite Media gate. postgresql-applicability.json identifies
each test. The literal frozen '0 skipped' is not presented as the raw aggregate. Upgrade+Schema alone: 100 passed, 611 assertions. The remaining grid covers
P1 listing/outbox/host keys, three ID strategies, prefix, disabled activity, Spatie,
Drive races and upload contracts, and Media CAS. All six packages' Pint/PHPStan/Pest
commands exit 0. SQLite test counts: core 653 passed/38 skipped/17 risky; Drive 506/18/2;
Documents 256/1/0; Media 138/16/4; Filament 80/1/0; Azgard 21/0/0. Conditional skips/risky
cases remain visible and are not claimed executed. Azgard uses the unchanged qualified
P0 dependency recorded by P2.1; its package is not modified.

Both negative controls fail for the intended missing subscription UPDATE/node preflight
check, then pass after byte-exact restoration. They use an isolated temporary *_test
database; it was empty after cleanup and removed. No source mutation remains.
A general test-harness performance trial did not improve timings and was removed;
only FixtureTestCase's required fixture-owned migration lifecycle is retained.

## Known deviation, owner P8.1

Real MySQL fresh installation exits 2 at error 1215: sibling_active STORED conflicts
with the cascading parent_id FK. Archived unchanged P2.1 product reproduces the exact
same SQL/error on MySQL 8.0.46. This is P2.7's explicitly permitted baseline exception.
The raw failing command is retained; E11 accepts its documented conditional classifier.
No successful full MySQL installation or MySQL 0.2 upgrade is claimed.

## Review and closure

review: grok/grok-4.7 FINDINGS. The completed independent 500k review found one major
local omission: the base blobs migration still had a runtime feature gate. That gate
is removed; a fresh migrate, full rollback and repeated migrate with every module flag
disabled are checked on SQLite and PostgreSQL. The regression failed before the fix
(missing blobs on SQLite; missing referenced relation on PostgreSQL) and passes after.
Post-fix PostgreSQL Upgrade+Schema and scoped Pint/PHPStan pass; their exact results and
hashes are recorded in review-fix-validation.json. Existing six package gates and P1
grid are reused because normal enabled-feature execution is unchanged by this fix.
D19 records the owner's explicit cancellation of further audits. No second reviewer
was launched and no external GREEN is claimed for the corrected candidate.
The original active reviewer had completed naturally and was not interrupted.
Two earlier incomplete transport attempts remain preserved without verdict claims.

write-site: pre_mutation: allow · pre_final: allow

P2.7 closes with deviations solely for the declared MySQL installation baseline owned
by P8.1. P2 remains open. One scoped local commit contains implementation, docs, tests
and task-owned evidence. No push or release. The successor B2-A (P2.2 P2.3) is a
recommendation outside this invocation's admitted scope.

## Bookkeeping validation

Post-fix PostgreSQL Upgrade+Schema: 101 passed, 649 assertions, no failures/skips;
new SQLite feature-off lifecycle: 1 passed, 38 assertions. Scoped Pint and core
PHPStan exit 0. git diff --check passes. Plan lint exits 1: a pre-existing P2.1
completion diagnostic and two unresolved bare read-budget filenames in untouched
P2.6 (AutoIncrementIds.php, Uuid7Ids.php). It reports no P2.7 diagnostic. Those
out-of-scope carriers are not edited or represented as a successful whole-plan lint.
