# P4.1 — negative controls

Bounded fault injection (D25: not a mutation campaign). Each control replaced one string in one file, ran the owning guard test (expected red), restored the exact original bytes (sha256 verified) and re-ran the same command (green). Runner: scratchpad `nc.py`, 2026-10-07, route anthropic/claude-opus-5-5/high.

## NC1 writer boundary (Arch)

- File: `packages/documents/src/Actions/Documents/UpdateDocumentContentAction.php`
- Break: `$document->setRelation('node', $node);` → `$document->setRelation('node', $node);
            $document->yjs_state = $data->yjsState;`
- Command: `cd ./packages/documents && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch/DocumentStateWriterBoundaryTest.php`
- Red: exit 1 — ["Tests:    1 failed, 2 passed (4 assertions)"]
  - FAILED  Tests\Arch\DocumentStateWriterBoundaryTest > it writes Yjs state…
  - Failed asserting that two arrays are identical.
- Restored sha256: `3d60f411b7d64ef7a75f50149c03106d4aba867ec67fb7b57bd11b1c25693e62`
- Green after restore: exit 0 — ["Tests:    3 passed (4 assertions)"]

## NC2 checkpoint immutability

- File: `packages/documents/src/Models/Revisions/DocumentRevision.php`
- Break: `if (array_diff($changed, [self::RETENTION_COLUMN]) !== []) {` → `if (false && array_diff($changed, [self::RETENTION_COLUMN]) !== []) {`
- Command: `cd ./packages/documents && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Document/DocumentRevisionImmutabilityTest.php`
- Red: exit 1 — ["Tests:    8 failed, 3 passed (12 assertions)"]
  - FAILED  Tests\Document\DocumentRevisionImmutabilityTest > it refuses to c…
  - FAILED  Tests\Document\DocumentRevisionImmutabilityTest > it refuses to c…
  - FAILED  Tests\Document\DocumentRevisionImmutabilityTest > it refuses to c…
- Restored sha256: `2d2310b91418caf5632e851c01d6d48189a80058bf845003c113e46884137331`
- Green after restore: exit 0 — ["Tests:    11 passed (28 assertions)"]

## NC3 stale content expectation

- File: `packages/documents/src/Content/DocumentStateWriter.php`
- Break: `if ($change->expectedStateVersion !== $locked->state_version) {` → `if (false && $change->expectedStateVersion !== $locked->state_version) {`
- Command: `cd ./packages/documents && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Document/DocumentStateVersionTest.php`
- Red: exit 1 — ["Tests:    2 failed, 7 passed (34 assertions)"]
  - FAILED  Tests\Document\DocumentStateVersionTest > i…  AssertionFailedError
  - FAILED  Tests\Document\DocumentStateVersionTest > it takes named checkpoi…
- Restored sha256: `967114c0fbb3d6d0e249aae6b20acdb61b61e8fe7baef794d85e469cf8d97aec`
- Green after restore: exit 0 — ["Tests:    9 passed (46 assertions)"]

## NC4 file head FK RESTRICT (MySQL)

- File: `packages/core/src/Schema/SchemaBuilder.php`
- Break: `REFERENCES %s (%s, %s) ON DELETE RESTRICT'` → `REFERENCES %s (%s, %s) ON DELETE CASCADE'`
- Command: `cd . && env APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret scripts/php84 vendor/bin/pest --ci packages/core/tests/Schema/FileHeadIntegrityTest.php`
- Red: exit 1 — {"result": "failed", "tests": 3, "passed": 2, "failed": 1, "errors": null, "skipped": null}
  - __pest_evaluable_it_enforces_the_file_head_FK_where_the_driver_supports_it — Failed asserting that two strings are identical. --- Expected +++ Actual @@ @@ -'restrict' +'cascade'
- Restored sha256: `334fb53931874c1c8b980f67fcd72365eaf425f089646a46dfae17e04dfe0548`
- Green after restore: exit 0 — {"result": "passed", "tests": 3, "passed": 3, "failed": null, "errors": null, "skipped": null}

## NC5 document head FK RESTRICT (PG)

- File: `packages/core/database/migrations/2026_10_01_000005_documents_aggregate.php`
- Break: `REFERENCES %s (%s, %s) ON DELETE RESTRICT'` → `REFERENCES %s (%s, %s) ON DELETE CASCADE'`
- Command: `cd ./packages/documents && env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret ../../scripts/php84 vendor/bin/pest --ci tests/Document/DocumentHeadIntegrityTest.php`
- Red: exit 1 — ["Tests:    1 failed, 1 passed (18 assertions)"]
  - FAILED  Tests\Document\DocumentHeadIntegrityTest > it enforces the head f…
  - Failed asserting that two strings are identical.
- Restored sha256: `a4f5e3f286cd7f992148ca6c000469440383a88b875e54ca4c05e8fefe2f9764`
- Green after restore: exit 0 — ["Tests:    2 passed (22 assertions)"]

## NC6 revision ids preserved on upgrade (PG)

- File: `packages/core/database/migrations/2026_10_01_000005_documents_aggregate.php`
- Break: `SELECT v.id, v.tenant_id, d.id, v.version_no` → `SELECT v.id || '', v.tenant_id, d.id, v.version_no`
- Command: `cd . && env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret scripts/php84 vendor/bin/pest --ci packages/core/tests/Upgrade/DocumentsAggregateUpgradeTest.php`
- Red: exit 2 — {"result": "failed", "tests": 3, "passed": 0, "failed": null, "errors": 3, "skipped": null}
  - __pest_evaluable_it_upgrades_0_2_document_versions_into_the_document_aggregate_with_ids_pr — syntax error, unexpected single-quoted string ", v.tenant_id, d.id, v.version...", expecting ")"
  - __pest_evaluable_it_upgrades_0_2_document_versions_into_the_document_aggregate_with_ids_pr — syntax error, unexpected single-quoted string ", v.tenant_id, d.id, v.version...", expecting ")"
  - __pest_evaluable_it_refuses_a_document_node_without_versions_instead_of_inventing_content — syntax error, unexpected single-quoted string ", v.tenant_id, d.id, v.version...", expecting ")"
- Restored sha256: `a4f5e3f286cd7f992148ca6c000469440383a88b875e54ca4c05e8fefe2f9764`
- Green after restore: exit 0 — {"result": "passed", "tests": 3, "passed": 3, "failed": null, "errors": null, "skipped": null}

## Note on NC6

The id-preservation break (`v.id || ''`) turns the copied key into text, so PostgreSQL rejects the INSERT on both fixtures before the id assertions run: the control proves the test executes the revision copy statement, not that it reports a renumbered id specifically. Id equality itself is asserted on every run (revision keys == legacy keys, rollback ids, re-applied ids).
