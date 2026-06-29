// Client
export { createVaulterClient, VaulterApiError } from './client'
export type { VaulterClient } from './client'

// Plugin
export { VaulterPlugin, provideVaulterClient } from './plugin'
export type { VaulterPluginOptions } from './plugin'

// Composables
export { docVaultClientKey, useVaulterClient } from './composables/useVaulterClient'
export { usePermissions } from './composables/usePermissions'
export type { AbilityName, UsePermissionsReturn } from './composables/usePermissions'
export { useDocuments } from './composables/useDocuments'
export type { UseDocumentsReturn } from './composables/useDocuments'
export { useVaulterEditor } from './composables/useVaulterEditor'
export type { UseVaulterEditorOptions, UseVaulterEditorReturn } from './composables/useVaulterEditor'
export { useCollaboration } from './composables/useCollaboration'
export type { UseCollaborationReturn } from './composables/useCollaboration'
export { useComments } from './composables/useComments'
export type { UseCommentsReturn } from './composables/useComments'
export { useShares } from './composables/useShares'
export type { UseSharesReturn } from './composables/useShares'
export { useDocumentExport } from './composables/useDocumentExport'
export type { UseDocumentExportReturn } from './composables/useDocumentExport'

// Collaboration transport
export { createCollaborationSession } from './collaboration'
export type {
  CollaborationProviderHandle,
  CollaborationSession,
  CreateCollaborationOptions,
} from './collaboration'

// Components
export { default as VaulterFileManager } from './components/VaulterFileManager.vue'
export { default as VaulterEditor } from './components/VaulterEditor.vue'
export { default as VaulterVersionHistory } from './components/VaulterVersionHistory.vue'
export { default as VaulterPermissionsPanel } from './components/VaulterPermissionsPanel.vue'
export { default as VaulterCommentsPanel } from './components/VaulterCommentsPanel.vue'
export { default as VaulterSharePanel } from './components/VaulterSharePanel.vue'
export { default as VaulterBreadcrumb } from './components/VaulterBreadcrumb.vue'

// Types
export type * from './types'

export { VaulterPlugin as default } from './plugin'
