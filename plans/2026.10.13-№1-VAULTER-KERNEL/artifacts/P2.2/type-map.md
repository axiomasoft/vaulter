# Type-map (P2.2)

Generated from `tests/Repo/Arch/Fixtures/target-map.json` (base `35718fd2`); the JSON is the source of truth.

## Types: package → owner × action × phase

| package | owner | action | phase | types |
|:--|:--|:--|:--|:--|
| azgard | azgard | keep | stay | 10 |
| core | core | keep | P3 | 27 |
| core | core | keep | stay | 99 |
| core | core | split | P3 | 9 |
| core | core | split | P4 | 3 |
| core | documents | replace | P4 | 1 |
| core | drive | move | P4 | 323 |
| core | drive | replace | P4 | 3 |
| core | media | move | P4 | 21 |
| core | webhooks | move | P4 | 55 |
| documents | collaboration | move | P5 | 20 |
| documents | documents | keep | stay | 134 |
| drive | drive | keep | stay | 181 |
| filament | filament | keep | stay | 19 |
| media | media | keep | stay | 14 |
| media | media | split | P4 | 1 |

## Surface by owner

| owner | surface | types |
|:--|:--|:--|
| azgard | Api | 4 |
| azgard | Internal | 4 |
| azgard | Spi:Implement | 2 |
| collaboration | Api | 3 |
| collaboration | Internal | 17 |
| core | Api | 55 |
| core | Internal | 72 |
| core | Spi:Implement | 7 |
| core | undecided | 4 |
| documents | Api | 73 |
| documents | Internal | 53 |
| documents | Spi:Implement | 9 |
| drive | Api | 184 |
| drive | Internal | 304 |
| drive | Spi:Implement | 18 |
| drive | undecided | 1 |
| filament | Api | 3 |
| filament | Internal | 16 |
| media | Api | 16 |
| media | Internal | 14 |
| media | Spi:Implement | 6 |
| webhooks | Api | 3 |
| webhooks | Internal | 50 |
| webhooks | Spi:Implement | 2 |

## Types: split (13)

- `Vaulter\Doctor\Checks\SchemaTablesCheck` → core `Vaulter\Doctor\Checks\SchemaTablesCheck` — core keeps the check mechanism; each owner contributes its tables (drive/documents/media/webhooks/outbox)
- `Vaulter\Enums\Events\EventType` → core `Vaulter\Enums\Events\EventType` — mechanics (type naming, catalog) stay in core; the concrete event type values are contributed by drive/documents/webhooks (EventCatalog)
- `Vaulter\Http\Middleware\VaulterHttpOperationMap` → core `Vaulter\Http\Middleware\VaulterHttpOperationMap` — core keeps the labelling mechanism; route-name and path labels (drive/documents/hub) become module contributions
- `Vaulter\Http\Middleware\VaulterThrottledRouteMap` → core `Vaulter\Http\Middleware\VaulterThrottledRouteMap` — core keeps the throttling mechanism; throttled route names become module contributions
- `Vaulter\Http\Support\Preconditions` → core `Vaulter\Http\Support\Preconditions` — core keeps header parsing (If-Match/If-None-Match/428); matchNode -> drive; document match mapping -> documents
- `Vaulter\Http\Support\RepresentationTag` → core `Vaulter\Http\Support\RepresentationTag` — core keeps the generic tag/canonical-json helper; node() -> drive; document() -> documents
- `Vaulter\Media\Cas\BlobStore` → media `Vaulter\Media\Cas\BlobStore` — split: attach/detach -> drive link service (see the member rules); commitStaged and the rest stay in media
- `Vaulter\Providers\BindingsRegistrar` → core `Vaulter\Providers\BindingsRegistrar` — bindings of every slice: core keeps the generic registration mechanism; each owner registers its own (module manifest, P3)
- `Vaulter\Providers\ConsoleRegistrar` → core `Vaulter\Providers\ConsoleRegistrar` — commands of every slice: core keeps the generic registration mechanism; each owner registers its own (module manifest, P3)
- `Vaulter\Providers\HttpRegistrar` → core `Vaulter\Providers\HttpRegistrar` — routes of every slice: core keeps the generic registration mechanism; each owner registers its own (module manifest, P3)
- `Vaulter\Providers\SchedulingRegistrar` → core `Vaulter\Providers\SchedulingRegistrar` — schedules of every slice: core keeps the generic registration mechanism; each owner registers its own (module manifest, P3)
- `Vaulter\Schema\SchemaBuilder` → core `Vaulter\Schema\SchemaBuilder` — domain define*/afterCreate/createAll/dropAll/addHeadForeignKey go to the owners of the tables; core keeps only generic id/host-key/counter/connection helpers (see the member rules)
- `Vaulter\Webhooks\OutboxRelay` → core `Vaulter\Webhooks\OutboxRelay` — split: generic claim/lease/retention relay stays in core (D29); WebhookDelivery fan-out and DeliverWebhook dispatch become the webhooks consumer (P4/P6); open: P3 renames out of the Vaulter\Webhooks namespace

