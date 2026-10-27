# Vaulter metapackage

`axioma-studio/vaulter` is the MIT-licensed public metapackage for an embeddable Laravel
file drive and documents. It requires these public OSS modules at `self.version`:

- `axioma-studio/vaulter-core` — kernel, operations, events and outbox.
- `axioma-studio/vaulter-media` — content-addressed storage.
- `axioma-studio/vaulter-drive` — files, folders, uploads and access.
- `axioma-studio/vaulter-documents` — documents, versions, comments and export.

Public PHP packages share a version line; their `dev-main` aliases are `1.0.x-dev`.
Webhooks, AzGuard and Filament are optional public packages installed separately;
the AzGuard bridge is excluded from release artifacts until its upstream has a stable tag.
`@vaulter/vue` is the public browser client.

Realtime co-editing is a commercial add-on (`axioma-studio/vaulter-collaboration`).
It is distributed through a private PHP split and Private Packagist for Vendors, with
`proprietary` Composer metadata and public dependencies at `^1.0`. The associated
`@vaulter/vue-collaboration` package uses `UNLICENSED`, restricted npm access and no
provenance; the `UNLICENSED` hub is a private image-only workspace distributed through
private GHCR. These artifacts have independent release lines. The metapackage has no
require or suggest dependency on the commercial add-on.

The private LICENSE placeholders grant no license. Axioma Studio must supply the separate
commercial agreement and final legal text; third-party licenses remain applicable to
their respective dependencies.

Package-local path repositories and `minimum-stability: dev` support monorepo development;
Composer ignores these root-only settings when the package is installed as a dependency.
