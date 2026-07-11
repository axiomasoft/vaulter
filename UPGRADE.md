# Upgrade Guide

This file documents breaking changes and how to migrate through them. Entries are ordered
newest-first. See `CHANGELOG.md` for the full list of changes per release; this file only covers
changes that require action from consumers of the package.

## Unreleased

### `EnumCaseAttributeResolver::forCase()`/`flush()` are no longer static — resolve the class through the container (P4.6 / F-P2.1-05)

**What changed.** `Vaulter\Support\EnumCaseAttributeResolver` (the enum-case PHP-attribute
reflection resolver backing the `HasIcon`/`HasLabel`/`HasTiptapExtensions` traits) moved its
reflection cache from a process-wide `private static array $cache` to an instance property
(`private array $cache`), and its two public methods moved from `public static function` to
`public function` accordingly:

```php
// Before
public static function forCase(UnitEnum $case, string $attribute): ?object
public static function flush(): void

// After
public function forCase(UnitEnum $case, string $attribute): ?object
public function flush(): void
```

The class is bound as a container `scoped()` singleton in
`VaulterServiceProvider::packageRegistered()` and resolved via
`app(EnumCaseAttributeResolver::class)` — the same pattern already used by `PanelRegistry`/
`IdStrategyResolver` elsewhere in the package. The three in-package call sites (`HasIcon`,
`HasLabel`, `HasTiptapExtensions`) were switched to this container-resolved form in the same
commit.

**Why.** The previous `static $cache` was process-wide, mutable state living outside the
facade (E3.3 — no global state outside the facade): under Octane/queue workers it silently
carried reflection results across unrelated requests/jobs sharing the same worker process, and
any two independent container scopes (e.g. two Testbench test cases in the same process)
unintentionally shared one cache. Binding the resolver as a container-scoped singleton ties the
cache's lifetime to the container/request scope instead of the whole PHP process.

**Breaking consequence.** Calling `EnumCaseAttributeResolver::forCase(...)` or
`EnumCaseAttributeResolver::flush()` **statically** now fatals immediately with `Error:
Non-static method Vaulter\Support\EnumCaseAttributeResolver::forCase() cannot be called
statically` (`::flush()` likewise) — this is a pre-1.0 breaking change shipped without a
deprecation cycle (D3), not a deprecation-then-removal.

**Who is affected.** Only host code that calls `EnumCaseAttributeResolver::forCase(...)` or
`::flush()` directly and statically, bypassing the package's own `HasIcon`/`HasLabel`/
`HasTiptapExtensions` traits (the traits themselves already resolve the instance through the
container and need no changes on your side).

**What you must do.** Resolve an instance through the container instead of calling the class
statically:

```php
// Before
$label = EnumCaseAttributeResolver::forCase($case, Label::class);
EnumCaseAttributeResolver::flush();

// After
$label = app(EnumCaseAttributeResolver::class)->forCase($case, Label::class);
app(EnumCaseAttributeResolver::class)->flush();
```

No config change or database migration is required for this upgrade note.

### `@vaulter/vue`: `yjs` and `@tiptap/pm` moved to `peerDependencies` — install them yourself (P4.7 / F-P2.5-07)

**What changed.** `packages/vue/package.json`'s `yjs` (`^13.6`) and `@tiptap/pm` (`^3.0.0`) moved
from `dependencies` to `peerDependencies` (matching the ranges already shipped and tested). They
stay listed in `devDependencies` too so the package's own build/test/dev loop is unaffected.
`vue` was already a peer; these two join it for the same reason.

**Why.** Both libraries hold module-level singleton state — `yjs` a `Y.Doc` registry, `@tiptap/pm`
a ProseMirror instance/schema. A host that itself uses TipTap/ProseMirror or Yjs (very likely for
an app embedding Vaulter specifically for collaborative editing) previously risked a SECOND copy
of these libraries in `node_modules` whenever bundler/npm dedup missed a matching minor version —
two `Y.Doc` instances for the same document silently diverge instead of syncing; two ProseMirror
instances give `instanceof` mismatches between the host's schema and `@vaulter/vue`'s. Moving them
to `peerDependencies` makes the host own a single shared instance the same way it already owns
`vue`.