## Types: replace (4)

- `Vaulter\Contracts\Profiles\CommentsFeature` → documents — replaced by a typed ProfileOption of documents (drive owns ProfileOption); no one-to-one successor type
- `Vaulter\Facades\Vaulter` → drive `Vaulter\Drive\Facades\Vaulter` — replaced by Vaulter\Drive\Facades\Vaulter; the Laravel alias Vaulter is declared by drive
- `Vaulter\Profiles\DisabledCommentsFeature` → drive — disappears with CommentsFeature: the typed profile option has its own default (architecture#extension-system)
- `Vaulter\VaulterManager` → drive `Vaulter\Drive\Vaulter` — replaced by Vaulter\Drive\Vaulter (#[Api], final); withoutTenancy moves to core Tenancy::without

## Types: delete (0)

- —

## Types: surface undecided (5)

- `Vaulter\Attributes\Icon` → core `Vaulter\Attributes\Icon` — open: consumed today by HasLabel/HasIcon of enums owned by drive/sharing/etc.; P3 decides core vs drive presentation
- `Vaulter\Attributes\Label` → core `Vaulter\Attributes\Label` — open: consumed today by HasLabel/HasIcon of enums owned by drive/sharing/etc.; P3 decides core vs drive presentation
- `Vaulter\Drive\DriveProvisioner` → drive `Vaulter\Drive\DriveProvisioner` — open: Api (host provisions drives) or Internal — P3 decides with the typed surface (D27)
- `Vaulter\Support\Concerns\HasIcon` → core `Vaulter\Support\Concerns\HasIcon` — open: follows Vaulter\Attributes\Icon (P3 decides)
- `Vaulter\Support\Concerns\HasLabel` → core `Vaulter\Support\Concerns\HasLabel` — open: follows Vaulter\Attributes\Label (P3 decides)

## Types: note "open: …" (input for P2.5 open-questions) (23)

- `Vaulter\Attributes\Icon` → core `Vaulter\Attributes\Icon` — open: consumed today by HasLabel/HasIcon of enums owned by drive/sharing/etc.; P3 decides core vs drive presentation
- `Vaulter\Attributes\Label` → core `Vaulter\Attributes\Label` — open: consumed today by HasLabel/HasIcon of enums owned by drive/sharing/etc.; P3 decides core vs drive presentation
- `Vaulter\Configuration\ScheduleConfig` → core `Vaulter\Configuration\ScheduleConfig` — open: the keys of each module move to their owner with the section split (P4)
- `Vaulter\Configuration\WebhooksConfig` → webhooks `Vaulter\Webhooks\Configuration\WebhooksConfig` — open: outbox keys (retention, consumer hold) move to core with D29
- `Vaulter\Content\ScanInput` → media `Vaulter\Media\Content\ScanInput` — open: carries drive vocabulary (revisionId, driveId, tenantId); the media SPI needs a neutral input shape (P3/P4)
- `Vaulter\Doctor\Checks\SchemaKeyTypesCheck` → core `Vaulter\Doctor\Checks\SchemaKeyTypesCheck` — open: reads the table list of every module; contributions replace the central list in P3
- `Vaulter\Doctor\Checks\WebhooksOutboxLagCheck` → core `Vaulter\Doctor\Checks\WebhooksOutboxLagCheck` — open: renamed away from Webhooks with the outbox (D29, P3)
- `Vaulter\Doctor\Checks\WebhooksRelayLeasesCheck` → core `Vaulter\Doctor\Checks\WebhooksRelayLeasesCheck` — open: renamed away from Webhooks with the relay (D29, P3)
- `Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction` → documents `Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction` — open: written for the hub store path; stays with the aggregate unless collaboration takes the write path (P5)
- `Vaulter\Documents\Enums\Collaboration\CollaborationDriverType` → collaboration `Vaulter\Collaboration\Enums\CollaborationDriverType` — open: DocumentsConfig reads it today; the config section split decides how documents keeps working without collaboration (P5)
- `Vaulter\Drive\DriveHandleFactory` → drive `Vaulter\Drive\DriveHandleFactory` — open: folds into Vaulter\Drive\Vaulter or stays as an Internal factory once the entry point moves to drive (D10)
- `Vaulter\Drive\DriveProvisioner` → drive `Vaulter\Drive\DriveProvisioner` — open: Api (host provisions drives) or Internal — P3 decides with the typed surface (D27)
- `Vaulter\Http\Support\DownloadHeaders` → core `Vaulter\Http\Support\DownloadHeaders` — open: used for downloads only today; moves to drive if the kernel does not consume it (P4)
- `Vaulter\Models\Webhooks\Outbox` → core `Vaulter\Models\Webhooks\Outbox` — D29 reshapes the outbox (outbox_seq, consumers, deliveries); the Webhooks namespace segment of the FQCN is historical (open: P3 renames)
- `Vaulter\Repositories\Webhooks\OutboxStoreRepository` → core `Vaulter\Repositories\Webhooks\OutboxStoreRepository` — open: P3 renames out of the Webhooks namespace together with the Outbox model
- `Vaulter\Support\Concerns\HasIcon` → core `Vaulter\Support\Concerns\HasIcon` — open: follows Vaulter\Attributes\Icon (P3 decides)
- `Vaulter\Support\Concerns\HasLabel` → core `Vaulter\Support\Concerns\HasLabel` — open: follows Vaulter\Attributes\Label (P3 decides)
- `Vaulter\Support\EnumCaseAttributeResolver` → core `Vaulter\Support\EnumCaseAttributeResolver` — open: follows Label/Icon (P3 decides)
- `Vaulter\Tenancy\ShareTenantContext` → drive `Vaulter\Drive\Tenancy\ShareTenantContext` — open: typed on the Drive model; stays drive unless a neutral scope ref replaces it (P4)
- `Vaulter\ValueObjects\Access\OwnerRef` → core `Vaulter\ValueObjects\Access\OwnerRef` — open: only access (AccessScope, GatePermissionDriver) uses it today; core keeps it as a neutral ref unless P3 finds no kernel consumer
- `Vaulter\Webhooks\EventEnvelope` → core `Vaulter\Webhooks\EventEnvelope` — D29 generalises it; open: P3 renames out of the Vaulter\Webhooks namespace (webhooks package owns that namespace)
- `Vaulter\Webhooks\OutboxRecorder` → core `Vaulter\Webhooks\OutboxRecorder` — open: P3 renames out of the Vaulter\Webhooks namespace
- `Vaulter\Webhooks\OutboxRelay` → core `Vaulter\Webhooks\OutboxRelay` — split: generic claim/lease/retention relay stays in core (D29); WebhookDelivery fan-out and DeliverWebhook dispatch become the webhooks consumer (P4/P6); open: P3 renames out of the Vaulter\Webhooks namespace

## Files: package → owner × role × action × phase

| package | owner | role | action | phase | files |
|:--|:--|:--|:--|:--|:--|
| azgard | azgard | config | keep | stay | 1 |
| core | core | config | split | P4 | 1 |
| core | core | migration | keep | stay | 1 |
| core | core | routes | split | P4 | 1 |
| core | documents | migration | move | P4 | 3 |
| core | drive | migration | move | P4 | 18 |
| core | media | migration | move | P4 | 1 |
| core | webhooks | migration | move | P4 | 2 |
| documents | documents | config | keep | stay | 1 |
| documents | documents | routes | keep | stay | 1 |
| drive | drive | config | keep | stay | 1 |
| drive | drive | routes | keep | stay | 1 |
| filament | filament | config | keep | stay | 1 |
| media | media | config | keep | stay | 1 |

## Files: split / open (4)

- `packages/core/config/vaulter.php` → core (split) — sections by owner (drive/media/webhooks/documents); kernel sections stay; details in identities/config (P2.3)
- `packages/core/routes/api.php` → core (split) — routes by owner of the controller (drive/documents/webhooks); kernel keeps only the registrar; details in identities/http (P2.3)
- `packages/documents/config/vaulter-documents.php` → documents (keep) — open: collaboration keys (driver, hub, JWT) move to collaboration in P5
- `packages/documents/routes/documents.php` → documents (keep) — open: collaboration routes move to collaboration in P5

## Members (SchemaBuilder, BlobStore): owner × action × phase

| owner | action | phase | members |
|:--|:--|:--|:--|
| core | keep | P3 | 1 |
| core | keep | stay | 15 |
| core | split | P4 | 7 |
| documents | move | P4 | 4 |
| drive | move | P4 | 27 |
| media | move | P4 | 1 |
| webhooks | move | P4 | 2 |

## Members (57)

- `Vaulter\Media\Cas\BlobStore::attach` → drive, move, P4 — -> drive link service; media keeps pins only (D23)
- `Vaulter\Media\Cas\BlobStore::detach` → drive, move, P4 — -> drive link service; media keeps pins only (D23)
- `Vaulter\Schema\SchemaBuilder::addActivityContextGinIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addActivityCreatedAtIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addDocumentContentGinIndex` → documents, move, P4
- `Vaulter\Schema\SchemaBuilder::addGrantUniqueIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addHeadForeignKey` → core, split, P4 — split by owner of the cycle: files<->file_revisions drive, documents<->document_revisions documents; create+deferred head FK in the last create migration of the cycle
- `Vaulter\Schema\SchemaBuilder::addNodeSequence` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addOwnerUniqueIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addShareExpiryIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addSiblingUniqueIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addSingleRootIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addTrashedAtIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::addUploadSweeperIndex` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::afterCreate` → core, split, P4 — per-entity steps go to the owners of their tables; core keeps only generic helpers
- `Vaulter\Schema\SchemaBuilder::assertEnforcedMysqlChecks` → core, keep, stay
- `Vaulter\Schema\SchemaBuilder::binaryColumn` → core, keep, stay — generic binary column (yjs_state)
- `Vaulter\Schema\SchemaBuilder::counterColumn` → core, keep, stay — generic BIGINT counter column (node sequence, state versions, epochs)
- `Vaulter\Schema\SchemaBuilder::createAll` → core, split, P4 — orchestration goes to the owners; core keeps only generic helpers
- `Vaulter\Schema\SchemaBuilder::createTable` → core, split, P4 — orchestration goes to the owners; core keeps only generic helpers
- `Vaulter\Schema\SchemaBuilder::dbConnection` → core, keep, stay
- `Vaulter\Schema\SchemaBuilder::defineActivity` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineBlobs` → media, move, P4
- `Vaulter\Schema\SchemaBuilder::defineDocumentRevisions` → documents, move, P4
- `Vaulter\Schema\SchemaBuilder::defineDocuments` → documents, move, P4
- `Vaulter\Schema\SchemaBuilder::defineDriveChangeHeads` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineDriveChanges` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineDrives` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineFileRevisionBlobs` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineFileRevisions` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineFiles` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineNodeComments` → documents, move, P4
- `Vaulter\Schema\SchemaBuilder::defineNodeGrants` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineNodeShares` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineNodeUserStates` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineNodes` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineOutbox` → core, keep, P3 — D29 reshapes the outbox tables (v_outbox, v_outbox_consumers, v_outbox_deliveries)
- `Vaulter\Schema\SchemaBuilder::defineScanTable` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineUploadCleanup` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineUploadParts` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineUploadSessions` → drive, move, P4
- `Vaulter\Schema\SchemaBuilder::defineWebhookDeliveries` → webhooks, move, P4
- `Vaulter\Schema\SchemaBuilder::defineWebhookEndpoints` → webhooks, move, P4
- `Vaulter\Schema\SchemaBuilder::dropAll` → core, split, P4 — orchestration goes to the owners; core keeps only generic helpers
- `Vaulter\Schema\SchemaBuilder::dropHeadForeignKey` → core, split, P4 — split by owner of the cycle: the head FK is dropped before the tables of drive/documents
- `Vaulter\Schema\SchemaBuilder::dropTable` → core, split, P4 — orchestration goes to the owners; core keeps only generic helpers
- `Vaulter\Schema\SchemaBuilder::hasForeignKey` → core, keep, stay
- `Vaulter\Schema\SchemaBuilder::ids` → core, keep, stay
- `Vaulter\Schema\SchemaBuilder::ipColumn` → core, keep, stay — generic column helper (named after the activity ip column)
- `Vaulter\Schema\SchemaBuilder::nameKeyColumn` → core, keep, stay — generic collation helper (name_key byte compare); consumed by drive nodes
- `Vaulter\Schema\SchemaBuilder::panelDiscriminator` → core, keep, stay — generic helper emitted by several owners tables
- `Vaulter\Schema\SchemaBuilder::scanEnum` → core, keep, stay — generic enum column helper named after the scan tables (D28); rename in P4
- `Vaulter\Schema\SchemaBuilder::scanInteger` → core, keep, stay — generic signed-counter helper named after the scan tables (D28); rename in P4
- `Vaulter\Schema\SchemaBuilder::scanReference` → core, keep, stay — generic reference-column helper named after the scan tables (D28); rename in P4
- `Vaulter\Schema\SchemaBuilder::schemaConnection` → core, keep, stay
- `Vaulter\Schema\SchemaBuilder::table` → core, keep, stay
- `Vaulter\Schema\SchemaBuilder::tenant` → core, keep, stay

## Filament model reads (models; 35 reads) (5)

- `Vaulter\Models\Access\NodeGrant` × 5
- `Vaulter\Models\Activity\Activity` × 3
- `Vaulter\Models\Drive\Drive` × 5
- `Vaulter\Models\Drive\Node` × 14
- `Vaulter\Models\Sharing\NodeShare` × 8

