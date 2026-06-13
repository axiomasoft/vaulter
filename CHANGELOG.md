# Changelog

All notable changes to **DocVault** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial monorepo scaffold: `packages/core` (Laravel package), `packages/vue`
  (`@docvault/vue` components), `hocuspocus-server/` (collaboration server) and
  VitePress documentation under `docs/`.
- Development tooling: Pint, PHPStan (level 8) + Larastan, Rector, Pest + Orchestra
  Testbench, Infection, GitHub Actions CI and a `Makefile`.
- Core contracts (`PermissionResolver`, `StorageAdapter`), `DocVaultManager`, facade,
  config, model and migration skeletons (no business logic yet — see `docvault-spec.md`).

[Unreleased]: https://github.com/axioma-studio/doc-vault/commits/main