**Who is affected.** Every consumer of `@vaulter/vue` — npm (`>=7`) auto-installs missing peers by
default, so `npm install` alone may already satisfy this; older npm, `--legacy-peer-deps`, `pnpm`
in strict mode, or a lockfile that never resolved these two packages at the top level will instead
warn/fail on missing peers.

**What you must do.** Ensure `yjs@^13.6` and `@tiptap/pm@^3.0.0` (or a version your package
manager considers compatible with those ranges) are present in your own `dependencies` —
add them explicitly if your install reports them missing or your bundler complains about a
duplicate/unresolved peer.

No PHP-side change; this only affects `@vaulter/vue` npm consumers.

### Node-name policy is now a config seam; the reject message is now a lang key; `NodeExportService` takes a new constructor dependency (P4.5 / F-P3.1-01, F-P3.1-02, F-P3.1-04)

**What changed.**

1. `DriveNameRules::rules()`/`messages()` (the shared name validation for
   create/rename/upload/copy Drive requests) now read `config('vaulter.drive.name_rules.
   max_length')` and `config('vaulter.drive.name_rules.no_separators_pattern')` instead of
   hardcoding `255` and the slash-reject regex. Defaults are byte-identical to the previous
   hardcode — a host that never touches this config key sees no behaviour change.
2. The reject message (`'The name must not contain path separators (/ or \\).'`) is now
   resolved through `trans('vaulter::validation.name_no_separators')` (a new package lang
   file, publishable via `--tag=vaulter-translations`) instead of an inline string literal.
   Default text is unchanged.
3. `Vaulter\Documents\Documents\NodeExportService` (the node-attached document export path)
   gains a new constructor dependency, `Vaulter\Documents\Contracts\Export\
   ExportFilenameResolver`, bound by `VaulterDocumentsServiceProvider` to a new
   `DefaultExportFilenameResolver` that reproduces the pre-P4.5 filename scheme (node
   basename + exporter extension) unchanged. The class stays `final` (it is covered by the
   `Vaulter\Documents\Documents`-namespace `ArchTest` finality gate, P4.1/H.5) — a host
   wanting a different export filename scheme rebinds `ExportFilenameResolver` in its own
   provider instead of subclassing.

**Why.** F-P3.1-01/02 (Minor/Nit): the name policy and its message were hardcoded in a
`final` class with no config/lang seam, forcing a host with a different naming policy or
locale to fork the file. F-P3.1-04 (Minor): the export filename scheme was a `private`
method on the same `final` class — no override path existed at all.

**Who is affected.** Config/lang consumers: nobody unless they now explicitly opt into a
custom `name_rules`/translation — defaults are unchanged. `NodeExportService` consumers:
only a host that manually `new`s the class instead of resolving it through the container
(the documented/only supported way) — container resolution picks up the new dependency
automatically via the provider's binding.

### `VaulterManager::for()`/`registerHandler()`/`handlers()` now throw a `MissingPackageException` (not a raw `BindingResolutionException`) when `vaulter-drive`/`vaulter-documents` are absent (P4.4 / F-P3.3-02, F-P3.5-01)

