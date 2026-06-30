# ADR-0008 — Media Library: custom CAS, not spatie/laravel-medialibrary

**Status:** accepted

## Context

`spatie/laravel-medialibrary` is the obvious off-the-shelf file layer for Laravel.
We evaluated building Vaulter's binary plane on top of it instead of writing a
content-addressed store from scratch.

## Decision

**Do not build on `spatie/laravel-medialibrary`.** Vaulter ships its own CAS
(`v_blobs` + `v_file_versions`, see ADR-0006). medialibrary is
collection-and-conversion-centric and binds each file to a single host model; a
Drive needs content-addressed dedup, ref-count GC, binary version history, and
per-file/per-folder ACL — a fundamentally different core.

An **optional input bridge** (`vaulter-medialibrary-bridge`) may import existing
spatie media into `v_nodes`/`v_blobs`. The bridge is purely additive — a host that
already uses medialibrary can migrate in — and depends on spatie only as a
`suggest`, never a hard dependency of core/media.

## Consequences

- Vaulter controls dedup, GC, versioning and ACL semantics end-to-end rather than
  fighting a model designed for a different problem.
- Hosts on medialibrary are not stranded — the bridge offers a migration path.
- We carry the cost of owning the CAS, justified by it being the product's core
  (and reusable standalone as `vaulter-media`).
