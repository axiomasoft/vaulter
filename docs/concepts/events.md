# Change journal

The drive journal supports reader-specific incremental synchronization (PLAN4-D20).
Webhooks wake a client; the journal supplies ordered changes. Bootstrap in this order:

## Starting

1. Take `C0 = $drive->changes()->startCursor()` outside a host transaction.
2. List the visible drive recursively and retain each node's `sync_key` with its local tree.
3. Read `$drive->changes()->list(C0)` and apply pages in order. Persist each returned cursor,
   including empty pages, and continue while `hasMore` is true.

Taking C0 before listing avoids losing writes made during the listing. Projection always
uses current authorization; a cursor grants no access. HTTP requires a visible node in the
drive (a nested grant or visible trash root suffices); an invisible drive returns 404.

## Applying a page

An upsert contains `operation`, `sync_key`, `node_id`, `subtree`, `node_sequence`, and `node`.
The node snapshot is the current authorized view. Store its reader-specific key alongside
its id. When `subtree` is true, re-list that folder's descendants. Ordinary node listings,
show responses and cached snapshots also project the current reader's key; it participates in
the reader-specific representation ETag. Anonymous upserts have a null key; anonymous readers receive no removes.

A remove contains exactly `operation: remove` and `sync_key`. It exposes no raw node id,
reason, snapshot, subtree flag or node sequence. Use the saved key to delete the local node
and its locally known descendants. An unknown key is harmless. Repeated upserts/removes
are safe; the last occurrence wins. This minimizes identities, but correlated activity and
timing are still observable; the feed does not promise anonymity.

## Cursors

The opaque signed format 2 feed cursor is authenticated, not encrypted. It binds tenant,
drive, typed principal (including system reason), key generation, epoch, position,
original issue time and a latched deadline. A malformed, foreign or format 1 token returns
`400 invalid_cursor`; discard the local mapping and bootstrap again. `app.key` rotation
without key overlap can also produce this response.

## Expired cursors

The deadline is the minimum of the prior deadline, original issue time plus the current
`cursor_max_age_seconds`, and the earliest non-null live grant expiry in the reader's
subject set for this drive. No expiry contributes no bound. Paging never renews the issue
time or lengthens the deadline. Deadline, generation and epoch/prune boundaries are checked
before and after projection. Expiry, generation changes, epoch reset or pruned history
return `410 cursor_expired`; bootstrap and replace the local mapping.

`vaulter-drive.changes.key_generation` defaults to 1; the owner must raise it on every
instance after journaling was disabled and re-enabled, or when rotating sync key material.
The generation changes every reader's sync keys. Feature-off HTTP requests return
`403 profile_restriction`; enabling the feature alone cannot repair the journal gap.
`cursor_max_age_seconds` defaults to 86400 and is bounded by 2592000 and retention.

Node/access writes record entries transactionally. Existing access invalidation bumps the
head epoch, including tenant/global invalidation, without a second permission framework.
Receipts retain reader-neutral snapshots; replay rechecks current authorization and projects
the current reader's key. Never persist a reader key in a shared receipt; representation ETags vary with the reader key.

## Lists and feed

PLAN4-D21 list continuations are encrypted and authenticated, bound to endpoint, tenant,
typed subject, query and version. They hide scanned sort keys, retain progress over hidden
rows and may yield an empty page with a non-null continuation. Continue until it is null.
HTTP grants/shares use bounded pages; public PHP array `list()`/`grants()` remain available.
These confidential list cursors are a separate contract from the signed feed cursor above.

## Retention and qualification

Schedule `vaulter:changes:prune` daily; entries live `retention_days` (default 30).
`vaulter:changes:reset` starts a new epoch. See [Commands](/reference/commands) and
[Configuration](/reference/config). PostgreSQL/MySQL isolation, concurrent invalidation,
Redis/relay races, process kills and actual consumer qualification remain pending in the
owner's P8.2 runbook; SQLite and structural evidence do not qualify those environments.

For independently pinned or shared roots, a parent remove invalidates the known branch,
then the client reauthorizes already known affected ids with existing find/list calls. Keep
roots still visible through direct nested grants and re-list their subtrees. A root listing
does not enumerate every independently granted descendant. Hide stale records during
reconciliation; commit the page and checkpoint atomically only after reconciliation succeeds.
Viewer-null/domain events, outbox, webhook, activity, exports and ShareSession carry no user
sync key. With the feature disabled, cached records need a full bootstrap before feed reuse.
Host external-role/profile/tenant changes call `AccessInvalidation::changed()` after host
commit with durable retry. Failure is not successful revocation; the integration window
before notification is bounded by current authorization and the finite cursor horizon.
