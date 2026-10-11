import { flushPromises } from '@vue/test-utils'
import { vi } from 'vitest'
import { createVaulterClient, type VaulterClient } from '../../src/api/client'
import type { Node, NodeAbilities, NodePresentation } from '../../src/api/types'
import nodeExample from '../../../../openapi/examples/node.json'

let sequence = 100

const ALL_ABILITIES: NodeAbilities = {
  view: true,
  download: true,
  comment: true,
  edit: true,
  create: true,
  move: true,
  copy: true,
  trash: true,
  restore: true,
  share: true,
  manage: true,
  delete: true,
}

/** A folder node shaped exactly like the server's example, with every ability. */
export function makeNode(overrides: Partial<Omit<Node, 'abilities'>> & { abilities?: Partial<NodeAbilities> } = {}): Node {
  sequence += 1
  const { abilities, ...rest } = overrides
  const base = JSON.parse(JSON.stringify(nodeExample.body.data)) as Node

  return {
    ...base,
    id: String(sequence),
    name: `Node ${sequence}`,
    etag: `"${sequence}.0.0000000000000000"`,
    ...rest,
    abilities: { ...ALL_ABILITIES, ...abilities },
  }
}

export function makeFile(overrides: Parameters<typeof makeNode>[0] = {}): Node {
  return makeNode({
    kind: 'file',
    mime: 'application/octet-stream',
    extension: 'bin',
    size_bytes: 1024,
    presentation: makePresentation({ key: 'vaulter/download' }),
    ...overrides,
  })
}

export function makeDocument(overrides: Parameters<typeof makeNode>[0] = {}): Node {
  return makeNode({
    kind: 'document',
    presentation: makePresentation({ key: 'vaulter/tiptap', documentType: 'richtext', handler: null }),
    ...overrides,
  })
}

export function makePresentation(options: {
  key: string | null
  documentType?: string | null
  typeAvailable?: boolean
  handler?: string | null
  props?: Record<string, unknown>
}): NodePresentation {
  return {
    handler: options.handler === undefined ? 'file.generic' : options.handler,
    document_type: options.documentType ?? null,
    type_available: options.typeAvailable ?? true,
    viewer: { key: options.key, props: options.props ?? {} },
    capabilities: { editable: false, previewable: true, downloadable: true, thumbnailable: false, collaborative: false },
  }
}

export function pageOf<T>(data: T[], nextCursor: string | null = null) {
  return { data, meta: { next_cursor: nextCursor, per_page: 50 } }
}

/** A client whose every call is answered by `respond(method, path)`; the calls are recorded. */
export function stubClient(respond: (method: string, url: string, body: unknown) => unknown): {
  client: VaulterClient
  calls: Array<{ method: string, url: string, body: unknown, headers: Record<string, string> }>
} {
  const calls: Array<{ method: string, url: string, body: unknown, headers: Record<string, string> }> = []
  const fetchStub: typeof fetch = async (input, init) => {
    const url = String(input).replace('/api/vaulter/v1', '')
    const method = init?.method ?? 'GET'
    const body = typeof init?.body === 'string' ? JSON.parse(init.body) : init?.body
    calls.push({ method, url, body, headers: (init?.headers ?? {}) as Record<string, string> })
    const answer = respond(method, url, body)

    if (answer instanceof Response) {
      return answer
    }
    if (answer === undefined) {
      return new Response(null, { status: 204, headers: { 'X-Vaulter-Api-Version': '1' } })
    }
    return new Response(JSON.stringify(answer), {
      status: 200,
      headers: { 'Content-Type': 'application/json', 'X-Vaulter-Api-Version': '1' },
    })
  }

  return { client: createVaulterClient({ fetch: fetchStub }), calls }
}

/** Let lazily imported viewers load and the components they mount render. */
export async function settle(): Promise<void> {
  await vi.dynamicImportSettled()
  await flushPromises()
  await vi.dynamicImportSettled()
  await flushPromises()
}
