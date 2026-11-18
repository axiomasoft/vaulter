# Vaulter metapackage

`axiomasoft/vaulter` is the MIT-licensed public metapackage for an embeddable Laravel
file drive and documents. It requires these public OSS modules at `self.version`:

- `axiomasoft/vaulter-core` — kernel, operations, events and outbox.
- `axiomasoft/vaulter-media` — content-addressed storage.
- `axiomasoft/vaulter-drive` — files, folders, uploads and access.
- `axiomasoft/vaulter-documents` — documents, versions, comments and export.

Public PHP packages share a version line; their `dev-main` aliases are `1.0.x-dev`.
Webhooks, AzGuard and Filament are optional public packages installed separately;
the AzGuard bridge is excluded from release artifacts until its upstream has a stable tag.
`@vaulter/vue` is the public browser client.

Realtime co-editing is available as a commercial add-on with independent release lines.

Package-local path repositories and `minimum-stability: dev` support monorepo development;
Composer ignores these root-only settings when the package is installed as a dependency.
