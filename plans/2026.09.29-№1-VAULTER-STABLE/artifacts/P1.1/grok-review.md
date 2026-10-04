GREEN (finding closed).

`UploadSession`, `UploadPart`, `NodeComment`, and `NodeDocumentVersion` now use `ResolvesVaulterConnection`. `getConnectionName()` returns an explicit model connection when one is set, and otherwise `Config::connection()`, the same name `VaulterDatabase::transaction()` opens. `Model::query()`, `forceFill()->save()`, and the repository calls cited in the original review (`UploadModels::session()::query()`, the version insert, and the comment insert) all resolve through that method, so those writes sit on the transaction connection. On MySQL/MariaDB, `FOR UPDATE NOWAIT` in `acquireForPatch()` is held until that transaction ends.

The PostgreSQL suite shows the same connection instance for all four models and rolls their inserts back. The negative control, with only those four trait uses removed, fails all four identity checks and leaks all four inserts (8 failed, 5 passed). Restoring the traits returns 13 passed and 0 skipped.
