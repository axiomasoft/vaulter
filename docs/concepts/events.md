# Change journal

Every drive keeps a journal of its node changes. A client that mirrors a drive — a desktop
sync app, a search index, a cache — lists the drive once, takes a cursor, and from then on reads
only what changed after it. Webhooks stay a wake-up signal: the journal is the authoritative,
ordered record (the model Dropbox, Microsoft Graph and Google Drive use).

```php
$changes = Vaulter::drive($project)->actingAs($user)->changes();

$cursor = $changes->startCursor();          // after the initial full listing
// … later, or when a webhook arrives:
do {
    $page = $changes->list($cursor, limit: 500);
    foreach ($page->changes as $change) {
        $replica->apply($change);
    }
    $cursor = $page->cursor;                 // store it with the replica
} while ($page->hasMore);
```

Over HTTP the same journal is two [REST v1 routes](/reference/rest-api#change-journal):

```bash
# After the initial listing:
curl https://app.test/api/vaulter/v1/drives/01J.../changes/start -H 'Authorization: Bearer <token>'
# → { "data": { "cursor": "…" } }

curl 'https://app.test/api/vaulter/v1/drives/01J.../changes?cursor=…&limit=500' -H 'Authorization: Bearer <token>'
# → { "data": [ {"node_id", "operation", "reason", "subtree", "node_sequence", "node"} … ],
#     "meta": { "next_cursor": "…", "has_more": false } }
```

`node` is the v1 node as the reader sees it now, abilities and `etag` included, or `null` for a
`remove`. `meta.next_cursor` is always set: store it even when the page is empty. With the
journal switched off the routes answer `403 profile_restriction`; a forged or foreign cursor
`400 invalid_cursor`, an expired one `410 cursor_expired`.

The HTTP journal requires at least one node the caller can view in this drive, including a
trash root. A nested-folder grant is sufficient. An entirely invisible drive returns
`404 drive_not_found` before issuing or reading a cursor, exactly like an unknown drive id.
Removal entries keep their node ids so a syncing reader can discard previously stored nodes
after deletion, a move or lost access.

## Starting

1. Take `startCursor()`.
2. List the drive (`nodes()->children()`, recursively) and store the result.
3. Read `list($cursor)` from the cursor of step 1.

Taking the cursor **before** listing means a change made during the listing is read again from
the journal; applying it twice is harmless.

## Applying a page

`list(string $cursor, int $limit = 100): ChangePage` returns `changes` (a list of `NodeChange`),
the next `cursor` and `hasMore`. Apply the changes in order, store the cursor, and ask again at
once while `hasMore` is true. `limit` is 1..`changes.max_page_size` (default 1000).

Each `NodeChange` has `nodeId`, `operation`, `reason`, `subtree`, `node` and `nodeSequence`:

| Change | What to do |
|---|---|
| `upsert` | Store `node` (a `NodeSnapshot` of the node as it is now) under `nodeId`; `nodeSequence` is its current sequence. |
| `upsert` with `subtree = true` | Store `node`, then re-read the folder's subtree by listing: a move, copy, restore or access change of a folder reaches every descendant, and only the folder has an entry. |
| `remove` | Delete `nodeId` and, for a folder, everything you hold below it. `node` is null. |

The journal keeps the latest state of a node, not every step: a node may appear more than once
in a page — the last occurrence wins. Keep your copy as a tree by id (`nodeId` → `parentId`):
a renamed or moved folder does not list its descendants again.

`reason` says why a node was removed:

| `reason` | Meaning |
|---|---|
| `deleted` | The node went to the trash (a folder takes its subtree along). |
| `moved_out` | The node was moved where this reader cannot see it. |
| `permission_lost` | An access change (grant revoked, inheritance restricted) took it away from this reader. |

A restore after a trash, or a grant that makes a node visible again, is an `upsert` that comes
later in the journal.

## What a reader sees

The journal is filtered by the reader's access **now**, with the same exact rules as a listing:
an `upsert` carries a snapshot only for a node the reader may see; a node it cannot see appears
only as a `remove` with a reason when a move or an access change explains its disappearance, and
is skipped otherwise. A node in the trash, or purged, is never shown as an upsert. A system actor
(`asSystem()`) sees every node.

## Cursors

A cursor is an opaque, signed string: store it as it is. It names its drive, the drive's tenant,
the journal epoch and a position, and it is signed with a key derived from `app.key` — a forged
cursor, or one taken from another drive or tenant, is refused with `invalid_cursor` (400).
Rotating `app.key` invalidates every stored cursor.

## Expired cursors

`list()` throws `CursorExpiredException` (`cursor_expired`, 410) when the entries after the cursor
are gone:

- `vaulter:changes:prune` deleted them — entries live `changes.retention_days` (default 30) days;
- `vaulter:changes:reset` started a new epoch of the drive's journal (after a restore from backup).

Resynchronize: take a new `startCursor()`, list the drive again and replace your copy. A reader
never skips entries silently: the prune records how far it deleted in the same transaction, and a
read checks that mark after it reads.

## Retention

Schedule the prune daily:

```php
Schedule::command('vaulter:changes:prune')->daily();
```

A client offline for longer than the retention window resynchronizes on its next read. See
[Commands](/reference/commands#vaulter-changes-prune) and
[Configuration → Change journal](/reference/config#change-journal-—-changes).

## Webhooks

The webhook envelope carries `changes.seq`, the journal position of its event. Use the webhook to
wake the client up and read the journal from the stored cursor; deliveries are unordered and may
repeat, the journal is neither. See [Webhooks → The envelope](/guides/webhooks#the-envelope).

## What is journalled

`folder.created`, `file.uploaded`, `file.revision_added`, `node.renamed`, `node.moved`,
`node.copied`, `node.updated`, `node.trashed` (remove), `node.restored`, `document.created`,
`document.updated` (every content save), `document.restored`, `grant.created`, `grant.revoked`
and `access.inheritance_changed` — one entry per event, for the node it is about. Drive lifecycle,
uploads, comments, shares and revision deletions are not node-state changes and are not
journalled; a purge is already a `remove` from the trash.

The journal is written in the transaction of the change itself, as its last step: a change and
its entry commit together, and entries become visible in the order of their position. It is on by
default (`features.changes`); switching it off and on again leaves a gap that only
`vaulter:changes:reset` closes.
