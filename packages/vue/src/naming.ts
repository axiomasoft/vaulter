/**
 * The portable-v1 node-name rules, mirrored from `Vaulter\Naming\PortableNamePolicy` and
 * `Vaulter\Support\NodeName` (packages/core/src): the same checks in the same order, so a
 * name this function accepts is a name the server's default policy accepts, and the reason
 * it returns is the `meta.reason` the server would answer. Keep both in step.
 */

/** The closed list of `invalid_node_name` reasons. */
export type NameReason =
  | 'empty'
  | 'path_alias'
  | 'separator'
  | 'control_char'
  | 'reserved_char'
  | 'trailing_dot_or_space'
  | 'leading_or_trailing_space'
  | 'reserved_name'
  | 'too_long'

export const NAME_REASONS: readonly NameReason[] = [
  'empty',
  'path_alias',
  'separator',
  'control_char',
  'reserved_char',
  'trailing_dot_or_space',
  'leading_or_trailing_space',
  'reserved_name',
  'too_long',
]

/** The width of the node name column, in UTF-8 bytes and (portable policy) UTF-16 code units. */
export const MAX_NAME_LENGTH = 255

const DEVICE_NAME = /^(?:CON|PRN|AUX|NUL|COM[0-9¹²³]|LPT[0-9¹²³])$/iu
const RESERVED_FILES = ['desktop.ini', '.ds_store']
// The server's `[\s\p{Z}]` (PCRE): ASCII whitespace, every separator, and U+180E, which PCRE counts as a
// space. ECMAScript's `\s` differs (it has U+FEFF, which PCRE does not, and lacks U+180E), so spell it out.
const EDGE_SPACE = /^[\t-\r\p{Z}\u180E]|[\t-\r\p{Z}\u180E]$/u
const LONE_SURROGATE = /[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?<![\uD800-\uDBFF])[\uDC00-\uDFFF]/

/**
 * Why `name` breaks the portable-v1 policy, or null when it is acceptable. The name is judged
 * as typed after NFC normalisation — nothing is trimmed, exactly as on the server.
 */
export function portableNameReason(name: string): NameReason | null {
  if (LONE_SURROGATE.test(name)) {
    return 'reserved_char'
  }

  const normalized = name.normalize('NFC')

  if (normalized === '') {
    return 'empty'
  }
  if (normalized === '.' || normalized === '..') {
    return 'path_alias'
  }
  if (/[/\\]/.test(normalized)) {
    return 'separator'
  }
  if (/[\u0000-\u001F\u007F-\u009F]/.test(normalized)) {
    return 'control_char'
  }
  if (new TextEncoder().encode(normalized).length > MAX_NAME_LENGTH) {
    return 'too_long'
  }

  if (/[<>:"|?*]/.test(normalized)) {
    return 'reserved_char'
  }
  if (EDGE_SPACE.test(normalized)) {
    return 'leading_or_trailing_space'
  }
  if (normalized.endsWith('.')) {
    return 'trailing_dot_or_space'
  }

  const stem = normalized.split('.', 1)[0]
  if (DEVICE_NAME.test(stem) || RESERVED_FILES.includes(normalized.toLowerCase())) {
    return 'reserved_name'
  }

  // A UTF-16 code unit is what `String.length` counts.
  if (normalized.length > MAX_NAME_LENGTH) {
    return 'too_long'
  }

  return null
}
