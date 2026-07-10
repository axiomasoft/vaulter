# Upgrade Guide

This file documents breaking changes and how to migrate through them. Entries are ordered
newest-first. See `CHANGELOG.md` for the full list of changes per release; this file only covers
changes that require action from consumers of the package.

## Unreleased

### Realtime collaboration now needs its OWN feature flag; ActivityResource degrades instead of registering against a disabled subsystem (P3.1 / F-P1.6-01, F-P1.10-03)

**What changed.**

1. **A new flag, `config('vaulter.features.collaboration')` (default `false`), now gates the
   entire realtime-collaboration surface** — `POST nodes/{node}/collab/token`, the
   `collab/authenticate`/`collab/store` server-to-server webhooks, the private broadcast
   channel authorization, and the `ResolveCollaborationCall` middleware group. Previously all
   of this was gated ONLY by `features.document_versions`, so any host with document editing
   on automatically shipped the entire realtime/Node/webhook attack surface, whether or not it
   used collaboration. The two flags are now independent and BOTH must be `true` for the
   realtime routes to mount (`document_versions && collaboration`); `document_versions` alone
   continues to gate the non-realtime editing surface (`content` GET/PATCH, named
   versions/restore) exactly as before.

   **Who is affected.** Any host with `features.document_versions => true` that relies on
   realtime collaboration (Yjs sessions, the collab-token handshake, the Hocuspocus/Reverb
   webhooks).

   **What you must do.** Set `config('vaulter.features.collaboration', true)` (or
   `VAULTER_COLLAB_ENABLED`-style env wiring in your own config, if you introduce one) after
   upgrading — otherwise every collab endpoint starts 404ing. A host that only edits documents
   without realtime sync needs no change (it never relied on the collab endpoints, and now
   also no longer ships their surface).

2. **`ActivityResource` (Filament) is now ALSO gated on the core `activity` feature flag**, on
   top of its own existing `config('vaulter-filament.resources.activity')` toggle (default
   `true`). Previously the plugin consulted only its own toggle, so a host that disabled the
   core `activity` subsystem (`config('vaulter.features.activity', false)`) but left the
   Filament toggle untouched kept `ActivityResource` registered against a subsystem it had
   turned off (F-P1.10-03). This is a footgun/degradation fix, not a security fix: a host
   running with `features.activity` at its default (`true`) sees no change.

   **Who is affected.** Hosts that disabled `features.activity` while keeping the Filament
   plugin's `activity` resource toggle at its default `true` — `ActivityResource` now simply
   does not register (matching every other core-flag-gated Filament surface), instead of
   appearing in navigation.

**Docs corrected alongside this change (no code/behaviour impact):** the collaboration guide
and `ADR-0001` now correctly state `hocuspocus` (not `reverb`) as the default transport —
this ONLY affects documentation and a dead code-level fallback
(`Config::collaborationDriverName()`); the actual config default (`config/vaulter.php:755`)
was already `hocuspocus` and is unchanged (F-P3.6-01).

No database migration is required for either change in this section.

### Four Filament resources now scope their listing; PanelActor resolves through the panel's own guard; a boot-time guard throws on a tenancy footgun (P2.3 / F-P1.10-01, F-P1.10-02, F-P1.9-01)

**What changed.**

1. **`StorageResource`/`NodeGrantResource`/`NodeShareResource`/`ActivityResource` now scope
   `getEloquentQuery()` to the acting panel admin's grant-reachable candidate set
   (F-P1.10-01)**, the same candidate-set semantic `NodeResource` already used. Previously
   these four resources had no policy and no query scope, so ANY authenticated panel user
   saw every storage/grant/share/activity row of every owner — this closes a broken-access-
   control gap, so it is a security fix, not a regression. A host relying on every panel user
   seeing every row (e.g. a single trusted super-admin panel with the `null`/non-grant-
   authoritative driver) is unaffected — the narrowing only applies when the active
   `HostRoleDriver` is grant-authoritative (the standalone `database` driver) with the
   `grants` feature on; every other driver keeps the listing OPEN as before.
