# Changelog

All notable changes to **DocVault** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial monorepo scaffold: `packages/core` (Laravel package), `packages/vue`
  (`@docvault/vue` components), `hocuspocus-server/` (collaboration server) and
  VitePress documentation under `docs/`.
- Development tooling: Pint, PHPStan (**level max**) + Larastan, Rector, Pest + Orchestra
  Testbench + type-coverage, Infection, GitHub Actions CI and a `Makefile`.
- **M1 core domain** (`packages/core`):
  - Enums with attribute metadata (`DocumentType`, `PermissionLevel`, `CollaborationDriverType`),
    value objects (`TipTapContent`, `WorkspaceRef`), DTOs, domain events and a typed exception
    hierarchy.
  - Final readonly Actions (documents, versions, folders, permissions, collaboration token) over
    read/store repositories with a `DocumentVisibilityService`.
  - Swappable models with dual key mode (`id` | `ulid`) and optional `tenant_id` via
    `config('docvault.models'|'id_type'|'tenant')`.
  - REST API (documents, versions, folders, permissions, collab token) with `JsonResource`
    responses that embed computed `abilities` (`AbilitiesDto`).
  - Collaboration driver abstraction: `ReverbCollaborationDriver` (default) and
    `HocuspocusCollaborationDriver` (optional), plus broadcast-channel authorization.
  - `PermissionResolver`, `StorageAdapter`, `CollaborationDriver` contracts; `DocVaultManager`
    + facade.
- Documentation: architecture, embedding, permission resolver, abilities, collaboration, error
  reference, and ADRs 0001–0004.

[Unreleased]: https://github.com/axioma-studio/doc-vault/commits/main