**What changed.** These seams previously let the container's generic
`Illuminate\Contracts\Container\BindingResolutionException` ("Target [...] is not
instantiable.") propagate — a message with no mention of "vaulter" or which composer package to
install. They now catch it and throw `Vaulter\Exceptions\Support\MissingPackageException`
instead, naming the missing contract and the exact `composer require` to run.

**Why.** The docblocks of `HandlerRegistry`/`NodeHandlerResolver` already claimed a "fails
loudly with an install hint" — this makes that true.

**Who is affected.** Only a host that specifically caught `BindingResolutionException` around
these calls to detect a missing satellite package — that catch no longer matches
(`MissingPackageException` still extends the base `RuntimeException`-rooted `VaulterException`,
so a broad `catch (\Throwable)`/`catch (\Exception)` is unaffected).

### Dead `StorageAdapter` seam removed: `Contracts\Cas\StorageAdapter`, `Support\DefaultStorageAdapter`, `config('vaulter.storage_adapter')` (P4.4 / F-P3.1-03)

**What changed.** `Vaulter\Contracts\Cas\StorageAdapter`, its no-op default implementation
`Vaulter\Support\DefaultStorageAdapter`, the `config('vaulter.storage_adapter')` key and
`Config::storageAdapterClass()` are removed entirely.

**Why.** This was a fully-declared seam (contract + default implementation + config-bound FQCN
resolver) left over from an earlier, pre-CAS milestone — nothing in the package ever resolved
`Config::storageAdapterClass()` or bound `StorageAdapter` in any provider. Real binary-attachment
persistence goes through the independent, actually-wired `StorageDriver`/`ContentStore` CAS seam
(`packages/media`). Keeping the dead seam around was a DX trap: a host reading the contract and
binding an implementation against it got a class that was never called.

**Who is affected.** Only a host that bound its own `StorageAdapter` implementation via
`config('vaulter.storage_adapter')` or referenced `Vaulter\Contracts\Cas\StorageAdapter` /
`Vaulter\Support\DefaultStorageAdapter` directly — given the seam was never invoked, this is
expected to be nobody in practice.

**What you must check.** If you implemented a `StorageAdapter` expecting it to handle binary
attachment storage, remove the config key/binding and use the `StorageDriver`/`ContentStore`
CAS contracts (`config('vaulter.cas.storage_driver')` / `config('vaulter.cas.content_store')`)
instead — that is the seam real persistence has always gone through.

### Node name search now escapes `%`/`_`/`\`; cycle detection now on for tree reads (P4.2 / F-P1.8-03, F-P4.2-02)

**What changed.** Two `packages/core` behaviour changes, both narrowing a previously-loose
result rather than adding a new capability:

1. `NodeReadRepository::search()` (backing the `GET storages/{storage}/search` endpoint) now
   escapes `%`, `_` and `\` in the caller-supplied search term before it is wrapped in `LIKE`
   wildcards (`... like ? escape '\'`). A term of `%` or `_` used to match every node in the
   tree (unescaped `LIKE` wildcard); it now matches only nodes whose name literally contains
   that character.
2. `Vaulter\Models\Drive\Node::enableCycleDetection()` now returns `true` (the
   staudenmeir/laravel-adjacency-list trait default is `false`). `ancestors()`/`descendants()`
   (and everything built on them: `AccessResolver::ancestorChain()`, breadcrumbs, subtree
   reads) now stop the recursive CTE at a repeated row instead of recursing without a bound.

**Why.** (1) a search term containing `%`/`_` previously behaved as a wildcard rather than a
literal character — surprising results, and a pathological term could force a full-table scan.
(2) the only existing guard against a `parent_id` cycle was `MoveNodeAction::assertNoCycle()`,
exercised solely on the move-through-Action write path; a cycle introduced any other way (raw
SQL, a seeder, a legacy import) went undetected on read and could exhaust memory/hang a request.

**Who is affected.** (1) Any caller of the node search endpoint/repository method whose search
terms may contain a literal `%` or `_` and previously relied on (or worked around) the
wildcard behaviour. (2) Only hosts whose data can contain a `parent_id` cycle bypassing
`MoveNodeAction` (raw writes, imports, seeders) — a tree with no cycle is completely unaffected;
a tree that does have one will now get a bounded/truncated ancestor or descendant list instead
of an unbounded recursive scan.

**What you must check.** If you depended on `%`/`_` behaving as a search wildcard, switch to
a proper wildcard/filter API instead (this was never a documented contract). If you suspect
cyclic `parent_id` data (only reachable by bypassing the package's own write paths), audit and
repair it — cycle detection now surfaces the cycle as a truncated traversal instead of masking
it with an unbounded/failing query.

No config change, migration, or database schema change is required for this upgrade note.

### `firebase/php-jwt` raised to `^7.0` (CVE-2025-45769) — signing secrets now need ≥256 bits (P4.1 / F-P4.4-04)

**What changed.** `firebase/php-jwt` moved from `^6.0` to `^7.0` (root `composer.json` and
`packages/documents/composer.json`, the package minting the Hocuspocus collaboration room JWT).
`^6.0` was affected by CVE-2025-45769 ("weak encryption"); `^7.0` closes it and adds a HS256
minimum-key-length check (`DomainException: Provided key is too short` for keys under 256 bits /
32 bytes).

**Action required if you set a short `vaulter.collaboration.drivers.hocuspocus.secret`.** Any
existing HS256 signing secret shorter than 32 bytes now throws at JWT-mint time instead of
silently signing with a weak key. Generate a secret with at least 32 bytes, e.g.
`php -r 'echo bin2hex(random_bytes(32));'` (64 hex chars = 32 bytes), and set it via
`VAULTER_COLLAB_JWT_SECRET`/config. No action needed if your secret was already ≥32 bytes.

### `happy-dom`/`vitest` raised in `packages/vue` devDependencies (critical RCE) — no consumer action (P4.1 / F-P4.4-05)

**What changed.** `packages/vue`'s test tooling moved: `happy-dom` `^15.11.0` → `^20.10.6` (closes
a critical VM Context Escape / RCE advisory, GHSA-37j7-fg3j-429f) and `vitest` `^2.1.0` → `^4.1.10`
(pulled in by the happy-dom fix). Both are devDependencies — **never shipped in the published
`dist/` bundle** — so this is not a consumer-facing change; only contributors running the test
suite are affected. One internal test fixture (`tests/drive-components.test.ts`) needed a
`window.prompt` stub before `vi.spyOn` (happy-dom ≥16 no longer defines `window.prompt` at all).

**Known residual (tracked, not a regression from this change).** `npm audit --audit-level=high`
in `packages/vue` still reports one advisory: `vite <=6.4.2` (esbuild dev-server request/response
disclosure, GHSA-67mh-4wv8-2f99, moderate — the severity shown against the `vite` entry is
inherited from its `esbuild` dependency). This is dev-server-only (never shipped), pre-existing
before this change, and NOT the critical happy-dom RCE this entry closes. A full fix needs a
`vite` 6→8 major bump; attempted and reverted here because `vite-plugin-dts` (Vue `.d.ts`
generation) has no release compatible with `vite@8` yet (its `5.0.3` Volar-based rewrite needs an
undeclared `@vue/language-core` dependency and breaks `npm run build`). Tracked for a follow-up
once `vite-plugin-dts` ships `vite@8` support.

### Reference Hocuspocus `docker-compose.yml` no longer defaults to `:latest` (P4.1 / F-P1.8-05)

**What changed.** `hocuspocus-server/docker-compose.yml`'s `image:` now requires
`VAULTER_COLLAB_IMAGE_TAG` to be set (no floating `:latest` default) — the file already documented
"pin a version" in its header comment, but silently defaulted to `:latest` otherwise.

**Action required if you copied this compose file.** Set `VAULTER_COLLAB_IMAGE_TAG` to a released
`vaulter-hocuspocus` tag (e.g. `1.2.3`) or, in production, an immutable `@sha256:…` digest — see
`docs/guide/hocuspocus-server.md`. Without it, `docker compose up` now fails fast at interpolation
time instead of silently floating to whatever `:latest` currently resolves to.

### `@tiptap/*` raised from `2.x` to `3.x` in `packages/vue` and `e2e/` — no code changes needed (P4.1 / F-P4.5-01)

**What changed.** `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/vue-3`, `@tiptap/extension-link`
(`packages/vue`) and `@tiptap/extension-collaboration` (`e2e/`) moved from `^2.10` to `^3.0.0`.
Verified mechanical: `npm run build`/`typecheck`/`test` (102 tests) pass unchanged in
`packages/vue`, and the `e2e/` playground builds cleanly — no source changes were required by the
TipTap 3.0 API.

**`@hocuspocus/*` stays on `2.x` for now (F-P4.5-02 — NOT bumped, see Known Deviations in
`plans/VLT-REMED/phases/P4.md` P4.1).** Bumping `@hocuspocus/server`/extensions/`provider` to
`^4.3.0` made the server's own `websocket-hardening.test.js` regression suite hang indefinitely —
that suite asserts a v2.x-specific internal wiring path (`listen(port, host, websocketOptions)`
3rd-argument propagation into the underlying `ws` server), which v4's internals appear to have
reshaped. This is a real API-shape change, not a mechanical version bump, so it was reverted and
escalated per the item's own escalation guard (D6) rather than forced green.

### New optional config('vaulter.connection') routes the whole schema onto a named connection (P3.6 / F-P2.3-02, F-P2.3-04)

**What changed.** A new, nullable config key — `config('vaulter.connection')` (env
`VAULTER_DB_CONNECTION`) — lets a host route every Vaulter-owned table/model onto a named
Laravel database connection instead of the application's default one (sharding, data placement,
isolating a heavy CAS/activity workload), without forking the package. `PanelTablesBuilder` now
creates/drops tables and runs every driver-aware index statement through
`Schema::connection(config('vaulter.connection'))`/`DB::connection(...)`, and every shipped model
(`Node`, `Storage`, `NodeGrant`, `Activity`, `File`, `FileVersion`, `FileVersionBlob`, `NodeShare`,
`Outbox`, `WebhookEndpoint`, `WebhookDelivery`, `Blob`) now resolves `getConnectionName()` from the
same key via the new `Vaulter\Models\Concerns\ResolvesVaulterConnection` trait.

