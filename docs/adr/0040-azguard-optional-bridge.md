# ADR-0040 — AzGuard optional bridge retention and release exclusion

Status: Accepted. Supplements [ADR-0035](/adr/0035-product-boundary-and-release-channels) and
[ADR-0039](/adr/0039-native-packman-extensions).

## Context

`packages/azguard` (`axiomasoft/vaulter-azguard`) adapts AzGuard 2.x panel and assignment-scope
authorization into Vaulter's `PermissionDriver` contract without pulling AzGuard dependencies into
`vaulter-core` or `vaulter-drive`. Because `axiomasoft/azguard` is still pinned to `^2.0@dev`
while its upstream stabilization completes, there is a recurring question of whether
`packages/azguard/` should be deleted from the monorepo or explicitly retained as an optional
channel-gated package.

## Decision

Keep `packages/azguard` in the Vaulter monorepo as a documented **optional integration bridge** and
retain its release exclusion gate until upstream stability is established:

1. `release/packages.json` explicitly declares `"requires_tag": "axiomasoft/azguard"` and
   `"excluded_unless_tagged": true` on `axiomasoft/vaulter-azguard`, so release artifact builders
   and tag verification (`scripts/release/check-versions.php`, `verify-split-tags.sh`) exclude
   `vaulter-azguard` from Packagist split publishing while `^2.0@dev` remains unresolved.
2. Neither the `axiomasoft/vaulter` metapackage nor any public core/drive/media/documents package may
   require `axiomasoft/vaulter-azguard`.
3. CI continues testing `packages/azguard` in the `azguard` zone (with the pinned upstream commit in
   `scripts/ci/checkout-ecosystem.sh`) so the bridge does not bit-rot prior to upstream tagging.
4. Once `axiomasoft/azguard` publishes a stable `^2.0` tag, replace `"^2.0@dev"` with `"^2.0"` in
   `packages/azguard/composer.json` and release `axiomasoft/vaulter-azguard` through the standard
   split matrix; if AzGuard integration is retired instead, removal requires deleting only
   `packages/azguard/` plus its manifest, test-zone, and CI matrix entries.

## Consequences

- `packages/azguard/` stays clean, tested, and installable for hosts using `VAULTER_PERMISSION_DRIVER=azguard`
  from a repository/path source without leaking a `@dev` constraint into public Packagist releases.
- Hosts not using AzGuard never download or depend on `vaulter-azguard`, and `vaulter:doctor` keeps
  verifying `azguard.dependency` only when the bridge is installed.
