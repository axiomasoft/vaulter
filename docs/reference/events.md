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
- **`ShouldBroadcast`** — the event is also broadcast to a realtime channel. Only the
  node-comment events broadcast (all in the Documents domain): `CommentAdded`,
  `CommentResolved`, `CommentDeleted`.

```php
use Vaulter\Drive\Events\Files\FileUploaded;

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

- `NodeGrantCreated` / `NodeGrantRevoked` — the **Drive ACL** grants (`v_node_grants`).
  `$revoked` is the count of grant rows removed.

## Documents — `Vaulter\Documents\Events` (requires `axioma-studio/vaulter-documents`)

| Event | Broadcasts? | Constructor payload |
|---|---|---|
| `DocumentCreated` | After commit | `Node $node, DocumentRevision $version, int $stateVersion, int $epoch, int\|string\|null $actorId = null` |
| `DocumentContentUpdated` | After commit | `Node $node, DocumentRevision $version, bool $created, int $stateVersion, int $epoch, int\|string\|null $actorId = null` |
| `DocumentRevisionCreated` | After commit | `Node $node, DocumentRevision $version, int $stateVersion, int $epoch, bool $restored = false, int\|string\|null $actorId = null` |
| `DocumentRestored` | After commit | `Node $node, DocumentRevision $version, DocumentRevision $restoredFrom, int $epoch, int $stateVersion, int\|string\|null $actorId = null` |
| `CommentAdded` | **Yes** (`ShouldBroadcast`) | `NodeComment $comment` |
| `CommentResolved` | **Yes** (`ShouldBroadcast`) | `NodeComment $comment, int\|string\|null $actorId = null` |
| `CommentDeleted` | **Yes** (`ShouldBroadcast`) | `NodeComment $comment, int\|string\|null $actorId = null` |

`$stateVersion` and `$epoch` are the document's content validators **after** the write, taken from
the writer's result under the document lock — not read back from the head checkpoint. A named
snapshot carries the unchanged values. See [Concurrency](./concurrency.md).

::: info Node-attached path
`DocumentCreated`, `DocumentContentUpdated`, `DocumentRevisionCreated` operate on the
**node-attached** document path (`Node` + `DocumentRevision`, enabled with the
Documents package). The `NodeComment*` events cover the node-comment threads
(ADR-0014). Vaulter has no other document-events domain — the earlier document-id-attached
DocVault events were fully excised (ADR-0014).
:::

### Broadcast details

All three `NodeComment*` events publish to a `PrivateChannel` named
`{collaboration.channel_prefix}.{nodeId}` (default prefix `vaulter.documents`) — the
same room open editors join for collaboration.

| Event | `broadcastAs()` | `broadcastWith()` keys |
|---|---|---|
| `CommentAdded` | `node-comment.added` | `id`, `node_id`, `parent_id`, `body`, `created_at` |
| `CommentResolved` | `node-comment.resolved` | `id`, `node_id`, `resolved_at`, `resolved_by` |
| `CommentDeleted` | `node-comment.deleted` | `id`, `node_id` |

```php
// Echo-side listener (front-end)
Echo.private(`vaulter.documents.${nodeId}`)
    .listen('.node-comment.added', (e) => { /* … */ })
    .listen('.node-comment.resolved', (e) => { /* … */ })
    .listen('.node-comment.deleted', (e) => { /* … */ })
```

## Drive — `Vaulter\Drive\Events` (requires `axioma-studio/vaulter-drive`)

All `ShouldDispatchAfterCommit`.

| Event | Constructor payload |
|---|---|
| `Drives\DriveCreated` | `Drive $storage, Node $root, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\FolderCreated` | `Node $node, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Files\FileUploaded` | `Node $node, File $file, FileRevision $version, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Files\FileRevisionAdded` | `Node $node, File $file, FileRevision $version, int $versionNo, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeRenamed` | `Node $node, string $fromName, string $toName, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeMoved` | `Node $node, int\|string\|null $fromParentId, int\|string\|null $toParentId, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeCopied` | `Node $source, Node $copy, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeTrashed` | `Node $node, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodeRestored` | `Node $node, int\|string\|null $actorId = null, ?string $eventId = null` |
| `Nodes\NodePurged` | `int\|string $storageId, array $nodeIds, int $freedBytes, int\|string\|null $actorId = null, ?string $eventId = null` |

- `Drives\DriveCreated` — fires when `Vaulter::for()` get-or-creates a `v_storages` row;
  `$root` is the auto-provisioned root folder node.
- `Nodes\NodePurged` — note this carries **ids**, not models (`$storageId`,
  `array $nodeIds`) plus `$freedBytes`; it's emitted after the subtree is gone, so its
  rows no longer exist. Listeners can schedule GC and release quota.

## Sharing — `Vaulter\Events\Sharing`

All `ShouldDispatchAfterCommit`.

| Event | Constructor payload |
|---|---|
| `NodeShareCreated` | `NodeShare $share, int\|string\|null $actorId = null` |
| `NodeShareRevoked` | `NodeShare $share, int\|string\|null $actorId = null` |

- `NodeShare*` — the **bearer node share-link** channel (`v_node_shares`); this is the
  only sharing channel Vaulter ships today (see [ADR-0013](/adr/0013-two-sharing-channels)).

See [Usage → Sharing](/guide/sharing).

---

## Reliability note

When `features.webhooks` is on, an after-commit subscriber (the `OutboxRecorder`)
stages domain events into `v_outbox` in the same request as the mutation; the
`vaulter:relay-outbox` command then fans each row out into signed webhook deliveries.
Consumers must **dedupe on the `event_id`** carried in the payload — the outbox is
idempotent by `event_id`, but a delivery may be re-queued. See
[Recipes → Reliability & webhooks](/guide/recipes).

## See also

- [Commands reference](/reference/commands) — the relay/retry/GC schedulables
- [Configuration reference](/reference/configuration) — `features.webhooks`, `vaulter-documents.collaboration.broadcast.channel_prefix`, `webhooks.*`
- [Contracts reference](/reference/contracts) — the models these payloads carry
