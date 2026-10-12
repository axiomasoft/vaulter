# P6.3 — pinned tools

| Tool | Pin | Where | Why |
|---|---|---|---|
| oasdiff (docker `tufin/oasdiff`) | `v1.33.0` | `.github/workflows/openapi.yml` (`OASDIFF_IMAGE`) | Latest stable release at execution time (GitHub release v1.33.0, 2026-10-01; Docker Hub tag `v1.33.0` exists). `latest`/`main`/`v1.33.1-rc.*` were not used: a floating or pre-release tag lets a new rule fail an unrelated PR. Not run locally (D66). |
| opis/json-schema | `^2.4` (locked 2.6.0) | root and package `composer.json` require-dev | Draft 2020-12 validator for OpenAPI 3.1 schemas (D64); league/openapi-psr7-validator covers 3.0 only. |
| symfony/yaml | `^8.0` (locked v8.1.8) | root and package `composer.json` require-dev | Major as in `composer.lock`; design text said `^7.0`, the lock already carries 8. |

Bump rule: change the pin, the workflow and this table in one commit, read the oasdiff release notes for new ERR-level rules first.
