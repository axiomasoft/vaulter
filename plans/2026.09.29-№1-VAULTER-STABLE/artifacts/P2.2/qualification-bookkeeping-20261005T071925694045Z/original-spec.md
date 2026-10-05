### P2.2 — Drive canon and layout

**Intent:** Drive canon/layout; thin provider (R14).
**Why:** OPUS 13 P2.2; AD03/04.

**Scope Included:** Implement Required Reads 0 in full except ledger `note` transfers; complete Rector map. `StorageController::show`→`ShowDriveController::__invoke`; preserve paths/names, JSON, behavior and P1.8 error codes.
D20: drive/core `storage()`→`model(): Drive`, `panel()`→`profile(): Profile` via ProfileRegistry and callers. Delete unused `ValueObjects\WorkspaceRef`, `Enums\BulkAction`, orphan test; src use: stop/escalate.
Extract unchanged bindings/HTTP into drive `Providers/{BindingsRegistrar,HttpRegistrar}`, each tested. Update core/documents/filament tests and three load callers mechanically; core Database rollback fixture `version_no` only. Update UPGRADE table (renames/deletions, AD03/AD04), CHANGELOG [Unreleased], active EN/RU docs/API golden. `docs/plan` is history.

**Scope Excluded:** New Actions: UpdateNode/EmptyTrash/RestoreFileRevision (P3.3), InitiateUpload/AbortUpload (P3.7), ArchiveDrive/DeleteDrive (P4.6); Node/DownloadController split (P6.1); UploadModels→DriveConfig (P2.5); RecordsDriveOutbox/RecordsDriveActivity removal (P5.1); sweep-uploads/purge-trash signatures (P7.2); other handle methods/contracts (P3.2/P3.3, D20); other load changes.

**Inputs:** `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2-design/P2.2-opus.md`;
`audits/2026-09-29-stable-audit/opus/03-glossary-and-renames.md` (§4);
`audits/2026-09-29-stable-audit/opus/04-packages-and-layout.md` (§4);
`packages/drive/src/DriveHandle.php`;
`packages/drive/tests/Arch/ArchTest.php`.

**Files:** Direct brace/glob inventory + deletions:
`{CHANGELOG,UPGRADE}.md`,
`docs/{guide/{ar*,au*,dr*,er*,f*,int*,m*,q*,r*,storage-a*,tra*,u*}.md,reference/{cont*,e*,f*,r*}.md,ru/{concepts/a*.md,guide/{ar*,dr*,f*,int*,m*,q*,r*,st*,tra*,u*}.md,reference/{cont*,e*,f*,r*}.md}}`,
`packages/core/src/Contracts/Drive/DriveHandle.php`,
`packages/core/tests/{Database/V*.php,Access/{AccessA*,AccessT*}.php,Activity/R*.php,Arch/{A*.php,__snapshots__/**},Drive/**,E2E/E2eS*.php,IdStrategy/{Bigint/H*.php,Ulid/H*.php},Observability/P83/{P83A*,P83S*}.php,ObservabilityDocuments/P83DocumentsM*.php,Sharing/ShareS*.php,Support/U*.php,Ulid/**,Unit/W*.php,Upgrade/Fi*.php,Webhooks/{OutboxR*,OutboxS*}.php}`,
`packages/documents/tests/{Collaboration/{CollaborationW*,N*}.php,Document/{C*,DocumentC*,DocumentE*,DocumentN*,DocumentTestH*,DocumentTr*,Dom*,L*,NodeE*,NodeH*,P*,R*,S*,T*,V*}.php,Feature/**}`,
`packages/drive/src/{{DriveH*,DriveM*,DriveP*,V*}.php,Actions/{Files/**,Nodes/**,Trash/**,Uploads/Comp*.php},Activity/**,Console/**,Data/{Files/**,Nodes/**,Uploads/I*.php},Enums/B*.php,Events/{Drives/**,Files/**,Nodes/**,Uploads/**},Exceptions/{Nodes/**,Quota/**,Uploads/**},Http/{Controllers/{N*.php,Drives/**,Uploads/{S3MultipartP*,TusP*}.php},Requests/{Files/**,Folders/**,Nodes/**},Resources/{D*,FileR*}.php},Providers/**,Repositories/Uploads/UploadSessionS*.php,Uploads/**,ValueObjects/**,Webhooks/**}`,
`packages/drive/tests/{Pe*.php,Arch/__snapshots__/**,Concurrency/{D*,L*,M*,Q*,R*,T*}.php,Drive/{CopyM*,Cr*,F*,M*,R*,S*,T*,U*}.php,Feature/{D*,M*,O*,Q*,R*,T*,U*}.php,Observability/P84*.php,Panels/**,Providers/**,Uploads/{R*,S*,TusF*,UploadI*,UploadSessionA*,UploadSessionC*}.php}`,
`packages/drive/routes/**`,
`packages/filament/tests/Fixtures/D*.php`,
`load/app/{LoadServiceProvider.php,Console/{PrepareMemoryFixturesCommand,SeedLoadDatasetCommand}.php}`,
`plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.2/{rename-map.php,rector-dry-run.txt}`.

**Required Reads:** 0) `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2-design/P2.2-opus.md` (normative in full);
1) `packages/drive/src/DriveHandle.php`; 2) `packages/drive/src/VaulterDriveServiceProvider.php`;
3) `packages/drive/routes/drive.php`; 4) `packages/drive/tests/Arch/ArchTest.php`;
5) `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P2.1/rename-map.php` (map format);
6) `plans/2026.09.29-№1-VAULTER-STABLE/decisions/D20-p22-handle-methods.md`.

**Authority:** routine

**Implementation Rules:** Rector dry-run→log→apply (R2), no aliases (R3). ADR-0017 Law 1/2 arch tests green, no new exceptions.

**Code Guidance:** Zero old mapped drive FQNs, WorkspaceRef/BulkAction, flat Actions/*Action, Data/*Data, Exceptions/*Exception across `packages/*/{src,tests,routes}`, `load/app`, `docs/{guide,reference,ru}`. No obsolete files/orphan tests. Docs: no old renamed short names (unchanged moved names allowed) or `ShowDriveController::show`; domain event paths; `profile(): Profile` (`getId(): string`); REST DriveResource. Filament StorageResource is live.

**Validation:**
- Code Guidance → пусто.
- Гейт drive, documents, filament и гейт core → exit 0; сетка P1 на PG → 0 failed, 0 skipped.
- `(cd packages/drive && env $SQ "$R/scripts/php84" vendor/bin/pest --ci tests/Arch tests/Providers)` → 0 failed.

**Deliverables:** Layout, registrars, map/log, docs/golden.
