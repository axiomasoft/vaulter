### Drive change journal (PLAN2.P5.5)

- Added: a per-drive change journal (PLAN2.D50, D59, D61). Every node mutation writes one entry in
  its own transaction, numbered by the drive's head row (`v_drive_change_heads`, `v_drive_changes`,
  migration `2026_10_10_000004_drive_changes` with a head for every existing drive). Entries are
  compacted to the latest state of a node; a remove, a folder's subtree entry and a move or access
  change are never hidden by a weaker later entry.
- Added: `DriveHandle::changes(): Vaulter\Operations\ChangeOperations` with `startCursor()` and
  `list(string $cursor, int $limit = 100): ChangePage` (`NodeChange`: `upsert`/`remove`, `reason`
  `deleted`|`moved_out`|`permission_lost`, `subtree`, current `NodeSnapshot`, `nodeSequence`),
  filtered by the reader's current access with the listing's exact rules.
- Added: signed opaque cursors; `InvalidCursorException` (`invalid_cursor`, 400) for a forged or
  foreign cursor and `CursorExpiredException` (`cursor_expired`, 410) after a prune or reset.
- Added: `php artisan vaulter:changes:prune {--days=} {--drive=*}` (schedule daily) and
  `php artisan vaulter:changes:reset {drive}`.
- Added: config `features.changes` (true), `changes.retention_days` (30), `changes.max_page_size`
  (1000), validated at boot; `VaulterConfig::changes()` / `ChangesConfig`.
- Added: `DomainEvent::journal(): ?JournalEffect`; the journalled events of the catalogue return
  their entry. The webhook envelope's `changes` is now `{"seq": n}` for a journalled event.
- Fixed: the collaboration hub signature check called the outbound `WebhookSigner::sign()` changed by
  P5.4 and failed for every hub request; the hub keeps its own contract (hex HMAC-SHA256 of
  `"{timestamp}.{body}"`, `CollaborationCallResolver::signature()`).
