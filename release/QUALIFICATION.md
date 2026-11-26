# Candidate qualification — PLAN4.P8.2

Owner-only runbook, NOT execution evidence. All future runtime steps below are **pending**.
Split/Packagist/npm/GHCR publication and `v1.0.0` require every applicable mandatory slot green on the same candidate tuple, followed by a separate explicit owner publication request. A mandatory skip, unavailable adapter or exit 2 means incomplete, never pass. PLAN4 D2 does not execute this runbook. No review in P8.2; original Grok HIGH batch review belongs to P8.5.

Historical PLAN3 architecture/release-gate store is unavailable. This is the current PLAN4 contract, not a literal reconstruction or evidence of historical runs. P8.1 produced operational documents; its notes explicitly leave actual installed runtime, owner A/T, registries, signatures and protection unqualified. Historical audit findings below are dispositions, not current runtime verdicts.

## Admission, variables and immutable evidence

Owner supplies a disposable isolated checkout `$S_ROOT` at **S = manifest.source_sha**, external evidence directory `$E`, approved read-only tool checkout `$T_ROOT` at `$T_SHA`, freeze metadata commit F separately, `$VERSION` per release line, and `$PUBLIC_OUT`. Never infer S from HEAD after freeze F. All paths are absolute and outside production; output directories must be new. No source edits during qualification. Before use, record actual PHP/Composer/Node/npm/DB/OS/tool versions and supported constraints. No credentials in logs.

Every step record (including pending steps) has: ID, cwd, exact argv/env, start/end, exit/status, observed duration, log hash and immutable output path; input tuple **K = (source_sha, split_tree, tgz_integrity, OCI_digest, policy_sha, resolution_sha)**. K is a typed map per package/release line/platform/lane: nonapplicable types explicitly N/A with reason; missing required type is pending. Record split_commit, normalized path/mode/content map, ZIP sha256, SBOM sha256, tool closure digest and F too. `policy_sha` includes separately named release-manifest and trusted rules/exceptions/approval hashes; `resolution_sha` identifies actual Composer/npm lock bytes and platform/profile, not an assumed solver result. Source-only approval is insufficient.

Evidence lives at `$E/<step-id>/<lane>/` with stdout.log, stderr.log, status.json, inputs.json and SHA256 manifest. Every row below inherits K and this output pattern; additional outputs are named in the row. Unknown timing means not measured. Retain each attempt immutably, never overwrite a failed attempt. A source/byte/policy/resolution change invalidates affected qualification: stop, commit revised sources if needed, refreeze S/F and rerun affected steps against new K; retain old evidence. DDL regeneration is a source change, not an invisible update to evidence.

Prerequisite Q-OWNER is required before dependent steps: owner provisions real A (authenticated external qualification approval), ToolT full approved closure (checker/helpers/schema/parser/autoload/runtime locks), protected base/environment rules and protected variables, registry credentials and actual private visibility, release-line permissions and legal license approval. CODEOWNERS or candidate JSON alone is not enforcement. These are **unprovisioned/unverified**, not fake external authority. Record authorized actor, enforcement evidence, exact S/policy/tool/resolution/artifact scope and approval expiry. Candidate extraction stays data-only with no secrets; privileged trusted jobs never execute candidate autoload/plugins.

## Exact inventory and single execution ownership

Each source requirement below has exactly one owning step. Gate rows reference owners; they do not schedule duplicate runs. Q-MATRIX owns all default supported engine cells, serial and extended cases; database/race paths listed below are coverage assertions within that single matrix, not additional full runs. Only missing runtime scenarios require additional fixture implementation and execution. No obsolete PLAN3 paths are runnable instructions.

