# ADR-0007 — Pluggable authorization

**Status:** accepted — superseded in part by [ADR-0025](/adr/0025-access-model-v1) (deny semantics: grants no longer deny; effective access is the union of roles up to an inheritance boundary)

## Context

ADR-0002 delegated authorization wholesale to a single host `PermissionResolver`
and left access **open** when none was configured. That is too coarse for a Drive:
Vaulter now owns a per-node ACL (`v_node_grants`) with inheritance, while still
needing to plug into wildly different host role systems (Gate, Spatie v8, AzGuard).
A single swappable resolver cannot both own the grant-evaluation algorithm and be
replaceable by the host.

## Decision

Split the contract into two lanes (master plan §H.2):

- **`HostRoleDriver` — pluggable, per-panel, identity-agnostic.** It never sees a
  `Node` or magic action strings; it answers `resolve(Subject, Authorizable, AclScope)`,
  `override(...)` (super-admin / `Gate::before`), `subjectsFor(Authorizable)`
  (actor → users + roles + teams, so Vaulter builds `WHERE subject IN (...)`), and
  `canCreateInWorkspace(...)`. Drivers shipped: `database` (default, standalone),
  `spatie` (v8, with the team-switch dance encapsulated), `gate`, and the non-prod
  `null` (open). An `azgard` lane is reserved in the config map but deferred until
  the Corex/AzGuard morph alignment lands.
- **`AccessResolver` — Vaulter-owned, NOT swappable.** A concrete `final` class
  that runs the effective-permission algorithm over `v_node_grants` + the driver.

**Algorithm — deny-veto, explicit-beats-inherited:** host override is absolute;
walk ancestors nearest-first within the workspace root, stopping at the first
`inherit_grants = false`; a nearer explicit `allow` beats a farther inherited
`deny`; then `granted = max(aclAllow, roleAllow)`; finally a **deny veto** —
`if deniedBits ⊇ requested → DENY` (a host role can never resurrect a denied
level). Expiry is filtered **on read**, not by cron.

## Consequences

- Vaulter owns a consistent, testable ACL while the host keeps its role system.
- The node-blind `HostRoleDriver` boundary is arch-tested (no `Node` leak); the
  `AccessResolver` is arch-tested to stay a concrete non-contract class.
- **No driver = fail-loud in production** (supersedes ADR-0002's open default);
  the open `NullRoleDriver` is env-gated to non-production only.

## Amendment (VLT-REMED P2.1, 2026-07-11)

Two clarifications to the accepted decision — neither changes the resolution
algorithm; they align the code's documented guarantees and add a fail-loud guard.

- **The `inherit_grants=false` wall cuts off ABOVE, it is not an absolute deny
  barrier (F-P1.1-01 / D17).** The rule "a nearer explicit `allow` beats a farther
  inherited `deny`" applies WITHOUT exception at the wall: a deny sitting ON an
  `inherit_grants=false` node is pierced by a nearer explicit allow BELOW it, exactly
  as any other inherited deny. The wall's only effect is that grants ABOVE it never
  contribute. Earlier `AccessResolver` docblocks wrongly described this deny as an
  "un-overridable wall … no nearer allow can ever pierce it"; those claims were
  corrected to match this ADR (the code was already correct). An absolute,
  un-pierceable wall-deny would be a NEW product decision (tracked as VLT-REMED Q4),
  not the current behaviour.

- **The `gate` and `azgard` lanes narrow `subjectsFor()` to `[actor]` (F-P1.2-01).**
  The `subjectsFor(actor) → users + roles + teams` contract is fully honoured only by
  the `spatie` lane (and any host driver marked
  `Vaulter\Contracts\Access\EnumeratesRoleSubjects`). The `gate` lane (abilities are
  evaluated per-user, not enumerable) and the `azgard` lane (per-resource checks, no
  role enumeration) — like the standalone `database` base and the `null` lane —
  contribute only the actor itself. A `v_node_grants` row addressed to a role/team is
  therefore inert under those lanes. This is safe (fail-closed: access is never
  raised) but was silent; Vaulter now makes it LOUD — `GrantNodeAccessAction` logs a
  warning always and throws `UnenumerableGrantSubjectException` outside production when
  a role/team grant is written under a driver that **cannot enumerate role subjects**.
  A driver counts as enumerating when it implements `EnumeratesRoleSubjects` **or**
  overrides `subjectsFor()` to append role/team `Subject`s (detected by
  `HostRoleDriverManager::enumeratesRoleSubjects()`); only the base lanes that inherit
  `subjectsFor()` unchanged trip the guard. A host that addresses grants to roles/teams
  should use `spatie`, or subclass its driver and **override `subjectsFor()`** to append
  the role/team `Subject`s — the documented extension path (see
  `DatabasePermissionResolver`) — optionally marking it `EnumeratesRoleSubjects`.
