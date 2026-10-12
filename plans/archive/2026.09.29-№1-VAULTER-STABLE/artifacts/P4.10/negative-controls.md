# P4.10 negative controls

Each control injects one fault, shows `ShareLinkLifecycleTest` red, restores the exact bytes (sha256 prefix compared) and shows it green. SQLite (`APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory:`), runner `scratchpad/nc.py`.

### NC1 — tenant audience comparison removed
- file: `packages/core/src/Sharing/ShareResolver.php`
- fault: `return $current !== null
            && $current === TenancyBoundary::normalize($drive->te` -> `return $current !== null;`
- red: failed=1 of 13; failing: _keeps_a_foreign_tenant_out_of_a_tenant_link_with_an_opaque_404
- restored: sha256[:12] `607c308fce64` == `607c308fce64`
- green: passed 13 of 13

### NC2 — rotate keeps the old generation
- file: `packages/core/src/Repositories/Sharing/NodeShareStoreRepository.php`
- fault: `'token_generation' => $share->token_generation + 1,` -> `'token_generation' => $share->token_generation,`
- red: failed=1 of 13; failing: _rotates_the_token__the_old_one_is_a_404__the_new_one_works__sessions_of_the_old_one_are_vo
- restored: sha256[:12] `2ddb6fcb910a` == `2ddb6fcb910a`
- green: passed 13 of 13

### NC3 — previewer download check removed
- file: `packages/core/src/Http/Controllers/Sharing/PublicShareController.php`
- fault: `if (!$context->allows(Ability::Download)) {
            throw PermissionDeniedException::f` -> `(removed)`
- red: failed=1 of 13; failing: _refuses_a_previewer_link_the_original_while_it_shows_the_node
- restored: sha256[:12] `a4dbf07f26d1` == `a4dbf07f26d1`
- green: passed 13 of 13

### NC4 — policy re-check removed from resolver
- file: `packages/core/src/Sharing/ShareResolver.php`
- fault: `|| !$this->rules->holds($drive, $share, Carbon::now())) {` -> `) {`
- red: failed=1 of 13; failing: _caps_a_link_at_the_drive_policy_and_applies_a_tightened_policy_to_existing_links_at_once
- restored: sha256[:12] `607c308fce64` == `607c308fce64`
- green: passed 13 of 13

### NC5 — session ignored (redeem on every request)
- file: `packages/core/src/Sharing/ShareResolver.php`
- fault: `$inSession = $this->sessions->valid($session, $share, $now);` -> `$inSession = false;`
- red: failed=2 of 13; failing: _counts_the_redemption_once_per_link_session_however_many_preview_requests_follow; _lets_a_visit_that_already_redeemed_keep_working_once_max__uses_is_reached__and_refuses_new
- restored: sha256[:12] `607c308fce64` == `607c308fce64`
- green: passed 13 of 13
