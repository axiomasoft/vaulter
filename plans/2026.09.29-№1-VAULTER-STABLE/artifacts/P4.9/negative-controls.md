# P4.9 negative controls

Each control injects one fault, shows the targeted tests red, restores the exact bytes (sha256 prefix
compared) and shows them green. Run on SQLite (`APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`) by
`scratchpad/nc.py` (inject / run / restore / run).

Findings:
- NC4 first SURVIVED: the previewer/commenter cases were also refused by the Copy check, so nothing pinned the
  Download requirement of D30 on its own. Fixed by a new case in `drive/tests/PublicApi/CopySubtreeAuthorizationTest.php`
  (host driver role with View+Copy+Create but no Download); the control below is the re-run after that fix.
- NC3 green shows 5 of 6 tests passed: the sixth is the PostgreSQL-only case, skipped on SQLite and covered by V4.

### NC1 — resolver keeps only the nearest grant instead of the union
- file: `packages/core/src/Access/AccessResolver.php`
- fault: `break;` added after `$bits |= $grant->role()->bits;` in the grant loop of effectiveGrants()
- red: failed tests=45 passed=42; failing: it_stays_monotonic__adding_a_grant_never_removes_an_ability; it_unions_the_roles_of_every_grant_that_applies__whatever_the_row_order with data set \
- restored: sha256[:12] `eacbcb114193` == `eacbcb114193`
- green: passed tests=45 passed=45

### NC2 — applicableGrants ignores inherit_grants=false
- file: `packages/core/src/Access/AccessResolver.php`
- fault: `if (!$current->inherit_grants) {` -> `if (false) {`
- red: failed tests=45 passed=38; failing: it_applies_a_direct_grant_on_a_node_below_a_boundary_that_cuts_the_parent_grant_off; it_applies_a_subtree_grant_below_a_boundary_to_everything_under_it; it_explain___lists_every_applying_role_with_its_origin_and_the_boundary_that_stopped_the_w; it_removes_an_inherited_role_from_a_node_by_restricting_its_inheritance
- restored: sha256[:12] `eacbcb114193` == `eacbcb114193`
- green: passed tests=45 passed=45

### NC3 — migration preflight no longer refuses deny/extra-bit grants
- file: `packages/core/database/migrations/2026_10_07_000005_access_model_v1.php`
- fault: `if ($reason !== null) {` -> `if (false) {`
- red: failed tests=6 passed=2; failing: it_refuses_deny_grants__unknown_levels_and_extra_permission_bits_and_changes_nothing with 
- restored: sha256[:12] `e2ca5069700c` == `e2ca5069700c`
- green: passed tests=6 passed=5

### NC4 — copyableSubtree drops the Download requirement (D30)
- file: `packages/core/src/Access/OperationGuard.php`
- fault: `|| !$set->allows(Ability::Download)` -> `(removed)`
- red: Tests:    1 failed, 5 passed (12 assertions); failing: it SEC-D30-copy-subtree refuses a copy of nodes the actor may view and copy but not download
- restored: sha256[:12] `b96df8e2189e` == `b96df8e2189e`
- green: Tests:    6 passed (13 assertions)

### NC5 — ShareContext ignores inherit_grants on the path to the shared node
- file: `packages/core/src/Sharing/ShareContext.php`
- fault: `if (!$ancestor->inherit_grants) {` -> `if (false) {`
- red: failed tests=4 passed=3; failing: it_a_subtree_share_on_an_ancestor_stops_at_an_inheritance_boundary_like_a_grant
- restored: sha256[:12] `3ece15b3653d` == `3ece15b3653d`
- green: passed tests=4 passed=4
