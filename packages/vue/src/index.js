import DocVaultFileManager from './components/DocVaultFileManager.vue'
import DocVaultEditor from './components/DocVaultEditor.vue'
import DocVaultVersionHistory from './components/DocVaultVersionHistory.vue'
import DocVaultPermissionsPanel from './components/DocVaultPermissionsPanel.vue'
import DocVaultBreadcrumb from './components/DocVaultBreadcrumb.vue'

export {
  DocVaultFileManager,
  DocVaultEditor,
  DocVaultVersionHistory,
  DocVaultPermissionsPanel,
  DocVaultBreadcrumb,
}

const components = {
  DocVaultFileManager,
  DocVaultEditor,
  DocVaultVersionHistory,
  DocVaultPermissionsPanel,
  DocVaultBreadcrumb,
}

/**
 * Vue plugin: registers all DocVault components globally.
 *
 *   import { DocVaultPlugin } from '@docvault/vue'
 *   app.use(DocVaultPlugin, { baseUrl: '/api/docvault' })
 */
export const DocVaultPlugin = {
  install(app, options = {}) {
    app.config.globalProperties.$docvault = { baseUrl: '/api/docvault', ...options }

    for (const [name, component] of Object.entries(components)) {
      app.component(name, component)
    }
  },
}

export default DocVaultPlugin