| Source requirement | Unique owner or accepted exclusion |
|---|---|
| deferred-runs#from-plan3 (1) server database lines incl integration; (2) serial races; (3) full matrix; (5) I6/I15 clean installs | Q-MATRIX |
| from-plan3 (4) schema presets/rollback, server stale regeneration | Q-SCHEMA |
| from-plan3 (7) coverage | Q-COVERAGE |
| from-plan3 (8) each package isolation incl filament | Q-ISOLATION |
| from-plan3 (9) AzGuard stable external tag / RealAzGuard* | Q-AZGUARD |
| from-plan3 (9) Composer/npm vulnerability audits | Q-SCA |
| from-plan3 real resolver/HTTP SSRF corpus | Q-SSRF |
| from-plan3 (10), plan4-additions (18), audit §21.2 load/k6 budgets | Accepted exclusion D2/D12: separate owner task; no load authorization here |
| plan4-additions (12) public ZIP canonical prepare | Q-PREPARE |
| plan4-additions (12) installed exact archives incl metapackage and public dependencies | Q-CONSUMERS |
| plan4-additions (13) npm pack/install entrypoints | Q-NPM |
| plan4-additions (15) complete trusted BC surfaces | Q-BC |
| plan4-additions (16) complete CI candidate gate | Q-CI |
| plan4-additions (17) RC upgrade saved-state fixtures | Q-UPGRADE |
| plan4-additions recovery/rollback, audit VLT-141/§25 | Q-RESTORE |
| plan4-additions cursor/privacy/pagination D21 | Q-PAGES |
| plan4-additions secret scanning | Q-SECRET |
| plan4-additions license policy | Q-LICENSE |
| plan4-additions container scan | Q-IMAGE-SCAN |
| deferred-runs#release-gates G1–G10 | Q-GATES (matrix below) |
| D16 schema/protocol transitions, mixed pending on server DBs; revoke/append, ACKed compaction; full second protocol lifecycle | Q-PROTOCOL |
| D18 exact split/ZIP/tgz/digest immutable identity, trusted predecessor/policy | Q-IDENTITY |
| D19 full bootstrap visibility/ACL expiry/host changes, client convergence | Q-SYNC |
| D19 proxy multipart latency/deadline/remote recovery and strict/direct delivery | Q-MULTIPART |
| D20 pgsql/mysql C0/global invalidation races, lazy/head-last/snapshot clock expiry/client pinned roots | Q-SYNC |
| D21 bounded grants/shares and confidential cursors through empty continuation | Q-PAGES |
| P8.1 actual A/ToolT/protection/registry/credentials runtime gaps | Q-OWNER |
| P8.1 signatures remain unqualified; audit §16 attestation/signature | Q-SIGNATURE |
| audit §16 SBOM/attestation, P7 conditional availability | Q-PROVENANCE |
| D11 build-dev/OS scan gap beyond root runtime audit | Q-SCA |
| M16 mutation testing | Accepted exclusion M16: removed tooling must not be restored |
| audit §16 blanket ban dependency repositories/minimum-stability | Accepted exclusion D8: root-only Composer fields; clean archive consumer still mandatory |
| audit extension importer/transcoder/indexer/XLSX worker sandbox | Accepted exclusion D12: roadmap, not shipped adapters |

## Ordered owner steps

All commands in this document are future commands only. Default cwd for Q-* is `$S_ROOT`; exceptions are explicit. Owner records K before each step. `$E` contains no secrets. Every step is mandatory unless an explicit conditional/exclusion is stated. Commands marked pending implementation have no existing CLI and cannot qualify until implemented, discovered and approved.

