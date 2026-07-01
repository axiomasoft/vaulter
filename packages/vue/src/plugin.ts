import type { App } from 'vue'
import { createVaulterClient, type VaulterClient } from './client'
import { docVaultClientKey } from './composables/useVaulterClient'
import type { VaulterClientOptions } from './types'
import VaulterCommentsPanel from './components/VaulterCommentsPanel.vue'
// Drive components (M1–M4)
import VaulterDrive from './components/VaulterDrive.vue'
import VaulterDriveBreadcrumb from './components/VaulterDriveBreadcrumb.vue'
import VaulterFolderPicker from './components/VaulterFolderPicker.vue'
import VaulterNameDialog from './components/VaulterNameDialog.vue'
import VaulterNodeList from './components/VaulterNodeList.vue'
import VaulterUploader from './components/VaulterUploader.vue'
import VaulterNodeGrantsPanel from './components/VaulterNodeGrantsPanel.vue'
import VaulterNodeSharePanel from './components/VaulterNodeSharePanel.vue'
import VaulterDocumentNode from './components/VaulterDocumentNode.vue'
import VaulterSpreadsheet from './components/VaulterSpreadsheet.vue'
import VaulterSharedWithMe from './components/VaulterSharedWithMe.vue'

export interface VaulterPluginOptions extends VaulterClientOptions {
  /** Provide a pre-built client instead of constructing one from the options. */
  client?: VaulterClient
}

/** Provide an existing client to a Vue app (for tests or custom wiring). */
export function provideVaulterClient(app: App, client: VaulterClient): void {
  app.provide(docVaultClientKey, client)
}

export const VaulterPlugin = {
  install(app: App, options: VaulterPluginOptions = {}): void {
    const client = options.client ?? createVaulterClient(options)
    app.provide(docVaultClientKey, client)

    app.component('VaulterCommentsPanel', VaulterCommentsPanel)

    // Drive components — the unified v_nodes surface (ADR-0014).
    app.component('VaulterDrive', VaulterDrive)
    app.component('VaulterDriveBreadcrumb', VaulterDriveBreadcrumb)
    app.component('VaulterFolderPicker', VaulterFolderPicker)
    app.component('VaulterNameDialog', VaulterNameDialog)
    app.component('VaulterNodeList', VaulterNodeList)
    app.component('VaulterUploader', VaulterUploader)
    app.component('VaulterNodeGrantsPanel', VaulterNodeGrantsPanel)
    app.component('VaulterNodeSharePanel', VaulterNodeSharePanel)
    app.component('VaulterDocumentNode', VaulterDocumentNode)
    app.component('VaulterSpreadsheet', VaulterSpreadsheet)
    app.component('VaulterSharedWithMe', VaulterSharedWithMe)
  },
}
