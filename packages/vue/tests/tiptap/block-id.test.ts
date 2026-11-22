import { Editor } from '@tiptap/core'
import { Slice } from '@tiptap/pm/model'
import StarterKit from '@tiptap/starter-kit'
import { describe, expect, it } from 'vitest'
import { BlockId } from '../../src/tiptap/block-id'

function createEditor(content: Record<string, unknown>, seqPrefix = 'b') {
  let seq = 0
  const generateId = () => `${seqPrefix}_${++seq}`

  return new Editor({
    extensions: [
      StarterKit,
      BlockId.configure({ generateId }),
    ],
    content,
  })
}

function blockIds(editor: Editor): Array<string | null> {
  const ids: Array<string | null> = []
  editor.state.doc.descendants((node) => {
    if (node.type.name === 'paragraph' || node.type.name === 'heading') {
      ids.push((node.attrs.blockId as string | null) ?? null)
    }
  })

  return ids
}

describe('BlockId Tiptap extension', () => {
  it('assigns unique blockIds on missing blocks and preserves existing ones', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        { type: 'heading', attrs: { level: 2, blockId: 'h1' }, content: [{ type: 'text', text: 'Title' }] },
        { type: 'paragraph', content: [{ type: 'text', text: 'Body' }] },
      ],
    })

    // Trigger a doc change so appendTransaction runs on newly inserted content
    editor.commands.insertContentAt(editor.state.doc.content.size, {
      type: 'paragraph',
      content: [{ type: 'text', text: 'Second' }],
    })

    const ids = blockIds(editor)
    expect(ids[0]).toBe('h1')
    expect(ids[1]).toBeTruthy()
    expect(ids[2]).toBeTruthy()
    expect(new Set(ids).size).toBe(3)

    editor.destroy()
  })

  it('keeps the first block ID and assigns a new ID to the second block on split', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: 'p1' }, content: [{ type: 'text', text: 'HelloWorld' }] },
      ],
    })

    // Position cursor between "Hello" and "World" (pos 1 + 5 = 6) and split
    editor.commands.setTextSelection(6)
    editor.commands.splitBlock()

    const ids = blockIds(editor)
    expect(ids).toHaveLength(2)
    expect(ids[0]).toBe('p1')
    expect(ids[1]).toBeTruthy()
    expect(ids[1]).not.toBe('p1')

    editor.destroy()
  })

  it('keeps the first block ID on join', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: 'p1' }, content: [{ type: 'text', text: 'Hello' }] },
        { type: 'paragraph', attrs: { blockId: 'p2' }, content: [{ type: 'text', text: 'World' }] },
      ],
    })

    // Place cursor at start of second paragraph and join backward
    const secondStart = editor.state.doc.child(0).nodeSize + 1
    editor.commands.setTextSelection(secondStart)
    editor.commands.joinBackward()

    const ids = blockIds(editor)
    expect(ids).toEqual(['p1'])
    expect(editor.getText()).toBe('HelloWorld')

    editor.destroy()
  })

  it('assigns new unique IDs to pasted blocks without altering originals', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: 'p1' }, content: [{ type: 'text', text: 'Original' }] },
      ],
    })

    const firstNode = editor.state.doc.child(0)
    const rawSlice = new Slice(editor.state.doc.content.cut(0, firstNode.nodeSize), 0, 0)

    // Run transformPasted through the plugin prop
    const plugin = editor.state.plugins.find((p) => p.props.transformPasted)
    expect(plugin).toBeDefined()
    const transformed = plugin!.props.transformPasted!.call(plugin!, rawSlice, editor.view, false)

    const tr = editor.state.tr.replace(editor.state.doc.content.size, editor.state.doc.content.size, transformed)
    editor.view.dispatch(tr)

    const ids = blockIds(editor)
    expect(ids).toHaveLength(2)
    expect(ids[0]).toBe('p1')
    expect(ids[1]).toBeTruthy()
    expect(ids[1]).not.toBe('p1')

    editor.destroy()
  })

  it('preserves block ID when a block is moved within the document', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: 'p1' }, content: [{ type: 'text', text: 'First' }] },
        { type: 'paragraph', attrs: { blockId: 'p2' }, content: [{ type: 'text', text: 'Second' }] },
      ],
    })

    // Move second paragraph before first paragraph in a single transaction
    const firstSize = editor.state.doc.child(0).nodeSize
    const secondNode = editor.state.doc.child(1)
    const tr = editor.state.tr
      .delete(firstSize, firstSize + secondNode.nodeSize)
      .insert(0, secondNode)
    editor.view.dispatch(tr)

    expect(blockIds(editor)).toEqual(['p2', 'p1'])

    editor.destroy()
  })

  it('preserves bold and link marks under block p42 (3.1-B)', () => {
    const editor = createEditor({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          attrs: { blockId: 'p42' },
          content: [
            { type: 'text', marks: [{ type: 'bold' }], text: '15' },
            { type: 'text', text: ' ' },
            {
              type: 'text',
              marks: [{ type: 'link', attrs: { href: 'https://example.com' } }],
              text: 'docs',
            },
          ],
        },
      ],
    })

    const json = editor.getJSON()
    const para = json.content?.[0]
    expect(para?.attrs?.blockId).toBe('p42')
    expect(para?.content?.[0]).toMatchObject({
      type: 'text',
      text: '15',
      marks: [{ type: 'bold' }],
    })
    expect(para?.content?.[2]).toMatchObject({
      type: 'text',
      text: 'docs',
      marks: [{ type: 'link', attrs: { href: 'https://example.com' } }],
    })
    expect(editor.getHTML()).toContain('data-block-id="p42"')
    expect(editor.getHTML()).toContain('<strong>15</strong>')

    editor.destroy()
  })
})
