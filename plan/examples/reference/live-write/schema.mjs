// @ts-check
// The Hub's Tiptap schema for 'vaulter/rich-text' (packages/collaboration/hub/src/schema.js, b230f6e27)
// plus the designed attrs.blockId of plan 3.1, which the current Hub schema does not declare yet.
// The tree of a Y.Doc is read through y-prosemirror (via @hocuspocus/transformer, as the Hub's buildSeed
// does) and validated against this ProseMirror schema: Node.fromJSON + check() refuse a tree the editor
// could not load, so the check compares the document a browser would show, not raw Yjs XML.

import { Extension, getSchema } from '@tiptap/core'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import { Node } from '@tiptap/pm/model'
import StarterKit from '@tiptap/starter-kit'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { FIELD, Y } from './room-sequencer.mjs'

/** Designed in plan 3.1 (attrs.blockId on paragraph and heading); not part of the current Hub schema. */
export const BlockId = Extension.create({
  name: 'vaulterBlockId',
  addGlobalAttributes() {
    return [{
      types: ['paragraph', 'heading'],
      attributes: {
        blockId: {
          default: null,
          parseHTML: (element) => element.getAttribute('data-block-id'),
          renderHTML: (attributes) => (attributes.blockId ? { 'data-block-id': attributes.blockId } : {}),
        },
      },
    }]
  },
})

export function richTextExtensions() {
  return [StarterKit.configure({ link: false }), Link, Image, Table, TableRow, TableHeader, TableCell, BlockId]
}

const schema = getSchema(richTextExtensions())

/**
 * The checkpoint of a document's JSON, built exactly like the Hub's buildSeed(): TiptapTransformer.toYdoc.
 * @param {Array<{ block_id: string, text: string }>} blocks
 */
export function seedUpdate(blocks) {
  const json = {
    type: 'doc',
    content: blocks.map((block) => ({ type: 'paragraph', attrs: { blockId: block.block_id }, content: [{ type: 'text', text: block.text }] })),
  }
  const seed = TiptapTransformer.toYdoc(json, FIELD, richTextExtensions())
  const update = Y.encodeStateAsUpdate(seed)
  seed.destroy()

  return update
}

/**
 * The ProseMirror tree of a Y.Doc, validated against the schema.
 * @param {InstanceType<typeof Y.Doc>} doc
 * @returns {{ paragraphs: Array<{ blockId: string | null, text: string, marks: string[] }>, json: any }}
 */
export function readTree(doc) {
  const json = TiptapTransformer.fromYdoc(doc, FIELD)
  const node = Node.fromJSON(schema, json)
  node.check()
  const paragraphs = []
  node.forEach((child) => {
    const marks = new Set()
    child.forEach((inline) => inline.marks.forEach((mark) => marks.add(mark.type.name)))
    paragraphs.push({ blockId: child.attrs.blockId ?? null, text: child.textContent, marks: [...marks].sort() })
  })

  return { paragraphs, json }
}