**This is purely additive — no action required.** The key defaults to `null`, which resolves
exactly as before (the host's default connection); nothing changes unless you explicitly set it.

**Known gap — `packages/drive`/`packages/documents` models.** Only the 12 `packages/core` models
(`Node`, `Storage`, `NodeGrant`, `Activity`, `File`, `FileVersion`, `FileVersionBlob`, `NodeShare`,
`Outbox`, `WebhookEndpoint`, `WebhookDelivery`, `Blob`) resolve `getConnectionName()` from this
key. `UploadSession`/`UploadPart` (`packages/drive`) and `NodeComment`/`NodeDocumentVersion`
(`packages/documents`) do NOT — their tables ARE created on `config('vaulter.connection')` (same
`PanelTablesBuilder`), but the models still resolve to the host's default connection. If you set
`VAULTER_DB_CONNECTION` to a non-default connection, these 4 models will throw "table not found"
on first access. Do not point `vaulter.connection` away from the default while using drive
upload-session or document-comment/version features until this is closed in a follow-up.

**Docs:** `docs/guide/database-compatibility.md` gained a "Dedicated database connection" section
covering this key, and a "Schema change policy" section formalising how the shipped `v_*` schema
is allowed to evolve after the package's first tag (F-P2.3-04, doc-only — no code/behaviour
change).

