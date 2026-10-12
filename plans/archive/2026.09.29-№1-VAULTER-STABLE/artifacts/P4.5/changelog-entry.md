# CHANGELOG [Unreleased] entry (applied to CHANGELOG.md in this item)

### Collaboration: epoch rooms, server-side loading, storage fence, read-only (PLAN2.D36; PLAN2.P4.5)

- Fixed: restoring a document while a collaboration room is open is no longer overwritten by the
  hub's next debounced store. Rooms are named `<prefix>.<driveId>.<nodeId>.<epoch>`; a store of a
  past generation is refused under the document lock with `409 collaboration_epoch_mismatch`, and
  the hub closes the room with close code `4409` `epoch_changed` instead of retrying.
- Added `POST collab/load` and server-side loading in `hocuspocus-server`: the Yjs working state,
  or a one-time seed from JSON built with `@hocuspocus/transformer` and stored conditionally
  (`409 collaboration_seed_conflict` for a second seed). Clients no longer seed.
- Changed hub calls to require the room JWT and the HMAC body signature (with a nonce); the shared
  secret is no longer an identity. Every call re-checks trash, tenant, drive status and current
  rights; read-only is enforced by the hub and by the backend (`403`).
- Changed `CollaborationSession` and its JSON to `room, node_id, drive_id, epoch, schema_version,
  mode, token, url`; `collaborationSession()` checks `collaboration.enabled`, `SupportsCollaboration`
  (`409 collaboration_unavailable`) and the client's schema version (read-only otherwise).
- Added `vaulter-documents.collaboration.max_state_bytes` (`413 document_state_too_large`),
  `DocumentsConfig::parseRoom()`, `DocumentRestored` and the `hub.token_ttl` ≤ 3600 check.
- Changed `@vaulter/vue` to recreate the provider and the `Y.Doc` on `epoch_changed`, renew the
  session on token expiry, open newer schemas read-only (`schema-outdated`) and pass no `content`
  to a collaborative editor.
- Removed the Reverb collaboration driver remnants: `driver = reverb` is an
  `InvalidConfigurationException`; the Vue Reverb bridge is gone.
