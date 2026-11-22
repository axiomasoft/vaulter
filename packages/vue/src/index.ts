// Client
export { createVaulterClient, filenameFromDisposition } from './api/client'
export type {
  NodeMoveOptions,
  NodeRestoreOptions,
  RestrictInheritanceOptions,
  ShareAccess,
  StartUploadOptions,
  VaulterClient,
  VaulterClientOptions,
} from './api/client'
export { VaulterApiError } from './api/errors'
export type { VaulterApiErrorInit, VaulterErrorCode } from './api/errors'
export type { ResumableUpload, ResumableUploadOptions } from './api/uploads/shared'
export type { components, operations, paths } from './api/schema'

// Plugin
export { VaulterPlugin, provideVaulterClient, provideViewerRegistry } from './plugin'
export type { VaulterPluginOptions } from './plugin'
export { createVaulterI18n, useVaulterI18n } from './i18n'
export type { VaulterI18n, VaulterI18nOptions, VaulterMessageKey, VaulterMessages } from './i18n'

// Viewers
export { createViewerRegistry, useViewerRegistry } from './viewers/registry'
export type { ViewerEntry, ViewerLoader, ViewerRegistry } from './viewers/registry'
export { VIEWER_KEYS, builtinViewers } from './viewers/builtin'
export type { BuiltinViewerKey } from './viewers/builtin'
export { default as VaulterViewer } from './viewers/VaulterViewer.vue'

// Composables
export { useVaulterClient } from './composables/useVaulterClient'
export { useDrive } from './composables/useDrive'
export type { UseDriveReturn } from './composables/useDrive'
export { useNodes } from './composables/useNodes'
export type { NodeConflict, UseNodesReturn } from './composables/useNodes'
export { useSharedNodes } from './composables/useSharedNodes'
export type { UseSharedNodesReturn } from './composables/useSharedNodes'
export { useUpload } from './composables/useUpload'
export type { UseUploadReturn } from './composables/useUpload'
export { useGrants } from './composables/useGrants'
export type { UseGrantsReturn } from './composables/useGrants'
export { useShares } from './composables/useShares'
export type { UseSharesReturn } from './composables/useShares'
export { useActivity } from './composables/useActivity'
export type { UseActivityReturn } from './composables/useActivity'
export { useComments } from './composables/useComments'
export type { UseCommentsReturn } from './composables/useComments'
export { useDocument } from './composables/useDocument'
export type { UseDocumentOptions, UseDocumentReturn } from './composables/useDocument'
export { useRevisions } from './composables/useRevisions'
export type { UseRevisionsReturn } from './composables/useRevisions'

// Collaboration seam (the implementation is @vaulter/vue-collaboration)
export { VaulterCollaborationKey } from './collaboration-adapter'
export type {
  CollaborationAdapter,
  CollaborationHandle,
  CollaborationOpenOptions,
  CollaborationStatus,
  Collaborator,
} from './collaboration-adapter'

// Components
export { default as VaulterBreadcrumb } from './components/VaulterBreadcrumb.vue'
export { default as VaulterCommentsPanel } from './components/VaulterCommentsPanel.vue'
export { default as VaulterDocumentNode } from './components/VaulterDocumentNode.vue'
export { default as VaulterDrive } from './components/VaulterDrive.vue'
export { default as VaulterFolderPicker } from './components/VaulterFolderPicker.vue'
export { default as VaulterGrantsPanel } from './components/VaulterGrantsPanel.vue'
export type { GrantSubject } from './components/VaulterGrantsPanel.vue'
export { default as VaulterNameDialog } from './components/VaulterNameDialog.vue'
export { default as VaulterNodeList } from './components/VaulterNodeList.vue'
export type { NodeAction } from './components/VaulterNodeList.vue'
export { default as VaulterSharedWithMe } from './components/VaulterSharedWithMe.vue'
export { default as VaulterSharePanel } from './components/VaulterSharePanel.vue'
export { default as VaulterUnsupportedNode } from './components/VaulterUnsupportedNode.vue'
export { default as VaulterUploader } from './components/VaulterUploader.vue'

// Display helpers
export { humanByteSize, nodeIconKind } from './components/drive/nodeDisplay'
export type { NodeIconKind } from './components/drive/nodeDisplay'
export { MAX_NAME_LENGTH, NAME_REASONS, portableNameReason } from './naming'
export type { NameReason } from './naming'

// Types
export type * from './api/types'

export { VaulterPlugin as default } from './plugin'
