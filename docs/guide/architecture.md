# Architecture

Vaulter is a layered, strictly-typed Laravel package. Everything the host application must
provide is expressed as a contract; everything Vaulter owns is final and typed.

```
Vue (@vaulter/vue) ──HTTP/JSON──► Controllers ─► FormRequests ─► DTOs
                    ──WebSocket──► CollaborationDriver (Reverb | Hocuspocus)
        │
   Actions (final readonly · transactional · dispatch Events)
        │
   Repositories (read / store split) ─► DocumentVisibilityService ─► PermissionResolver (host)
        │
   Models (swappable via config) ─► Database (id | ulid, optional tenant_id)
```

## Layers

- **Controllers / FormRequests / Resources** — thin HTTP edge. Requests validate and build
  DTOs; resources serialize models and embed computed `abilities`.
- **Actions** (`Vaulter\Actions`) — one use case each, `final readonly`, ≤5 dependencies.
  They open the transaction, enforce business rules with `ValidationException`, and dispatch
  domain events after commit.
- **Repositories** (`Vaulter\Repositories`) — read/store split. Reads flow through
  `DocumentVisibilityService`, which delegates to the host `PermissionResolver`. Stores never
  open transactions or dispatch events.
- **Models** — resolved exclusively through `Vaulter\Support\Config`, so a host app can swap
  any of them (e.g. a tenant-aware ULID base model).
- **Value Objects / DTOs / Enums** — `TipTapContent`, `WorkspaceRef`, `PermissionLevel`,
  `DocumentType` (with attribute metadata). All `final readonly`.

## Extension points

| Contract | Purpose |
|---|---|
| `PermissionResolver` | Delegate every authorization decision to the host. |
| `StorageAdapter` | Pluggable storage for binary attachments. |
| `CollaborationDriver` | Real-time transport (Reverb default, Hocuspocus optional). |
| `config('vaulter.models')` | Swap any Eloquent model. |
| `Vaulter::registerDocumentType()` | Add custom document types. |
| Domain `Events` | Hook notifications/audit without Vaulter depending on them. |

See the [ADRs](/adr/) for the reasoning behind these decisions.
