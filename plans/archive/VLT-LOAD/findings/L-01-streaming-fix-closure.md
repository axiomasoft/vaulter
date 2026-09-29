# L-01 — streaming fix closure for P4.3

**Date:** 2026-09-22  
**Scope:** `packages/drive` multipart assembly and VLT-LOAD post-fix evidence  
**P4.3 disposition:** GREEN; D11 mutation gate satisfied

## Result

`S3MultipartHandler::complete()` no longer concatenates all multipart payloads into one PHP
string. It copies each part stream into `php://temp/maxmemory:2097152`, validates the copied byte
count for every part and the aggregate declared size, rewinds once, and hands the stream to
`StagedUpload::writeStream()`. The storage driver receives a stream and verifies the staged size.

The authoritative tier-S live run `8f383737-406e-42b9-addd-3ecf4ef6fc2b` validates both
`s3_complete_postfix/500m` and `s3_complete_postfix/5g` three times. All six attempts have valid
evidence and product verdict GREEN. Recorded PHP peak is 2 MiB for each attempt, below the accepted
160 MiB ceiling. The full 15-cell matrix is 45/45 valid and 15/15 aggregate GREEN.

## Evidence

- `packages/drive/src/Uploads/S3MultipartHandler.php` — bounded streamed assembly.
- `packages/drive/src/Support/Uploads/StagedUpload.php` — stream-to-driver staging primitive.
- `packages/drive/tests/Uploads/S3MultipartTest.php` — 32 × 1 MiB complete with peak delta below
  16 MiB and exact staged size.
- `plans/VLT-LOAD/artifacts/P4/run-manifest.json` — immutable tier-S live run identity.
- `plans/VLT-LOAD/artifacts/P4/cell-verdicts.json` — 500 MiB and 5 GiB complete verdicts.
- `plans/VLT-LOAD/artifacts/P4/php-peaks.json` — per-attempt allocator peaks.

Validation completed:

```text
packages/drive: S3Multipart + integrity + critical-section + sweep tests — 31 passed, 88 assertions
packages/drive: PHPStan on StagedUpload and S3MultipartHandler — passed
packages/drive: Pint on changed files — passed
packages/drive: Pest mutation gate — 1661 tested, score 100.00%, minimum 95, passed
VLT-LOAD: MemoryFixtureCommandsTest — 3 passed, 21 assertions
VLT-LOAD: p4_memory + p4_memory_matrix unittest — 14 passed
TIER=S p4-memory.sh verify --run-id 8f383737-406e-42b9-addd-3ecf4ef6fc2b — two byte-stable passes
stable digest — b204a1d1749330a3156fe1065eab2d3c4fc3c1aaff2738eef77658b4a5f70a3e
```

## D11 mutation closure

The canonical `packages/drive` mutation command ran with system PHP 8.4 and PCOV after correcting
ownership of Testbench's generated storage directories:

```text
PCOV_ENABLED=1 /usr/bin/php8.4 vendor/bin/pest --mutate --path=src \
  --covered-only --parallel --processes=4 --min=95
```

The green baseline contained 175 passed tests and 548 assertions (5 skipped, 2 pre-existing risky).
Pest generated and tested 1661 mutations across 50 source files in 25.77 seconds and reported
100.00%. The command exited zero, satisfying the D11 `vaulter-drive >=95` gate. The machine-readable
carrier is `plans/VLT-LOAD/artifacts/P4-l01-mutation.json`. It is kept beside, rather than inside,
the immutable `artifacts/P4/` matrix bundle.