| ID | Command / variables and cwd | Expected behavior and required evidence | Duration/source; output |
|---|---|---|---|
| Q-OWNER | Manual owner provisioning described above; no local command can grant external authority | authenticated A, approved ToolT closure, protected vars/environments, registry access and legal decision; owner prerequisite pending | unknown; `$E/Q-OWNER/` |
| Q-MATRIX | `T_MATRIX_LOGS="$E/Q-MATRIX/cells" make test-full` | all supported default cells green with named-test/cell inventory, no mandatory skips; owns serial+extended/database/I6/I15 once | historical t-matrix 25 min, tests/README Timings; actual unknown; cells + status |
| Q-SCHEMA | `for e in sqlite pgsql mysql; do scripts/schema-snapshot.sh --driver="$e" --all-presets --rollback --compare=packages/core/tests/Fixtures/Schema/expected || exit 1; done` | every preset/engine DDL equal and rollback empty; capture generated/expected hashes | unknown; snapshots/diff/status |
| Q-ISOLATION | `for p in core media drive documents webhooks filament; do scripts/release/consumer-check.sh "$p" --work-dir="$E/Q-ISOLATION/work-$p" || exit 1; done` | declared dependency closure only, own test harness; isolation uses staged path deps and is not archive proof | unknown; per-package install/test log |
| Q-AZGUARD | Owner resolves actual stable `axiomasoft/azguard` tag and records lock/ref; `scripts/release/consumer-check.sh azguard` currently refuses excluded_unless_tagged | external gate pending; implement/adopt approved manifest transition and actual RealAzGuard lane before including bridge; absent tag is explicit conditional exclusion, never bridge pass | unknown; tag/ref decision + lane report |
| Q-PREPARE | public commands below | canonical split content/commit/tree/ZIP/SBOM/resolution agree; preparation alone remains pending qualification | unknown; prepared records/checksums |
| Q-CONSUMERS | `scripts/release/consumer-smoke.sh "$PUBLIC_OUT" "$PUBLIC_VERSION"`; extra matrix pending implementation | exact ZIP clean install/discovery/cache/migrations/platform checks; every PHP entry incl webhooks, filament, metapackage and conditional azguard gets actual install evidence | unknown; lock/installed refs/content maps + per-cell logs |
| Q-NPM | `npm run build --workspace @vaulter/vue`; each package cwd: `npm pack --json --pack-destination "$E/Q-NPM"` | pack once, retain exact tgz SHA512 integrity; clean Vite consumer installs that file, ESM/CJS/types/exports and supported Node minimum/current; consumer fixture pending implementation | unknown; tgz/pack.json/locks/entrypoint logs |
| Q-PROTOCOL | Q-MATRIX existing cases plus pending server lifecycle fixture implementation (additional cases only) | second-protocol full create/edit/pending/drain/revoke/restore; schema/protocol transition+mixed pending across pgsql/mysql; revoke/append races, ACKed compaction after revoke | unknown; named timeline/state/cell evidence |
| Q-SYNC | Q-MATRIX named existing cases plus pending actual pgsql/mysql concurrent/client fixture | full sync contract detailed below; SQLite pass insufficient | unknown; transaction/latency/deadlock/client traces |
| Q-PAGES | pending owner engine/concurrent HTTP fixture implementation | D21 detailed below; no fabricated pagination CLI | unknown; redacted page/cursor/DB timeline traces |
| Q-MULTIPART | pending real storage/transport fault fixture implementation | proxy staged retries, finite transport timeout and total completion deadline, remote failure/rollback/orphan recovery; strict/direct revocation/read semantics; future native adapters only if shipped, proxy is not direct-part checksum proof | unknown; latency/fault/storage reconciliation logs |
| Q-SSRF | pending real resolver/HTTP client owner fixture implementation | all A/AAAA/mapped/NAT64/redirect/proxy-env/rebind/HTTPS negative corpus; no unintended outbound private-network reach; redacted pinned-IP evidence | unknown; resolver/client/network trace |
| Q-UPGRADE | pending saved-state RC1→RC2 consumer fixture; within installed consumer `php artisan vaulter:doctor --production` | preserve data/receipts/keys, cache/migrations and rollback compatibility; before first RC mark conditional not-applicable with owner decision, not synthetic historical pass | unknown; versions/migration/data manifests |
| Q-RESTORE | isolated consumer cwd `$RESTORE_APP`; sequence below, infrastructure adapters pending | mandatory real rehearsal DB+blobs+keys+encrypted receipts/outbox and rollback; writing this runbook is not rehearsal | unknown; backup/restore manifest + observed RPO/RTO |
| Q-BC | trusted cwd `$T_ROOT`: `scripts/php84 php scripts/release/bc-check.php --root="$S_ROOT" --candidate="$S" --policy="$POLICY" --policy-rev="$T_SHA" --exceptions="$EXCEPTIONS"` | all PHP/OpenAPI/config/events/problems/identities/schema/npm surfaces; approved predecessor per line or external first-ever bootstrap, no self-seed pass, required adapter skip incomplete | unknown; BC JSON/tool/policy hashes |
| Q-SCA | pending owner complete resolution scan fixture; root existing `composer audit --locked --format=json` and `npm audit --omit=dev --audit-level=high --json` cover only those scopes | root Composer includes dev; root npm runtime only; additionally independent build-dev/OS closures require reports; high/critical require trusted scoped policy disposition | unknown; separate resolution/profile reports |
| Q-SECRET | pending pinned scanner/rules command chosen and approved by owner | secret scan of release contents/build/history scope with safe redacted output; missing report incomplete | unknown; scanner/rules/scope/hash report |
| Q-LICENSE | pending pinned inventory/legal policy command chosen by owner | licenses across actual resolutions including dev/OS as applicable, proprietary distribution permission separately owner-approved | unknown; inventory + signed legal disposition |
| Q-IMAGE-SCAN | pending owner pinned trivy/grype invocation against exact OCI_digest | independent OS/npm vulnerabilities, scanner DB version and trusted exceptions; no Composer audit substitution | unknown; digest-bound scan report |
| Q-PROVENANCE | pending owner attestation/SBOM verification against A/ToolT/K | SBOM actual resolved closure; verify subject digests/issuer/build inputs; D11 unavailable attestation explicit accepted limitation, never provenance pass | unknown; SBOM + verified/unavailable policy decision |
| Q-SIGNATURE | pending owner signing/verification tooling and trust root | independent signature verification for exact artifacts/digests, authorized signer and policy; workflow note “digest is not signed” is not pass | unknown; signature/verification/trust-root record |
| Q-IDENTITY | verification commands below, trusted owner A prerequisite | approved exact bytes/source/tree/resolution/policy/tools; missing/mismatched slot refuses; registry dist integrity and Packagist source/dist content proof needed before promotion | unknown; A + verification JSON |
| Q-COVERAGE | `make test-coverage` | configured min 80, inspect actual coverage scope (Makefile runs vendor Pest); do not infer every package coverage from target label | unknown; coverage/stdout + scope |
| Q-CI | owner runs actual `.github/workflows/ci.yml` at S with trusted policy enforcement; no new local pretend CLI | aggregate gate including consumer/database/serial/contract-bc/sca and JS/quality; same K, separate heavy owner permission | unknown; immutable workflow/run/job IDs + reports |
| Q-GATES | manual reconcile matrix below | no missing mandatory slot; owner records final qualification decision, not automatic publish | unknown; gate-matrix.json + approval |

