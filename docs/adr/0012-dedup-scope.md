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

## Amendment (VLT-REMED P2.3 / F-P1.5-03 / D18, 2026-07-11)

`unique(tenant_id, sha256)` was chosen as "the" race backbone without considering
NULL-tenant semantics of a UNIQUE index: for `scope=tenant` with `tenant_id=NULL`
(a single-tenant host, or tenancy simply not enabled — the common default), SQL
never treats two NULLs as equal, so the index does **not** converge concurrent
identical uploads — two committers of the same bytes can both pass the INSERT.

No functional loss results: `v_blobs.storage_key` (`<scope>/ab/cd/<sha256>`,
ADR-0012 "Scope-qualified storage key") carries its **own** single-column UNIQUE
(`storage_key_uniq`) that is NULL-free by construction (the scope segment is the
literal string `tnull` when the tenant is NULL, never SQL NULL), and this is what
actually makes NULL-tenant CAS commits converge on one row today.

**Decision:** document `storage_key_uniq` as the load-bearing backbone for the
NULL-tenant case, and protect it with a schema/arch-guard test (single-column,
never composed with `disk` or anything else — composing it would break the
DISK-AGNOSTIC probe `BlobStoreRepository::findByStorageKey()` relies on). The
dedup-unique index remains as specified for every NON-NULL scope value (it is
still the backbone there — the true multi-tenant, multi-storage case the ADR's
main text is about). **Rejected:** storing a non-NULL sentinel (e.g. `0`) in
`tenant_id` when tenancy is disabled — mutates a column the host's own model may
interpret, and exceeds a Minor-severity fix (see VLT-REMED plan.md D18).
