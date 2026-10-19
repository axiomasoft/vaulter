import type { App } from 'vue'
import { createVaulterClient, type VaulterClient, type VaulterClientOptions } from './api/client'
import { VaulterCollaborationKey, type CollaborationAdapter } from './collaboration-adapter'
import { vaulterClientKey } from './composables/useVaulterClient'
import { createVaulterI18n, vaulterI18nKey, type VaulterI18nOptions } from './i18n'
import { builtinViewers } from './viewers/builtin'
import { createViewerRegistry, viewerRegistryKey, type ViewerEntry, type ViewerRegistry } from './viewers/registry'
import VaulterBreadcrumb from './components/VaulterBreadcrumb.vue'
import VaulterCommentsPanel from './components/VaulterCommentsPanel.vue'
import VaulterDocumentNode from './components/VaulterDocumentNode.vue'
import VaulterDrive from './components/VaulterDrive.vue'
import VaulterFolderPicker from './components/VaulterFolderPicker.vue'
import VaulterGrantsPanel from './components/VaulterGrantsPanel.vue'
import VaulterNameDialog from './components/VaulterNameDialog.vue'
import VaulterNodeList from './components/VaulterNodeList.vue'
import VaulterSharedWithMe from './components/VaulterSharedWithMe.vue'
import VaulterSharePanel from './components/VaulterSharePanel.vue'
import VaulterSpreadsheet from './components/VaulterSpreadsheet.vue'
import VaulterUnsupportedNode from './components/VaulterUnsupportedNode.vue'
import VaulterUploader from './components/VaulterUploader.vue'
import VaulterViewer from './viewers/VaulterViewer.vue'

export interface VaulterPluginOptions extends VaulterClientOptions {
  /** Provide a pre-built client instead of constructing one from the options. */
  client?: VaulterClient
  /**
   * Viewers by `presentation.viewer.key`, merged over the built-in ones: a host adds its own
   * (`'acme/diagram': () => import('./DiagramViewer.vue')`) or replaces any built-in key.
   */
  viewers?: Record<string, ViewerEntry>
  /** UI language and message overrides for the strings the package renders. */
  i18n?: VaulterI18nOptions
  /**
   * Realtime editing: `createCollaborationAdapter()` of `@vaulter/vue-collaboration`. Without it
   * every document is edited through the REST autosave.
   */
  collaboration?: CollaborationAdapter
}

/** Provide an existing client to a Vue app (for tests or custom wiring). */
export function provideVaulterClient(app: App, client: VaulterClient): void {
  app.provide(vaulterClientKey, client)
}

/** Provide a viewer registry to a Vue app (for tests or custom wiring). */
export function provideViewerRegistry(app: App, registry: ViewerRegistry): void {
  app.provide(viewerRegistryKey, registry)
}

export const VaulterPlugin = {
  install(app: App, options: VaulterPluginOptions = {}): void {
    app.provide(vaulterClientKey, options.client ?? createVaulterClient(options))
    // One registry per app: viewers registered on one application never leak into another.
    app.provide(viewerRegistryKey, createViewerRegistry({ ...builtinViewers(), ...options.viewers }))
    app.provide(vaulterI18nKey, createVaulterI18n(options.i18n))
    if (options.collaboration) {
      app.provide(VaulterCollaborationKey, options.collaboration)
    }

    app.component('VaulterBreadcrumb', VaulterBreadcrumb)
    app.component('VaulterCommentsPanel', VaulterCommentsPanel)
    app.component('VaulterDocumentNode', VaulterDocumentNode)
    app.component('VaulterDrive', VaulterDrive)
    app.component('VaulterFolderPicker', VaulterFolderPicker)
    app.component('VaulterGrantsPanel', VaulterGrantsPanel)
    app.component('VaulterNameDialog', VaulterNameDialog)
    app.component('VaulterNodeList', VaulterNodeList)
    app.component('VaulterSharedWithMe', VaulterSharedWithMe)
    app.component('VaulterSharePanel', VaulterSharePanel)
    app.component('VaulterSpreadsheet', VaulterSpreadsheet)
    app.component('VaulterUnsupportedNode', VaulterUnsupportedNode)
    app.component('VaulterUploader', VaulterUploader)
    app.component('VaulterViewer', VaulterViewer)
  },
}
