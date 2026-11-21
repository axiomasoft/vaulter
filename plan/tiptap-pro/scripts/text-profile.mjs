// Research-only adapter for a paragraph containing one Y.XmlText.
// It does not implement the Office SDK's authorization, intent or receipt contracts.
import { createRequire } from 'node:module'
const require = createRequire(new URL('../lab/package.json', import.meta.url))
export const Y = require('yjs')

export function paragraph(id, parts) {
  const block = new Y.XmlElement('paragraph')
  block.setAttribute('blockId', id)
  const text = new Y.XmlText()
  block.insert(0, [text])
  let offset = 0
  for (const part of parts) { text.insert(offset, part.text, part.attributes || {}); offset += part.text.length }
  return block
}

export function textAt(doc, blockId) {
  const block = doc.getXmlFragment('default').toArray().find(node => node.getAttribute('blockId') === blockId)
  if (!block || block.length !== 1 || !(block.get(0) instanceof Y.XmlText)) throw new Error('Unexpected block shape')
  return block.get(0)
}

export function plain(text) {
  return text.toDelta().map(item => {
    if (typeof item.insert !== 'string') throw new Error('Embedded content is outside this text profile')
    return item.insert
  }).join('')
}

export function replaceExact(doc, blockId, before, after) {
  if (!before) throw new Error('Non-empty target required')
  const text = textAt(doc, blockId)
  // XmlText.toString() serializes formatting tags; its offsets are not text offsets.
  const original = plain(text)
  const offset = original.indexOf(before)
  if (offset < 0 || original.indexOf(before, offset + 1) !== -1) throw new Error('Exact target conflict')
  let cursor = 0
  let attributes = {}
  let uniform = false
  for (const item of text.toDelta()) {
    const end = cursor + String(item.insert).length
    if (cursor <= offset && end >= offset + before.length) { attributes = item.attributes || {}; uniform = true; break }
    cursor = end
  }
  if (!uniform) throw new Error('Target crosses formatting runs')
  const vector = Y.encodeStateVector(doc)
  doc.transact(() => { text.delete(offset, before.length); text.insert(offset, after, attributes) })
  return Y.encodeStateAsUpdate(doc, vector)
}
