# ADR-0014 Step 3 — Port packages/filament off legacy Document* to node-path

**Branch:** `feat/excise-legacy-filament-adr-0014-step-3` (off step-2)
**Shape:** ONE excision commit. Node-path Filament resources ALREADY exist
(StorageResource, NodeResource, NodeGrantResource, NodeShareResource, ActivityResource) —
so this is removing the three legacy resources that reference the deleted core models.
**Risk:** LOW-MED (Filament resource removal + plugin/config rewiring; no data, no concurrency).
**Seam:** phpstan (filament package) — dangling refs to deleted core models/resources surface there.
**Gate:** pint + phpstan + pest (filament package has no type-cov script).

## DELETE
- src/Resources/DocumentResource.php + DocumentResource/Pages/{EditDocument,ListDocuments}.php
- src/Resources/FolderResource.php + FolderResource/Pages/{CreateFolder,EditFolder,ListFolders}.php
- src/Resources/ShareResource.php + ShareResource/Pages/ListShares.php
- tests/Fixtures/CustomDocument.php (extends legacy Document; used only by FilamentTest)
- tests/Feature/FilamentTest.php (all-legacy except 2 generic tests → folded into VaulterPluginTest)

## EDIT
- src/VaulterPlugin.php: drop imports (DocumentResource, FolderResource, ShareResource) and the
  candidate entries 'document'/'folder'/'legacy_share' from enabledResources().
- config/vaulter-filament.php: drop resources.{document,folder,legacy_share} toggles + fix the comment.
- src/Resources/StorageResource.php: drop the stale "mirrors the legacy DocumentResource" comment.
- tests/Feature/VaulterPluginTest.php: remove DocumentResource import + the "keeps the legacy document
  resources registered" test; fold in the "loads the package config" assertion from FilamentTest.

## KEEP (node-path, already present)
StorageResource, NodeResource, NodeGrantResource, NodeShareResource, ActivityResource + their Pages;
DriveBuilder/TestHost/TestUser/TestPanelProvider fixtures; all Node* feature tests.

## Acceptance
- grep legacy Document*/DocumentResource/FolderResource/ShareResource in packages/filament → empty.
- Filament gate green: pint + phpstan + pest.
