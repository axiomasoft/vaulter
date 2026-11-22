import type { Extensions } from '@tiptap/core'
import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table'
import StarterKit from '@tiptap/starter-kit'
import { BlockId } from '@vaulter/vue'

/**
 * Host extensions for `presentation.viewer.props.preset`.
 * Rich text follows the package editor floor (StarterKit, Link, Image, Table, BlockId).
 * Markdown stays on the closed set Markdown can represent.
 */
export function extensionsFor(preset: string): Extensions {
  const link = Link.configure({ openOnClick: false })

  if (preset === 'markdown') {
    return [
      StarterKit.configure({ link: false, underline: false }),
      link,
    ]
  }

  if (preset === 'rich-text') {
    return [
      StarterKit.configure({ link: false }),
      link,
      Image,
      Table,
      TableRow,
      TableHeader,
      TableCell,
      BlockId,
    ]
  }

  throw new Error(`Unknown document preset: ${preset}`)
}
