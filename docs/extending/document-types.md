---
title: Document Types
description: The registry of document types — built-in rich text, markdown and spreadsheet, your own types, codecs, schema versions and what happens when a type is removed.
outline: deep
---

# Document Types

A **document type** says what a document's content is: how client JSON is validated
(the *codec*), what an empty document looks like, which client editor opens it (the
*viewer*), what the client may do with it and whether several people can edit it at once.
Every document stores its type's key (`vendor/name`) and the schema version its content
was written in. The class behind the key is never stored and never serialized.

Types live in one registry, `Documents::types()`, filled while the application boots and
frozen afterwards.

::: tip Types are not file handlers
A [file handler](/extending/file-handlers) describes a **file** by its mime and extension.
A document type describes a **document** node. An uploaded `.docx`, `.md` or `.xlsx` file
stays a file: it is previewed or downloaded, never edited, until an import "file →
document" exists.
:::

## Built-in types

| Key | Codec | Collaboration | Viewer |
|---|---|---|---|
| `vaulter/rich-text` | `TipTapCodec` with the rich-text schema (StarterKit, Image, Table, Link, CodeBlock, plus `vaulter-documents.tiptap.*`) | Yjs | `vaulter/tiptap` (`preset: rich-text`) |
| `vaulter/markdown` | `TipTapCodec` with the markdown schema (only what Markdown can represent; host extensions do not apply) | Yjs | `vaulter/tiptap` (`preset: markdown`) |
| `vaulter/spreadsheet` | `OpaqueJsonCodec` (any JSON object) | none | `vaulter/univer` |

Rich text and markdown are registered by default. The spreadsheet type is registered only
when you list it:

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'types' => [
    \Vaulter\Documents\Types\RichTextDocumentType::class,
    \Vaulter\Documents\Types\MarkdownDocumentType::class,
    \Vaulter\Documents\Types\SpreadsheetDocumentType::class,
],
'default_type' => 'vaulter/rich-text',
```

`default_type` is the type `Documents::in($drive)->create()` uses when you name none. It must
be registered, or the application refuses to boot.

## Your own type

Generate a class:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:document-type Kanban
```

This writes `app/Vaulter/DocumentTypes/KanbanDocumentType.php` with the key `app/kanban`, an
opaque JSON codec and schema version 1. Registration is up to you — add the class to
`vaulter-documents.types`, tag it in a service provider, or register it before the application
has booted:

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'types' => [/* … */, \App\Vaulter\DocumentTypes\KanbanDocumentType::class],

// or in a service provider's register()
$this->app->tag([KanbanDocumentType::class], 'vaulter.document_types');
$this->app->make(\Vaulter\Documents\Types\DocumentTypeRegistry::class)->register(KanbanDocumentType::class);
```

A type implements `Vaulter\Documents\Contracts\DocumentType`:

<!-- snippet: signature Vaulter\Documents\Contracts\DocumentType Vaulter\Documents\Contracts\DocumentCodec -->

```php
interface DocumentType
{
    public function key(): string;                 // 'acme/kanban'
    public function label(): string;
    public function schemaVersion(): int;          // >= 1; the version new content is written in
    public function codec(): DocumentCodec;
    public function defaultContent(): DocumentContent;
    public function viewer(): DocumentViewer;    // the client editor key
    public function features(): DocumentFeatures;
}

interface DocumentCodec
{
    /** @throws InvalidDocumentContentException */
    public function decode(array $raw, int $schemaVersion): DocumentContent;
    public function maxSizeBytes(): ?int;          // null = vaulter-documents.content.max_size_kb
}
```

Two optional interfaces add behaviour:

- `SupportsCollaboration` — `collaborationProtocol()` (`'yjs'`) and `projectFromState()`.
- `UpgradesSchema` — `upgrade(array $content, int $fromVersion): array`. Content stored in an
  older schema version is upgraded **on read**; history is never rewritten. The next save
  writes the current version. `upgrade()` must be idempotent.

Building blocks you can reuse: `Content\OpaqueJsonCodec` and `Content\OpaqueContent` (store
JSON as is), `Content\TipTap\TipTapCodec` with `TipTapSchema::richText()` or
`TipTapSchema::markdown()`.

### Registry rules

- Keys match `^[a-z0-9-]+/[a-z0-9-]+$`. The `vaulter/` prefix belongs to the package's own
  types; a third-party type that claims it is refused at boot.
- Registering a key twice throws `DuplicateDocumentTypeException`, whatever order the
  providers run in. Override a type only with `Documents::types()->replace($key, $type)`
  (logged at `info`).
- `all()` is ordered by key, so the result never depends on provider order.
- A class name that is not a class or does not implement the contract fails at boot with
  `InvalidConfigurationException`. Closures are not allowed in config (`config:cache`).
- Once the application has booted the registry is frozen: a later `register()` throws
  `RegistryFrozenException`. Octane workers share one immutable set.

### Contract tests

Run your type through the same contract the built-in types keep:

<!-- snippet: host -->

```php
use Vaulter\Documents\Testing\Contracts\DocumentTypeContractTests;

