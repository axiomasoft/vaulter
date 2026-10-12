# P6.4 — pinned tools

| Tool | Pin | Where | Why |
|---|---|---|---|
| openapi-typescript | `7.13.0` (exact) | `packages/vue` devDependencies, `npm run api:generate` / `api:check` | Latest 7.x at execution time (`npm view openapi-typescript@7 version`). A generator bump changes `src/api/schema.d.ts` byte for byte, so it is a reviewed change: bump the pin, regenerate and commit both together. |
| @testing-library/vue | `8.1.0` (exact) | `packages/vue` devDependencies | Latest release at execution time; peer `vue >= 3`, `@vue/compiler-sfc >= 3` (the package builds on vue 3.5). Used by `tests/a11y` for the keyboard scenarios. |

Both were installed with `npm install -D --save-exact --workspace @vaulter/vue`.
