import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import type { VaulterClient } from '../../src/api/client'
import { vaulterClientKey } from '../../src/composables/useVaulterClient'
import { createVaulterI18n, vaulterI18nKey } from '../../src/i18n'
import { builtinViewers } from '../../src/viewers/builtin'
import { createViewerRegistry, viewerRegistryKey } from '../../src/viewers/registry'

/** Everything a component needs from the plugin, around a (partial) fake client. */
export function withClient(client: unknown) {
  return {
    global: {
      provide: {
        [vaulterClientKey as symbol]: client as VaulterClient,
        [viewerRegistryKey as symbol]: createViewerRegistry(builtinViewers()),
        [vaulterI18nKey as symbol]: createVaulterI18n(),
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
