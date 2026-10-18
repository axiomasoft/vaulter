# Vaulter · Collaboration

`axioma-studio/vaulter-collaboration` is the real-time layer of Vaulter documents: the Hocuspocus/Yjs room
transport, signed room tokens (HS256 JWT), the session endpoint for the editor and the three server-to-server
endpoints the Hocuspocus hub calls (`authenticate`, `load`, `store`).

It depends on `axioma-studio/vaulter-core`, `axioma-studio/vaulter-drive` and `axioma-studio/vaulter-documents`
(`firebase/php-jwt` signs the tokens), is not part of the `axioma-studio/vaulter` metapackage, and is **opt-in**: it
opens a network channel and needs secrets, so the module is active only after `vaulter.modules.collaboration` is
set to `true`. Without it, documents are edited one at a time through the ordinary content endpoints.

## Install

```bash
composer require axioma-studio/vaulter-collaboration
```

```php
// config/vaulter.php
'modules' => ['collaboration' => true],
```

```dotenv
VAULTER_COLLAB_URL=wss://hub.example.com
VAULTER_COLLAB_SECRET=...          # signs the room tokens, Laravel only
VAULTER_COLLAB_WEBHOOK_SECRET=...  # shared with the hub; must differ from the signing secret
```

## What it owns

| Concern | Where |
|---|---|
| Config | `config/vaulter-collaboration.php`, publish tag `vaulter-collaboration-config` (driver, hub URL and secrets, token lifetime, state limit) |
| Routes | `routes/collaboration.php`: `POST documents/{node}/collaboration/session` and `POST collaboration/hub/{authenticate,load,store}`, behind `vaulter.module:collaboration` |
| Operations | `Vaulter\Collaboration\Operations\CollaborationOperations::session()` through `$drive->extension(...)` |
| Capability | provides `Vaulter\Documents\Contracts\CollaborationDriver` (the transport the documents module defines) |
| Problem codes | `collaboration_epoch_mismatch`, `collaboration_seed_conflict`, `collaboration_unavailable` |
| Doctor | `collaboration.hub` |

The documents package keeps the aggregate (`collab_epoch`, `state_version`, the binary state) and the SPI
(`CollaborationDriver`, `SupportsCollaboration`, `CollaborationSession`); this package never writes the documents
table itself.

## Development in the monorepo

The package lives in `packages/collaboration`. Its tests run on its own `TestCase`:

```bash
scripts/t sqlite -p collaboration
```
