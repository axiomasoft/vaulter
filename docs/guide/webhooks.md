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
