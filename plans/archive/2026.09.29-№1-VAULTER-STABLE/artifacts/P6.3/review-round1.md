VERDICT: FINDINGS

No blocker. The OpenAPI suite and the core HTTP suite pass with validation on. The gaps below are places where a response can break a P6-design promise and the adapter still accepts it, plus one workflow quoting bug.

`packages/core/tests/OpenApi`: 38 passed, 1 skipped (the examples rewrite, which stays skipped unless `VAULTER_OPENAPI_EXAMPLES_WRITE=1`), exit 0. `packages/core/tests/Http`: 150 passed, exit 0. `packages/drive/tests/Http` (123 failed, 13 passed) and `packages/documents/tests/Http` (41 failed, 0 assertions) died in this sandbox on `Permission denied` writing Testbench’s `storage/logs` and disk directories under `vendor/orchestra`. Neither log contains `breaks the OpenAPI contract`.

## Minor

**1. Public share timestamps are unconstrained strings.** `openapi/vaulter-v1.yaml:4321` and `:4325` (`PublicNode.created_at`, `updated_at`) have `type: [string, "null"]` and no `format`. Every other `*_at` field in the document is `format: date-time`. P6-design#OpenAPI requires that for times. `SharedNodeResource` emits Carbon values, so today’s responses happen to be ISO strings, and a garbage string still validates.

Reproduction: `OpenApiResponseValidator` on `public-shares.show` / 200 with `created_at: "not-a-date"`, no `Referrer-Policy`, and the three required headers (`content-type`, `x-vaulter-api-version`, `x-request-id`, `cache-control: no-store, private`) returned no violations.

**2. `format: date-time` accepts a timestamp with no offset.** Opis’s pattern marks the offset optional (`vendor/opis/json-schema/src/Formats/DateTimeFormats.php:24`). P6-design#Validators requires RFC 3339 UTC with `Z`. `SnapshotValue::iso` emits that form, so current bodies pass, and a value that drops the zone still passes. Fixture `10-malformed-timestamp.json` only substitutes `"yesterday"`, which fails the pattern for a different reason.

Reproduction: `DateTimeFormats::dateTime("2026-10-11T04:35:03")` is true. The same call on `2026-10-11T04:35:03.000000Z` is true, on `2026-02-31T00:00:00Z` is false, and on `yesterday` is false. The node example with `data.created_at` set to `2026-10-11T04:35:03` produced no validator violations.

**3. HEAD and any streamed response skip the body.** `packages/core/tests/Support/OpenApi/ValidateOpenApiResponses.php:43-49` passes `inspectBody: false` for `HEAD` and for `StreamedResponse` / `BinaryFileResponse`. Downloads are streams, so skipping their bytes is what the octet-stream schema needs. The same switch also accepts a JSON operation whose body does not match the schema.

Reproduction: a `vaulter.v1.nodes.show` response `{"data":{"id":1}}` with valid `Content-Type`, `X-Vaulter-Api-Version`, `X-Request-Id`, and `Cache-Control` headers. `terminate()` threw `AssertionFailedError` for GET. The same call with method `HEAD`, and again with a `StreamedResponse`, returned without throwing. No current JSON controller returns `StreamedResponse` (`ContentResponder::stream` is the only production stream).

**4. The probe exemption is a raw prefix.** `ValidateOpenApiResponses.php:24` and `:34` skip every route name that begins with `vaulter.v1.__probe`. That covers the real fixtures `vaulter.v1.__probe` and `vaulter.v1.__probe.node.show`. It also covers any other name with that prefix, so those responses are never checked. `DomainExceptionStatusTest` walks the catalog through `vaulter.v1.__probe`, so those problem bodies are outside the contract run.

Reproduction: the same invalid `nodes.show` body as above. `terminate()` did not throw for `vaulter.v1.__probe`, `vaulter.v1.__probe.node.show`, or `vaulter.v1.__probeNOT`. It threw for `vaulter.v1.nodes.__probe`.

**5. A leaked internal id passes, and a problem’s `status` is not tied to the HTTP status.** `Node` (`openapi/vaulter-v1.yaml:4139`) does not set `additionalProperties: false`. P6-design#Resources says `file_id` is not part of the representation. `Problem.status` (`:3878`) is only `type: integer`.

Reproduction: the committed `node` example plus `data.file_id = "internal-pointer"` produced no violations. `problem-412` with HTTP status 412 and body `status: 500`, `code: "not_a_catalog_code"` produced no violations. (`code` is `examples`, which JSON Schema does not enforce. The document test does check that the example list equals `ProblemCatalog`.)

**6. Two headers the design requires are not enforced, and `500` is never an operation response.** `ApplyResponseCachePolicy.php:55` sets `Vary: Authorization, Cookie` on an ETag response. P6-design#Validators requires that pair. `nodes.show` 200 (`openapi/vaulter-v1.yaml:1459-1467`) does not list `Vary`. `Referrer-Policy` (`:3579`) has no `required: true`, while public-share responses are specified to send `no-referrer`. `Problem500` is declared at `:3848` and no operation references status `500`, which `ProblemRenderer` can emit for any other throwable.

Reproduction: the node example with no `Vary` header produced no violations. The public-share body in finding 1, sent without `Referrer-Policy`, produced no violations. `grep` of `'500':` in `openapi/vaulter-v1.yaml` has no matches.

