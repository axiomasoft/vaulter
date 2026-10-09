---
title: Webhooks
---

# Webhooks

## How an event reaches the outbox

Every Vaulter change records its event through the `EventRecorder` as the last step of
its own database transaction (see [Events](/reference/events)). With `features.webhooks`
on, the recorder writes one `v_outbox` row for the occurrence **in that transaction**:

- the change and its outbox row commit together, so a committed change is never
  missing its webhook, even if the process dies right after the commit;
- if the outbox row cannot be written, the change is rolled back and the operation
  fails — Vaulter does not commit a change it cannot announce;
- your Laravel listeners run only after the commit; a failing listener is reported and
  never undoes the change.

The `vaulter:relay-outbox` command then fans each pending row out into deliveries.

## Delivery guarantees

The relay turns each `pending` outbox row into one `v_webhook_deliveries` row per
eligible endpoint and queues a job per delivery:

- **Every eligible endpoint gets exactly one delivery row per event.** The relay leases
  the outbox row (`webhooks.relay.claim_seconds`, default 60) and writes
  `webhooks.relay.fanout_chunk` endpoints (default 100) per transaction, remembering the
  last one in `fanout_cursor`. The row turns `completed` in the same transaction as its
  last deliveries. If the relay dies, the next run takes the row over once the lease
  lapses and continues after the cursor; a relay that lost its lease writes nothing, and
  `UNIQUE(event_id, endpoint_id)` backs this up. You may run several relays at once.
- **Jobs are queued after the rows commit.** A job lost after that is re-queued by the
  retry sweep (`vaulter:retry-webhooks`, also run by `vaulter:relay-outbox`) once the
  delivery has waited `webhooks.relay.claim_seconds` without being picked up.
- **HTTP is at-least-once.** A delivery is retried on failure and can arrive twice if
  your acknowledgement is lost: deduplicate on the envelope `id`. Deliveries are not
  ordered.
- **Prune deletes only finished work.** `vaulter:prune-webhooks` removes `completed`
  outbox rows and `delivered`/`dead` deliveries older than `webhooks.retention_days`; a
  `pending` row or a `pending`/`failed` delivery stays however old it is. Pruned rows are
  gone for good — nothing can replay them.

## Who receives an event

An endpoint receives an event when, at the moment its chunk is written:

- it is active and subscribed to the event type;
- its tenant equals the event's tenant — an endpoint without a tenant receives only
  events without one;
- it has no `drive_id`, or the event happened in that drive;
- it already existed when the event happened (its `created_at` is not later than the
  event's). An endpoint registered afterwards never receives older events.

An endpoint deactivated while an event is being fanned out misses the remaining part
of it. Register and update endpoints in the tenant they belong to: under strict tenancy
the endpoint takes the current tenant, and `drive_id` must name a drive of that tenant
(any other id answers 404).

## Rows from before 1.0

The relay before 1.0 marked a row `dispatched` before writing its deliveries, so a crash
could lose them. The upgrade marks those rows `completed` with `legacy_fanout = true`.
A legacy row without any delivery is ambiguous — nobody was subscribed, or the fan-out
was lost. `php artisan vaulter:webhooks:legacy-fanout` lists them;
`--replay` (try `--dry-run` first) sends them through the relay again, to the endpoints
eligible **now**. Nothing is replayed automatically, and no event is invented. See
[Commands](/reference/commands#vaulter-webhooks-legacy-fanout).

## The envelope

The outbox payload is the webhook body. It is built once per occurrence and copied
into every delivery:

```json
{
  "id": "01JA2Z4P6V8Y0C1D2E3F4G5H6J",
  "type": "node.renamed",
  "version": 1,
  "source": "vaulter",
  "time": "2026-10-09T12:00:00.123Z",
  "tenant_id": null,
  "drive_id": "42",
  "subject": {"type": "node", "id": "1337"},
  "sequence": 7,
  "actor": {"type": "App\\Models\\User", "id": "5"},
  "correlation_id": "01JA2Z4P6V8Y0C1D2E3F4G5H6K",
  "changes": null,
  "data": {}
}
```

- `id` identifies the occurrence: it is the activity row's `event_id` and stays the
  same on every retry of every delivery. **Deduplicate on `id`** — delivery is
  at-least-once.
- `data` is thin: ids, enums, counters and flags. Names, paths, mime types, tokens and
  request metadata are never sent; read the subject with your own credentials when you
  need them. The per-type keys are listed in the [catalogue](/reference/events#catalogue).
- `sequence` is the node's sequence after the change (`null` for events that do not
  change a node, and for document content, which carries `state_version` and `epoch`
  in `data`). Deliveries are not ordered; compare `sequence` (or `state_version`) to
  drop a stale update.
- `version` is the schema version of `data` for this `type`.

## Event names


`Vaulter\Enums\Events\EventType` uses the canonical 0.3 names. Upgrade migration
№9 converts `v_activity.verb`, `v_outbox.event`, `v_webhook_deliveries.event` and
`v_webhook_endpoints.events` together. Endpoint lists are processed in chunks of
500; list order, duplicates, `*` and unrecognized third-party names are preserved.

| Old name | Canonical name |
|---|---|
| `storage.created` | `drive.created` |
| `node.created` | `folder.created` |
| `version.created` | `file.revision_added` |
| `node.force_deleted` | `node.purged` |
| `upload_session.initiated` | `upload.initiated` |
| `upload_session.aborted` | `upload.aborted` |
| `upload_session.expired` | `upload.expired` |

`file.uploaded`, node move/rename/copy/trash/restore, grant and share events keep
their names. Upload lifecycle events remain audit-only. Update downstream webhook
consumers when deploying the schema/code upgrade. The activity occurrence ID and
outbox aggregate fields are filled for every event recorded since the event pipeline
(the 1.0 event types are listed in the [catalogue](/reference/events#catalogue)).

The catalogue is closed: each type declares an `EventSpec` (subject, the keys and types
of `data`, which sinks record it) and the table in the [events reference](/reference/events#catalogue)
is generated from it, so the reference cannot drift from the code. The 1.0 catalogue adds
`node.updated` (properties changed), `access.inheritance_changed`, `share.updated` and
`share.rotated`; `share.updated` reports only the *names* of the changed fields in
`data.changed`, and no envelope ever carries a share token, its hash or prefix, a
password, a name, a path or request details.

REST resource JSON keys stay unchanged until P6.1. See
[UPGRADE.md](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) for safety
checks, backups and the irreversible parts of rollback.
