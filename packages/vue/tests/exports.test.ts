import { describe, expect, it } from 'vitest'
import * as api from '../src/index'

// The public surface of @vaulter/vue. Adding an export is a deliberate, reviewed change to
// this snapshot (and a changeset); removing or renaming one is a breaking change.
describe('public exports', () => {
  it('matches the export snapshot', () => {
    expect(Object.keys(api).sort()).toMatchSnapshot()
  })

  it('has no leftovers of the pre-v1 surface', () => {
    for (const removed of [
      'docVaultClientKey', 'registerEngineComponent', 'resolveEngineComponent', 'unregisterEngineComponent',
      'clearEngineComponents', 'registeredEngineComponentIds', 'useStorage', 'useNodeGrants', 'useNodeShares',
      'useDocumentNode', 'useNodeCollaboration', 'useSpreadsheet', 'createNodeCollaborationSession',
      'VaulterDriveBreadcrumb', 'VaulterNodeGrantsPanel', 'VaulterNodeSharePanel',
    ]) {
      expect(api, removed).not.toHaveProperty(removed)
    }
  })

  it('has a default export: the plugin', () => {
    expect(api.default).toBe(api.VaulterPlugin)
  })
})