2. **`PanelActor::current()`/`id()` now resolve through the active panel's OWN auth guard**
   (`Filament::auth()`), not the application's global default guard (F-P1.10-02). A host whose
   panel runs on a non-default guard (`->authGuard('admin')` etc.) previously had every
   Vaulter Filament resource evaluate the WRONG identity's grants against
   `AccessResolver`. Hosts on the default guard (the common case) see no behaviour change.
3. **Booting with `vaulter.tenant.enabled=true` and the default (non-scoping) `Node` model now
   throws outside production** (F-P1.9-01, ADR-0003 Amendment). This was always a silent,
   fully-open-listing misconfiguration (the `tenant_id` column is populated but nothing
   filters by it); it is now loud everywhere except `app()->environment('production')`, which
   still only logs a warning (no crash in production). **Action required** if you enabled
   `vaulter.tenant.enabled` without swapping `config('vaulter.models.node')` for your own
   tenant-scoping subclass: either supply that subclass, or disable `vaulter.tenant.enabled`
   — both silence the guard.

### Trash now blocks download/export; purge-trash physically deletes; keep_forever is releasable (P2.2 / F-P1.12-01, F-P1.12-02, F-P1.12-03)

**What changed.** Three corrections to the trash/remanence lifecycle (see ADR-0016):

1. **A trashed node 404s on identity download/export, not just listings (F-P1.12-01).**
   `GET nodes/{node}/download`, `.../versions/{v}/download`, `nodes/{node}/export`, and the
   whole `DocumentNodeController` document surface (`content`/`versions`/autosave/named-
   version/restore/export) now 404 when the node's `trashed_at` is set, even for an actor
   whose ACL grant on it is still live. Previously only listings and the public share
   channel excluded trashed nodes — the identity download/export path did not, so a
   "deleted" file stayed fully downloadable for the whole retention window.
2. **`vaulter:purge-trash` now PHYSICALLY deletes rows past retention (F-P1.12-02).**
   Previously "purge" called the same stage-2 action as the HTTP force-delete endpoint — a
   `SoftDeletes` tombstone (`deleted_at`), never a real `DELETE` — so inline document
   content (`v_node_document_versions.content`/`yjs_state`) and version metadata stayed in
   the database forever, defeating the retention/erasure expectation. Purge now issues a
   genuine `DELETE` on the subtree after the retention window elapses, so FK
   `cascadeOnDelete` removes every dependent row (file/document versions, blob edges,
   grants, shares) too. **This is irreversible** — a host that relied on
   `Node::withTrashed()` to forensically inspect a purged node after retention will no
   longer find it.
3. **`keep_forever` can now be released (F-P1.12-03).** `PATCH
   nodes/{node}/document-versions/{version}/keep-forever` (guarded by the same `Edit`
   ability as setting the pin) clears the pin, so the version re-enters the prune window on
   the node's next snapshot/restore. Previously the pin, once set, could never be unset.

**Who is affected.**

- Any integration that polled/relied on `nodes/{node}/download` or `.../export` continuing
  to serve content for a node the user believed "deleted" (it is now `404` immediately)
  — this closes an information-disclosure gap, so this is a security fix, not a regression.
- Any operator/runbook that used `Node::withTrashed()` after `vaulter:purge-trash` to
  inspect or recover a "purged" node's data — that data is now genuinely gone.
- Anyone who pinned a document version `keep_forever` expecting it to stay pinned forever
  with no release path — a release path now exists; nothing is auto-released.

**What you must check.** If your host UI polls a download/export URL for a node the user
just trashed, expect a `404`, not a stale `200`. If a runbook depends on purge-trash being
recoverable, update it — purge is now genuinely irreversible.

### ACL core: role/team grants fail loud under non-enumerating drivers; wall-deny semantics clarified; new `explain()` diagnostic (P2.1 / F-P1.1-01, F-P1.1-02, F-P1.2-01)

**What changed.** Three corrections to the authorization core (`AccessResolver` and the
grant write-path), none of which changes an existing `can()` verdict on the default
`database` lane:

