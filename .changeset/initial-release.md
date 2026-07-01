---
"@vaulter/vue": minor
---

Initial public release of `@vaulter/vue` — the headless Vue 3 + TypeScript client for Vaulter.

Ships the typed API client (Drive/documents/sharing), the collaboration composables
(`useNodeCollaboration` with TipTap + Yjs over Hocuspocus), the Drive components
(`VaulterDrive` with folder-picker and name dialogs — no `window.prompt`), the
`shared-with-me` view, and the headless spreadsheet shell (`VaulterSpreadsheet` +
`useSpreadsheet`). Built to `dist/` with type declarations and dual ESM/CJS exports.
