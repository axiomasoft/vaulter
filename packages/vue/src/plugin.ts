import type { App } from 'vue'
import { createVaulterClient, type VaulterClient } from './client'
import { docVaultClientKey } from './composables/useVaulterClient'
import type { VaulterClientOptions } from './types'
import VaulterBreadcrumb from './components/VaulterBreadcrumb.vue'
import VaulterCommentsPanel from './components/VaulterCommentsPanel.vue'
import VaulterEditor from './components/VaulterEditor.vue'
import VaulterFileManager from './components/VaulterFileManager.vue'
import VaulterPermissionsPanel from './components/VaulterPermissionsPanel.vue'
import VaulterSharePanel from './components/VaulterSharePanel.vue'
import VaulterVersionHistory from './components/VaulterVersionHistory.vue'

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

    app.component('VaulterFileManager', VaulterFileManager)
    app.component('VaulterEditor', VaulterEditor)
    app.component('VaulterVersionHistory', VaulterVersionHistory)
    app.component('VaulterPermissionsPanel', VaulterPermissionsPanel)
    app.component('VaulterCommentsPanel', VaulterCommentsPanel)
    app.component('VaulterSharePanel', VaulterSharePanel)
    app.component('VaulterBreadcrumb', VaulterBreadcrumb)
  },
}
