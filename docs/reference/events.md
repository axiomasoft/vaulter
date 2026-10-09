---
title: Events
description: Every Vaulter event by domain — how it is recorded, its constructor, its activity and webhook rows.
outline: deep
---

# Events

Every Vaulter event extends `Vaulter\Events\DomainEvent`: a `readonly` class that
identifies one occurrence of a change. Listen to them in your `EventServiceProvider`
to react to Drive activity, ACL changes, sharing, documents and comments.

```php
use Vaulter\Drive\Events\Files\FileUploaded;

Event::listen(function (FileUploaded $event): void {
    // $event->node, $event->file, $event->version
    // $event->eventId, $event->occurredAt, $event->actor, $event->correlationId
});
```

Every event carries:

| Property | Type | Meaning |
|---|---|---|
| `eventId` | `string` (ULID) | The occurrence id. The activity row, the outbox row and the webhook `id` share it. |
| `occurredAt` | `CarbonImmutable` (UTC) | When the change happened. |
| `actor` | `ActorRef` | Who caused it: `type` + `id` of the host user, or the system actor (`type = vaulter:system`, `id = null`, a `reason`). |
| `correlationId` | `string` | The operation's correlation id; system paths mint one. |

and answers `type()` (its `EventType`), `scope()` (tenant, drive, profile), `subject()`
(what it is about), `sequence()` (the node's sequence after the change, or `null`) and
`data()` (the webhook data).

## How events are recorded

Vaulter's Actions hand every event to `Vaulter\Events\EventRecorder` as the **last step
of their transaction**, on the Vaulter connection. The recorder:

1. writes the activity row (when `features.activity` is on and the type is audited);
2. writes the outbox row with the webhook envelope (when `features.webhooks` is on and
   the type is a webhook type);
3. dispatches the Laravel event to your listeners **after the transaction commits**.

Both rows commit or roll back with the change itself. If a row cannot be written, the
exception leaves the transaction and the change is rolled back — a committed change
always has its activity and outbox rows. A failing listener does not undo the change:
it runs after the commit, its exception is reported (`report()`) and counted in
`vaulter_event_listener_failures_total{type}`, and the operation returns normally.

Recording the same occurrence again (a rebuilt event with the same `eventId`) is a
no-op and is not dispatched a second time. Two identical commands are two occurrences:
renaming A→B→A→B gives three `node.renamed` events.

::: warning Do not dispatch Vaulter events yourself
`event(new NodeRenamed(...))` reaches your listeners but writes neither the activity
row nor the outbox row. Events no longer implement `ShouldDispatchAfterCommit`; the
recorder provides the after-commit delivery.
:::

The comment events also implement `ShouldBroadcast`; their broadcast is queued by the
same after-commit dispatch.

## Catalogue

`A` — written to the activity feed; `W` — staged for webhooks. The webhook `data` holds
ids, enums and counters only; names, mime types and token prefixes stay in the activity
context.

| `EventType` | Class | Subject | Webhook `data` | A | W |
|---|---|---|---|---|---|
| `drive.created` | `Vaulter\Drive\Events\Drives\DriveCreated` | drive | `root_node_id`, `profile` | ✓ | ✓ |
| `drive.archived` | `Vaulter\Drive\Events\Drives\DriveArchived` | drive | — | ✓ | ✓ |
| `drive.unarchived` | `Vaulter\Drive\Events\Drives\DriveUnarchived` | drive | — | ✓ | ✓ |
| `drive.deleted` | `Vaulter\Drive\Events\Drives\DriveDeleted` | drive | — | ✓ | ✓ |
| `folder.created` | `Vaulter\Drive\Events\Nodes\FolderCreated` | node | `parent_id` | ✓ | ✓ |
| `file.uploaded` | `Vaulter\Drive\Events\Files\FileUploaded` | node | `parent_id`, `revision_id`, `size` | ✓ | ✓ |
| `file.revision_added` | `Vaulter\Drive\Events\Files\FileRevisionAdded` | node | `revision_id`, `number`, `size` | ✓ | ✓ |
| `file.revision_deleted` | `Vaulter\Drive\Events\Files\FileRevisionDeleted` | node | `revision_id`, `number` | ✓ | ✓ |
| `node.renamed` | `Vaulter\Drive\Events\Nodes\NodeRenamed` | node | — | ✓ | ✓ |
| `node.moved` | `Vaulter\Drive\Events\Nodes\NodeMoved` | node | `from_parent_id`, `to_parent_id` | ✓ | ✓ |
| `node.copied` | `Vaulter\Drive\Events\Nodes\NodeCopied` | node (the copy) | `source_id`, `destination_id` | ✓ | ✓ |
| `node.trashed` | `Vaulter\Drive\Events\Nodes\NodeTrashed` | node | — | ✓ | ✓ |
| `node.restored` | `Vaulter\Drive\Events\Nodes\NodeRestored` | node | `parent_id` | ✓ | ✓ |
| `node.purged` | `Vaulter\Drive\Events\Nodes\NodePurged` | drive | `node_ids` (up to 1000), `node_count`, `freed_bytes` | ✓ | ✓ |
| `upload.initiated` / `upload.aborted` / `upload.expired` | `Vaulter\Drive\Events\Uploads\*` | upload | `upload_id`, `size` | ✓ | — |
| `document.created` | `Vaulter\Documents\Events\Documents\DocumentCreated` | node | `type`, `revision_id`, `state_version`, `epoch` | ✓ | ✓ |
| `document.updated` | `Vaulter\Documents\Events\Documents\DocumentContentUpdated` | node | `revision_id` (checkpoint only), `checkpoint`, `state_version`, `epoch` | — | checkpoint only |
| `document.revision_created` | `Vaulter\Documents\Events\Revisions\DocumentRevisionCreated` | node | `revision_id`, `state_version`, `epoch`, `restored` | ✓ | ✓ |
| `document.restored` | `Vaulter\Documents\Events\Revisions\DocumentRestored` | node | `from_revision_id`, `revision_id`, `state_version`, `epoch` | ✓ | ✓ |
| `comment.added` / `comment.resolved` / `comment.deleted` | `Vaulter\Documents\Events\Comments\*` | comment | `comment_id`, `node_id` | ✓ | ✓ |
| `grant.created` | `Vaulter\Events\Access\NodeGrantCreated` | node | `grant_id`, `subject {type, id}`, `role`, `role_version`, `scope` | ✓ | ✓ |
| `grant.revoked` | `Vaulter\Events\Access\NodeGrantRevoked` | node | `subject {type, id}`, `revoked` | ✓ | ✓ |
| `share.created` | `Vaulter\Events\Sharing\NodeShareCreated` | share | `node_id`, `role`, `role_version`, `audience`, `scope`, `expires_at` | ✓ | ✓ |
| `share.revoked` | `Vaulter\Events\Sharing\NodeShareRevoked` | share | `node_id` | ✓ | ✓ |

Every content save of a document would flood the feed, so `document.updated` is not
audited; webhooks receive the saves that took a checkpoint, while your listeners
receive every save (`$event->created` tells which). Document events carry the
document's `state_version` and `epoch` after the write, taken from the writer's result;
their `sequence` is `null` because content does not move the node sequence. See
[Concurrency](./concurrency.md).

## Constructors

Every constructor ends with `ActorRef $actor, string $correlationId, ?string $eventId = null, ?CarbonImmutable $occurredAt = null`; the table lists the parameters before them.

| Event | Leading parameters |
|---|---|
| `DriveCreated` | `Drive $storage, Node $root` |
| `DriveArchived`, `DriveUnarchived` | `Drive $drive` |
| `DriveDeleted` | `string $driveId, string $ownerType, string $ownerId, int\|string\|null $tenantId, string $profile` |
| `FolderCreated`, `NodeTrashed`, `NodeRestored` | `Node $node` |
| `FileUploaded` | `Node $node, File $file, FileRevision $version` |
| `FileRevisionAdded` | `Node $node, File $file, FileRevision $version, int $versionNo` |
| `FileRevisionDeleted` | `Node $node, File $file, int\|string $revisionId, int $revisionNo, int $sizeBytes` |
| `NodeRenamed` | `Node $node, string $fromName, string $toName` |
| `NodeMoved` | `Node $node, int\|string\|null $fromParentId, int\|string\|null $toParentId` |
| `NodeCopied` | `Node $source, Node $copy` |
| `NodePurged` | `int\|string $storageId, array $nodeIds, int $freedBytes, int\|string\|null $tenantId, string $profile` |
| `UploadInitiated`, `UploadAborted`, `UploadExpired` | `UploadSession $session` |
| `DocumentCreated` | `Node $node, DocumentRevision $version, int $stateVersion, int $epoch` |
| `DocumentContentUpdated` | `Node $node, DocumentRevision $version, bool $created, int $stateVersion, int $epoch` |
| `DocumentRevisionCreated` | `Node $node, DocumentRevision $version, int $stateVersion, int $epoch, bool $restored` |
| `DocumentRestored` | `Node $node, DocumentRevision $version, DocumentRevision $restoredFrom, int $epoch, int $stateVersion` |
| `CommentAdded`, `CommentResolved`, `CommentDeleted` | `NodeComment $comment` |
| `NodeGrantCreated` | `NodeGrant $grant` |
| `NodeGrantRevoked` | `Node $node, Subject $subject, int $revoked` |
| `NodeShareCreated`, `NodeShareRevoked` | `NodeShare $share` |

- `DriveDeleted` and `NodePurged` carry ids, not models: their rows no longer exist.
- `NodeShare*` — the bearer node share-link channel (`v_node_shares`); see
  [ADR-0013](/adr/0013-two-sharing-channels) and [Usage → Sharing](/guide/sharing).

## Comment broadcasts

The three comment events publish to a `PrivateChannel` named
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

## See also

- [Webhooks](/guide/webhooks) — the envelope, delivery and deduplication
- [Commands reference](/reference/commands) — the relay/retry/GC schedulables
- [Configuration reference](/reference/configuration) — `features.activity`, `features.webhooks`, `webhooks.*`
- [Contracts reference](/reference/contracts) — the models these payloads carry
