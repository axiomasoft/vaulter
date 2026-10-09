# P4.7 — writer map of the validators (PLAN2.D35)

Replaces the writer map of P3-security-contracts §Sequence for documents; that section is not
rewritten. The table is also published in `docs/reference/concurrency.md`. Executable form:
`packages/documents/tests/Document/DocumentValidatorMatrixTest.php`.

| Writer | Entry | sequence | state_version | collab_epoch | Test row |
|---|---|---|---|---|---|
| rename | `nodes()->rename` | +1 | 0 | 0 | `rename` |
| move | `nodes()->move` | +1 | 0 | 0 | `move` |
| trash | `nodes()->trash` | +1 | 0 | 0 | `trash` |
| trash restore | `trash()->restore` | +1 | 0 | 0 | `trash restore` |
| setProperties | `nodes()->setProperties` | +1 | 0 | 0 | `setProperties` |
| star / unstar | `nodes()->star/unstar` (user actor) | 0 | 0 | 0 | `star`, `unstar` |
| restrictInheritance | `access()->restrictInheritance` | 0 | 0 | 0 | `restrictInheritance` |
| copy | `nodes()->copy` | source 0; copy 0 | source 0; copy 1 | source 0; copy 0 | `copy`, copy-own-validators test |
| document create | `CreateDocumentAction` | 0 | 1 | 0 | new-document test |
| update | `UpdateDocumentContentAction` | 0 | +1 | 0 | `update` |
| update, identical | same | 0 | 0 | 0 | `update (identical content)` |
| update, Yjs state present | same | 0 | +1 | +1 | `update (document with Yjs state)` |
| collab seed | `PersistCollaborationStateAction` (seed) | 0 | +1 | 0 | `collab seed` |
| collab store | same | 0 | +1 | 0 | `collab store` |
| collab store, identical | same | 0 | 0 | 0 | `collab store (identical state)` |
| restore | `RestoreDocumentRevisionAction` | 0 | +1 | +1 | `restore` |
| snapshot | `CreateNamedRevisionAction` | 0 | 0 | 0 | `snapshot` |
| setRetention | `UpdateRevisionRetentionAction` | 0 | 0 | 0 | `setRetention` |
| export | `ExportDocumentAction` | 0 | 0 | 0 | `export` |
| purge | `trash()->purge` | node gone | gone | gone | purge test (siblings untouched) |
| file upload revision / restore revision | `files()->addRevision/restoreRevision` | +1 | n/a | n/a | file-node dataset |
| file delete revision / set retention | `files()->deleteRevision/setRevisionRetention` | 0 | n/a | n/a | file-node dataset |

Writer paths of `Documents` never call `NodeSequence::bump`: the node is touched
(`updated_at/updated_by`) without its sequence (`DocumentStateWriterTest`).

## Legacy tests removed — each scenario found in the new tests

| Removed | Scenario | Now covered by |
|---|---|---|
| `LegacyNodeSequenceTest` #1 content writes leave sequence alone, count state_version | update, collab store, snapshot, restore, setRetention | matrix rows with sequence Δ 0 and their state_version Δ |
| `LegacyNodeSequenceTest` #2 rename and update independent | rename then update with expectedStateVersion | `keeps the node validator independent from content edits in either order` |
| `LegacyNodeSequenceTest` #3 node stamp without sequence | `updated_by`, `updated_at` | `DocumentStateWriterTest` "stamps the node change but never its sequence" |
| `LegacyNodeSequenceTest` #4 registry ownership in the manager | types/exporters frozen | kept as `DocumentsManagerRegistryTest` (file renamed, sequence parts dropped) |
| `LegacyNodeSequenceRaceTest` #1 rename beside a JSON update (PG) | both commit; sequence +1 only from rename | `NodeSequenceDocumentIndependenceTest` "JSON update" |
| `LegacyNodeSequenceRaceTest` #2 rename beside a Yjs store (PG) | both commit | `NodeSequenceDocumentIndependenceTest` "collaboration store" |

New PG scenarios: `DocumentStateRaceTest`, `DocumentRenameStaleTest` (V36), `DegradedContentRaceTest`
(F16, V4), plus the existing `CollaborationFenceRaceTest` (P4.5).
