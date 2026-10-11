---
"@vaulter/vue": minor
---

**Breaking (0.x):** `@vaulter/vue` now talks to REST API v1 only.

- Types are generated from `openapi/vaulter-v1.yaml`; the client has one namespace per resource, parses
  `application/problem+json` into `VaulterApiError`, checks `X-Vaulter-Api-Version` and sends the opaque `etag` of a
  node or document as a required `If-Match` on every mutation (`412` → the composables re-read and set `conflict`).
- New viewer registry per application (`viewers` plugin option, `<VaulterViewer>`) with `VaulterUnsupportedNode` as the
  fallback; the engine registry and `componentMap` are removed.
- Composables and components follow the v1 vocabulary (`useDrive`, `useGrants`, `useShares`, `useDocument`,
  `useRevisions`, `useCollaboration`, `VaulterBreadcrumb`, `VaulterGrantsPanel`, `VaulterSharePanel`); `storageId` is
  `driveId`; `VaulterNodeList` is an accessible listbox; `VaulterNameDialog` validates with the server's
  `portable-v1` rules.

See `UPGRADE.md` ("`@vaulter/vue` on REST v1") for the was → now table.
