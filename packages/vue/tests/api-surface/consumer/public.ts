// Consumer fixture: imports ONLY the published entrypoint name (resolved to the emitted public declarations, never ../src).
import { VaulterPlugin, createVaulterClient, useVaulterI18n } from '@vaulter/vue'
import type { VaulterClient, VaulterClientOptions, VaulterPluginOptions } from '@vaulter/vue'

export const client: (options: VaulterClientOptions) => VaulterClient = createVaulterClient
export const plugin: unknown = VaulterPlugin
export const i18n: unknown = useVaulterI18n
export type PluginOptions = VaulterPluginOptions

// Exported .vue component through the public entrypoint: $props optionality/types and $emit typing.
import { VaulterBreadcrumb } from '@vaulter/vue'

type Crumb = InstanceType<typeof VaulterBreadcrumb>
export const minimalProps: Crumb['$props'] = {}
export const fullProps: Crumb['$props'] = {
  path: [{ id: 'n1' as never, name: 'Docs' }],
  rootLabel: 'Drive',
  onNavigate: (id) => { const v: string | null = id; void v },
}
export const emitsNavigate = (c: Crumb): void => { c.$emit('navigate', null) }
// @ts-expect-error unknown event name
export const emitsUnknown = (c: Crumb): void => { c.$emit('nope') }
// @ts-expect-error payload must be a node id or null
export const emitsBadPayload = (c: Crumb): void => { c.$emit('navigate', 42) }
// @ts-expect-error rootLabel is a string
export const badProp: Crumb['$props'] = { rootLabel: 42 }
