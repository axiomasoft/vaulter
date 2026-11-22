import type { ViewerEntry } from './registry'

/** The viewer keys the server may put in `presentation.viewer.key` for built-in types. */
export const VIEWER_KEYS = {
  tiptap: 'vaulter/tiptap',
  pdfPreview: 'vaulter/pdf-preview',
  imagePreview: 'vaulter/image-preview',
  download: 'vaulter/download',
  unsupported: 'vaulter/unsupported',
} as const

export type BuiltinViewerKey = (typeof VIEWER_KEYS)[keyof typeof VIEWER_KEYS]

/**
 * The viewers installed by the plugin. `vaulter/tiptap` is the headless
 * editing shell (load, autosave, collaboration): the editor engine itself is the host's, who
 * replaces the key through `viewers` or fills the shell's slot. Everything else is lazy.
 */
export function builtinViewers(): Record<BuiltinViewerKey, ViewerEntry> {
  return {
    [VIEWER_KEYS.tiptap]: () => import('../components/VaulterDocumentNode.vue'),
    [VIEWER_KEYS.pdfPreview]: () => import('./builtin/PdfPreviewViewer.vue'),
    [VIEWER_KEYS.imagePreview]: () => import('./builtin/ImagePreviewViewer.vue'),
    [VIEWER_KEYS.download]: () => import('./builtin/DownloadViewer.vue'),
    [VIEWER_KEYS.unsupported]: () => import('../components/VaulterUnsupportedNode.vue'),
  }
}