1. **A role/team grant under a non-enumerating driver now fails loud (F-P1.2-01).**
   `GrantNodeAccessAction::execute()` now inspects the active `HostRoleDriver`: if the
   grant's subject is a role/team subject (`subject_type` = the configured
   `vaulter.spatie.role_subject_type` / `team_subject_type`, default `role`/`team`) and
   the driver **cannot enumerate role subjects**, the grant would never take effect and
   Vaulter **logs a warning always**, and **throws
   `Vaulter\Exceptions\Access\UnenumerableGrantSubjectException` outside production**. A
   driver counts as enumerating when it either implements the marker
   `Vaulter\Contracts\Access\EnumeratesRoleSubjects` **or** overrides `subjectsFor()` to
   append role/team `Subject`s — so a host that follows the documented subclass-and-append
   extension path is recognised without the marker. Only the built-in base lanes that
   inherit `subjectsFor()` unchanged (the standalone `database` base, `gate`, `azgard`,
   `null`) trip the guard — they expand an actor only into itself. The `spatie` lane
   implements the marker and is unaffected. The failure direction is safe (an inert grant
   grants nothing), so production only logs.
2. **The `inherit_grants=false` "wall" is documented correctly (F-P1.1-01 / D17).** The
   `AccessResolver` docblocks used to claim a deny on a wall node was an "un-overridable
   wall … no nearer allow can ever pierce it". That was never the behaviour (nor what
   ADR-0007 specifies): a nearer explicit allow **below** the wall pierces such a deny,
   exactly as it pierces any other inherited deny. The code is unchanged; only the false
   guarantee was removed. If you locked down a subtree with `inherit_grants=false` + a
   subtree deny expecting an absolute barrier, re-audit — a nearer allow below it still
   grants access (an absolute wall-deny is a possible future feature, not current
   behaviour).
3. **New diagnostic `AccessResolver::explain()` (F-P1.1-02).** A public
   `explain(Authorizable $actor, Ability $ability, Node $node): AccessExplanation`
   returns the verdict, a machine-readable reason, the folded allow/deny masks + level,
   and the provenance chain of every grant the bounded walk visited (each tagged with
   depth and whether it counted). It runs a separate pass and never touches the hot
   `can()`/`effectiveGrants()` path.

**Who is affected.**

- Any host that writes `v_node_grants` rows addressed to a role/team subject under the
  `database`, `gate`, `azgard`, or `null` driver **with `subjectsFor()` inherited
  unchanged** — such a write now throws in non-prod and logs a warning in prod. Switch to
  the `spatie` driver, or subclass your driver and **override `subjectsFor()`** to append
  the role/team `Subject`s (optionally marking it `EnumeratesRoleSubjects`). A subclass
  that already overrides `subjectsFor()` to append those Subjects is unaffected.
- Anyone who relied on the (incorrect) "un-overridable wall" docblock guarantee — see
  point 2.

**What you must check.** Grep your integration for grant writes targeting `role`/`team`
subject types and confirm the active driver enumerates them; audit any
`inherit_grants=false` lockdown that assumed an absolute deny barrier.

No database migration or config change is required for this upgrade note.

### Mass-assignment hardening: every model now carries an explicit `$fillable`/`$guarded` list (P1.4 / F-P1.8-02, F-P1.5-04, F-P1.7-04)

**What changed.** All 16 package models used to declare `protected $guarded = [];` — Eloquent
mass-assignment fully open on every column, including service/security ones (`tenant_id`,
`token_hash`, `signing_secret`, `sha256`, `storage_key`, `use_count`, `status`, `owner_*`, …). Each
model now carries an explicit, closed list:

- **A closed `$fillable`** (only genuinely user-facing columns) on `Node`, `Storage`, `File`,
  `FileVersion`, `NodeShare`, `WebhookEndpoint`, `UploadSession`, `NodeComment`,
  `NodeDocumentVersion` — a key outside the list is silently discarded by `fill()`/`create()`.
