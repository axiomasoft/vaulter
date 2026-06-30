# Changesets

This folder powers the **npm** release channel of the Vaulter monorepo
(`@vaulter/vue`, and later `@vaulter/client`). The **PHP** packages are released
separately via `monorepo-split.yml` — changesets do not touch them.

## Adding a changeset

When a change to a published npm package should ship in the next release, run:

```bash
npx changeset
```

Pick the affected package(s) and the semver bump (patch / minor / major), then
write a one-line summary. This creates a markdown file under `.changeset/` —
**commit it with your change**. The summary becomes the CHANGELOG entry.

## How a release happens

- `.github/workflows/release-npm.yml` runs on push to `main`.
- When pending changesets exist, `changesets/action` opens (or updates) a
  **"Version Packages"** PR that consumes the changesets, bumps versions and
  rewrites CHANGELOGs.
- Merging that PR triggers the same workflow, which now `npm publish`-es the
  bumped packages (with OIDC provenance) and tags them.

## Conventions

- `linked: [["@vaulter/vue", "@vaulter/client"]]` — the two front-end packages
  share a version **ceiling**: bumping one to `X.Y.Z` aligns the other's number,
  but they are **not** forced to publish in lockstep (that would be `fixed`).
- `baseBranch: main` — the comparison branch for detecting changed packages.
- `@vaulter/hocuspocus-server` is `private` and `ignore`d — it ships as a Docker
  image (`ghcr.io/axioma-studio/vaulter-hocuspocus`), never to npm.
- No changeset = no release. The publish step is gated on pending changesets.

See <https://github.com/changesets/changesets> for the full docs.
