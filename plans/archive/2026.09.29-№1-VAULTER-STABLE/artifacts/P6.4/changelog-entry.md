### `@vaulter/vue` on REST v1 (PLAN2.P6.4)

- Changed: `@vaulter/vue` talks to REST v1 only (`baseUrl` defaults to `/api/vaulter/v1`). Its types are generated
  from `openapi/vaulter-v1.yaml` (`npm run api:generate`; `api:check` runs in `frontend.yml`) and the hand-written
  `types.ts` is gone. The client has one namespace per resource (`drives`, `nodes`, `files`, `uploads`, `documents`,
  `grants`, `shares`, `comments`, `changes`, …); lists are cursor pages without totals (PLAN2.D62–D65).
- Added: mutations take the opaque `etag` of the node (or of the document content) as a required `{ etag }` option and
  send it as `If-Match`. A `412` makes the composables re-read the node and set `conflict`; a stale document save keeps
  the local edits and re-reads the head; a `428` is rethrown as an integration error.
- Added: `VaulterApiError` is parsed from `application/problem+json` (`code`, `title`, `detail`, `requestId`, `meta`,
  `errors`; `http_<status>` for an HTML or empty error). The first response's `X-Vaulter-Api-Version` is checked: another
  major makes this and every later call fail with `api_version_mismatch`.
- Added: a viewer registry per application (`viewers` plugin option, `useViewerRegistry`, `<VaulterViewer>`), built-in
  keys `vaulter/tiptap`, `vaulter/univer`, `vaulter/pdf-preview`, `vaulter/image-preview`, `vaulter/download`,
  `vaulter/unsupported`, and `VaulterUnsupportedNode` for an unknown key, a node without presentation or a document
  type that is not available — with a download button only for a file the caller may download.
- Added: `useDrive`, `useGrants`, `useShares`, `useDocument`, `useRevisions`, `useCollaboration` (with `onEpochChanged`),
  `createCollaborationSession`, `VaulterBreadcrumb`, `VaulterGrantsPanel`, `VaulterSharePanel`, a small `i18n` (`en`,
  `ru`) for the built-in viewers, and `portableNameReason` — `VaulterNameDialog` validates names with the server's
  `portable-v1` rules and shows the server's `meta.reason`.
- Added: async results never overwrite newer local state — saves and loads of a document run one at a time (an autosave
  never overwrites an open conflict), list reads in the composables are dropped when a newer reload or a local change
  happened meanwhile, and a `connect()` that was overtaken by another rejects with `CollaborationCancelledError`.
- Added: accessibility — `VaulterNodeList` is a listbox with arrow/Home/End navigation, Enter to open, F2 to rename and
  Delete to trash, focus on the neighbour after a node leaves the list, and failures announced through `aria-live`.
- Changed: the node resource drops `is_pinned`, `rich_text` and `handler`; it carries `viewer.starred`,
  `content_modified_at` and `presentation { handler, document_type, type_available, viewer, capabilities }`.
- Removed: `src/client.ts`, `src/types.ts`, the engine registry (`registerEngineComponent`, `resolveEngineComponent`,
  `docVaultClientKey`), `useStorage`, `useNodeGrants`, `useNodeShares`, `useDocumentNode`, `useNodeCollaboration`,
  `useSpreadsheet` and the unversioned client namespaces, without aliases (`0.x`); `UPGRADE.md` has the was → now table.
- Docs: `guide/vue` (en, ru) is rewritten for the v1 client, the registry, the composables and the keyboard model.

