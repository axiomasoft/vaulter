# Read-only review brief — PLAN2.P6.7 (drive routes on REST v1)

You are an independent, read-only reviewer. Do not modify any file (the sandbox refuses writes to the
repository). Inspect, run read-only commands and tests, report findings, stop.

## What to review

The uncommitted working tree of `/home/vostrikov/projects/packages/vaulter` against `HEAD` (3e62c6be).
Ignore everything under `plans/` except the artifacts named below (owner work in `plans/**/views/` is not
part of this item).

    git status --short -- packages docs UPGRADE.md CHANGELOG.md
    git diff HEAD -- packages docs UPGRADE.md CHANGELOG.md

Item contract (JSON, source of truth): `plans/2026.09.29-№1-VAULTER-STABLE/phases/P6/P6.7.json`.
Normative design: `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-design.json` sections `Routes` (DRIVE and
Uploads tables), `Validators`, `Egress`, `Resources`; invariants and interfering pairs:
`plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-invariants.json`. Executor notes and recorded deviations:
`plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` (entries for P6.7) and `artifacts/P6.7/check-profile.json`.

## Substantive changes (review closely)

- `packages/drive/routes/drive.php` — all drive routes as `vaulter.v1.*` on the authenticated stack; uploads
  under `throttle:vaulter-uploads`; no binding middleware.
- `packages/drive/src/Http/Controllers/**` — invokable controllers per the design table; `NodeController` and
  `DownloadController` deleted; `Uploads/CreateUploadController` dispatches to `TusProtocolController::create`
  / `S3MultipartProtocolController::create`; the protocol controllers resolve a session by upload id → its
  drive through `HttpResourceResolver` → `UploadOperations::status()`.
- `packages/drive/src/Http/Resources/NodeResponses.php` — the If-Match flow: `current()` finds the node as the
  caller (trashed nodes through `NodeLocator` + `NodeSnapshotMapper` + `abilitiesMany`), `expectedSequence()`
  compares If-Match with that representation's tag and returns the sequence handed to the operation's CAS.
- `packages/drive/src/Http/Resources/{DriveResource,FileRevisionResource,Uploads/UploadSessionResource}.php`,
  `packages/drive/src/Http/Requests/**`.
- PHP API: `TrashOperations::purge(+?int $expectedSequence)`, `RestoreOptions::$expectedSequence`,
  `FileOperations::restoreRevision(+?int $expectedSequence)` (core contracts + drive implementations),
  `RestoreNodeAction` asserts the expectation on the locked row; `RestoreNodeInput` carries it.
- `packages/drive/src/Operations/UploadOperations.php` — resumable feature gate (403 profile_restriction,
  visible target first on initiate).
- Core adjacent edits: `Http/Middleware/VaulterHttpOperationMap.php`, `VaulterThrottledRouteMap.php`,
  `Http/Problems/ProblemRenderer.php` (Tus-Resumable/Tus-Version pass through),
  `Http/Support/HttpOperationContextFactory.php` (`onDrive` maps a drive deleted after resolution).

Mechanical (skim): the rewritten drive/core tests that called the removed unversioned paths, golden snapshots,
docs (`docs/reference/rest-api.md`, `docs/reference/concurrency.md`, `docs/guide/{drive,uploads,trash,search-trash,quick-start,architecture}.md` and `docs/ru/**` mirrors), `UPGRADE.md`, `CHANGELOG.md`.

## Risky seams to probe

1. Lost update: every IM route (rename, properties, move, trash, restore, purge, revisions.store,
   revisions.restore, uploads.store with node_id) must 428 without/with `*` If-Match, 412 on a stale tag, pass
   the matched sequence to the operation, and change nothing on 412 (purge: no rows/bytes/quota touched).
2. Authorization order (P6-I1/I2): nothing — 304, 412, 428, stream, 302 — before authentication, tenant
   resolution and View; unknown vs invisible node/drive bodies identical except `request_id`.
3. Representation tag consistency: the tag a client gets from `nodes.show`, from list items (including the trash
   listing for trashed nodes) and from mutation responses must equal what `expectedSequence()` recomputes.
4. Egress: downloads only through `ContentResponder`; previewer 403 and quarantine 423 before any byte, in
   `strict` and `direct_signed`.
5. Uploads: tus/S3 protocol headers kept; a foreign-tenant session not reachable; the feature gate does not
   reveal ids to a caller without View.
6. Restore/purge expectation is checked before side effects and does not alter the lock order.
7. Anything in the diff that contradicts the design or invariants without a recorded deviation.

## Checks you may run (read-only, SQLite in memory)

    cd packages/drive && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Http
    cd packages/drive && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci <file or --filter>
    env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Http   # from the repo root

Do not run PostgreSQL/MySQL, full suites, mutation tools or anything that needs network.

## Report

Start with a one-line verdict: `VERDICT: PASS` or `VERDICT: FINDINGS`. Then, per finding: severity
(blocker / major / minor), file:line, what is wrong, and a **reproduction** — a test you ran or a command and
its output. A finding without a reproduction is at most "plausible"; say so. Keep style nits out unless they
hide a defect.
