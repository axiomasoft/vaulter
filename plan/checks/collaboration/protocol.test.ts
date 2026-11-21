// @vitest-environment happy-dom
import { once } from 'node:events'
import type { IncomingMessage } from 'node:http'
import type { AddressInfo } from 'node:net'
import { expect, it } from 'vitest'
import { WebSocketServer } from 'ws'
import { Doc } from 'yjs'
import { openCollaborationTransport } from '../../../packages/vue-collaboration/src/transport'
import { hocuspocusSession } from '../../../packages/vue-collaboration/tests/support/fakes'

it('checks the request URL received from the stock collaboration provider', async () => {
  const expected = process.env.VAULTER_HANDSHAKE_EXPECT ?? '2'
  expect(['missing', '2']).toContain(expected)

  const server = new WebSocketServer({ host: '127.0.0.1', port: 0 })
  const doc = new Doc()
  let transport: ReturnType<typeof openCollaborationTransport> | undefined
  try {
    await once(server, 'listening')
    const { port } = server.address() as AddressInfo
    const endpoint = `ws://127.0.0.1:${port}/editor?tenant=sample`
    transport = openCollaborationTransport(hocuspocusSession({ endpoint }), { doc })

    const [, request] = await once(server, 'connection', { signal: AbortSignal.timeout(4000) })
    const url = new URL((request as IncomingMessage).url!, endpoint)
    process.stdout.write(`WebSocket upgrade: ${url.pathname}${url.search}\n`)
    expect(url.pathname).toBe('/editor')
    expect(url.searchParams.getAll('tenant')).toEqual(['sample'])
    expect(url.searchParams.getAll('protocol')).toEqual(expected === 'missing' ? [] : ['2'])
  } finally {
    transport?.destroy()
    doc.destroy()
    for (const socket of server.clients) socket.terminate()
    await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()))
  }
})
