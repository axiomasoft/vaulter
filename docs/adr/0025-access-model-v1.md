# ADR-0025 — Access model 1.0: versioned roles, no deny, inheritance boundaries

**Status:** accepted (PLAN2.D45, D46, D53)

**Amends:** [ADR-0007](/adr/0007-pluggable-authorization) — the deny semantics are superseded;
the two-lane split (host driver + Vaulter-owned `AccessResolver`) stands.

## Context

The 0.x model stored a level, a permission bitmask and an `allow`/`deny` effect on every
grant, ranked levels with `max(rank)`, and let a deny veto any allow. That made access
non-monotonic and hard to explain, tied Download to View (no previewer was possible), and gave
the creator of a node permanent rights through `v_nodes.owner_id`.

## Decision

### Roles v1

A grant stores `role_key` and `role_version`. `RoleRegistry` resolves a pair to a `Role`; an
unknown key or version throws `UnknownRoleException` (`unknown_role`, 422). Version 1:

| Role | Abilities |
| --- | --- |
| `previewer` | View |
| `reader` | previewer + Download |
| `commenter` | reader + Comment |
| `writer` | commenter + Edit, Create, Move, Copy, Trash, Restore |
| `organizer` | writer + Share |
| `owner` | organizer + Manage, Delete |

A role is a closed set of abilities; a change to it ships as a new version, so a stored grant
always resolves to the abilities it was given.

### Ability bits

`Permission` has one bit per ability: View 1, Edit 2, Create 4, Delete 8, Share 16, Comment 32,
Download 64, Move 128, Copy 256, Trash 512, Restore 1024, Manage 2048 (`all()` = 4095).
`Ability::bits()` is the ability's own bit. View and Download are separate.

### Effective access

For an actor and a node:

1. The administrative override of the permission driver (`PermissionDriver::override()`) is
   applied first: `Effect::Deny` blocks, `Effect::Allow` is a super-allow. This is the only
   block in the model; it belongs to the host, not to a grant.
2. Otherwise the abilities are the **union** of the roles of the actor's subjects from
   - direct grants on the node (any scope),
   - `subtree` grants on ancestors, **up to and including the first node with
     `inherit_grants = false`** — grants above that node never contribute,
   - the roles the permission driver returns (`Decision{Role}`),
   skipping expired grants (filtered on read).
3. There is no deny grant and no `max(rank)`.

A node stops inheriting with `access()->restrictInheritance($node, InheritanceRestriction)` and
resumes with `access()->restoreInheritance($node)`; both require Manage. `empty` leaves the
node with only its direct grants. `copy_current` first gives each subject that currently
inherits access a direct subtree grant: the role covering the inherited roles, never broader
and never longer-lived than the inherited ones (a conflicting direct grant resolves toward less
access). Restoring keeps the direct grants.

### Multi-resource rules

| Operation | Required |
| --- | --- |
| copy | view + download + copy on every copied node (D30), create on the destination |
| move | move on the node, create on the destination |
| restore | restore on the node, create on the destination unless it returns to its original parent |
| purge | delete |

### Explain

`access()->explain()` returns `AccessExplanation{allowed, ability, reason, sources,
restrictions, boundaryNodeId}`; each `AccessSource` names the grant, subject, role and
version, the origin node, whether it was inherited and its expiry. `boundaryNodeId` is the node
where inheritance stopped.

### Drivers

`gate`, `spatie` and `azgard` map role keys to host abilities/permissions (the maps gain
`previewer => vaulter.preview`); a driver returns the union of the roles it grants as one
`Decision`. A map key that is not a role throws `UnknownRoleException`. `AccessScope` no longer
carries the node owner; the Gate context has no `node_owner`.

### Migration `2026_10_07_000005_access_model_v1`

- Each level becomes the role of the same name at version 1; no legacy role versions exist (D53).
- Declared equivalence exceptions: `writer` gains Trash and Restore; `organizer` gains Share and
  loses Delete. `reader` keeps what Read gave, which now includes Download.
- **Preflight refusal** (`UpgradePreflightException`, nothing changed) on any `deny` grant, any
  unknown level, and any permission bit outside the level's own bits: such a grant has no
  positive equivalent and converting it would silently widen or alter access.
- Grants are unique per (node, subject, scope); `effect` leaves the key. `level`,
  `permissions` and `effect` are dropped.
- `v_nodes.owner_id` is dropped **without creating grants**: no built-in driver granted
  anything from it (D46, D53). A host that relied on creator-owns must grant explicitly.
- `down()` restores the 0.x shape.

## Consequences

- Access is monotonic: adding a grant never removes access; removing the deny concept makes
  every answer explainable by listing roles.
- Hosts that stored deny grants must resolve them (usually by an inheritance boundary or an
  administrative override) before upgrading.
- Share links are adapted minimally (stored bits use the new layout, the level column holds a
  role key, subtree links stop at inheritance boundaries); their redesign is P4.10.
