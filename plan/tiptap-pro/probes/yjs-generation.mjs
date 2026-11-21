/** Demonstrate range collapse and independent stale bytes on a freshly seeded Y.Doc. */

import assert from 'node:assert/strict'
import { writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { parseArgs } from 'node:util'

const require = createRequire(new URL('../lab/package.json', import.meta.url))
const Y = require('yjs')

function paragraph(id, text) {
  const block = new Y.XmlElement('paragraph')
  block.setAttribute('blockId', id)
  block.insert(0, [new Y.XmlText(text)])
  return block
}

function seed() {
  const document = new Y.Doc()
  document.getXmlFragment('default').insert(0, [paragraph('p42', 'Payment 15 days')])
  return document
}

function textOf(document) {
  return document.getXmlFragment('default').toArray().map(block => block.get(0).toString())
}

function bounds(document, start, end) {
  return [start, end].map(position => Y.createAbsolutePositionFromRelativePosition(position, document)?.index ?? null)
}

const original = seed()
const block = original.getXmlFragment('default').get(0)
const text = block.get(0)
const start = Y.createRelativePositionFromTypeIndex(text, 8, 0)
const end = Y.createRelativePositionFromTypeIndex(text, 10, -1)
assert.deepEqual(bounds(original, start, end), [8, 10])

text.insert(8, '<')
text.insert(11, '>')
const [left, right] = bounds(original, start, end)
assert.equal(text.toString().slice(left, right), '15')

text.delete(left, 1)
const partial = bounds(original, start, end)
assert.equal(text.toString().slice(...partial), '5')
text.delete(partial[0], 1)
const deleted = bounds(original, start, end)
assert.notEqual(deleted[0], null)
assert.equal(deleted[0], deleted[1])

original.getXmlFragment('default').delete(0, 1)
assert.deepEqual(bounds(original, start, end), [null, null])

const earlier = seed()
const alienOriginal = new Y.Doc()
Y.applyUpdate(alienOriginal, Y.encodeStateAsUpdate(earlier))
const beforeOffline = Y.encodeStateVector(alienOriginal)
const offlineText = alienOriginal.getXmlFragment('default').get(0).get(0)
offlineText.delete(8, 2)
offlineText.insert(8, '20')
const staleText = Y.encodeStateAsUpdate(alienOriginal, beforeOffline)
const restored = seed()
assert.notEqual(earlier.clientID, restored.clientID)
Y.applyUpdate(restored, staleText)
assert.deepEqual(textOf(restored), ['Payment 15 days'])
assert.ok(restored.store.pendingStructs ?? restored.store.pendingDs)

const previouslyEmpty = new Y.Doc()
previouslyEmpty.getXmlFragment('default').insert(0, [paragraph('offline-block', 'Offline append to empty root')])
Y.applyUpdate(restored, Y.encodeStateAsUpdate(previouslyEmpty))
assert.ok(textOf(restored).includes('Offline append to empty root'))

const report = {
  yjs: require('yjs/package.json').version,
  comments: {
    insert_at_both_edges_retained_text: '15',
    partial_delete_retained_text: '5',
    full_delete_resolved_to_empty_range: deleted,
    delete_block_resolved_to: [null, null],
  },
  restore: {
    old_text_delta_on_fresh_seed: 'missing_history',
    accepted_dependency_free_append_without_generation_gate: true,
  },
}

const { values } = parseArgs({ options: { report: { type: 'string' } } })
const json = JSON.stringify(report, null, 2) + '\n'
if (values.report) writeFileSync(values.report, json)
process.stdout.write(json)
