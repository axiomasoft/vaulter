# ADR-0001 — Collaboration transport

**Status:** accepted

## Context

The original DocVault spec assumed a standalone Node **Hocuspocus** server. The first
consumer (FlexCRM, CoreX-based) instead runs **Yjs + y-websocket over Laravel Reverb** — no
extra service to deploy. Forcing either choice on every host would hurt adoption.

## Decision

Abstract the transport behind a `CollaborationDriver` contract with two implementations:

- `ReverbCollaborationDriver` (**default**) — Yjs over Laravel Reverb; authorization via a
  private broadcast channel that delegates to the host `PermissionResolver`. No Node service.
- `HocuspocusCollaborationDriver` (**optional**) — targets an external Node hub; mints room
  JWTs via `firebase/php-jwt`.

The driver is selected through `config('docvault.collaboration.driver')` and resolved by
`DocVaultManager::driver()`.

## Consequences

- Pure-Laravel hosts get real-time editing with zero extra infrastructure.
- Teams that want a dedicated hub opt into Hocuspocus without touching application code.
- The full Yjs runtime (presence, snapshot persistence, offline merge) is layered on top of
  this abstraction in a later milestone.
