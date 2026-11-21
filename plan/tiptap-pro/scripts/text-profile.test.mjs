import { test } from 'node:test'
import assert from 'node:assert/strict'
import { Y, paragraph, textAt, plain, replaceExact } from './text-profile.mjs'

function fixture() {
  const doc = new Y.Doc()
  doc.getXmlFragment('default').insert(0, [
    paragraph('p42', [{ text: 'Срок 😀: ' }, { text: '15', attributes: { bold: {} } }, { text: ' дней.' }]),
    paragraph('p71', [{ text: 'Банк Б' }]),
  ])
  return doc
}

test('plain text positions exclude XmlText markup and preserve UTF-16 offsets', () => {
  const doc = fixture()
  assert.equal(plain(textAt(doc, 'p42')), 'Срок 😀: 15 дней.')
  assert.match(textAt(doc, 'p42').toString(), /<bold>/)
  replaceExact(doc, 'p42', '15', '30')
  assert.equal(plain(textAt(doc, 'p42')), 'Срок 😀: 30 дней.')
  assert.deepEqual(textAt(doc, 'p42').toDelta().find(item => item.insert === '30').attributes, { bold: {} })
  assert.equal(plain(textAt(doc, 'p71')), 'Банк Б')
  doc.destroy()
})

test('replaying the same differential bytes does not duplicate replacement', () => {
  const doc = fixture()
  const seed = Y.encodeStateAsUpdate(doc)
  const update = replaceExact(doc, 'p42', '15', '30')
  const reader = new Y.Doc()
  Y.applyUpdate(reader, seed)
  Y.applyUpdate(reader, update)
  Y.applyUpdate(reader, update)
  assert.equal(plain(textAt(reader, 'p42')), 'Срок 😀: 30 дней.')
  assert.deepEqual(textAt(reader, 'p42').toDelta(), textAt(doc, 'p42').toDelta())
  reader.destroy(); doc.destroy()
})

test('a changed target conflicts without changing the candidate document', () => {
  const doc = fixture()
  replaceExact(doc, 'p42', '15', '20')
  const before = Y.encodeStateAsUpdate(doc)
  assert.throws(() => replaceExact(doc, 'p42', '15', '30'), /Exact target conflict/)
  assert.deepEqual(Y.encodeStateAsUpdate(doc), before)
  assert.equal(plain(textAt(doc, 'p42')), 'Срок 😀: 20 дней.')
  doc.destroy()
})