uses(DocumentTypeContractTests::class);

it('keeps the document type contract', function (): void {
    $this->assertDocumentTypeContract(new KanbanDocumentType);
});
```

It checks the key format, that `decode(defaultContent()->toArray())` round-trips, that the
content hash is deterministic, that the default content fits `maxSizeBytes()`, and that
`upgrade()` is idempotent.

## When a type is not installed

Removing an extension never touches its documents. A document whose type is not registered:

- stays in the tree, and is listed, copied, trashed, restored and purged as usual;
- reads as opaque JSON: `DocumentSnapshot::$typeAvailable = false`, `$editable = false`,
  `$content` is an `OpaqueContent` with the stored payload;
- is presented with the `vaulter/unsupported` viewer and no editing or collaboration
  capability (`type_available: false` in the node's presentation);
- answers `409 document_type_unavailable` to `update()`, `snapshot()`, `restore()`, `export()`
  and `collaborationSession()`.

Install and register the type again and the same document is fully usable, with nothing lost.

## Content that reads only partly

Reading is lenient: a rich-text document that contains nodes of an editor extension you have
since switched off (`vaulter-documents.tiptap.extra_nodes`) still opens — the unknown parts are
left out. Saving that view back would delete them for good, so such a document is read-only:
`DocumentSnapshot::$editable = false`, collaboration opens read-only (the session is `mode: ro`,
so the hub's authenticate answers read-only and its appends are refused), and `update()` answers
`409 document_content_degraded`. Switch the extension back on (or restore a revision without
it) and the document is editable again.

The same applies to a document written in a **newer** schema version than the installed type
knows: it is shown, not overwritten.

The lenient read leaves out only what is not TipTap JSON or not allowed by the schema. A node
carries `type`, `attrs` (an object), `marks` (on any node, as in ProseMirror) and `content`, or a
string `text` on a text node; the strict write rejects anything else (`422 invalid_document_content`),
so whatever it accepts reads back without loss.

## Presentation

A document node is presented by its type, not by a file handler:

```json
{
  "id": null,
  "document_type": "vaulter/rich-text",
  "type_available": true,
  "capabilities": { "editable": true, "previewable": true, "downloadable": false, "thumbnailable": false, "collaborative": true },
  "ui": { "key": "vaulter/tiptap", "props": { "preset": "rich-text" } }
}
```

`id` (the file handler key) is `null` for documents. Core knows nothing about document types:
`vaulter-documents` tags a `Vaulter\Drive\Contracts\Presentation\KindPresenter` for the `document`
kind and core's `NodePresenter` defers to it.

## Profiles

A profile can narrow the types that may be **created** in its drives:

<!-- snippet: host -->

```php
$profile->documentTypes('vaulter/markdown', 'acme/kanban');
```

An empty list allows every registered type. Creating a type outside the list throws
`ProfileRestrictionException`.

## Generator

<!-- snippet: shell -->

```bash
php artisan vaulter:make:document-type Kanban
```

Writes a type in `app/Vaulter/DocumentTypes/KanbanDocumentType.php` and a Pest test in `tests/Unit/Vaulter/` that runs `DocumentTypeContractTests`. It prints the line to `vaulter-documents.types`
and never edits your config. An existing file is kept unless you pass `--force`.

## Testing your extension

Besides the contract suite above, `Document::factory()` creates documents through
`DocumentOperations`, so a fixture has its node, its type's codec and its first checkpoint:

<!-- snippet: host -->

```php
use Vaulter\Documents\Models\Documents\Document;

$document = Document::factory()->richText('Hello')->in($drive)->create();
$board = Document::factory()->ofType('acme/kanban')->named('Roadmap')->in($folder)->create();
```

`richText()` fills a rich-text document, `ofType()` starts a document of any installed type with
that type's default content, and `in()` takes a drive (its root) or a folder. A tenant other than
the current one is chosen with `forTenant()`.

Document types use `Vaulter\Documents\Data\Types\DocumentViewer` and
`Vaulter\Documents\Data\Types\DocumentFeatures`. A backend-only type returns
`new DocumentViewer(key: 'vaulter/unsupported')`.
