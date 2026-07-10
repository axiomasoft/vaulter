# ADR-0013 — Two sharing channels kept separate

**Status:** accepted

## Context

A Drive shares content two ways: by **identity** ("this user/role/team may access
this node") and by **bearer link** ("anyone holding this URL may access it"). Both
grant access, but conflating them is a classic confused-deputy bug — "I hold a
link" is not "I am in the team", and unioning the two passes lets a bearer link
silently inherit a viewer's identity grants (or vice-versa).

## Decision

Evaluate the two channels as **separate passes**, never unioned (master plan §2.7
/ §10):

- **Identity ACL** — `v_node_grants` + `HostRoleDriver`, run by the Vaulter-owned
  `AccessResolver` with inheritance and deny-veto (ADR-0007).
- **Bearer links** — `v_node_shares`: only the `sha256` `token_hash` is stored
  (with a short `token_prefix` for owner-side UX); the raw token is returned to the
  creator exactly once and never re-exposed. A link grants exactly its **own**
  bitmask on its **own** node-set, evaluated in its own pass and intersected with
  item-safety (tenant / trash / quarantine) — never combined with the identity ACL.

## Consequences

- A bearer link cannot escalate via a viewer's identity grants, and an identity
  grant cannot leak through a link — each channel is bounded by its own rules.
- Tokens are non-recoverable at rest (hash-only storage); revocation and expiry
  are link-local.
- Hosts can disable the link channel entirely (`features.share_links`) without
  touching identity ACL.

## Amendment (P1.2 / F-P1.3-02, D14) — issuer re-check on consumption

The original decision evaluated a link purely against its own row (liveness,
password, item-safety) and never asked whether the identity that minted it was
still authorized. That is a gap, not a deliberate exception: a link outliving the
issuer's own revoked access is a textbook confused-deputy bug (S2.2).

`ShareResolver::resolve()` now re-checks, on every consumption, that the share's
recorded issuer (`granted_by`) STILL holds `Ability::Share` on the node through
the identity ACL (`AccessResolver` — the same host-role + deny-veto algorithm
every other authorization decision uses, never a hand-rolled grant lookup). The
check is bound to `Ability::Share` specifically — the ability that authorized
minting the link in the first place — not to the link's own bitmask: an issuer who
lost `Edit` but kept `Share` does not kill a read-only link they minted; only
losing `Share` itself does. A share with no recorded issuer (a legacy row, or one
minted by a system/console actor with no identity to re-check) has nothing to
re-verify and resolves as before. Failure is opaque — the same `NotFound` as a
wrong token, never a distinguishable error.
