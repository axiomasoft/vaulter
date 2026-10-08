# P4.9 — handoff to the finishing session (Sonnet medium)

You continue plan item **P4.9** of plan `2026.09.29-№1-VAULTER-STABLE` (repo
`/home/vostrikov/projects/packages/vaulter`). The item is already `started` by
`anthropic/claude-opus-5-5/high` (session 232434b5-…). `plan.py` =
`python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py`.
Do not `start` it again. Do not read or edit the generated Markdown plan views.

**Route warning.** The item route is `frontier/high`. If `plan.py log/finish/commit` refuses your
actor (`ROUTE_UNDERSHOOT`), stop and report it to the owner. Never declare an actor you are
not (declare your own observed model/effort).

## Read first (short)
- `plan.py show 2026.09.29-№1-VAULTER-STABLE P4.9` — scope, rules, validation V1–V5, V80, V81, V90.
- `plans/…/artifacts/P4.9/check-profile.json` — exact commands and planned negative controls.
- `plans/…/artifacts/P4.9/test-map.md` — every rewritten deny test and its replacement (keep appending).
- Decision **D53** (owner, accepted): level → same-named role v1 (no legacy versions); writer gains
  trash/restore, organizer gains share and loses delete — declared exceptions; `v_nodes.owner_id` is
  dropped without creating grants; deny and permission bits outside the level bits → preflight refusal.

## Already done (do not redo)
Product code (core/drive/documents/azgard/filament):
- `Enums/Access/Permission` one bit per ability (all()=4095), `Ability::bits()/mask()/fromMask()`,
  `GrantLevel` deleted, `Effect` only for `PermissionDriver::override()`, new `InheritanceRestriction`.
- `Access/Roles/{Role,RoleRegistry}`, `Exceptions/Access/UnknownRoleException` (unknown_role, 422).
- `Decision{Role role}` + `Decision::union()`, `GrantSet{allowedBits, superAllow, superDeny}`,
  `AccessExplanation{allowed, ability, reason, sources, restrictions, boundaryNodeId}`, `AccessSource`;
  `AclFolder`, `AclMask`, `AccessAce` deleted; `AccessScope.nodeOwner` removed.
- `AccessResolver` rewritten (union of roles up to the first inherit_grants=false node, no deny,
  no rank), `OperationGuard::copyableSubtree` requires view+download+copy (D30).
- `access()->restrictInheritance(node, InheritanceRestriction)/restoreInheritance(node)` (Manage) via
  `Actions/Access/SetNodeInheritanceAction` (copy_current never broader/longer, see its docblock).
- `GrantInput{subject, role, scope, expiresAt}`; grant store/read repositories; `NodeGrant::role()`;
  HTTP grant request/resource (`role`, `role_version`); activity/outbox grant context `role`/`role_version`.
- Gate/Spatie/Azgard drivers on roles (config maps gain `previewer => vaulter.preview`; unknown map
  key → UnknownRoleException); Filament NodeGrantResource/NodeShareResource.
- Share links minimal (P4.10 owns the redesign): stored bits use the new layout, `level` is a role
  key string, subtree links stop at inheritance boundaries (`ShareContext`).
- Migration `core/database/migrations/2026_10_07_000005_access_model_v1.php` (+ reversible down()).
- `SchemaBuilder` grants shape, `KeyColumnInspector`, Node model (`owner_id` gone, `inherit_grants` no
  longer mass-assignable), owner_id writers removed (drive CreateFolder/IngestFile/CopyNode, documents
  CreateDocumentAction).
- `docs/guide/errors.md` + ru: `unknown-role` section.
- Golden `core/tests/Arch/__snapshots__/public-api.txt` regenerated and reviewed;
  `service-locator.json` baseline lowered (GrantReadRepository app() calls removed).
Tests rewritten: core Access/Operations/Sharing/Activity/Schema/PublicApi/Unit + helpers/fixtures,
drive Feature/PublicApi/Drive, filament Feature + DriveBuilder, azgard Access helpers/tests, documents
Comment/Collaboration. New: `core/tests/Access/AccessModelV1Test.php`,
`core/tests/Upgrade/AccessUpgradeEquivalenceTest.php`, share-boundary case in `ShareScopeTest`,
D30 download case in drive `CopySubtreeAuthorizationTest`.

Last runs: V1 273/276 before the final fixes; affected core dirs 353/364 before the final fixes.
**Not yet run:** `AccessModelV1Test`, `AccessUpgradeEquivalenceTest`, the latest AccessResolver/Share
edits, any drive/filament/azgard/documents suite after the rewrite.

## Remaining work, in order
1. **V1** (exact cmd in check-profile). Fix failures in the owning file; keep the semantics of
   D45/D53 (no deny, union of roles, boundary). Also run once on SQLite:
   `packages/core/tests/Upgrade/AccessUpgradeEquivalenceTest.php` and
   `packages/core/tests/Activity packages/core/tests/Schema packages/core/tests/PublicApi packages/core/tests/Unit packages/core/tests/Feature packages/core/tests/Support packages/core/tests/Drive packages/core/tests/Tenancy`.
   Known: `core/tests/Support/DriveNameRulesConfigSeamTest.php` fails with “Unknown named parameter
   $maxLength” — introduced by P4.11 (DriveNameRules lost maxLength). This item is the barrier of the
   P4.11+P4.9 block: fix it as one owning correction and `plan.py log --type deviation` naming P4.11.
