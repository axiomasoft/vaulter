# P4.7 — negative controls (executed 2026-10-09)

Each control: a temporary break in product code → the named test goes red → the file is restored byte-for-byte
(`cmp` against a backup copy printed `restored`).

| # | Break | Test | Red output |
|---|---|---|---|
| NC1 | `DocumentStateWriter`: `$epochChanged = false && …Restore` (a restore no longer moves the epoch) | `DocumentValidatorMatrixTest` |   ⨯ it moves exactly the documented validators: "restore"                0.21s     ⨯ it carries the content validators the writer produced in every docu… 0.24s     Tests:    2 failed, 26 passed (35 assertions)  |
| NC2 | `UpdateDocumentContentAction`: event `stateVersion: …state_version - 1` | `DocumentValidatorMatrixTest` "carries the content validators" |   ⨯ it carries the content validators the writer produced in every docu… 0.51s     Tests:    1 failed (2 assertions)  |
| NC3 | `UpdateDocumentContentAction`: `editableType($document)` → `writable($document->type)` (no writability check on the locked row, F16) | `DocumentSchemaWritableTest` |   ⨯ it refuses a JSON update without an expectation once a newer schema… 0.52s     Tests:    1 failed, 1 passed (5 assertions)  (the collaboration-store test stays green: that action was not broken) |
| NC4 | `DocumentStateWriter`: `if (false && expectedStateVersion !== state_version)` (PG) | `DocumentStateRaceTest` |   ⨯ it lets one of two updates with expectedStateVersion N through and…  6.06s     ⨯ it shares the validator between an update and a restore: one wins,…  5.56s     Failed asserting that two arrays are identical.   Failed asserting that two arrays are identical.   Tests:    2 failed (8 assertions)  |
| NC5 | `ContentHasher`: `ksort($value, SORT_STRING)` disabled (hash depends on object key order again) | `ContentHasherTest` | ⨯ it hashes the same content identically whatever the order of object… 0.41s     Tests:    1 failed, 1 passed (2 assertions) |
| NC6 | `UpdateDocumentContentAction`: `editableType($document)` → `writable($document->type)` (PG, V4) | `DegradedContentRaceTest` (both cases) | ⨯ it refuses an update whose precheck went stale to a restore that co… 6.22s     ⨯ it refuses an update whose precheck went stale to a restore that co… 5.82s     Failed asserting that two strings are identical. |

`DegradedContentRaceTest` (V4) forces the order with marker files — the update's precheck passes, the restore commits before the
action's first statement — so the refusal is required. `DocumentSchemaWritableTest` is its fast SQLite companion for the
sequential state; NC3 and NC6 show both go red without the check on the locked row.
