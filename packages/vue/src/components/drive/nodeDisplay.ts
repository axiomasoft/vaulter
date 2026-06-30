import type { Node, NodeKind } from '../../types'

/**
 * The small set of visual kinds the Drive list paints an icon for. They are NOT
 * the API `NodeKind` — several mime families collapse to one bucket (image/pdf/
 * archive) while folders/documents pass through. Host apps restyle via the class
 * hook `vaulter-node-icon--<bucket>` and/or the per-row `icon` slot.
 */
export type NodeIconKind =
  | 'folder'
  | 'document'
  | 'image'
  | 'pdf'
  | 'archive'
  | 'audio'
  | 'video'
  | 'file'

const MIME_BUCKETS: ReadonlyArray<[RegExp, NodeIconKind]> = [
  [/^image\//, 'image'],
  [/^audio\//, 'audio'],
  [/^video\//, 'video'],
  [/(^application\/pdf$)|(\/pdf$)/, 'pdf'],
  [/(zip|x-tar|x-7z|x-rar|gzip|compressed)/, 'archive'],
]

const EXTENSION_BUCKETS: Record<string, NodeIconKind> = {
  pdf: 'pdf',
  zip: 'archive', tar: 'archive', gz: 'archive', rar: 'archive', '7z': 'archive',
  png: 'image', jpg: 'image', jpeg: 'image', gif: 'image', webp: 'image', svg: 'image',
  mp3: 'audio', wav: 'audio', flac: 'audio',
  mp4: 'video', mov: 'video', mkv: 'video', webm: 'video',
}

/**
 * Resolve a node to one of the {@link NodeIconKind} buckets: a folder/document by
 * kind, otherwise by mime family, then by extension, defaulting to a plain file.
 */
export function nodeIconKind(node: Pick<Node, 'kind' | 'mime' | 'extension'>): NodeIconKind {
  const kind = node.kind as NodeKind
  if (kind === 'folder') {
    return 'folder'
  }
  if (kind === 'document') {
    return 'document'
  }

  const mime = node.mime ?? ''
  for (const [pattern, bucket] of MIME_BUCKETS) {
    if (pattern.test(mime)) {
      return bucket
    }
  }

  const ext = (node.extension ?? '').toLowerCase().replace(/^\./, '')
  if (ext in EXTENSION_BUCKETS) {
    return EXTENSION_BUCKETS[ext]
  }

  return 'file'
}

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as const

/**
 * Format a byte count as a short human string (e.g. `1.2 MB`). `null`/`undefined`
 * (a folder, or an unsized node) returns an empty string so the cell stays blank.
 */
export function humanByteSize(bytes: number | null | undefined, fractionDigits = 1): string {
  if (bytes == null || Number.isNaN(bytes)) {
    return ''
  }
  if (bytes < 1) {
    return '0 B'
  }

  const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), BYTE_UNITS.length - 1)
  const value = bytes / 1024 ** exponent
  // Whole bytes never show a fraction; larger units round to `fractionDigits`.
  const digits = exponent === 0 ? 0 : fractionDigits
  return `${value.toFixed(digits)} ${BYTE_UNITS[exponent]}`
}
