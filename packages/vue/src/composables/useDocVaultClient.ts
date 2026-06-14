import { inject, type InjectionKey } from 'vue'
import type { DocVaultClient } from '../client'

export const docVaultClientKey: InjectionKey<DocVaultClient> = Symbol('docvault.client')

/**
 * Resolve the DocVault API client provided by `DocVaultPlugin`
 * (or `provideDocVaultClient`).
 */
export function useDocVaultClient(): DocVaultClient {
  const client = inject(docVaultClientKey, null)

  if (client === null) {
    throw new Error(
      '[DocVault] No API client found. Install the plugin with '
      + '`app.use(DocVaultPlugin, { baseUrl })` or call `provideDocVaultClient()` in a parent component.',
    )
  }

  return client
}
