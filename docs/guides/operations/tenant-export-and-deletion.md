---
title: Tenant export and deletion
---

# Tenant export and deletion

Operational procedure for the host owner. This page documents actions; it does not authorize or execute them.

## Symptom

A tenant requests a portable export or deletion, or a drive remains in `deleting` state.

## Read-only diagnosis

Validate tenant identity, authorization, retention obligations and the complete set of its drives using the host application. Record shared references and legal holds. For each validated drive id replace `DRIVE_ID` below:
<!-- snippet: shell -->

```bash
php artisan vaulter:drives:purge --drive=DRIVE_ID --dry-run
php artisan vaulter:revisions:prune --drive=DRIVE_ID --dry-run
```
The purge preview only covers drives already marked deleting; an empty preview does not prove the tenant has no data. Inspect counts/status in read-only host reports; no dedicated tenant-export completion metric exists.

## Bounded action

There is no universal tenant export command. The host owner must implement and verify an authorized export of tenant metadata, revisions and referenced content, with a manifest and hashes, before deletion. Purge and retention are not export. Require explicit owner authorization, a verified protected backup, retention/hold approval and a final drive-id list before any deletion.

Use the existing host drive-deletion workflow to mark only approved drives deleting. After reviewing each preview, an authorized operator may run:
<!-- snippet: shell -->

```bash
php artisan vaulter:drives:purge --drive=DRIVE_ID --sync
```
This resumes purge synchronously: each step processes up to 100 children of the drive root, and each child can be a subtree. It removes the drive tree, revisions and references. `--sync` runs until the drive is purged; it has no wall-clock or total-node budget. Scope the maintenance window to the listed drives; there is no tenant selector on this command. Revision retention only prunes eligible non-current revisions. Shared blob bytes survive while pinned and may remain until GC; activity/outbox rows stay. This is not a complete tenant-erasure guarantee.

## Postcondition

Reconcile the approved drive list against remaining rows and references. Verify export hashes and authorized access before accepting deletion. Record remaining activity/outbox, backups and any host-owned data with their retention policy and owner. A removed drive row does not prove all tenant data or physical bytes have been erased.

## Rollback

Before purge, cancel through the supported host workflow if available. After purge there is no undelete command: stop writers and use the coordinated backup recovery procedure with explicit owner authorization. Do not restore a whole shared store merely to recover one tenant without assessing impact on other tenants.
