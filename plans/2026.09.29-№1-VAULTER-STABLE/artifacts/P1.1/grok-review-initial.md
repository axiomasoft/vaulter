The review is read-only: I will check the P1.1 contract against the rest of the diff, the new database class, and the verification output.The contract and the new database class are in view. Next I will check connection selection, lock SQL, and whether model writes stay on that same connection.findings:1

`VaulterDatabase` matches the contract for the core models that use `ResolvesVaulterConnection`: the connection, nested savepoints, attempt count of 3, tree and CAS locks, after-commit, the facade inventory, the arch rules, and the V17 receipts. Upload sessions, document versions, and comments do not follow that connection, so moving their transactions onto `VaulterDatabase` drops those writes out of the transaction whenever `vaulter.connection` is set.

**Upload, comment, and version writes leave the Vaulter transaction.** `UploadSession`, `UploadPart`’s sibling session model, `NodeComment`, and `NodeDocumentVersion` do not use `ResolvesVaulterConnection`. Their Eloquent queries stay on the host default connection. `VaulterDatabase::transaction()` opens the transaction on `Config::connection()` (`packages/core/src/Database/VaulterDatabase.php` 17–36).

That split is live in:

- `packages/drive/src/Repositories/Uploads/UploadSessionStoreRepository.php` 82–131 and 209–220. The PostgreSQL advisory lock uses `$this->database->connection()`. `freshSession()`, `advanceReceived()`, and `markCommitted()` use `UploadModels::session()::query()` on the default connection. On MySQL/MariaDB the `FOR UPDATE NOWAIT` is on that default connection (line 104).
- `packages/drive/src/Uploads/TusUploadHandler.php` 122–174. The patch critical section is a `VaulterDatabase` transaction whose session-row lock and `advanceReceived()` are on the other connection.
- `packages/drive/src/Actions/Uploads/CommitUploadAction.php` 68–77. The node and blob work is inside the Vaulter transaction; `markCommitted()` autocommits on the default connection.
- `packages/documents/src/Actions/Documents/CreateDocumentNodeAction.php` 60–76 and `packages/documents/src/Repositories/Versions/NodeDocumentVersionStoreRepository.php` 29–38. The node insert is on the Vaulter connection; the version insert is not.
- `packages/documents/src/Actions/Comments/AddNodeCommentAction.php` 28–35 and `packages/documents/src/Repositories/Comments/NodeCommentStoreRepository.php` 21–28. Same split for comment rows.

V17’s own setup is a second connection name copied from the same PostgreSQL database, so both names see `v_upload_sessions`, `v_node_document_versions`, and `v_node_comments`. Those Eloquent statements then autocommit. A later failure rolls back nodes and blobs and leaves the session, version, or comment row behind. On MySQL/MariaDB there is no transaction-scoped advisory lock, and `FOR UPDATE NOWAIT` is released when that autocommit `SELECT` ends, so two PATCH/sweep callers can both pass `acquireForPatch()` and append at the same offset. The guide’s “table not found” warning (`docs/guide/database-compatibility.md` 101–110) describes a different database, which this copied-connection case is not.

The green PG upload and concurrency run does not cover this. Those tests leave `vaulter.connection` unset, so both paths still share the default connection. `DedicatedConnectionTest` only checks `CreateFolderAction` and `BlobLock`.
