# ADR-0020 — Authorized public API

**Status:** accepted

> Transfers decisions D06–D09 of the 2026-09-29 stable audit
> (`audits/2026-09-29-stable-audit/opus/02-decisions.md#d06` … `#d09`) into the project ADR set.
> Normative signatures live in `audits/2026-09-29-stable-audit/opus/05-php-api.md` §3–§7.

## Context

The drive handle passes a received `Node` to Actions without checking that it belongs to the
handle's drive: `trash`, `forceDelete` and `rename` of a foreign node through a "foreign" handle
succeed (audit finding N27). Filament actions check rights only through `->visible()` and call a
non-authorizing facade — `move` checks `Move` on the source node but not `Create` on the
destination folder (N14). Authorization helpers (`guard()/guardCreate()/authorizable()`) are
copy-pasted across about eight controllers.

There must be one boundary that guarantees identical behaviour for HTTP, the facade, Filament and
jobs — the Laravel analogue is `Gate` with the current user and `Gate::forUser()`.

## Decision

**1. The public PHP API stays Laravel-native (D06).**

- Methods accept and return Eloquent models; a node argument is typed `Node|int|string` (model or
  key).
- The handle **never trusts** the state of a passed model: it takes the key and re-reads the node
  within its own drive (under a lock for writes); a foreign drive → `NodeNotFoundException` (N27).
- Lists return `NodePage` (items + `nextCursor`), not a `Collection`.
- Ambiguous positional arguments are replaced by typed `*Options` / `UploadSource`.
- `actorId` parameters are removed from all methods: the subject is set on the handle.

**2. The public API always authorizes; the system path is explicit (D07).**

```php
$drive = Vaulter::drive($project);                              // actor = current guard user, lazily
$drive = Vaulter::drive($project)->actingAs($user);             // explicit user, new immutable handle
$drive = Vaulter::drive($project)->asSystem('nightly import');  // no ACL, audited as system
```

- Every handle operation calls `OperationGuard` before executing.
- No actor set and the guard returns no user → `MissingActorException` (HTTP 401).
- `asSystem($reason)` bypasses ACL but **not** tenancy and **not** invariants; it writes activity
  with `actor_type = 'vaulter:system'` and the reason.
- Queues and console must explicitly choose `actingAs()` or `asSystem()`: a worker has no "current
  user".

The handle contract is `Vaulter\Contracts\Drive\DriveHandle` (`actor()`, `actingAs()`,
`asSystem()`, `withCorrelationId()`, `nodes()`, `folders()`, `files()`, `trash()`, `access()`,
`shares()`, `activity()`, `extension()`); its implementation is immutable — `actingAs()` and the like
return a clone. The operation context is `Vaulter\Operations\OperationContext` (drive, `Actor`,
correlation id), with `OperationContext::system()` for jobs and commands without a handle; `Actor`
is `user` or `system`, persisted as `ActorRef` (`SYSTEM_TYPE = 'vaulter:system'`).

**3. Application layers and who calls whom (D08).**

```
HTTP controllers ─┐
Filament actions ─┼──►  Operations (public, authorize) ──► Actions (@internal: invariants,
Host code ────────┘        │                                transaction, events)
                           └──► Queries (@internal: read + ACL narrowing)        │
Jobs/Console ──(OperationContext::system)────────────────────────────────────────┘──► Repositories (@internal)
```

- **Operations** implement the handle sub-APIs (`NodeOperations`, `FileOperations`, …): resolve
  nodes, call `OperationGuard`, build `*Input`, call an Action/Query.
- **Actions** — `execute(OperationContext $context, XxxInput $input): Result`; hold all invariants
  (tree, quota, upload policy, names) and the transaction; publish events through `EventRecorder`
  ([ADR-0023](/adr/0023-event-pipeline)). They do not authorize.
- **Queries** — reads with exact ACL narrowing.
- Controllers and Filament do **not** import Actions/Repositories (arch test).
- HTTP is a client of the same PHP API as the host: permission parity by construction.

**4. Resource hiding (D09).** No `View` right on a node (or the node is in another drive/tenant) →
`NodeNotFoundException` (404). `View` present but the required ability missing →
`PermissionDeniedException` (403). Listing and search never reveal nodes without `View`.

*Name-conflict exception (Q4, D33).* A user with `Create` on the parent can learn that a name is
occupied by a sibling they cannot `View`; this disclosure is inherent to sibling-unique names and
accepted for 1.0. It is limited to the occupancy bit: under `Fail` the 409 carries no id and no
details of the hidden sibling, and `AddRevision` onto a hidden node answers 404.

## Consequences

- "Who may" (Operations + `OperationGuard`) and "what is allowed" (Actions) are separated; no public
  entry point skips either.
- The per-controller `guard()/guardCreate()/authorizable()` copy-paste disappears.
- Host code, HTTP, Filament and jobs share one authorization path; background work must name its
  actor explicitly.
- Cross-drive node access through a handle is impossible: models passed in are re-read by key within
  the handle's drive.
- Existence of unreadable nodes is not disclosed (404 instead of 403).

## Compatibility before 1.0

The package has no published release (0.2.0 was never published, plan decision D02) and ADR-0017
records the same "no BC obligation" stance for the structure moves. Until 1.0, renames and removals
that this ADR and ADR-0019/0021 require are made outright, without aliases or deprecation shims, and
each one has an `UPGRADE.md` entry. From 1.0 on, the public-api rule applies: removing or renaming a
contract method, or narrowing a parameter type, is a MAJOR change.

## Layers

`Operations/` (public entry points per sub-API, plus `NodeLocator`, `NodeSequence`, `OperationContext`)
and `Queries/` (reads with ACL narrowing) are canonical layers under ADR-0017. They stay flat: each
class is one entry point named after its sub-API, listed in the Law 2 allow-list.

## Alternatives rejected

- **`NodeReference` / `WorkspaceReference` abstractions over Eloquent** — no consumer would benefit
  from them.

## References

- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d06`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d07`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d08`
- `audits/2026-09-29-stable-audit/opus/02-decisions.md#d09`
- `audits/2026-09-29-stable-audit/opus/05-php-api.md` §3–§7
- `audits/2026-09-29-stable-audit/opus/01-review.md` — N14, N27
