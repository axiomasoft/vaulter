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