**7. `github.base_ref` is interpolated into the shell script.** `.github/workflows/openapi.yml:38-39` places `${{ github.base_ref }}` inside double quotes in the `run` script. Tag selection itself is consistent with the item: `fetch-depth: 0` fetches `refs/tags/*`, the only local tag is `v0.2.0`, and `git cat-file -e v0.2.0:openapi/vaulter-v1.yaml` fails, so a pull request falls through to `origin/<base>`. `--fail-on ERR` is the oasdiff flag that fails the job on ERR. The mounts (`/base/vaulter-v1.yaml`, `/w/openapi/vaulter-v1.yaml`) match the two `docker -v` sources.

Reproduction, same expansion Actions performs:

```bash
base_ref='feat"; echo INJECTED; echo "'
bash -c "if [ \"pull_request\" = \"pull_request\" ]; then source=\"origin/${base_ref}\"; echo \"source=[\$source]\"; fi"
```

The script printed `INJECTED` and then `source=[origin/feat]`. A pull request’s base branch is chosen in this repository. The practical case is a branch name that contains `"`.

**8. `CollaborationSession.url` is required `string` while the resource emits `null`.** Schema `openapi/vaulter-v1.yaml:4909` and `:4931`. `DocumentsConfig::hubUrl()` (`packages/documents/src/Configuration/DocumentsConfig.php:206`) returns `null` for an empty value, and `CollaborationSession::toArray()` always writes that value (`packages/documents/src/Data/Collaboration/CollaborationSession.php:61`). The default config is `ws://localhost:1234`, so the usual response is a string.

Reproduction: `documents.collaboration.session` / 201 with `url: null` and otherwise valid session fields returned `body /data/url: The data (null) must match the type: string`.

**9. Two resource shapes disagree with P6-design#Resources, with no log deviation.** `log.jsonl` records the `getData()` fix and the `symfony/yaml` / `composer.lock` notes. It does not mention these:

- `Drive.archived_at` is in the design. `DriveResource::toArray()` (`packages/drive/src/Http/Resources/DriveResource.php:34-43`) and the `Drive` schema (`openapi/vaulter-v1.yaml:4337`) omit it.
- The design’s change entry is `seq, operation, reason, node_id, node`. `ChangeResource::toArray()` (`packages/core/src/Http/Resources/Changes/ChangeResource.php:37-44`) and the `Change` schema (`openapi/vaulter-v1.yaml:4662`) use `node_sequence` and `subtree` and have no `seq`.

The document matches those resources. The item rule for a code/design clash is to stop and record a deviation.

## Checked, and consistent with the code

`If-Match` required plus `412` and `428` is exactly the ten IM operations in P6-design#Routes. `uploads.store` uses optional `IfMatchWhenReplacing` and still declares `412` and `428`. `304` with no `content` exists only on `nodes.show` and `documents.content.show`. The four byte operations declare `application/octet-stream`, `Content-Disposition`, and `Cache-Control`. Symfony’s header bag rewrites `private, no-cache` to `no-cache, private` and `no-store` to `no-store, private`, which matches the consts. `properties: []` fails `type: object`. `oneOf` with `null`, `allOf` across a `$ref`, a `304` body, a charset on `Content-Type`, and integer header coercion all behave as the tests claim. Route coverage strips the Laravel `HEAD` that rides along with `GET` and keeps a HEAD-only route. That suite passed, including the 81-operation equality. `UPDATE_GOLDEN=1` and `VAULTER_OPENAPI_EXAMPLES_WRITE=1` rewrite tracked files. `phpunit.xml` does not set either variable, and the write test is skipped in a normal run. The `ShowNodeController` `getData()` change is the recorded deviation. `getData(true)` is what turned an empty object into `[]`.

---
Reviewer session: grok-4.7 (init event of review-grok.jsonl), session 01a1294c-a490-7440-874d-7823bf11f1c3, contextWindowTokens 500000 (signals.json), effort high requested in the launch argv (review-launch.json). Duration 1693 s, 67 turns. Read-only through ro-run.py.

## Executor dispositions (each finding re-run or re-read by the executor)
1. CONFIRMED, fixed: PublicNode created_at/updated_at now date-time.
2. CONFIRMED, fixed: every date-time carries pattern `Z$` (RFC 3339 UTC); fixtures 12 added.
3. CONFIRMED, fixed: a streamed JSON response is a violation; HEAD/octet streams stay body-exempt.
4. CONFIRMED, fixed: probe exemption is exactly `vaulter.v1.__probe` or `vaulter.v1.__probe.*` (isProbe, tested).
5. PARTLY: Problem.status must equal the HTTP status (fixed, fixture 13). Node additionalProperties:false REJECTED: the compatibility policy says clients ignore unknown fields and additive fields keep v1; a closed schema would also make every added field an oasdiff break. Leak protection stays with the exact-key tests of the resources.
6. CONFIRMED, fixed: Vary required on ETag GET 200 and 304 (optional on nodes.show with include), Referrer-Policy required on public share responses (fixture 14), 500 declared on every operation.
7. CONFIRMED, fixed: base_ref and event name go through env, not interpolated into the script.
8. CONFIRMED, fixed: CollaborationSession.url nullable.
9. CONFIRMED, NOT fixed (out of item scope, code vs P6-design): Drive.archived_at and Change.seq are in P6-design#Resources but not in DriveResource/ChangeResource (P6.7/P6.1 code); the document follows the code. Recorded as a deviation for the owner.
Re-run after the correction: V1 193 passed, V2 625+, V3 290+, V80 green.
