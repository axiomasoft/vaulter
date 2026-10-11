import { defineAsyncComponent, inject, markRaw, type Component, type InjectionKey } from 'vue'

/**
 * A viewer is a component, or a plain function returning a (lazily imported) component:
 * `() => import('./DiagramViewer.vue')`. A plain function is always a loader; wrap a
 * functional component with `defineComponent` to register it directly.
 */
export type ViewerLoader = () => Promise<Component | { default: Component }>
export type ViewerEntry = Component | ViewerLoader

/** A dynamic `import()` yields a module namespace; a loader may also return the component itself. */
function unwrapModule(loaded: Component | { default: Component }): Component {
  return typeof loaded === 'object' && loaded !== null && 'default' in loaded ? loaded.default : loaded
}

export interface ViewerRegistry {
  /** Register or replace the viewer of `key`. */
  register(key: string, entry: ViewerEntry): void
  unregister(key: string): void
  has(key: string): boolean
  /** The component to mount for `key`, or null when none is registered. */
  resolve(key: string): Component | null
  keys(): string[]
}

export const viewerRegistryKey: InjectionKey<ViewerRegistry> = Symbol('vaulter.viewers')

/**
 * A registry belongs to ONE application (provided through `provide/inject`): two apps
 * on a page, or two SSR requests, never see each other's viewers.
 */
export function createViewerRegistry(initial: Record<string, ViewerEntry> = {}): ViewerRegistry {
  const entries = new Map<string, ViewerEntry>()
  // An async wrapper must be created once per entry, or every render would remount the viewer.
  const resolved = new Map<string, Component>()

  function register(key: string, entry: ViewerEntry): void {
    entries.set(key, entry)
    resolved.delete(key)
  }

  for (const [key, entry] of Object.entries(initial)) {
    register(key, entry)
  }

  return {
    register,
    unregister(key) {
      entries.delete(key)
      resolved.delete(key)
    },
    has: (key) => entries.has(key),
    resolve(key) {
      const cached = resolved.get(key)
      if (cached) {
        return cached
      }

      const entry = entries.get(key)
      if (entry === undefined) {
        return null
      }

      // `markRaw` keeps the component out of the reactivity system.
      const component = markRaw(typeof entry === 'function'
        ? defineAsyncComponent(() => (entry as ViewerLoader)().then(unwrapModule))
        : entry)
      resolved.set(key, component)

      return component
    },
    keys: () => Array.from(entries.keys()),
  }
}

/** The registry of the current app; throws when the plugin was not installed. */
export function useViewerRegistry(): ViewerRegistry {
  const registry = inject(viewerRegistryKey, null)

  if (registry === null) {
    throw new Error(
      '[Vaulter] No viewer registry found. Install the plugin with `app.use(VaulterPlugin, { baseUrl })`.',
    )
  }

  return registry
}
