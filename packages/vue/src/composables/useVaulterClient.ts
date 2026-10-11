import { inject, type InjectionKey } from 'vue'
import type { VaulterClient } from '../api/client'

export const vaulterClientKey: InjectionKey<VaulterClient> = Symbol('vaulter.client')

/**
 * Resolve the Vaulter API client provided by `VaulterPlugin`
 * (or `provideVaulterClient`).
 */
export function useVaulterClient(): VaulterClient {
  const client = inject(vaulterClientKey, null)

  if (client === null) {
    throw new Error(
      '[Vaulter] No API client found. Install the plugin with '
      + '`app.use(VaulterPlugin, { baseUrl })` or call `provideVaulterClient()` in a parent component.',
    )
  }

  return client
}
