---
title: Events
description: Every Vaulter event by domain — constructor payloads, ShouldBroadcast vs ShouldDispatchAfterCommit.
outline: deep
---

# Events

Vaulter dispatches Laravel events across four domains. Every event is a
`final readonly` class with public promoted constructor properties — listen to them in
your `EventServiceProvider` to react to Drive activity, ACL changes, sharing, and
document edits.

Two delivery contracts are in play:

- **`ShouldDispatchAfterCommit`** — the event only fires once the surrounding DB
  transaction commits (no listener acts on a row that gets rolled back). This is the
  vast majority of Vaulter events.
- **`ShouldBroadcast`** — the event is also broadcast to a realtime channel. Only two
  events broadcast (both in the Documents domain): `CommentAdded` and
  `DocumentContentSynced`.

```php
use Vaulter\Events\Drive\FileUploaded;

Event::listen(function (FileUploaded $event): void {
    // $event->node, $event->file, $event->version, $event->actorId
});
```

::: info `actorId`
Nearly every event carries an `int|string|null $actorId` — the id of the host user
who triggered it (the host owns its users table, so this is an id, not a model). It is
`null` for system-initiated actions (e.g. scheduled purge/GC).
:::

---

## Access — `Vaulter\Events\Access`

All `ShouldDispatchAfterCommit`.

| Event | Constructor payload |
|---|---|
| `NodeGrantCreated` | `NodeGrant $grant, int\|string\|null $actorId = null` |
| `NodeGrantRevoked` | `Node $node, Subject $subject, int $revoked, int\|string\|null $actorId = null` |
| `PermissionGranted` | `DocumentPermission $permission, int\|string\|null $actorId = null` |
| `PermissionRevoked` | `Document $document, string $subjectType, int\|string $subjectId, int\|string\|null $actorId = null` |

- `NodeGrantCreated` / `NodeGrantRevoked` — the **Drive ACL** grants (`v_node_grants`).
  `$revoked` is the count of grant rows removed.
- `PermissionGranted` / `PermissionRevoked` — the **legacy** document permissions.

## Documents — `Vaulter\Events\Documents`

| Event | Broadcasts? | Constructor payload |
|---|---|---|
| `CommentAdded` | **Yes** (`ShouldBroadcast`) | `DocumentComment $comment` |
| `CommentResolved` | After commit | `DocumentComment $comment, int\|string\|null $actorId = null` |
| `DocumentContentSynced` | **Yes** (`ShouldBroadcast`) | `Document $document, int\|string\|null $actorId = null` |
| `DocumentContentUpdated` | After commit | `Node $node, NodeDocumentVersion $version, bool $created, int\|string\|null $actorId = null` |
| `DocumentCreated` | After commit | `Document $document, int\|string\|null $actorId = null` |
| `DocumentDeleted` | After commit | `Document $document, int\|string\|null $actorId = null` |
| `DocumentMoved` | After commit | `Document $document, int\|string\|null $folderId = null, int\|string\|null $actorId = null` |
| `DocumentNodeCreated` | After commit | `Node $node, NodeDocumentVersion $version, int\|string\|null $actorId = null` |
| `DocumentPurged` | After commit | `Document $document, int\|string\|null $actorId = null` |
| `DocumentRestored` | After commit | `Document $document, int\|string\|null $actorId = null` |
| `DocumentUpdated` | After commit | `Document $document, int\|string\|null $actorId = null` |
| `DocumentVersionCreated` | After commit | `Node $node, NodeDocumentVersion $version, bool $restored = false, int\|string\|null $actorId = null` |
| `VersionCreated` | After commit | `DocumentVersion $version, int\|string\|null $actorId = null` |
| `VersionRestored` | After commit | `Document $document, DocumentVersion $version, int\|string\|null $actorId = null` |

::: info Node path vs legacy path
`DocumentNodeCreated`, `DocumentContentUpdated`, `DocumentVersionCreated` operate on
the **node** path (`Node` + `NodeDocumentVersion`). `DocumentCreated`,
`DocumentUpdated`, `VersionCreated`, `VersionRestored`, etc. operate on the **legacy**
`Document`/`DocumentVersion` path.
:::

