import { Extension } from '@tiptap/core'
import { Fragment, Slice, type Node as ProseMirrorNode } from '@tiptap/pm/model'
import { Plugin, PluginKey } from '@tiptap/pm/state'

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
          transformPasted(slice: Slice): Slice {
            const stripped = stripBlockIdsInFragment(slice.content, typeSet)

            return new Slice(stripped, slice.openStart, slice.openEnd)
          },
        },

        appendTransaction(transactions, _oldState, newState) {
          if (!transactions.some((tr) => tr.docChanged)) {
            return null
          }

          const seen = new Set<string>()
          const tr = newState.tr
          let modified = false

          newState.doc.descendants((node, pos) => {
            if (!typeSet.has(node.type.name)) {
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
