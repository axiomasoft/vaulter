import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { getSchema } from '@tiptap/core'
import { extensionsFor, SUPPORTED_TYPES } from '../src/schema.js'

// The hub seeds and projects with src/schema.js; the backend validates with its
// TipTapSchema allow-list. packages/documents/tests/Fixtures/tiptap-schema.json is
// written by the PHP TipTapSchemaFixtureTest — both sides must name the same nodes and
// marks, or a seed loses content / every store fails.

const fixture = JSON.parse(readFileSync(new URL('../../packages/documents/tests/Fixtures/tiptap-schema.json', import.meta.url), 'utf8'))

test('the hub knows exactly the collaborative types the backend pins', () => {
  assert.deepEqual([...SUPPORTED_TYPES].sort(), Object.keys(fixture).sort())
})

for (const type of Object.keys(fixture)) {
  test(`${type}: hub nodes and marks equal the backend allow-list`, () => {
    const schema = getSchema(extensionsFor(type))

    assert.deepEqual(Object.keys(schema.nodes).sort(), fixture[type].nodes)
    assert.deepEqual(Object.keys(schema.marks).sort(), fixture[type].marks)
  })
}
