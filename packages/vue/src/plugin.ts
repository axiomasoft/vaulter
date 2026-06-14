import type { App } from 'vue'
import { createDocVaultClient, type DocVaultClient } from './client'
import { docVaultClientKey } from './composables/useDocVaultClient'
import type { DocVaultClientOptions } from './types'
import DocVaultBreadcrumb from './components/DocVaultBreadcrumb.vue'
import DocVaultEditor from './components/DocVaultEditor.vue'
import DocVaultFileManager from './components/DocVaultFileManager.vue'
import DocVaultPermissionsPanel from './components/DocVaultPermissionsPanel.vue'
import DocVaultVersionHistory from './components/DocVaultVersionHistory.vue'

export interface DocVaultPluginOptions extends DocVaultClientOptions {
  /** Provide a pre-built client instead of constructing one from the options. */
  client?: DocVaultClient
}

/** Provide an existing client to a Vue app (for tests or custom wiring). */
export function provideDocVaultClient(app: App, client: DocVaultClient): void {
  app.provide(docVaultClientKey, client)
}

export const DocVaultPlugin = {
  install(app: App, options: DocVaultPluginOptions = {}): void {
    const client = options.client ?? createDocVaultClient(options)
    app.provide(docVaultClientKey, client)

    app.component('DocVaultFileManager', DocVaultFileManager)
    app.component('DocVaultEditor', DocVaultEditor)
    app.component('DocVaultVersionHistory', DocVaultVersionHistory)
    app.component('DocVaultPermissionsPanel', DocVaultPermissionsPanel)
    app.component('DocVaultBreadcrumb', DocVaultBreadcrumb)
  },
}
