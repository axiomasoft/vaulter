# ADR-0007 — Pluggable authorization

**Status:** accepted

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
