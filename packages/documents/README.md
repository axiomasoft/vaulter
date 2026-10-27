# Vaulter · Documents

`axioma-studio/vaulter-documents` adds **document nodes** to Vaulter: TipTap-based editing, a bounded revision history
(named checkpoints, restore), comments and export (HTML, Markdown). A document is a node of the drive tree
(`kind = document`) whose content lives in the `v_documents` aggregate, authorized by the drive's access model.

It depends on `axioma-studio/vaulter-core` and `axioma-studio/vaulter-drive` and is part of the `axioma-studio/vaulter`
metapackage (core, media, drive, documents). It is an *auto* module: installing the package activates it.

```bash
composer require axioma-studio/vaulter-documents   # or axioma-studio/vaulter, which includes it
php artisan migrate
```

## What it owns

| Concern | Where |
|---|---|
| Tables | `v_node_comments`, `v_documents`, `v_document_revisions` (migrations `0001_01_01_000301` to `…303`, run from `vendor/`, never published) |
| Config | `config/vaulter-documents.php`: `content`, `revisions`, `comments`, `exporters`, `types`, `default_type`, `tiptap.*`, `broadcast.channel_prefix`, `models` |
| Routes | `routes/documents.php`: create, content (`If-Match`), revisions, restore, export, comments, behind the documents module |
| Types | the document type registry (`vaulter/rich-text`, `vaulter/markdown`, your own with `vaulter:make:document-type`) |
| Export | `DocumentExporter` implementations (`vaulter:make:exporter`) |
| Doctor | `documents.types` |

## The collaboration seam

Documents owns the **aggregate** that realtime editing needs and nothing of the transport:

- `collab_epoch` (the *generation*; moves on restore and on a JSON replace of a document with Yjs state), `state_version`
  and the binary Yjs checkpoint;
- the neutral **pending marker** `(pending_generation, pending_through_seq)` — saved collaboration edits that are not in a
  checkpoint yet. While it is set the content is never read, exported, replaced or restored from the stale state:
  `409 document_state_pending` (retryable), after a drain attempt through `PendingStateDrainer`;
- the public state contract `Vaulter\Documents\State\CollaborativeState` (`lock`, `markPending`, `publishCheckpoint`,
  `pendingDocuments`) that a transport writes through, and the contracts `CollaborationDriver` and `SupportsCollaboration`.

Realtime editing is available as a commercial add-on. A host registers its transport through the
public protocol contracts; without a transport, documents use the content endpoints.

## Development in the monorepo

```bash
scripts/t sqlite -p documents
scripts/release/consumer-check.sh documents    # builds it in an empty directory with only core, media and drive
```

Guides: [Documents](../../docs/guides/documents.md), [Document types](../../docs/extending/document-types.md),
[Export](../../docs/guides/export.md). Package rules: [ADR-0030](../../docs/adr/0030-package-ownership-and-schema.md).
