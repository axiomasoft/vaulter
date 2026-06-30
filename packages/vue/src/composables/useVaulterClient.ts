import { inject, type InjectionKey } from 'vue'
import type { VaulterClient } from '../client'

export const docVaultClientKey: InjectionKey<VaulterClient> = Symbol('vaulter.client')

/**
 * Resolve the Vaulter API client provided by `VaulterPlugin`
 * (or `provideVaulterClient`).
 */
export function useVaulterClient(): VaulterClient {
  const client = inject(docVaultClientKey, null)

  if (client === null) {
    throw new Error(
      '[Vaulter] No API client found. Install the plugin with '
      + '`app.use(VaulterPlugin, { baseUrl })` or call `provideVaulterClient()` in a parent component.',
    )
  }

  return client
}
