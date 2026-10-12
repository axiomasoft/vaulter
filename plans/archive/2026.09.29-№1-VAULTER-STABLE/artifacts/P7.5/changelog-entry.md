### Release tooling: metapackage, dist archives and split tag checks (PLAN2.P7.5)

- Added: the `axioma-studio/vaulter` metapackage (`packages/vaulter`, own split repository) requiring core, media,
  drive and documents at `self.version`. `axioma-studio/vaulter-core` is now described as the kernel.
- Added: `.gitattributes` with `export-ignore` for `tests/`, `phpunit.xml`, `phpstan.neon`, `.claude/` and `vendor/` in
  every PHP package, so dist archives no longer carry tests.
- Added: `release/packages.json` and `scripts/release/` (`build-artifacts.php`, `consumer-smoke.sh`,
  `verify-split-tags.sh`, `check-versions.php`) with offline tests in `scripts/test-release-tools.py`.
- Added: the `verify-tags` job in `monorepo-split.yml` fails the release when a split repository lacks the tag or has
  it on another commit; the split matrix now includes `vaulter`.
- Added: `@vaulter/vue` declares `"vaulter": { "api": "1" }` in `package.json`.
- Changed: `vaulter-azgard` is excluded from the release until AzGuard is tagged; its `dev-main` require is unchanged.
