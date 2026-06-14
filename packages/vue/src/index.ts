// Client
export { createDocVaultClient, DocVaultApiError } from './client'
export type { DocVaultClient } from './client'

// Plugin
export { DocVaultPlugin, provideDocVaultClient } from './plugin'
export type { DocVaultPluginOptions } from './plugin'

// Composables
export { docVaultClientKey, useDocVaultClient } from './composables/useDocVaultClient'
export { usePermissions } from './composables/usePermissions'
export type { AbilityName, UsePermissionsReturn } from './composables/usePermissions'
export { useDocuments } from './composables/useDocuments'
export type { UseDocumentsReturn } from './composables/useDocuments'
export { useDocVaultEditor } from './composables/useDocVaultEditor'
export type { UseDocVaultEditorOptions, UseDocVaultEditorReturn } from './composables/useDocVaultEditor'
export { useCollaboration } from './composables/useCollaboration'
export type { UseCollaborationReturn } from './composables/useCollaboration'

// Components
export { default as DocVaultFileManager } from './components/DocVaultFileManager.vue'
export { default as DocVaultEditor } from './components/DocVaultEditor.vue'
export { default as DocVaultVersionHistory } from './components/DocVaultVersionHistory.vue'
export { default as DocVaultPermissionsPanel } from './components/DocVaultPermissionsPanel.vue'
export { default as DocVaultBreadcrumb } from './components/DocVaultBreadcrumb.vue'

// Types
export type * from './types'

export { DocVaultPlugin as default } from './plugin'
