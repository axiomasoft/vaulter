---
"@vaulter/vue": minor
---

**Breaking (0.x):** the server refuses a write on a versioned resource without `If-Match` (428), so the client
now takes a required `{ etag }` on `drives.archive/unarchive/delete`, `shares.update/rotate/revoke`,
`comments.resolve/remove`, `grants.revoke` and `access.restrictInheritance/restoreInheritance` (the last three take
the node's access tag from the new `grants.listTagged()`). `useComments`, `useShares` and `useGrants` send the tag of
the item they hold (`useGrants` exposes `accessTag`); a stale tag rejects with a 412 `stale_write` `VaulterApiError`.
