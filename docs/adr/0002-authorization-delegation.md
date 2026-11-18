# ADR-0002 — Authorization delegation

**Status:** accepted

## Context

Vaulter must embed in apps with wildly different role systems (Laravel Gate, Spatie
Permission, AzGuard/Laravel entity-scoped RBAC). Shipping its own permission model would
conflict with the host's.

## Decision

Vaulter ships **no** authorization logic. The host implements a single contract,
`PermissionResolver` (`can($user, $action, $document)`, `canCreate($user, $type, $id)`), and
registers it via `Vaulter::resolvePermissionsWith()` or `config('vaulter.permission_resolver')`.

- Read visibility flows through `DocumentVisibilityService`, which calls the resolver.
- The API maps a denied action to `403` via `PermissionDeniedException`.
- Computed per-document permissions are shipped to the front-end as an `abilities` object
  (`AbilitiesDto`), so the UI renders only allowed actions.
- When no resolver is configured, access is open — intended for trusted/dev setups; production
  must bind a resolver.

## Consequences

- Zero coupling to any RBAC package; works with all of them.
- The host keeps a single source of truth for permissions.
- Vaulter stays focused on documents, not access control.

> **Refined by ADR-0007:** the single swappable `PermissionResolver` splits into a
> pluggable `HostRoleDriver` plus a Vaulter-owned `AccessResolver` over `v_node_grants`,
> and the **"open when unconfigured"** default is replaced by **fail-loud in production**
> (the open `NullRoleDriver` is env-gated to non-prod). The delegation principle stands.