`make test-full` already calls `scripts/t-matrix` with default sqlite,pgsql,mysql and core,integration,drive,documents,media,webhooks,filament,azguard server cells subject to suite engine support; SQLite uses default suites. It runs --full, including serial/extended. Do not also run t-matrix or duplicate database/serial/full commands. Verify actual discovered case/engine coverage; zero tests is not a case pass. Historical times: database pgsql 2m43/28 steps, mysql 10m04; full SQLite 4m28, pgsql 9m23, mysql 33m20 (tests/README Timings). These are observations from another environment, not candidate measurements or command deadlines. Owner heavy run budgets need separate scheduling; PLAN4's 300s executor cap does not authorize these runs.

Resolved old paths: CleanInstallMatrixTest is `tests/Integration/Modules/CleanInstallMatrixTest.php`, integration outbox/idempotency races are `tests/Integration/Concurrency/{IdempotencyRaceTest,OutboxLateCommitTest,OutboxLeaseTakeoverTest,OutboxFanoutRaceTest}.php`; core retains `packages/core/tests/Concurrency/ForkRaceTest.php`. Media pin sweep, drive ShareVersion/GcNewRevision and documents Concurrency remain package-relative per suites.json. Q-MATRIX must include them, plus current webhooks races. Actual `scripts/t` executes suite cwd from tests/suites.json; repository path_base=repo versus package path_base must not be confused. Paths above describe coverage; they are not extra test invocations.