### Broadcast details

Both broadcasting events publish to a `PrivateChannel` named
`{collaboration.channel_prefix}.{documentId}` (default prefix `vaulter.documents`).

| Event | `broadcastAs()` | `broadcastWith()` keys |
|---|---|---|
| `CommentAdded` | `comment.added` | `id`, `document_id`, `parent_id`, `body`, `created_at` |
| `DocumentContentSynced` | `content.synced` | `document_id`, `actor_id`, `updated_at` |

```php
// Echo-side listener (front-end)
Echo.private(`vaulter.documents.${documentId}`)
    .listen('.comment.added', (e) => { /* … */ })
    .listen('.content.synced', (e) => { /* … */ })
```

## Drive — `Vaulter\Events\Drive`

All `ShouldDispatchAfterCommit`.

| Event | Constructor payload |
|---|---|
| `StorageCreated` | `Storage $storage, Node $root, int\|string\|null $actorId = null` |
| `NodeCreated` | `Node $node, int\|string\|null $actorId = null` |
| `FileUploaded` | `Node $node, File $file, FileVersion $version, int\|string\|null $actorId = null` |
| `NodeVersionCommitted` | `Node $node, File $file, FileVersion $version, int $versionNo, int\|string\|null $actorId = null` |
| `NodeRenamed` | `Node $node, string $fromName, string $toName, int\|string\|null $actorId = null` |
| `NodeMoved` | `Node $node, int\|string\|null $fromParentId, int\|string\|null $toParentId, int\|string\|null $actorId = null` |
| `NodeCopied` | `Node $source, Node $copy, int\|string\|null $actorId = null` |
| `NodeTrashed` | `Node $node, int\|string\|null $actorId = null` |
| `NodeRestored` | `Node $node, int\|string\|null $actorId = null` |
| `NodeForceDeleted` | `int\|string $storageId, array $nodeIds, int $freedBytes, int\|string\|null $actorId = null` |

- `StorageCreated` — fires when `Vaulter::for()` get-or-creates a `v_storages` row;
  `$root` is the auto-provisioned root folder node.
- `NodeForceDeleted` — note this carries **ids**, not models (`$storageId`,
  `array $nodeIds`) plus `$freedBytes`; it's emitted after the subtree is gone, so its
  rows no longer exist. Listeners can schedule GC and release quota.

## Sharing — `Vaulter\Events\Sharing`

All `ShouldDispatchAfterCommit`.

| Event | Constructor payload |
|---|---|
| `NodeShareCreated` | `NodeShare $share, int\|string\|null $actorId = null` |
| `NodeShareRevoked` | `NodeShare $share, int\|string\|null $actorId = null` |
| `ShareLinkCreated` | `DocumentShare $share, int\|string\|null $actorId = null` |
| `ShareLinkRevoked` | `Document $document, int\|string\|null $actorId = null` |

- `NodeShare*` — the **bearer node share-link** channel (`v_node_shares`).
- `ShareLink*` — the **legacy** document-share channel (`v_document_shares`).

See [Usage → Sharing](/guide/sharing) for the two channels.

---

## Reliability note

When `features.reliability` is on, an after-commit subscriber (the `OutboxRecorder`)
stages domain events into `v_outbox` in the same request as the mutation; the
`vaulter:relay-outbox` command then fans each row out into signed webhook deliveries.
Consumers must **dedupe on the `event_id`** carried in the payload — the outbox is
idempotent by `event_id`, but a delivery may be re-queued. See
[Recipes → Reliability & webhooks](/guide/recipes).

## See also

- [Commands reference](/reference/commands) — the relay/retry/GC schedulables
- [Configuration reference](/reference/configuration) — `features.reliability`, `collaboration.channel_prefix`, `webhooks.*`
- [Contracts reference](/reference/contracts) — the models these payloads carry
