---
title: Webhook event names
---

# Webhook event names

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
outbox aggregate fields are nullable; their population follows in P5.1.

REST resource JSON keys stay unchanged until P6.1. See
[UPGRADE.md](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md) for safety
checks, backups and the irreversible parts of rollback.
