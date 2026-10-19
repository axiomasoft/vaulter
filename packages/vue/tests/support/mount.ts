import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import type { VaulterClient } from '../../src/api/client'
import { VaulterCollaborationKey, type CollaborationAdapter } from '../../src/collaboration-adapter'
import { vaulterClientKey } from '../../src/composables/useVaulterClient'
import { createVaulterI18n, vaulterI18nKey } from '../../src/i18n'
import { builtinViewers } from '../../src/viewers/builtin'
import { createViewerRegistry, viewerRegistryKey } from '../../src/viewers/registry'

/**
 * Everything a component needs from the plugin, around a (partial) fake client; `collaboration`
 * installs a (fake) collaboration adapter, as the plugin option does.
 */
export function withClient(client: unknown, options: { collaboration?: CollaborationAdapter } = {}) {
  return {
    global: {
      provide: {
        [vaulterClientKey as symbol]: client as VaulterClient,
        [viewerRegistryKey as symbol]: createViewerRegistry(builtinViewers()),
        [vaulterI18nKey as symbol]: createVaulterI18n(),
        ...(options.collaboration ? { [VaulterCollaborationKey as symbol]: options.collaboration } : {}),
      },
    },
  }
}

/**
 * Mount a component that runs `setup()` against a fake client and exposes whatever it
 * returns, so a test can drive a composable.
 */
export function mountWith<R>(client: unknown, setup: () => R): { vm: R, unmount: () => void } {
  let captured: R
  const Comp = defineComponent({
    setup() {
      captured = setup()
      return () => h('div')
    },
  })
  const wrapper = mount(Comp, withClient(client))

  return { vm: captured!, unmount: () => wrapper.unmount() }
}

export async function flush(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await Promise.resolve()
  }
}