No database migration is required for this change.

### The tus resumable-upload endpoints now enforce the tus 1.0.0 protocol: offset-mismatch is 409 (not a bare redirect/422), a wrong PATCH Content-Type is 415, a missing/wrong `Tus-Resumable` is 412, an OPTIONS discovery endpoint exists, and metadata keys are whitelisted (P3.4 / F-P1.4-02, F-P1.4-05, F-P1.4-06, F-P1.4-07, F-P1.4-08)

**What changed.**

1. **A tus PATCH whose `Upload-Offset` does not match the server's authoritative offset now
   returns `409 Conflict`** (with the CURRENT server offset in the `Upload-Offset` response
   header), instead of a bare 302 redirect (non-JSON request) or 422 (JSON request) a plain
   Laravel validation error rendered before. A retransmit of a chunk whose bytes are IDENTICAL to
   what is already staged at that exact byte range is still accepted (204) — only a genuine
   mismatch (different bytes, or a "jump ahead" past the server's offset) is now a 409.

   **Breaking consequence:** any client-side code that specifically branched on 302/422 for a
   tus offset conflict must branch on 409 instead — this is what real tus clients already expect
   per the protocol.

2. **A PATCH whose `Content-Type` is not `application/offset+octet-stream` now returns `415`**
   before any byte is appended (previously accepted and staged regardless of Content-Type).

3. **HEAD/PATCH/DELETE on a resumable-upload session now require the `Tus-Resumable: 1.0.0`
   request header** — a request missing it, or naming an unsupported version, is refused with
   `412 Precondition Failed` (carrying `Tus-Version: 1.0.0`). Previously the header was set on
   RESPONSES but never checked on requests.

   **Breaking consequence:** any client issuing HEAD/PATCH/DELETE against these endpoints WITHOUT
   the `Tus-Resumable` header now gets 412 instead of succeeding. A real tus client already sends
   this header on every request; only a bespoke/incomplete client integration is affected.

4. **`OPTIONS` on a resumable-upload handle is now a real discovery endpoint**, answering (without
   authorization) with `Tus-Resumable`, `Tus-Version: 1.0.0`, `Tus-Extension: creation,termination`
   and `Tus-Max-Size` (from `uploads.max_bytes`, omitted when unbounded). The checksum extension is
   NOT implemented and therefore deliberately not advertised.

5. **Resumable-upload metadata (`Upload-Metadata` header or JSON `metadata`) is now filtered
   through a closed key allow-list** (`vaulter.uploads.metadata.allowed_keys`, default `filename`,
   `filetype`, `name`, `mime_type`) and every accepted value is truncated to
   `vaulter.uploads.metadata.max_value_length` (default 512 characters). A key outside the
   allow-list is silently dropped, not rejected.

   **Breaking consequence:** any host relying on an arbitrary/custom metadata key surviving to
   `v_upload_sessions.metadata` must add that key to `vaulter.uploads.metadata.allowed_keys`.

**Who is affected.** Any consumer of the tus resumable-upload endpoints (`resumable-uploads/
{upload_id}`) — a compliant tus client already sends `Tus-Resumable` and the correct
`Content-Type`, and already expects 409 on offset conflict, so most integrations need no change.
A host relying on a non-standard metadata key, or on the pre-fix 302/422/204-on-anything
behaviour, must adjust as described above.

No database migration is required for this upgrade note.

### Upload-session lifecycle: a stuck Assembled upload now gets swept, only the session's OWNER may drive it, and initiate/abort/expire now write activity rows (P3.3 / F-P1.4-10, F-P1.4-12, F-P1.4-13)

**What changed.**

1. **The sweeper (`vaulter:sweep-uploads`) now reclaims a session stuck in `Assembled`** — every
   byte landed but the commit that follows was interrupted — once it is past its TTL, exactly as
   it already did for `Initiated`/`Uploading`. Before this, an `Assembled` session was never
   swept: its staged temp (and any known S3-multipart parts) leaked forever. A session that
   reaches `Assembled` well within its TTL is unaffected — `expires_at` is stamped once at
   `initiate` and never bumped, so only a session that got stuck PAST its original TTL is
   reclaimed.

   **Who is affected.** Any host running `vaulter:sweep-uploads` (or its schedule) with resumable
   uploads whose commit step can be interrupted after assembly (process kill, deploy mid-request,
   `BlobStore.commit` failure). No action required — this only reclaims sessions that were
   already permanently stuck.

2. **`authorizeSession` (tus HEAD/PATCH/DELETE and S3-multipart parts/complete) now ALSO checks
   that the acting actor is the session's `created_by` owner**, on top of the existing
   Create/Edit ability check on the target folder/node. Previously any actor holding a
   legitimate Create/Edit grant on the SAME folder/node — having merely learned the
   (unguessable, server-minted) `upload_id` — could HEAD/PATCH/DELETE or drive someone ELSE's
   in-flight session to completion or abort it. A session with no recorded `created_by`
   (system-minted, no owner to compare against) is unaffected — the ability gate alone still
   applies.

   **Breaking consequence:** a session that used to be drivable by ANY actor sharing the
   Create/Edit grant on its target is now refused (403) to everyone except its original creator.

   **Who is affected.** Any host where multiple actors share a Create/Edit grant on the same
   folder/node AND rely on being able to resume/abort a colleague's resumable-upload session by
   its `upload_id`. This was never a supported/intended workflow (the finding is exactly that it
   should not have worked) — if your integration depended on it, have the ORIGINAL uploader
   drive their own session instead, or open a new session under the actor who needs to act.

3. **`initiate`/abort (client DELETE)/`expire` (sweeper) on a resumable-upload session now each
   write ONE activity row** (`upload_session.initiated` / `upload_session.aborted` /
   `upload_session.expired`, gated on the existing `features.activity` flag, same as every other
   Drive activity row). Previously only the terminal commit
   (`file.uploaded`/`version.created`) was audited; session creation/abandonment/reclaim left no
   trace at all. `expire` rows carry no actor (the sweeper runs outside an HTTP request).

   **Who is affected.** Hosts consuming the activity feed (`GET .../activity`, the Filament
   `ActivityResource`) will start seeing three new verbs. No action required unless your
   integration enumerates a closed list of expected verbs.

No database migration is required for this upgrade note.

### Every collab webhook call must now carry a valid HMAC body signature; `webhook_secret` is REQUIRED for collaboration to work at all (P3.2 / F-P1.6-02, F-P1.6-03)

**What changed.**

1. **`collab/authenticate` and `collab/store` now require an HMAC-SHA256 signature of the
   raw request body**, on top of the existing identity check (the shared `webhook_secret`
   header OR a valid room JWT bearer token). The signature is computed over
   `"{timestamp}.{rawBody}"` — the SAME canonical string `Vaulter\Webhooks\WebhookSigner`
   already uses for outbound webhook deliveries — keyed by
   `config('vaulter.collaboration.webhook_secret')`, and presented in two new headers:
   `X-Vaulter-Collab-Timestamp` + `X-Vaulter-Collab-Signature`. A call with a missing
   signature, a timestamp outside `config('vaulter.collaboration.webhook_timestamp_tolerance')`
   (default ±300s), or a replayed signature (the same signature seen again within that
   window) is now refused with a 401 — even when its identity (secret/JWT) is perfectly
   valid. Previously the request body was never signed at all, on either identity path, so a
   captured secret/JWT let an attacker replay or tamper with the `yjs_state`/`node_id` payload
   indefinitely.

   **Breaking consequence:** because the signing key is ALWAYS `webhook_secret` — regardless
   of whether the caller identifies via the secret header or a JWT — `webhook_secret` is now
   REQUIRED for ANY collab webhook call to succeed. A deployment running the JWT-only identity
   path with no `webhook_secret` configured will find every `collab/authenticate`/`collab/store`
   call 401 after upgrading.

   **Who is affected.** Any host running the Hocuspocus collaboration hub
   (`config('vaulter.features.collaboration', true)`, VLT-REMED P3.1).

   **What you must do.** Set `VAULTER_COLLAB_WEBHOOK_SECRET` (both on the Laravel app and on
   the `hocuspocus-server` Node hub, via its own `VAULTER_COLLAB_WEBHOOK_SECRET` env var) if you
   have not already. Ensure the Laravel app server and the Node hub have closely synchronised
   clocks (NTP) — a clock skew beyond the ±300s tolerance window causes signed calls to be
   refused as stale; the tolerance is configurable via
   `config('vaulter.collaboration.webhook_timestamp_tolerance')`.

2. **`hocuspocus-server` now arms a server-side timer that force-closes a live collaboration
   WebSocket once its JWT's `exp` passes**, instead of relying solely on the debounced
   `onStoreDocument` write to re-validate the token. Previously a long-lived READ-ONLY viewer
   connection (which never triggers a store) could keep syncing over the open socket
   indefinitely past its token's expiry, since only a write re-checked `exp`.

   **Who is affected.** Any host running the Hocuspocus hub with viewer (read-only) sessions
   left open past the collab token TTL (`config('vaulter.collaboration.drivers.hocuspocus.
   token_ttl')`, default 3600s). No action required — this tightens enforcement automatically;
   a client that needs a longer session should request a fresh token before its current one
   expires (reconnect), same as before.

No database migration is required for either change in this section.

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
