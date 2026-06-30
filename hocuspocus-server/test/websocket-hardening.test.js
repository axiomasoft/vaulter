import { test } from 'node:test'
import assert from 'node:assert/strict'
import { buildServer, websocketOptions } from '../src/index.js'
import { config } from '../src/config.js'

// Regression for the silent-no-op bug (master plan Addendum A.3 #3/#5): in
// @hocuspocus/server v2.x the ws frame limits are honoured ONLY via listen()'s 3rd argument,
// NOT a `websocketOptions` config key. These tests assert the limit actually reaches the
// underlying `ws` WebSocketServer — a config-only wiring would leave it at the ~100 MiB default.

/** Walk the Hocuspocus instance to find the real `ws` WebSocketServer (has options + clients). */
function findWebSocketServer(root) {
  const seen = new Set()
  const stack = [root]
  while (stack.length) {
    const node = stack.pop()
    if (!node || typeof node !== 'object' || seen.has(node)) continue
    seen.add(node)
    if (node.options && typeof node.options.maxPayload !== 'undefined' && node.clients) {
      return node
    }
    for (const key of Object.keys(node)) {
      try { stack.push(node[key]) } catch { /* getters may throw */ }
    }
  }
  return null
}

test('maxPayload + perMessageDeflate reach the ws server when passed through listen()', async () => {
  const server = await buildServer()
  try {
    await server.listen(0, undefined, websocketOptions()) // ephemeral port
    const wss = findWebSocketServer(server)
    assert.ok(wss, 'located the underlying ws.WebSocketServer')
    assert.equal(wss.options.maxPayload, config.maxPayloadBytes, 'maxPayload applied (not the ws default)')
    assert.equal(wss.options.perMessageDeflate, false, 'perMessageDeflate disabled')
  } finally {
    await server.destroy()
  }
})

test('negative control: WITHOUT passing the options through listen() the cap is NOT applied (v2 ignores the config key)', async () => {
  const server = await buildServer()
  try {
    await server.listen(0) // no ws options → library default, proving the config key alone is dead
    const wss = findWebSocketServer(server)
    assert.ok(wss)
    assert.notEqual(wss.options.maxPayload, config.maxPayloadBytes,
      'the ws server keeps its default cap unless options are passed via listen()')
  } finally {
    await server.destroy()
  }
})