Q-SCHEMA mismatch/stale server expected snapshots: stop and regenerate on each actual server with existing `--write=packages/core/tests/Fixtures/Schema/expected` only under separately scoped source update. Never infer pgsql/mysql DDL from SQLite or modify original/**. New source requires refreeze and affected reruns.

Q-PREPARE existing commands, cwd `$S_ROOT` (trusted tool closure must be approved before execution):

```sh
scripts/php84 php scripts/release/build-artifacts.php --ref="$S" --version="$PUBLIC_VERSION" --channel=public --out="$PUBLIC_OUT" --sbom="$RESOLUTION_JSON"
scripts/php84 php scripts/release/build-artifacts.php --verify-prepared="$PUBLIC_OUT/prepared"
```

`--sbom` requires a provenance JSON path, not a boolean. Its existing schema/reader requires source_sha, lock_sha256, php_version, composer_version, profile and `lock` (absolute or relative to provenance JSON) referencing a real lock; capture actual resolution per lane. SBOM reference closure is not proof that all consumer floor locks resolve. Owner records SHA256SUMS verification from the corresponding output cwd with `sha256sum -c SHA256SUMS`. Builder reads package and manifest from the same S and refuses mixed manifest-ref. Qualify its exact prepared tree/ZIP. Packagist may repackage ZIP wrappers: own ZIP checksum is not claimed as registry ZIP checksum; installed source/dist reference and normalized exported bytes must match.

Q-CONSUMERS missing matrix is a blocker, not a ready `--laravel` option: existing consumer-smoke takes only artifacts-dir/version, hardcodes Laravel ^13.0, covers metapackage/core+media+drive/+documents/+filament and conditional azguard. It does not establish separate webhooks install, Laravel12/13 lower/upper resolutions. Implement scoped fixture tooling first; use PHP8.4+Laravel12 and Laravel13 with actually compatible PHP, Composer lowest/highest supported locks, with no path repo in archive lane. Each cell needs exact argv, lock hash/platform, installed dist/source/content comparison, cache/discovery/migration and contract assertions. Unsupported pairs explicitly N/A by constraints, no blanket green matrix.

Q-SYNC requires pgsql AND mysql C0 against global invalidation/journal mutation/bootstrap/prune; committed lazy head before fresh authorization; head-last locking with no writes/locks after head; atomic all-head epoch UPDATE, rollback/retry on failure, no partial success. Exercise repeatable-read snapshot/grant MIN/projection, latched Carbon clock and pre/post expiry/key/prune checks, monotonic issued_at cap, shortened/extended/deleted grants, grant→expiry→rename/trash without a mutation at expiry. Real clients retain independent nested/pinned roots, reset on invalid/expired cursors and converge under private churn without raw hidden identities. Record fleet head count, server latency distributions, lock waits/deadlocks/timeouts and bounded retry envelope; budget failure needs a new design decision, never SQLite substitution or silent skipping.

Q-PAGES requires all supported engine collation/order and concurrent grants/shares changes; confidential scoped cursors for protected/public shared listings; bounded HTTP grants/shares pages, including a fully filtered empty page WITH continuation. Traverse until `next_cursor=null`, assert no loss/duplicates/leakage and stable tie-breaks; empty page is not completion. Public PHP array list()/grants() remains supported. Authorization success alone does not prove cursor confidentiality.

Q-RESTORE mandatory owner-only drill: seed tenant A/B with current/history documents/blobs, pins/ref_count, encrypted receipts/webhook secrets/key versions, pending+delivered outbox. Record row counts/stable IDs, full blob {key,size,sha256}, expected side-effect IDs and secure key-id restore mapping (no key bytes). Quiesce writers/scheduler/relay, coordinate DB+blob backup at one sequence point. Source and empty target DB names end `_test`; owner verifies exact disposable target and new absolute backup/blob paths, tool/server versions and PGPASSFILE before these PostgreSQL/local-filesystem commands, cwd `$RESTORE_APP`:

```sh
pg_dump --format=custom --no-owner --file="$VAULTER_BACKUP_DIR/db.dump" --dbname="$VAULTER_RESTORE_SOURCE_DB"
sha256sum "$VAULTER_BACKUP_DIR/db.dump"
cp -a "$VAULTER_BLOB_SOURCE/." "$VAULTER_BACKUP_DIR/blobs/"
pg_restore --exit-on-error --single-transaction --no-owner --dbname="$VAULTER_RESTORE_TARGET_DB" "$VAULTER_BACKUP_DIR/db.dump"
cp -a "$VAULTER_BACKUP_DIR/blobs/." "$VAULTER_BLOB_RESTORE/"
php artisan vaulter:doctor --json --check=core.schema
php artisan vaulter:blobs:scrub --dry-run --scope="tenant:$VAULTER_RESTORE_TENANT" --limit=100 --max-bytes=104857600 --max-seconds=30
```

Restore keys via owner secret store before boot. Pending implementation: actual fixture/commands for name/empty-target guards, full blob manifest generator, row counts/tenant denials/decrypt receipt assertions, sampled document/history GET/export/fresh upload, pin reconciliation, bounded outbox replay and exact dedup effect identities. Wrong key/missing blob negative controls must fail. Scrub sample is not full dataset proof. MySQL and remote object-store need exact owner adapters/commands before their lanes can qualify; PostgreSQL/local disk does not cover them. Rollback previous compatible runtime/schema on disposable restored copy; incompatible down requires full snapshot restore, never old binary on new schema. Record observed RPO/RTO, post-backup writes/loss, restored DB/blob hashes, commands/tool versions/status and cleanup owner. Missing infrastructure-specific argv keeps Q-RESTORE pending.

Q-BC complete admission additionally uses the existing externally provisioned bootstrap: cwd = owner-approved disposable host directory, command `"$HOST_PYTHON" -I "$BC_HOST_BOOTSTRAP"`. `$BC_HOST_BOOTSTRAP` is an externally approved copy of `scripts/release/Contract/trusted-host.py`, not candidate code. Owner provisions protected `BC_TOOL_ROOT`, `BC_TOOL_REV`, `BC_SHA256SUMS`, `BC_RUNTIME_APPROVAL`, `BC_RUNTIME_APPROVAL_SHA256`, `BC_SOURCE_ROOT`, `BC_SOURCE_SHA`, `BC_SELECTED_LINES=all`, `BC_REQUIRED_LINES=php-public,npm-vue`. Runtime approval pins absolute PHP/Python, environment, extensions and config bytes. It verifies complete closure, invokes BC and required OpenAPI checker; a raw bc-check.php report alone is insufficient. Bootstrap reports `signature_guard_only=true`, `actual_runtime_qualification=false`: do not mistake this for Q-SIGNATURE or server qualification. Host bootstrap/runtime/enforcement are currently owner prerequisites, unprovisioned here.

Q-IDENTITY needs protected external `VAULTER_PREPARED_APPROVAL_SHA256` for PHP and `VAULTER_ARTIFACT_APPROVAL_SHA256` for npm/OCI. Owner supplies the expected approval-byte hashes through protected environment; never derive trust from candidate approval bytes. Registry access/visibility still requires external verification because these local gates do not contact a registry.

Q-IDENTITY existing admission commands (cwd `$S_ROOT`, owner-authenticated A input):

```sh
scripts/php84 php scripts/release/build-artifacts.php --require-approved="$PUBLIC_OUT/prepared" --package="$PHP_PACKAGE" --source-sha="$S" --approval="$A" --root="$S_ROOT"
python3 scripts/release/artifact-gate.py npm --tgz "$TGZ" --approval "$A" --source-sha "$S" --channel "$CHANNEL" --visibility "$VISIBILITY"
python3 scripts/release/artifact-gate.py oci --approval "$A" --source-sha "$S" --digest "$OCI_DIGEST" --platforms "$PLATFORMS" --sbom-sha256 "$SBOM_SHA" --policy-sha256 "$POLICY_SHA" --tool-sha256 "$TOOL_SHA" --channel private --visibility restricted
```

Repeat PHP for every package/channel using its corresponding prepared directory, and npm for both packages. Local passing fixture cannot authenticate A. Registry visibility unknown or mismatched bytes refuses admission. Signature/provenance/secret/license/container reports keep independent statuses even if admission or runtime SCA succeeds.

## Current gate contracts and required slots — Q-GATES

| ID | Acceptance criterion | Owner and evidence |
|---|---|---|
| G1 Architecture | Core remains independent. CI rejects forbidden dependency edges and internal imports. New types, editors and protocols use extension slots. | Kernel, consumer, isolation and contract suites in P2/P3/P6. |
| G2 Public compatibility | Inventory PHP, OpenAPI, configuration, events, errors, identities, schema and npm. Compare each release line with its trusted predecessor or approved external bootstrap. | P6/P8.4. Record source, fixtures, tools and policy versions. Pending schema contracts block release. |
| G3 Functionality and schema | Exercise supported PHP/Laravel and database combinations, ID presets, cache builds, upgrades, migrations and rollbacks on the frozen candidate. | P8.2 steps 1–5 and 17. Record suite inventory and skipped or stale runs. |
| G4 Concurrency and consistency | Exercise CAS, logs, checkpoints, restore, revocation, outbox, uploads, sync, expiration, global invalidation and partial failures in serial and independent processes. Preserve acknowledged writes. | P8.2/D16–D20. Record cases, engines, commands and hashes. |
| G5 Packaging | Install each PHP artifact, metapackage and supported private/public dependency floor in a clean consumer. Check archive contents and identity against the qualified split. | P4/P7/P8.2 steps 8 and 12. Include Filament and the external Azguard gate. Archive consumers must resolve without path repositories. |
| G6 JavaScript | Verify generated APIs, type definitions, Vitest, builds and ESM/CJS/browser consumers for supported Node versions and entry points. | P3/P8.2 steps 6, 13 and 14. Record tarball integrity. |
| G7 Security | Exercise authorization, SSRF protection, uploads, JWT, revocation and redaction. Document accepted risks. Required scans must contain no unapproved high or critical findings. | P5/P7/P8.2. Record approved suppressions. Missing reports leave the gate incomplete. Redact secrets. |
| G8 Supply chain | Pin external actions, images and tools. Check least privilege, SBOMs, checksums and required attestations. Publish only the qualified split, tarball or image digest. | P4/P7/P8.2. Record identity, resolution and tool versions. Reject missing evidence or unknown visibility. |
| G9 Operations and recovery | Rehearse backup, restore, key mapping, CAS reconciliation, outbox replay, upgrades and rollback in isolation using the documented commands. | P8.1/P8.2 and Q-RESTORE. Record RPO, hashes and status. Freeze a new candidate after source changes. |
| G10 Documentation and governance | Check English docs, README, UPGRADE, CHANGELOG, ROADMAP, SECURITY and CODEOWNERS against the candidate. Verify registry access, visibility, credentials, legal status and branch settings with the owner before publication. | P4.4/P7.5/P8.3/P8.5. Record owner prerequisites. Each publication or tag requires separate authorization. |

All current gate statuses: **runtime pending / not qualified**. PLAN4 supplies design/static readiness only; item done does not fill runtime slots. Per-gate command/cwd/K are the referenced Q owners above.

| Gate | PLAN4 documentary/static attribution | Required runtime/evidence slots and step owners | Status / owner |
|---|---|---|---|
| G1 | P2/P3/P6 dependency/kernel/contract-kit changes | Q-MATRIX kernel/edges/contract discovery; Q-ISOLATION isolated closure; Q-PROTOCOL second lifecycle | pending owner |
| G2 | P6 catalog, P8.4 candidate baseline | Q-BC complete surfaces/approved predecessor or bootstrap; Q-SCHEMA all engines; Q-IDENTITY S/F/T/tool/policy approval | pending owner; baseline not BC pass |
| G3 | classified tests/suite/preset inventory | Q-MATRIX supported engine cases; Q-SCHEMA preset/rollback; Q-CONSUMERS PHP/Laravel/lowest/highest/cache; Q-UPGRADE saved data | pending owner |
| G4 | P5/P6 lightweight fault fixtures | Q-MATRIX named serial/CAS/outbox cases; Q-PROTOCOL mixed/revoke/ACK; Q-SYNC server locks/snapshots/expiry; Q-MULTIPART latency recovery; Q-RESTORE actual restored consistency | pending owner |
| G5 | P4/P7 archive and channel tools | Q-PREPARE every PHP record; Q-ISOLATION incl filament; Q-CONSUMERS metapackage/private/floors; Q-AZGUARD external tag; Q-IDENTITY ref/content equality | pending implementation + owner |
| G7 | P5 security corpus/production invariants | Q-MATRIX negative tests; Q-SSRF real client; Q-PAGES confidentiality; Q-MULTIPART delivery; Q-SCA resolution reports; Q-SECRET and Q-LICENSE and Q-IMAGE-SCAN separate dispositions | pending owner |
| G8 | P4/P7 admission/pins/SBOM tooling | Q-OWNER real enforcement/visibility; Q-PREPARE SBOM/checksums; Q-IDENTITY exact bytes; Q-PROVENANCE attestations; Q-SIGNATURE verified signer; Q-CI trusted job | pending owner; D11 limitation explicit |
| G9 | P8.1 written runbooks | Q-RESTORE isolated rehearsal/RPO/RTO/key mapping/CAS/outbox; Q-UPGRADE actual upgrade/rollback; Q-SYNC reconnect reset | pending owner; docs not restore evidence |
| G10 | P4.4/P7.5 governance; P8.3/P8.5 future documentation/checkpoint | Q-OWNER legal/registry/private/branch protections; Q-GATES records P8.3 bilingual README/UPGRADE/CHANGELOG/ROADMAP/SECURITY/CODEOWNERS checks once at assigned checkpoint | pending P8.3/P8.5 + owner |

Audit §16 trace: finding L archive gap → Q-PREPARE actual canonical split/content map/ZIP/SBOM from S → Q-CONSUMERS installs those exact bytes with actual lock/ref checks → Q-IDENTITY authenticates A over those identities → G5/G8 Q-GATES slots. D8 explicitly rejects the audit's root-only Composer field blanket ban; it does not waive archive installation. Current limited Laravel13 consumer smoke cannot close lowest/highest/private slots. No publish/rebuild is part of this execution.

## Historical audit dispositions and remaining evidence

This is knowledge/audit-verification#findings disposition accounting, not a claim that the historical audit still describes current sources. One primary owner per residual requirement; gate references remain cross-references.

| Finding / source status | Current disposition / evidence owner |
|---|---|
| A partial commercial/public channel issue | Q-OWNER actual private/legal settings; tools alone do not prove visibility |
| B confirmed core dependency/test coupling | Q-MATRIX kernel/dependency tests |
| B′ hidden activation refuted | accepted exclusion: existing I4 contract, no new activation framework |
| C confirmed document feature coupling | Q-MATRIX documents feature/contract cases |
| D confirmed singleton/protocol coupling | Q-PROTOCOL real second lifecycle |
| E outside plan extension lifecycle | accepted exclusion D12 roadmap |
| E′ partial SPI kits | Q-MATRIX actual discovered kits |
| F partial secure defaults/doctor | Q-MATRIX production negative cases |
| G confirmed authorization reads gap | Q-PAGES server HTTP/security evidence |
| H partial SSRF | Q-SSRF real resolver/client, mocked fixtures insufficient |
| I partial/outside upload/OOXML | Q-MULTIPART shipped upload; XLSX sandbox accepted D12 exclusion |
| K confirmed supply chain gap | Q-SCA remaining root npm dev, independent build closures; secret/license/OCI/provenance/signature retain distinct owners above |
| L partial archive qualification | Q-CONSUMERS actual installed bytes |
| L′ root-only Composer ban refuted | accepted exclusion D8, no archive install waiver |
| M partial BC/catalog | Q-BC both public release lines and required adapters |
| N confirmed misleading metadata | Q-GATES P8.3 final descriptions; no runtime pass inferred |
| O confirmed governance files absent historically | Q-OWNER actual protection/private reporting settings beyond files |
| P partial tracked output/archive hygiene | Q-PREPARE exact allowlisted content |
| §21 partial/outside performance | load budgets accepted exclusion D2/D12; Q-MATRIX partial-failure tests remain required |
| §22 partial observability/runbooks | Q-RESTORE operational rehearsal; P8.1 documents are available, execution remains pending |

## Local P8.2 validation boundary

V1 is manual inventory, full G1–G10 slot and K traceability inspection. V2 is static source/option-parser/cwd inspection only: scripts/t, t-matrix, Makefile, schema-snapshot.sh, consumer-smoke.sh, consumer-check.sh, build-artifacts.php, sbom.py, bc-check.php and artifact-gate.py. No help wrappers, server startup, archive preparation/install or heavy checks ran here. Broader docs links/snippets checks belong to P8.3. Review NONE here, batch review P8.5.
