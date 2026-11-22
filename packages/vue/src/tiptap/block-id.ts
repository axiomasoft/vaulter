import { Extension } from '@tiptap/core'
import { Fragment, Slice, type Node as ProseMirrorNode } from '@tiptap/pm/model'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import type { EditorView } from '@tiptap/pm/view'

export const BLOCK_ID_TYPES = ['paragraph', 'heading'] as const

export interface BlockIdOptions {
  /** Node types that receive a stable `attrs.blockId`. Defaults to `['paragraph', 'heading']`. */
  types: string[]
  /** Generator for fresh unique block IDs. */
  generateId: () => string
}

export function generateBlockId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `blk_${crypto.randomUUID().replace(/-/g, '').slice(0, 16)}`
  }

  return `blk_${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36)}`
}

const BLOCK_ID_PLUGIN_KEY = new PluginKey('vaulterBlockId')

function stripBlockIdsInFragment(fragment: Fragment, types: ReadonlySet<string>): Fragment {
  const children: ProseMirrorNode[] = []

  fragment.forEach((node) => {
    let next = node

    if (next.content.size > 0) {
      const strippedContent = stripBlockIdsInFragment(next.content, types)
      if (strippedContent !== next.content) {
        next = next.copy(strippedContent)
      }
    }

    if (types.has(next.type.name) && next.attrs.blockId != null) {
      next = next.type.create(
        { ...next.attrs, blockId: null },
        next.content,
        next.marks,
      )
    }

    children.push(next)
  })

  return Fragment.fromArray(children)
}

/**
 * Tiptap extension for addressable rich-text blocks (`attrs.blockId`).
 *
 * Rules:
 *  - split: first block keeps its ID (`keepOnSplit: false`), second receives a new ID
 *  - join: ProseMirror keeps the first block's attrs (and therefore its ID)
 *  - paste: `transformPasted` clears copied IDs so every pasted block receives a new unique ID
 *  - move: moving a unique block within the document preserves its ID
 *  - deduplication: `appendTransaction` assigns fresh IDs to any missing or duplicate block IDs
 */
export const BlockId = Extension.create<BlockIdOptions>({
  name: 'blockId',

  addOptions() {
    return {
      types: [...BLOCK_ID_TYPES],
      generateId: generateBlockId,
    }
  },

  addGlobalAttributes() {
    return [
      {
        types: this.options.types,
        attributes: {
          blockId: {
            default: null,
            keepOnSplit: false,
            parseHTML: (element: HTMLElement) => element.getAttribute('data-block-id') ?? null,
            renderHTML: (attributes: Record<string, unknown>) => {
              if (typeof attributes.blockId !== 'string' || attributes.blockId === '') {
                return {}
              }

              return { 'data-block-id': attributes.blockId }
            },
          },
        },
      },
    ]
  },

  addProseMirrorPlugins() {
    const typeSet = new Set(this.options.types)
    const generateId = this.options.generateId

    return [
      new Plugin({
        key: BLOCK_ID_PLUGIN_KEY,

        props: {
          transformPasted(slice: Slice, view: EditorView): Slice {
            // Preserve IDs on internal drag-moves within the same editor; strip on paste or copy-drag.
            if (view?.dragging?.move) {
              return slice
            }

            const stripped = stripBlockIdsInFragment(slice.content, typeSet)

            return new Slice(stripped, slice.openStart, slice.openEnd)
          },
        },

        appendTransaction(transactions, oldState, newState) {
          if (!transactions.some((tr) => tr.docChanged)) {
            return null
          }

          // Detect start-of-block split where Tiptap converted the first fragment to a
          // default paragraph without carrying over the original blockId (e.g. Enter at
          // pos 1 of a heading): carry the original ID onto the first fragment so the
          // second fragment receives a fresh ID via the deduplication pass below.
          let splitCarryPos: number | null = null
          let splitCarryId: string | null = null
          if (
            oldState.selection.empty &&
            oldState.selection.$from.depth >= 1 &&
            oldState.selection.$from.parentOffset === 0
          ) {
            const oldPos = oldState.selection.$from.before()
            const oldParent = oldState.selection.$from.parent
            const oldId = oldParent.attrs.blockId
            if (typeSet.has(oldParent.type.name) && typeof oldId === 'string' && oldId !== '') {
              const firstNew = oldPos < newState.doc.content.size ? newState.doc.nodeAt(oldPos) : null
              if (
                firstNew &&
                typeSet.has(firstNew.type.name) &&
                (firstNew.attrs.blockId == null || firstNew.attrs.blockId === '')
              ) {
                const secondPos = oldPos + firstNew.nodeSize
                const secondNew = secondPos < newState.doc.content.size ? newState.doc.nodeAt(secondPos) : null
                if (secondNew && typeSet.has(secondNew.type.name) && secondNew.attrs.blockId === oldId) {
                  splitCarryPos = oldPos
                  splitCarryId = oldId
                }
              }
            }
          }

          const seen = new Set<string>()
          const tr = newState.tr
          let modified = false

          newState.doc.descendants((node, pos) => {
            if (!typeSet.has(node.type.name)) {
              return
            }

            if (pos === splitCarryPos && splitCarryId !== null && !seen.has(splitCarryId)) {
              seen.add(splitCarryId)
              tr.setNodeMarkup(pos, undefined, { ...node.attrs, blockId: splitCarryId })
              modified = true

              return
            }

            const existing = node.attrs.blockId
            if (typeof existing === 'string' && existing !== '' && !seen.has(existing)) {
              seen.add(existing)

              return
            }

            let nextId: string
            do {
              nextId = generateId()
            } while (nextId === '' || seen.has(nextId))

            seen.add(nextId)
            tr.setNodeMarkup(pos, undefined, { ...node.attrs, blockId: nextId })
            modified = true
          })

          if (!modified) {
            return null
          }

          tr.setMeta('addToHistory', false)

          return tr
        },
      }),
    ]
  },
})
