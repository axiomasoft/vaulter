# P1.1 Baseline (base c64bba25, HEAD 26ef0a0e)

Measured 2026-10-13T10:27:02Z; host: 32 CPU, load at start `8.16, 8.01, 6.57` (machine not idle). PHP 8.4.26 (shim; system php is 8.3.35), Pest 4.7.8, node v22.11.0, MySQL 8.0.46, PG binaries 16.

DB_FAST=1 (durability off) is **not** tmpfs: data dir /tmp is ext4 on nvme.

## Discovery (no tests executed)

| suite | metric | count | sha256(list) |
|:--|:--|--:|:--|
| core | test_cases | 2002 | `02692dfeeacc` |
| media | test_cases | 198 | `57e20fd5dbde` |
| drive | test_cases | 1120 | `8ba6ce568458` |
| documents | test_cases | 799 | `d878f3555cac` |
| azgard | test_cases | 59 | `7e22a0f25e8e` |
| filament | test_cases | 117 | `369b615bebdc` |
| load | test_cases | 56 | `5d8b8414f22b` |
| hub | test_files | 13 | `7be57a3c01e0` |
| vue | test_cases | 303 | `957faaa448cd` |

Lists: `tests/baseline/original/<suite>.list|count` (immutable) + current copy in `tests/baseline/`. Hub = file paths only (node --test has no discovery); case-level TAP baseline is P1.9. Full before/after timing: **deferred_to_P1.9**.

## Targeted runs (seconds wall; 2 reps; load avg 5–10 during runs)

| target | engine | DB_PARALLEL | OPENAPI | seconds |
|:--|:--|--:|:--|:--|
| media-find | sqlite | 0 | - | 1.2, 1.1 |
| media-find | sqlite | 4 | - | 1.6, 1.6 |
| drive-api | sqlite | 0 | - | 7.9, 8.1 |
| drive-api | sqlite | 4 | - | 8.2, 8.6 |
| media-atomic | pgsql | 0 | - | 5.0, 7.0 |
| media-atomic | pgsql | 4 | - | 6.6, 13.8 |
| media-atomic | mysql | 0 | - | 86.2, 97.7 |
| media-atomic | mysql | 4 | - | 87.1, 92.7 |
| core-openapi | sqlite | 0 | 0 | 2.3, 0.7 |
| core-http | sqlite | 0 | 0 | 3.0, 2.4 |
| core-openapi | sqlite | 0 | 1 | -1.0, 0.8, 0.8 |
| core-http | sqlite | 0 | 1 | 3.2, 3.1 |

Reading (not a global-bottleneck claim):
- MySQL 1-test file costs ~85–98 s wall: server provisioning dominates; PG ~5–14 s; SQLite 1–8 s. DB_PARALLEL=4 gives no gain on single-file targets.
- VAULTER_OPENAPI_VALIDATE 0 vs 1: CanonicalSchemaTest 0.7–2.3 vs 0.8 s; HttpKernelV1Test 2.4–3.0 vs 3.1–3.2 s — difference within noise on these two files.
- No skipped tests; engine verified by runner banner only.

## Schema partitions (static Pest-binding fingerprint)

| suite | files | partitions | unbound files |
|:--|--:|--:|--:|
| core | 257 | 20 | 26 |
| media | 32 | 4 | 4 |
| drive | 140 | 4 | 0 |
| documents | 100 | 13 | 0 |
| azgard | 13 | 5 | 3 |
| filament | 21 | 3 | 4 |
| load | 19 | 2 | 0 |

Full path lists per partition: `baseline.json → schema_partitions`. Unbound files (Arch/Unit/PublicApi) are listed there explicitly for P1.3.

Skeleton writers (touch app_path/base_path/config_path): 7 files — `packages/core/tests/Console/InstallCommandTest.php`, `packages/core/tests/Console/MakeFileHandlerCommandTest.php`, `packages/core/tests/Console/MakePermissionDriverCommandTest.php`, `packages/core/tests/Profiles/MakeProfileCommandTest.php`, `packages/documents/tests/Console/MakeGeneratedContractTest.php`, `packages/documents/tests/Export/MakeExporterCommandTest.php`, `packages/documents/tests/Types/MakeDocumentTypeCommandTest.php`

## CI inventory

17 workflows, 32 jobs (full triggers/if/steps in `baseline.json → ci.workflows`).

- **matrix_tests_yml**: PHP 8.4 x {L12.*/TB^10, L13.*/TB^11}; SQLite coverage xdebug --min=80 + composer test:types (100%)
- **pgsql_job**: tests.yml test-pgsql: postgres:16 service, one pest step per TestCase schema (base/Concurrency/Ulid/IdStrategy x3/TablePrefix/ActivityGate/Spatie)
- **package_jobs**: push has paths filter, pull_request has none, workflow_dispatch
- **mutation_jobs**: drive/documents/media/filament *-mutation jobs already `if: github.event_name == workflow_dispatch`; mutation.yml core is workflow_dispatch only
- **media_dual_engine_union_gate**: media.yml media-mutation: two cold --mutate runs (pgsql+sqlite), bin/assert-mutation-union.php, floor 95%
- **load**: load.yml pull_request(paths)+cron 17 3 * * * + dispatch
- **release_required_checks**: Laravel Pint; Larastan (PHPStan level 8); PHP 8.4 / Laravel 12.*; PHP 8.4 / Laravel 13.*; PHP 8.4 / Postgres (full schema portability lane); @vaulter/vue (Node 20); @vaulter/vue (Node 22); @vaulter/hocuspocus-server (Node 22)
- **release_required_checks_source**: .github/workflows/monorepo-split.yml verify-release-sha

## Unknowns for P1.3

- partition key is a static fingerprint of Pest bindings (TestCase+traits); actual schema fingerprint (providers/ids/tenancy/features) is resolved by P1.3 runner
- unbound files (26 core, 4 media, 3 azgard, 4 filament) have no Pest TestCase binding: Arch/Unit/PublicApi; P1.3 must classify each as database-free explicitly
- top-slow suite candidates not claimed: only 4 targeted files measured
