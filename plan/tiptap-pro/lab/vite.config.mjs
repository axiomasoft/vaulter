import { defineConfig } from 'vite'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const optionalPackages = {
  provider: '@tiptap-pro/provider',
  comments: '@tiptap-pro/extension-comments',
  snapshot: '@tiptap-pro/extension-snapshot',
  snapshotCompare: '@tiptap-pro/extension-snapshot-compare',
  trackedChanges: '@tiptap-pro/extension-tracked-changes',
}
const available = Object.fromEntries(Object.entries(optionalPackages).map(([name, pkg]) => {
  try { require.resolve(pkg); return [name, true] } catch { return [name, false] }
}))

export default defineConfig({
  plugins: [{
    name: 'pro-feature-availability',
    resolveId(id) { if (['virtual:cloud-provider', 'virtual:pro-extensions'].includes(id)) return `\0${id}` },
    load(id) {
      if (id === '\0virtual:cloud-provider') return available.provider
        ? "export { TiptapCollabProvider } from '@tiptap-pro/provider'; export const proAvailable = true;"
        : "export const proAvailable = false; export class TiptapCollabProvider { constructor() { throw new Error('Install @tiptap-pro/provider with your npm token first') } }"
      if (id !== '\0virtual:pro-extensions') return null
      return [
        `export const proFeaturesAvailable = ${JSON.stringify(available)};`,
        available.comments ? "export { CommentsKit, getThreads as getThreadsWithPositions } from '@tiptap-pro/extension-comments';" : 'export const CommentsKit = null, getThreadsWithPositions = null;',
        available.snapshot ? "export { Snapshot, watchPreviewContent } from '@tiptap-pro/extension-snapshot';" : 'export const Snapshot = null, watchPreviewContent = null;',
        available.snapshotCompare ? "export { SnapshotCompare } from '@tiptap-pro/extension-snapshot-compare';" : 'export const SnapshotCompare = null;',
        available.trackedChanges ? "export { TrackedChanges, findSuggestions } from '@tiptap-pro/extension-tracked-changes';" : 'export const TrackedChanges = null, findSuggestions = null;',
      ].join('\n')
    },
  }],
  server: {
    host: '127.0.0.1',
    port: 5193,
    strictPort: true,
    proxy: { '/api': { target: 'http://127.0.0.1:5194', changeOrigin: true } },
    fs: { strict: true },
  },
})