- **A fully `$guarded = ['*']`** (Eloquent's "totally guarded" state) on `Blob`, `FileVersionBlob`,
  `NodeGrant`, `Activity`, `Outbox`, `WebhookDelivery` — these rows are exclusively server-written
  today (CAS identity, ACL, audit trail, outbox/delivery bookkeeping), so `fill()`/`create()` with
  ANY attribute now throws `Illuminate\Database\Eloquent\MassAssignmentException` instead of
  silently doing nothing.
- `File` carries a partial `$guarded` list (mostly-service columns, `mime_type` remains fillable).

**Why.** F-P1.8-02 (Minor): a latent footgun — any future `Model::create($request->validated())`/
`->fill($input)` on an unguarded model could let a caller set a service/security column. No
exploitable sink existed in the shipped code (every write path already used explicit server-built
arrays), but the package is a library other hosts extend — closing the class of bug matters more
than any single instance of it. F-P1.5-04/F-P1.7-04 single out `Blob`
(content-addressed CAS identity) and the three webhook models (`signing_secret`) as the
highest-value columns to protect.

**Who is affected.**

- A host subclassing a Vaulter model (`config('vaulter.models.*')`) that relies on mass-assigning a
  column outside the new list — e.g. `MyNode::create(['tenant_id' => …, 'name' => …])` — will now
  find `tenant_id` silently dropped (closed-`$fillable` models) or the whole call throwing
  `MassAssignmentException` (fully-`$guarded` models). Use `forceFill()`/`->fill()` on only the
  documented public columns, or extend the model to widen its own `$fillable` if your subclass
  genuinely needs to mass-assign more.
- No database migration or config change is required; this is a pure in-memory guard.

**What you must check.** Grep your integration for direct `::create([...])`/`->fill([...])` calls
against `Vaulter\Models\**`/`Vaulter\Drive\Uploads\Models\**`/`Vaulter\Documents\Models\**` (or
their configured subclasses) that pass a service column (`tenant_id`, `*_id`, `status`,
`signing_secret`, `token_hash`, `sha256`, `storage_key`, `use_count`, `granted_by`, `owner_*`, any
timestamp) — route that write through the corresponding action/repository (or `forceFill()` if you
are confident the value is trusted) instead.

### Public share-link surface hardened: no more presign-redirect, issuer re-check, default TTL, opaque public id (P1.2 / F-P1.3-01/02/03/05)

**What changed.** Four independent fixes to the bearer share-link surface
(`DownloadController`, `PublicShareController`, `ShareResolver`, `SharedNodeResource`):

1. **No more presign-redirect (F-P1.3-01).** Both `DownloadController::current()`/`version()`
   and `PublicShareController::download()` used to 302-redirect to a short-lived signed URL when
   the configured disk could mint one (any S3-compatible disk). They now ALWAYS stream the bytes
   through PHP, exactly like a disk without `providesTemporaryUrls()` always did. Their return
   type narrowed from `StreamedResponse|RedirectResponse` to `StreamedResponse`. A presigned URL
   is a bearer token that is never re-authorized per request and cannot be revoked instantly —
   D15 never sanctioned this mode; it was a defect, not a supported optimization.
2. **Issuer re-check on every share consumption (F-P1.3-02).** A bearer link now dies the moment
   its issuer (the actor who minted it, `granted_by`) loses `Ability::Share` on the node — not
   just when the link itself is explicitly revoked. Previously a link outlived its issuer's own
   access indefinitely (confused-deputy). A share with no recorded issuer (a pre-existing row
   minted before this fix, or one created by a system/console actor) is unaffected.
3. **Bearer links are never immortal by omission (F-P1.3-03).** A link created without an
   explicit `expires_at` now gets `config('vaulter.share.default_ttl_hours')` (default 72h)
   instead of never expiring. An explicit `expires_at` above
   `config('vaulter.share.max_ttl_hours')` (default 720h / 30 days) is now rejected by validation
   (422) instead of silently accepted.
4. **The public share JSON no longer exposes the raw node id (F-P1.3-05).** `SharedNodeResource`
   (`GET share/{token}`, `GET share/{token}/nodes`) now returns `id`/`parent_id` as an opaque,
   deterministic `hash_hmac`-derived string, not `Node::getKey()`. Under `id_type=bigint` the raw
   key is a sequential integer that leaked creation order/count to an anonymous link holder; under
   the default `ulid` it was already unguessable but is now opaque too, for consistency across id
   strategies. No public endpoint accepts a node id from the client, so this does not break
   addressing.

**Who is affected.**

- Anyone relying on `DownloadController`/`PublicShareController` returning a redirect on an
  S3-compatible disk (e.g. a CDN/proxy expecting a 302 to pass through) — the response is now
  always a streamed download through the application.
- Anyone parsing the `id`/`parent_id` fields of the public share JSON as the internal node key
  (e.g. correlating it against an owner-side API response) — it is now an opaque string with no
  relationship to `Node::getKey()` outside the share response itself.
- Anyone who created share links expecting them to never expire without an explicit
  `expires_at` — pre-existing links with a `null` `expires_at` are UNAFFECTED (the default only
  applies at mint time); newly minted links without an explicit expiry now expire after
  `default_ttl_hours`.
- Anyone whose share-minting form posts an `expires_at` further out than `max_ttl_hours` — that
  request now 422s; either request a shorter expiry or raise `vaulter.share.max_ttl_hours`.

**What you must check.** If you depend on any of the four behaviours above, override the
relevant config key (`vaulter.share.default_ttl_hours`, `vaulter.share.max_ttl_hours`) or adjust
client code that parsed the public `id`/`parent_id` as a real key or relied on a 302 from the
download endpoints.

No database migration is required for this upgrade note.

### Access/sharing events no longer dispatch after commit (P0.2 / F-P1.7-03)

**What changed.** Four kernel events dropped `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`
and are now dispatched **synchronously, inside the database transaction** of the mutation that
raises them:

- `Vaulter\Events\Access\NodeGrantCreated`
- `Vaulter\Events\Access\NodeGrantRevoked`
- `Vaulter\Events\Sharing\NodeShareCreated`
- `Vaulter\Events\Sharing\NodeShareRevoked`

**Why.** The package's transactional-outbox fix (P0.2 / F-P1.7-03) stages the outbox and activity
rows for these events in the *same* database commit as the grant/share mutation, to close a crash
window in which a committed grant/share could lose its outbox/activity row. That requires the
event — and therefore every listener on it — to run before the transaction commits, not after.

**Who is affected.** Only hosts that have registered a listener on one of the four events above.
If you have not, no action is needed.

**What you must check.** Open every listener class registered for `NodeGrantCreated`,
`NodeGrantRevoked`, `NodeShareCreated` or `NodeShareRevoked` (`EventServiceProvider::$listen`,
`Event::listen(...)` calls, or attribute-based `#[AsEventListener]` — including a listener that
happens to catch a broader event via an interface or the wildcard `*`), and confirm it satisfies
both of the following:

1. **It is synchronous.** The listener class must **not** implement `Illuminate\Contracts\Queue\ShouldQueue`.
   A queued listener attached to one of these events used to run only after the transaction
   committed; it now runs inside the still-open transaction, which either fails outright (no
   queue connection available mid-request in most setups) or — if it does get dispatched — can
   execute against a mutation that is later rolled back.
2. **It is idempotent and side-effect-safe if the surrounding transaction rolls back.** Because the
   listener now runs before commit, any work it performs (writes to another table, external HTTP
   call, cache write, mail dispatch, etc.) can execute even if the enclosing grant/share mutation
   is subsequently rolled back by the caller. Do not rely on "if the listener ran, the mutation
   committed."

**How to migrate a listener that needs an after-commit or queued guarantee.** Do not attach that
behaviour to the raw event. Instead, drive it off the package's durable transactional-outbox
relay, which already staged an outbox row atomically with the mutation and dispatches it only
after commit (`vaulter:relay-outbox`) with at-least-once delivery:

- If you need an outbound webhook, register a `WebhookEndpoint` subscribed to the corresponding
  outbox event type instead of listening to the Eloquent/domain event directly.
- If you need a queued side-effect (e.g. send a notification, update a search index), have your
  listener enqueue a job for the relayed webhook/activity payload — i.e. drive it from something
  that already only fires after commit — rather than from `NodeGrantCreated` /
  `NodeGrantRevoked` / `NodeShareCreated` / `NodeShareRevoked` themselves.

**If you cannot make your listener synchronous+idempotent right now**, detach it from these four
events (`Event::forget(...)` in your provider, or remove the listener registration) rather than
leave a queued/non-idempotent listener attached — an unreviewed queued listener on these events
will misbehave under transaction rollback.

No config change, migration, or database schema change is required for this upgrade note; it is a
pure code-contract change on the four events listed above.
