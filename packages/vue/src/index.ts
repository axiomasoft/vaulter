// Client
export { createVaulterClient, VaulterApiError } from './client'
export type {
  VaulterClient,
  ResumableUpload,
  ResumableUploadOptions,
  TusPatchResult,
  S3PartResult,
} from './client'

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

// Drive composables (M1–M4)
export { useStorage } from './composables/useStorage'
export type { UseStorageReturn } from './composables/useStorage'
export { useNodes } from './composables/useNodes'
export type { UseNodesReturn } from './composables/useNodes'
export { useUpload } from './composables/useUpload'
export type { UseUploadReturn } from './composables/useUpload'
export { useNodeGrants } from './composables/useNodeGrants'
export type { UseNodeGrantsReturn } from './composables/useNodeGrants'
export { useNodeShares } from './composables/useNodeShares'
export type { UseNodeSharesReturn } from './composables/useNodeShares'
export { useActivity } from './composables/useActivity'
export type { UseActivityReturn } from './composables/useActivity'
export { useDocumentNode } from './composables/useDocumentNode'
export type { UseDocumentNodeOptions, UseDocumentNodeReturn } from './composables/useDocumentNode'
export { useNodeCollaboration } from './composables/useNodeCollaboration'
export type {
  UseNodeCollaborationOptions,
  UseNodeCollaborationReturn,
} from './composables/useNodeCollaboration'

// Collaboration transport (legacy document-centric)
export { createCollaborationSession } from './collaboration'
export type {
  CollaborationProviderHandle,
  CollaborationSession,
  CreateCollaborationOptions,
} from './collaboration'

// Node collaboration transport (M4b)
export { createNodeCollaborationSession } from './nodeCollaboration'
export type {
  AwarenessLike,
  Collaborator,
  CreateNodeCollaborationOptions,
  NodeCollaborationProviderHandle,
  NodeCollaborationSessionHandle,
} from './nodeCollaboration'

// Components
export { default as VaulterFileManager } from './components/VaulterFileManager.vue'
export { default as VaulterEditor } from './components/VaulterEditor.vue'
export { default as VaulterVersionHistory } from './components/VaulterVersionHistory.vue'
export { default as VaulterPermissionsPanel } from './components/VaulterPermissionsPanel.vue'
export { default as VaulterCommentsPanel } from './components/VaulterCommentsPanel.vue'
export { default as VaulterSharePanel } from './components/VaulterSharePanel.vue'
export { default as VaulterBreadcrumb } from './components/VaulterBreadcrumb.vue'

// Drive components (M1–M4)
export { default as VaulterDrive } from './components/VaulterDrive.vue'
export { default as VaulterDriveBreadcrumb } from './components/VaulterDriveBreadcrumb.vue'
export { default as VaulterFolderPicker } from './components/VaulterFolderPicker.vue'
export { default as VaulterNodeList } from './components/VaulterNodeList.vue'
export type { NodeAction } from './components/VaulterNodeList.vue'
export { default as VaulterUploader } from './components/VaulterUploader.vue'
export { default as VaulterNodeGrantsPanel } from './components/VaulterNodeGrantsPanel.vue'
export type { GrantSubject } from './components/VaulterNodeGrantsPanel.vue'
export { default as VaulterNodeSharePanel } from './components/VaulterNodeSharePanel.vue'
export { default as VaulterDocumentNode } from './components/VaulterDocumentNode.vue'
export type { DocumentNodeEditorProps } from './components/VaulterDocumentNode.vue'

// Drive display helpers
export { humanByteSize, nodeIconKind } from './components/drive/nodeDisplay'
export type { NodeIconKind } from './components/drive/nodeDisplay'

// Types
export type * from './types'

export { VaulterPlugin as default } from './plugin'
