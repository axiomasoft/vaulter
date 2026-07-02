# ADR-0010 — PHP + npm monorepo & release

**Status:** accepted

## Context

Vaulter ships both PHP packages (`vaulter-core`, `-media`, `-drive`, `-documents`,
`-filament`) and npm packages (`@vaulter/vue`, later `@vaulter/client`) from one
repository. The two ecosystems version and publish on different cadences and to
different registries (Packagist vs npm), so a single release flow cannot serve both.

## Decision

**One git repo, two package managers, two release channels** (master plan §3 / D15).

- **PHP — path-repos locally, subtree-split on publish.** Composer reads each
  `packages/<name>/composer.json` as a path repo for development. On a pushed tag,
  `.github/workflows/monorepo-split.yml` force-splits `packages/<name>` into a
  read-only `axioma-studio/vaulter-<name>` repo and propagates the tag; Packagist
  is registered against the **split** repos. This mirrors Filament's mechanics and
  pins `danharrin/monorepo-split-github-action` by commit (`# v2.4.5`).
- **npm — workspaces locally, Changesets on publish.** Root `package.json`
  workspaces build in place. `.changeset/` drives versioning; `release-npm.yml`
  runs `changesets/action` on push to `main`, opening a "Version Packages" PR when
  changesets exist and publishing the bumped packages **with OIDC provenance**
  (`id-token: write`, `NPM_CONFIG_PROVENANCE: true`) after that PR merges. The
  front-end packages are a `linked` group (shared version ceiling, not lockstep);
  `dist/` is gitignored and built before publish.

## Consequences

- Each package publishes to its native registry with its native tooling; no
  forks, no "one package" confusion despite sharing a disk.
- The split repos and the `GH_ACCESS_TOKEN` secret must be created before
  `monorepo-split.yml` can run — it is authored but dormant until then.
- This is standard practice (Inertia/Livewire/Filament/Spatie ship `resources/js`
  the same way).
