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
export { useComments } from './composables/useComments'
export type { UseCommentsReturn } from './composables/useComments'

// Drive composables (M1–M4)
export { useStorage } from './composables/useStorage'
export type { UseStorageReturn } from './composables/useStorage'
export { useNodes } from './composables/useNodes'
export type { UseNodesReturn } from './composables/useNodes'
export { useSharedNodes } from './composables/useSharedNodes'
export type { UseSharedNodesReturn } from './composables/useSharedNodes'
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
export { useSpreadsheet } from './composables/useSpreadsheet'
export type { UseSpreadsheetOptions, UseSpreadsheetReturn } from './composables/useSpreadsheet'
export { useNodeCollaboration } from './composables/useNodeCollaboration'
export type {
  UseNodeCollaborationOptions,
  UseNodeCollaborationReturn,
} from './composables/useNodeCollaboration'

// Engine component registry (VLT-CANON P1.2) — explicit, host-owned mapping from a
// handler's `ui.component`/`id` to the Vue component `VaulterDocumentNode` mounts.
export {
  clearEngineComponents,
  registerEngineComponent,
  registeredEngineComponentIds,
  resolveEngineComponent,
  unregisterEngineComponent,
} from './engineRegistry'

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
export { default as VaulterCommentsPanel } from './components/VaulterCommentsPanel.vue'

// Drive components (M1–M4)
export { default as VaulterDrive } from './components/VaulterDrive.vue'
export { default as VaulterDriveBreadcrumb } from './components/VaulterDriveBreadcrumb.vue'
export { default as VaulterFolderPicker } from './components/VaulterFolderPicker.vue'
export { default as VaulterNameDialog } from './components/VaulterNameDialog.vue'
export { default as VaulterNodeList } from './components/VaulterNodeList.vue'
export type { NodeAction } from './components/VaulterNodeList.vue'
export { default as VaulterUploader } from './components/VaulterUploader.vue'
export { default as VaulterNodeGrantsPanel } from './components/VaulterNodeGrantsPanel.vue'
export type { GrantSubject } from './components/VaulterNodeGrantsPanel.vue'
export { default as VaulterNodeSharePanel } from './components/VaulterNodeSharePanel.vue'
export { default as VaulterDocumentNode } from './components/VaulterDocumentNode.vue'
export type { DocumentNodeEditorProps } from './components/VaulterDocumentNode.vue'
export { default as VaulterSpreadsheet } from './components/VaulterSpreadsheet.vue'
export { default as VaulterSharedWithMe } from './components/VaulterSharedWithMe.vue'

// Drive display helpers
export { humanByteSize, nodeIconKind } from './components/drive/nodeDisplay'
export type { NodeIconKind } from './components/drive/nodeDisplay'

// Types
export type * from './types'

export { VaulterPlugin as default } from './plugin'
