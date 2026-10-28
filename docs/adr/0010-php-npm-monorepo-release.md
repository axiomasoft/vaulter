# ADR-0010 — PHP + npm monorepo & release

**Status:** accepted

## Context

Vaulter ships both PHP packages (`vaulter-core`, `-media`, `-drive`, `-documents`,
`-filament`) and npm packages (`@vaulter/vue`, later `@vaulter/client`) from one
repository. The two ecosystems version and publish on different cadences and to
different registries (Packagist vs npm), so a single release flow cannot serve both.

## Decision

**One git repo, two package managers, two release channels** (master plan §3 / D15).

- **PHP — path-repos locally, canonical prepared split on publish.** Composer reads each
  `packages/<name>/composer.json` as a path repo for development. On a pushed tag,
  `.github/workflows/monorepo-split.yml` runs ONE `scripts/release/build-artifacts.php` prepare
  from the tagged commit (manifest and package bytes from the same commit); the ZIP, the split
  tree and the split commit all derive from that prepared record. The publisher only verifies the
  record, requires the external approved qualification of that exact record (no approval, no
  credential read), and pushes the prepared commit to the read-only
  `axioma-studio/vaulter-<name>` repo atomically and WITHOUT force: an existing branch or tag that
  is not already the prepared commit refuses. The prepared commit is parentless, so publishing
  onto an existing history stays refused until a history-aware parent derivation is qualified.
  Packagist is registered against the **split** repos. The earlier subtree-split action
  (`danharrin/monorepo-split-github-action`) is no longer used.
- **npm — workspaces locally, Changesets on publish.** Root `package.json`
  workspaces build in place. `.changeset/` drives versioning; `release-npm.yml`
  runs `changesets/action` on push to `main`, opening a "Version Packages" PR when
  changesets exist and publishing the bumped packages with an npm registry token
  after that PR merges. npm provenance stays disabled while the source repository
  is private because npm cannot generate public provenance from a private source. The
  front-end packages are a `linked` group (shared version ceiling, not lockstep);
  `dist/` is gitignored and built before publish.

## Consequences

- Each package publishes to its native registry with its native tooling; no
  forks, no "one package" confusion despite sharing a disk.
- The split repos and the `GH_ACCESS_TOKEN` secret must be created before
  `monorepo-split.yml` can run — it is authored but dormant until then.
- This is standard practice (Inertia/Livewire/Filament/Spatie ship `resources/js`
  the same way).
