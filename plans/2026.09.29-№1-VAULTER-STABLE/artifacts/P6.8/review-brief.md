# Read-only review brief — PLAN2.P6.8 (documents routes on REST v1, hub endpoints, removal of the unversioned HTTP surface)

You are an independent, read-only reviewer. Do not modify any file (the sandbox refuses writes to the
repository). Inspect, run read-only commands and tests, report findings, stop.

## What to review

The uncommitted working tree of `/home/vostrikov/projects/packages/vaulter` against `HEAD` (a3febca3).
Ignore everything under `plans/` except the artifacts named below (owner work in `plans/**/views/` is not
part of this item). New files are untracked; renames/deletes are staged.

    git status --short -- packages docs UPGRADE.md CHANGELOG.md
    git diff HEAD -- packages docs UPGRADE.md CHANGELOG.md        # tracked changes
    # untracked: packages/documents/src/Http/Controllers/**, new Requests/Resources, packages/documents/tests/Http/**,
    # packages/documents/tests/Arch/HttpV1ArchTest.php, packages/core/tests/Http/LegacyRoutesRemovedTest.php

Item contract (JSON, source of truth): `plans/2026.09.29-№1-VAULTER-STABLE/phases/P6/P6.8.json`.
Normative design: `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-design.json` sections `Routes` (DOCUMENTS and
Hub), `Validators`, `Egress`, `Resources`, `Hub`; invariants and interfering pairs:
`plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-invariants.json`. Executor notes and recorded deviations:
`plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` (entries for P6.8) and `artifacts/P6.8/check-profile.json`.

## Substantive changes (review closely)

- `packages/documents/routes/documents.php` — documents/revisions/comments/session routes as `vaulter.v1.*` on the
  authenticated stack; the hub stack (`throttle:vaulter-public` + `ResolveCollaborationCall`) only while
  collaboration is enabled; no binding middleware.
- `packages/documents/src/Http/Controllers/**` — invokable controllers; `DocumentNodeController`,
  `NodeCommentController`, `NodeCollaborationController` deleted; hub logic moved unchanged (protocol D36) into
  `HubAuthenticateController`, `HubLoadController`, `HubStoreController`.
- `packages/documents/src/Http/Resources/DocumentResponses.php` — the If-Match flow for content: View + document kind
  first, then Edit ability (403), then `Preconditions::matchDocument` against `"{node}.{epoch}.{stateVersion}"`; the
  matched state version is handed to `DocumentOperations::update/restore` as `expectedStateVersion` (CAS).
- `DocumentContentResource` (tag independent of caller), `DocumentRevisionResource`, `CommentResource`,
  `CollaborationSessionResource`; requests under `packages/documents/src/Http/Requests`.
- `packages/core`: `ContentResponder::export()`, `HttpOperationContextFactory::withinDrive()`, removal of
  `VaulterException::render()`, `ResolveVaulterRouteParameters`, `HttpResourceResolver::storage()/node()`,
  `LegacyNodeResource`; `VaulterHttpOperationMap`/`VaulterThrottledRouteMap` by route name;
  `RecordVaulterRequestMetrics` (name-only); `HttpRegistrar` priority line removed.
- `packages/documents/src/Http/Middleware/ResolveCollaborationCall.php` — an unsigned call now throws
  `AuthenticationException` (rendered 401 `actor_required` problem+json).

Mechanical (skim): rewritten documents/core/drive tests that called removed paths, golden snapshots
(`packages/core/tests/Arch/__snapshots__/*`), docs (`docs/reference/rest-api.md`, `docs/guide/{documents,collaboration,comments,export}.md` +
`docs/ru/**`), `UPGRADE.md`, `CHANGELOG.md`.

## Risky seams to probe

1. Lost update: `PUT documents/{node}/content` and `POST …/revisions/{revision}/restore` must 428 without/with `*`
   If-Match, 412 `document_state_mismatch` for a stale or weak tag (also after a hub store between read and write),
   pass the matched state version to the operation, and change nothing on 412. Check the 403-before-428 order and that
   an invisible document is 404 first.
2. Authorization order (P6-I1/I2): no 304/412/428/stream before authentication, tenant resolution and View; unknown vs
   invisible document bodies identical except `request_id`; `documents/{id}` of a non-document kind.
3. The content ETag is caller-independent; reader and writer bodies equal; export headers (attachment, nosniff, CSP
   sandbox, no-store) and that the export Content-Type being the exporter's own mime is safe.
4. Hub (P6-I10): routes only on the hub stack, never on the authenticated one; `ResolveCollaborationCall` failure
   path; 409 `collaboration_epoch_mismatch` terminal; ordering of the manual body validation in `HubStoreController`
   after `open()`; read-only token cannot store.
5. Feature flags (comments off → 403 `profile_restriction` on a visible node, 404 on an invisible one; collaboration
   off → session 409 `collaboration_unavailable`, hub routes absent).
6. Removals: nothing left under the prefix without a `vaulter.v1.*` name; `load/` still calls
   `VaulterHttpOperationMap::resolve()` / `VaulterThrottledRouteMap::resolve(Request)` — verify it does not break.
7. Anything in the diff that contradicts the design or invariants without a recorded deviation, and any
   correctness/concurrency/security bug.

## How to report

For every finding give: severity (blocker/major/minor), file:line, what is wrong, and a reproduction — a failing test or
a command with its output. A finding without a reproduction is at most plausible. Run only light checks from
`packages/documents` / `packages/drive` / repo root:

    env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <paths>

Finish with a verdict line: `VERDICT: GREEN` (no confirmed blocker/major) or `VERDICT: FINDINGS`.
