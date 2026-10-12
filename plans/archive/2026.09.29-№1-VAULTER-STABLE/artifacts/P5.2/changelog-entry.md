### Closed event catalogue 1.0 with EventSpec (PLAN2.P5.2)

- Added: `#[Vaulter\Attributes\EventSpec]` on every `EventType` case (subject, the keys and types of
  `data`, activity/webhook/journal flags, description) as the single source of the sink matrix;
  `EventType::spec()` and `journalOperation()`. `activityTypes()`, `webhookTypes()`, `isActivity()` and
  `isWebhook()` read it.
- Added: events `node.updated` (`Vaulter\Drive\Events\Nodes\NodeUpdated`), `access.inheritance_changed`
  (`NodeInheritanceChanged`), `share.updated` (`NodeShareUpdated`, `data.changed` lists field names only)
  and `share.rotated` (`NodeShareRotated`); `file.revision_deleted` is covered by conformance tests.
  No envelope carries a share token, its hash or prefix.
- Added: `php artisan vaulter:events:reference [--check]` generates the catalogue table of
  `docs/reference/events.md` (en/ru) from the specs.
