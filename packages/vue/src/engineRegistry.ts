import type { Component } from 'vue'

// The explicit, host-owned registry for mapping a handler's `ui.component` (or its
// `id`) to the Vue component `VaulterDocumentNode` mounts (VLT-CANON P1.2). This
// formalises what was previously only a `componentMap` prop into a public,
// importable API — an explicit ES-module import a host calls once at startup,
// deliberately NOT an implicit ambient global (the Nextcloud Viewer lesson:
// `OCA.Viewer.registerHandler` was replaced with explicit imports for the same
// reason — discoverability and tree-shaking).
//
// `componentMap` on `VaulterDocumentNode` remains fully supported and takes
// precedence — this registry is an additional, global source a host may prefer for
// engines shared across many mount points instead of threading a prop everywhere.

const registry = new Map<string, Component>()

/**
 * Register a component for a handler's `ui.component` id (or its `id`, if the
 * handler has no distinct `ui.component`). Registering the same key again
 * overwrites the previous entry — useful for hot-reload or host-side overrides.
 */
export function registerEngineComponent(id: string, component: Component): void {
  registry.set(id, component)
}

/** Remove a previously registered component. No-op if the id was never registered. */
export function unregisterEngineComponent(id: string): void {
  registry.delete(id)
}

/** Look up a component registered under `id`, or `undefined` if none was registered. */
export function resolveEngineComponent(id: string): Component | undefined {
  return registry.get(id)
}

/** The ids currently registered — mostly useful for diagnostics/tests. */
export function registeredEngineComponentIds(): string[] {
  return Array.from(registry.keys())
}

/** Clear every registered component. Intended for test teardown, not app code. */
export function clearEngineComponents(): void {
  registry.clear()
}
