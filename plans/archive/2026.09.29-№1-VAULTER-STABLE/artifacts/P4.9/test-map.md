# P4.9 — test map (deny → boundary / admin override)

Rule (item P4.9): no test is removed without an equivalent. Every former deny scenario
is rewritten either as an inheritance-boundary scenario or as a PermissionDriver
`override()` administrative block. Level/bitmask rows become role rows.

| File | Former test | Now |
|---|---|---|
| core/tests/Access/AccessResolverTest.php | grants a direct allow on the node itself | grants a direct role on the node itself |
| 〃 | inherits a subtree allow … | inherits a subtree grant … |
| 〃 | stops inheriting at an inherit_grants=false cut-off node | stops inheriting at an inheritance boundary |
| 〃 | lets a nearer explicit allow pierce a farther inherited deny | applies a direct grant on a node below a boundary that cuts the parent grant off (boundary) |
| 〃 | lets a nearer deny beat a farther allow (deny-wins) | removes an inherited role from a node by restricting its inheritance (boundary) |
| 〃 | lets a same-node deny veto a same-node allow regardless of row order (2 rows) | unions the roles of every grant that applies, whatever the row order (2 rows; positive grants never cancel) |
| 〃 | applies the FINAL veto: a deny survives a host role grant | lets an administrative override block survive a host role and grants (admin override) |
| 〃 | a host role grant can raise access when nothing denies it | a host role grant raises access on its own |
| 〃 | ORs the bitmask and maxes the level across multiple allow grants | unions the host role with the node grants |
| 〃 | never applies a grant from another storage | never applies a grant from another drive |
| 〃 | honours an absolute super-admin override in both directions | honours an absolute host override in both directions |
| 〃 | maps each ability to the right required bits (10 rows) | gives each role exactly its abilities (15 rows, previewer/reader split, D53 writer/organizer) |
| 〃 | lets a nearer allow pierce a deny sitting ON the wall (F-P1.1-01) | applies a subtree grant below a boundary to everything under it (boundary) |
| 〃 | docblocks no longer claim an un-overridable wall | same + no max( |
| 〃 | explain() returns the deciding ACE chain … deny-vetoed | explain() lists every applying role with its origin and the boundary that stopped the walk |
| 〃 | explain() reports a granted ability with reason=allow | explain() reports … and names the host role source; + explain() names an administrative block as a restriction (new) |
| core/tests/Access/AccessAwareListingTest.php | excludes a deny-revoked row and an expired allow from the shared view (exact) | excludes an expired grant and keeps a direct grant behind a boundary in the shared view (exact) — every role carries view, so a grant row always shows its own node; exclusion now comes from expiry/override |
| 〃 | refuses the action on a deny-vetoed node that still appears in the candidate listing | refuses the action on a node behind an inheritance boundary that still appears in the candidate listing (boundary) |
| core/tests/Access/EvaluateManyEquivalenceTest.php | matches individual ACL resolution on 500 seeded random trees (random allow/deny + bits) | same, random roles × scopes × subjects × boundaries × expiry (no effect/bits) |
| 〃 | preserves absolute overrides … 'role and deny' row (owner deny grant) | 'role and grant' row (previewer grant + commenter host role); 'super deny' row keeps the override Deny |
| 〃 | evaluates override and role once per storage and node owner and subjects once per scope | evaluates override, role and subjects once per drive (node owner no longer groups; blocked drive via override Deny) |
| 〃 | preserves deciding grants above the 64th ancestor ('far deny', 'far allow') | preserves deciding grants and boundaries above the 64th ancestor ('far boundary', 'far grant') |
| core/tests/Access/PermissionDriverContractTest.php + azgard/tests/Access/PermissionDriverContractTest.php + Testing\Contracts\PermissionDriverContractTests | shared fixture: writer subtree allow on parent + writer item deny on child | writer subtree grant on parent + inheritance boundary on child (same truth table: parent.edit true, child.view/edit false) |
| core/tests/Access/SharedWithMeTest.php | excludes a shared row revoked by a nearer deny on re-resolution | excludes a candidate cut off by an inheritance boundary on re-resolution |
| core/tests/Access/StorageShowAuthorizationTest.php | refuses a rooted storage show when a deny vetoes the root view (exact gate) | refuses a rooted storage show when an administrative block overrides the root grant (admin override; the root has no ancestor to put a boundary above) |
| core/tests/Operations/ProfilePermissionDriverTest.php | keeps node-owner decisions local to each batch group … | keeps per-drive override decisions local to each batch group … (node owner removed, D53; driver blocks one drive via override Deny) |
| 〃 | keeps host denial when grants are disabled and preserves raw masks | keeps the host block when grants are disabled (ProfileDenyDriver is an override Deny; no raw masks remain) |
| 〃 | builds node-blind … coordinates (nodeOwner asserted) | same without nodeOwner (AccessScope.nodeOwner removed) |
| 〃 | normalizes level owners without modifying raw stored grants | resolves a grant by its stored role and version without rewriting it |
| core/tests/Fixtures/Access/ProfileDenyDriver.php | resolve() → Decision::deny(Owner) | override() → Effect::Deny (administrative block) |
| core/tests/Fixtures/Access/ProfileAllowDriver.php | deny when nodeOwner = 2 | override Deny for drives in ProfileAllowDriver::$blocked |
| core/tests/Operations/CoreOperationsSecurityTest.php | validates public grant bits and subject keys | validates public grant roles and subject keys (UnknownRoleException) |
| 〃 | HTTP grant with level reader + permissions 32 | HTTP grant with role commenter (+ unknown role 422) |
| core/tests/Sharing/ShareScopeTest.php | — | new: a subtree share on an ancestor stops at an inheritance boundary like a grant |
| core/tests/Sharing/ShareSecurityBoundaryTest.php | invalid bits 64 | invalid bits Permission::all()+1, unknown role key |
| core/tests/Concerns/ExercisesWideNodeListing.php (WideNodeListingTest + PG bind-limit lane) | candidate superset; exact per-action deny still wins | candidate superset; the exact gate honours an inheritance boundary the SQL does not apply |
| drive/tests/Feature/ExactListingTest.php | V09 … hides denied nodes everywhere (300 item denies) | V09 … hides walled-off nodes everywhere (300 boundaries) |
| 〃 | V22 enforces a child deny after the Gate role lane allows the workspace | V22 keeps a drive-wide Gate role through an inheritance boundary (boundary cuts grants only, PLAN2.D45) + an override Deny driver closes the node (admin override) |
| 〃 | scans shared candidates across storages without totals or denied rows | … without totals or blocked rows (candidate in a drive whose profile driver returns override Deny) |
| drive/tests/Feature/R07dObservableMutantTest.php (R07dBitsDriver) | Decision::allow(level, bits) with arbitrary bits; onlyOwnerId via AccessScope.nodeOwner | Decision of an ad-hoc Role carrying exactly the bits under test; "authorizes the requested parent, not the storage root" uses writer grants on the folder/file instead of node ownership (D53) |
| drive/tests/PublicApi/OperationMatrixTest.php | source permission matrix by raw bits (allowed = READ|required, missing = READ) | source matrix by roles: allowed = narrowest role with the ability, missing = broadest role without it (read: reader/previewer, writer ops: writer/commenter, purge: owner/organizer); destination checks by roles |
| drive/tests/PublicApi/CopySubtreeAuthorizationTest.php | D30 copy with READ|CREATE grants | D30 copy with writer grants + new case: a visible but not downloadable descendant (previewer, commenter-without-copy) refuses the whole copy |
| drive/tests/Feature/R07bActionNodeContractsTest.php | UploadFile: owner_id and created_by are set to actorId | UploadFile: created_by is set and no node owner column exists (D46/D53) |
| drive/tests/Drive/CopyMutationContractsTest.php, R07CommandActivityContractsTest.php | asserted copy/folder owner_id = actor | assertion removed with the column; created_by assertions kept |
| filament/tests/Feature/NodeResourceExactVisibilityTest.php | hidden node with allow + deny grant is not visible | hidden node behind an inheritance boundary under a root subtree grant is not visible (boundary) |
| filament/tests/Feature/NodeResourceQueryTest.php | lists a deny-vetoed candidate in the table query (superset) | lists a walled-off candidate in the table query (superset) (boundary) |
| filament/tests/Feature/NodeGrantResourceTest.php | form contract with level/effect=deny/permissions=31 | form contract with role (deny and bits are no longer form fields); asserts role_key + role_version |
| filament/tests/Fixtures/DriveBuilder.php | grant(level, effect, scope) | grant(role, scope) |
| azgard/tests/Access/AzgardDriverTest.php | composite order: a node-ACL deny still vetoes a workspace role grant | composite order: an inheritance boundary cuts node grants but not the workspace role (no node-level veto of a node-blind role in 1.0, D45) |
| azgard/tests/Access/AccessTestHelpers.php | grant(level, effect, permissions) | grant(role) |
| documents/tests/Comment/NodeCommentApiTest.php | vetoes Commenter creation with a same-node comment deny | refuses comment creation once an inheritance boundary cuts the inherited commenter role (direct reader grant still lists the thread) |
| core/tests/Unit/AccessEnumsTest.php | six CRUDS bits; level rank/bits; implies/max; ability required bits; ALL-bits satisfaction | twelve ability bits; one bit per ability; view/download independent; pinned v1 role table; roles nest (covering); unknown key/version → unknown_role 422 (level rank/max/implies removed with GrantLevel — max(rank) is forbidden by the item rule) |
| core/tests/Schema/NodeGrantSchemaTest.php | allows an allow AND a deny for the same subject/scope (distinct effect) | allows an item and a subtree grant for the same subject (distinct scope) — the effect is no longer part of the key |
| core/tests/Schema/CanonicalSchemaTest.php, DriveSchemaAssertions.php, PublicApi/OptionsTest.php, OperationsContractTest.php | old columns / unique / GrantInput params / 4 AccessOperations methods | role columns, 4-column unique, GrantInput{subject, role, scope, expiresAt}, + restrictInheritance/restoreInheritance amendment (D45) |
| core/tests/Support/DriveNameRulesConfigSeamTest.php | honours a host max_length (rules(maxLength: 10)); separators fixed independently of host naming length | ignores the deprecated max_length config and keeps the fixed 255-byte bound; separators fixed (P4.11 removed the argument — barrier correction, logged as deviation) |
| drive/tests/PublicApi/LifecycleBoundaryTest.php | restore/fallback destination grants by raw permission bits (9 / 5 / 1) | writer on the source (Restore) and writer-vs-reader on the destination (Create present/absent) |
| drive/tests/Feature/DriveAuthorizationTest.php | grant API body/response `level` | `role` |
| drive/tests/Feature/R07dObservableMutantTest.php | requested-parent case through the node-blind R07d driver | runs on the database driver with writer grants for the HTTP actor (user 41) on the folder and the file |
| drive/tests/PublicApi/CopySubtreeAuthorizationTest.php | (none) | + host driver role View+Copy+Create without Download refuses the whole copy (pins D30 Download on its own; negative control NC4) |
| core/tests/Access/AccessModelV1Test.php | (new) | grant HR before walling off /proj/hr (the owner reaches it only through the inherited grant); int keys of copy_current grants |