2. **V3** drive (cmd in item) + once `tests/Feature tests/Uploads tests/Arch tests/Drive`;
   **V5** filament; documents `tests/Comment tests/Collaboration tests/Document tests/Profiles`.
   Golden `PublicApiSnapshotTest` of drive/documents/azgard/filament: review the diff, then
   `UPDATE_SNAPSHOT=1` (only if the diff is the intended access surface).
3. **V2 azgard**: `packages/azgard/vendor` is missing and `composer install` fails (path repo
   `../../../azguard/packages/context` missing; local azguard publishes `axiomasoft/azguard`). Try a
   temporary *untracked* composer file (`COMPOSER=composer.p49.json`) with path repos `../core` and the
   local azguard core; remove the temp json/lock afterwards. If impossible → V2 is `blocked`
   (escalation), never reported green.
4. **Docs (same item):** create `docs/adr/0025-access-model-v1.md` (roles v1 table, Ability bits,
   union up to boundary, override precedence, multi-resource rules copy/move/restore/purge, explain,
   migration + D53 exceptions, owner_id removal); mark `docs/adr/0007-pluggable-authorization.md`
   “superseded in part by ADR-0025” (deny semantics); update `docs/guide/{authorization,grants,abilities,permission-drivers}.md`
   and the `docs/ru/guide/` mirrors; `UPGRADE.md` and `CHANGELOG.md [Unreleased]` (было → стало,
   PLAN2.D45/D46/D53): GrantLevel/Effect-for-grants/GrantInput level/effect/permissions removed,
   Permission bit layout, Decision/GrantSet/AccessExplanation/AccessScope shapes, Gate context
   `node_owner` removed, previewer map key, writer/organizer role changes, owner_id dropped, HTTP grant
   field `role`, activity/webhook grant payload `role`/`role_version`, restrict/restoreInheritance.
5. **V80**: `scripts/php84 vendor/bin/pint packages/core` etc. (fix, then `--test`), PHPStan core
   (`-c phpstan.neon`), drive, filament, azgard, documents. Negative controls → `artifacts/P4.9/negative-controls.md`
   (inject one fault, show red, restore exact bytes, show green): NC1 resolver keeps only the nearest
   grant instead of the union (AccessResolverTest union row red); NC2 ignore inherit_grants in
   `applicableGrants` (boundary test red); NC3 drop the deny check in the migration
   (AccessUpgradeEquivalenceTest preflight row red); NC4 drop Download in `copyableSubtree`
   (CopySubtreeAuthorizationTest previewer row red); NC5 `ShareContext` ignores inherit_grants
   (ShareScopeTest boundary case red).
6. **V81**: `python3 plans/2026.09.29-№1-VAULTER-STABLE/artifacts/coverage/opus-coverage.py check`.
7. **V4 + V90** (heavy, barrier of P4.11+P4.9): `scripts/db-preflight.sh` first, then the exact
   commands from the item (V4; V90 = P4.11 V3 PG Upgrade+NameKeyUniqueness, P4.11 V4 MySQL). Receipts
   with numeric executed>=1, skipped=0, test names → `artifacts/P4.9/barrier-receipts.json`; all runs →
   `artifacts/P4.9/receipts.json`. A barrier defect = one owning correction here, log the source item.
8. **Review (owner requested `grok/grok-4.7/high`)**: follow
   `references/reviewer-selection.md` of the Task package — launch via `plan.py bridge` `launch` for
   role reviewer, read-only, brief with the diff, D45/D46/D53, risky seams (migration preflight,
   SetNodeInheritanceAction copy_current, resolver union/boundary, share boundary, explain); prove the
   route from the grok session record; verdict artifact + `plan.py log --type note`; at most one owning
   correction and one re-review.
9. `plan.py finish … --status done_with_deviations --check V1=0 V2=… V3=0 V4=0 V5=0 V80=0 V81=0 V90=0
   --deviation …` (D53; shares adapted minimally ahead of P4.10; files beyond task_files) with notes
   incl. the P4-I20/P4-I21 self-check; then `plan.py commit … --role executor` with `--path` for files
   outside task_files (core Sharing/*, Http/*, Activity/ActivityRecorder, Webhooks/OutboxRecorder,
   Models/Drive/Node, Models/Sharing/NodeShare, Testing/*, Configuration/AccessConfig, Schema/KeyColumnInspector,
   drive Actions/*, documents Actions/Documents/CreateDocumentAction, docs/guide/errors.md + ru, artifacts).
   Never `git add -A`, never amend/push.

## Owner housekeeping
An empty stray file `~/projects/plans_dummy` was created by mistake in the Opus session (deletion was
blocked by a hook) — the owner removes it.
