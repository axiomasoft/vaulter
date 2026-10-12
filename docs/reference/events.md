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

`journal` is the change-journal operation the event produces. The webhook `data` holds ids, enums and counters only; names, mime types and token prefixes stay in the activity context. The table below is generated from the `EventSpec` attribute of each `EventType` by `php artisan vaulter:events:reference` — do not edit it by hand. A key marked `?` may be absent.

<!-- events:start -->
| Type | Subject | Data | Activity | Webhook | Journal | Description |
|---|---|---|---|---|---|---|
| `drive.created` | drive | `root_node_id`: `string`, `profile`: `string` | ✓ | ✓ | — | A drive was created together with its root node. |
| `folder.created` | node | `parent_id`: `string\|null` | ✓ | ✓ | upsert | A folder was created. |
| `file.uploaded` | node | `parent_id`: `string\|null`, `revision_id`: `string`, `size`: `int` | ✓ | ✓ | upsert | A file was created with its first revision. |
| `file.revision_added` | node | `revision_id`: `string`, `number`: `int`, `size`: `int` | ✓ | ✓ | upsert | A new revision became the current content of the file. |
| `upload.initiated` | upload | `upload_id`: `string`, `size`: `int` | ✓ | — | — | A resumable upload session was opened (audited only). |
| `upload.aborted` | upload | `upload_id`: `string`, `size`: `int` | ✓ | — | — | An upload session was aborted (audited only). |
| `upload.expired` | upload | `upload_id`: `string`, `size`: `int` | ✓ | — | — | An upload session expired and was reclaimed (audited only). |
| `node.moved` | node | `from_parent_id`: `string\|null`, `to_parent_id`: `string\|null` | ✓ | ✓ | upsert | A node moved to another parent. |
| `node.renamed` | node | — | ✓ | ✓ | upsert | A node was renamed; the names stay in the activity context. |
| `node.copied` | node | `source_id`: `string`, `destination_id`: `string\|null` | ✓ | ✓ | upsert | A node was copied; the subject is the copy. |
| `node.updated` | node | `changed`: `list<string>` | ✓ | ✓ | upsert | The properties of a node changed. |
| `node.trashed` | node | — | ✓ | ✓ | remove | A node and its subtree were moved to the trash. |
| `node.restored` | node | `parent_id`: `string\|null` | ✓ | ✓ | upsert | A trashed node was restored. |
| `node.purged` | drive | `node_ids?`: `list<string>`, `node_count`: `int`, `freed_bytes`: `int` | ✓ | ✓ | — | Trashed nodes were permanently deleted; node_ids is omitted above 1000 nodes. |
| `grant.created` | node | `grant_id`: `string`, `subject`: `object`, `role`: `string`, `role_version`: `int`, `scope`: `string` | ✓ | ✓ | upsert | A role was granted on a node. |
| `grant.revoked` | node | `subject`: `object`, `revoked`: `int` | ✓ | ✓ | upsert | The grants of a subject on a node were revoked. |
| `access.inheritance_changed` | node | `inherits`: `bool` | ✓ | ✓ | upsert | A node started or stopped inheriting grants from above. |
| `share.created` | share | `node_id`: `string`, `role`: `string`, `role_version`: `int`, `audience`: `string`, `scope`: `string`, `expires_at`: `string\|null` | ✓ | ✓ | — | A share link was created. |
| `share.updated` | share | `node_id`: `string`, `changed`: `list<string>` | ✓ | ✓ | — | A live share link: audience, expiry, budget or password changed. |
| `share.rotated` | share | `node_id`: `string` | ✓ | ✓ | — | The token of a share link was replaced. |
| `share.revoked` | share | `node_id`: `string` | ✓ | ✓ | — | A share link was revoked. |
| `drive.archived` | drive | — | ✓ | ✓ | — | A drive became read-only. |
| `drive.unarchived` | drive | — | ✓ | ✓ | — | An archived drive became writable again. |
| `drive.deleted` | drive | — | ✓ | ✓ | — | A drive was deleted. |
| `file.revision_deleted` | node | `revision_id`: `string`, `number`: `int` | ✓ | ✓ | — | A non-current revision was deleted. |
| `document.created` | node | `type`: `string`, `revision_id`: `string`, `state_version`: `int`, `epoch`: `int` | ✓ | ✓ | upsert | A document was created. |
| `document.updated` | node | `revision_id?`: `string`, `checkpoint`: `bool`, `state_version`: `int`, `epoch`: `int` | — | ✓ | upsert | The content of a document was saved; webhooks receive only saves that took a checkpoint. |
| `document.revision_created` | node | `revision_id`: `string`, `state_version`: `int`, `epoch`: `int`, `restored`: `bool` | ✓ | ✓ | — | A named or restored document revision was created. |
| `document.restored` | node | `from_revision_id`: `string`, `revision_id`: `string`, `state_version`: `int`, `epoch`: `int` | ✓ | ✓ | upsert | A document was restored from an earlier revision. |
| `comment.added` | comment | `comment_id`: `string`, `node_id`: `string` | ✓ | ✓ | — | A comment was added. |
| `comment.resolved` | comment | `comment_id`: `string`, `node_id`: `string` | ✓ | ✓ | — | A comment was resolved. |
| `comment.deleted` | comment | `comment_id`: `string`, `node_id`: `string` | ✓ | ✓ | — | A comment was deleted. |
<!-- events:end -->

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
| `NodeShareCreated`, `NodeShareRevoked`, `NodeShareRotated` | `NodeShare $share` |
| `NodeShareUpdated` | `NodeShare $share, array $changed` |
| `NodeUpdated` | `Node $node` |
| `NodeInheritanceChanged` | `Node $node, bool $inherits` |

- `DriveDeleted` and `NodePurged` carry ids, not models: their rows no longer exist.
- `NodeShare*` — the bearer node share-link channel (`v_node_shares`); see
  [ADR-0013](/adr/0013-two-sharing-channels) and [Usage → Sharing](/guides/sharing).

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

- [Webhooks](/guides/webhooks) — the envelope, delivery and deduplication
- [Commands reference](/reference/commands) — the relay/retry/GC schedulables
- [Configuration reference](/reference/config) — `features.activity`, `features.webhooks`, `webhooks.*`
- [Contracts reference](/reference/php-api) — the models these payloads carry
