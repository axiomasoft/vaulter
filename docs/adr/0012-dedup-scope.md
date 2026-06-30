# ADR-0012 — Dedup scope

**Status:** accepted

## Context

Content-addressed dedup (ADR-0006) coalesces identical bytes onto one blob. In a
multi-tenant deployment, a global dedup namespace leaks the *existence* of a file
across tenants: uploading bytes that already exist elsewhere becomes observably
cheaper, a confused-deputy side channel.

## Decision

- **Per-tenant dedup by default.** The dedup-unique index is
  `unique(tenant_id, sha256)`; `global` and `storage` scopes are opt-in via
  `config('vaulter.dedup.scope')` (master plan §2.3 / D18, following Seafile's
  per-repo isolation).
- **Scope-qualified storage key.** The physical key is `<scope>/ab/cd/<sha256>`
  where `<scope>` is the dedup-scope value (`tenant_id` / `storage_id` / `'global'`),
  so two scopes never alias one physical object and one scope's GC can never
  delete another's live bytes (the §H.3 hardening).
- **Dedup never crosses an encryption boundary.** `dedup_scope ⊆` the encryption
  key scope, since plaintext-sha256 dedup must not span keys.

## Consequences

- No cross-tenant existence leak by default; operators opt into wider dedup
  consciously when the tenants trust each other.
- The dedup-unique index and the storage-key layout are driven by the same scope
  setting, keeping them consistent.
- Cross-scope GC safety is covered by a dedicated test (two scopes, identical
  bytes, deleting one preserves the other's object).
