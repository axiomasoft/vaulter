### OpenAPI 3.1 contract and contract tests for REST v1 (PLAN2.P6.3)

- Added: `openapi/vaulter-v1.yaml` — the OpenAPI 3.1 contract of the whole REST API v1 (81 operations; `operationId`
  is the route name without `vaulter.v1.`): parameters, `If-Match` (`428`/`412`) and `If-None-Match` (`304`) rules,
  cache and file-delivery headers, `application/problem+json` errors with their `code` values, a schema for every
  resource, and `openapi/examples/*.json` — one real response per resource (PLAN2.D64).
- Added: contract tests. Every `vaulter.v1.*` route has an operation and the other way round (same method and path);
  the `core`, `drive` and `documents` feature tests run with `VAULTER_OPENAPI_VALIDATE=1`, so every v1 response is
  validated for status, headers, media type and body with `opis/json-schema` (draft 2020-12; the adapter stops on a
  construct it cannot judge instead of skipping it); a golden `routes-v1.json` pins the route list.
- Added: `.github/workflows/openapi.yml` — `oasdiff breaking --fail-on ERR` against the contract of the last
  released tag (the base branch before the first release that carries the file), with a pinned `oasdiff` version.
- Fixed: `GET nodes/{node}?include=children` answered an empty `properties` of a child as `[]`; it is `{}`, as in
  every other node (found by the response validation).
- Docs: `reference/rest-api` (en, ru) explains the contract, the checks and the compatibility policy of `v1`;
  CONTRIBUTING describes how to change the contract.

