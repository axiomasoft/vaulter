import { describe, expect, it } from 'vitest'
import { MAX_NAME_LENGTH, NAME_REASONS, portableNameReason } from '../src/naming'

// The cases below are the rows of packages/core/tests/Naming/NamePolicyTest.php (the server's
// portable-v1 policy), plus the rules that test leaves implicit. A change to the PHP policy
// must change both.
describe('portableNameReason mirrors the server portable-v1 policy', () => {
  it.each([
    ['leading space', ' a.txt', 'leading_or_trailing_space'],
    ['trailing space', 'a.txt ', 'leading_or_trailing_space'],
    ['no-break space edge', ' a', 'leading_or_trailing_space'],
    ['trailing dot', 'a.txt.', 'trailing_dot_or_space'],
    ['device name with extension', 'CON.txt', 'reserved_name'],
    ['device name any case', 'con', 'reserved_name'],
    ['device name, several extensions', 'nul.tar.gz', 'reserved_name'],
    ['COM digit', 'COM0', 'reserved_name'],
    ['LPT superscript digit', 'LPT¹.log', 'reserved_name'],
    ['desktop.ini', 'Desktop.INI', 'reserved_name'],
    ['.DS_Store', '.DS_Store', 'reserved_name'],
    ['colon', 'a:b', 'reserved_char'],
    ['angle bracket', 'a<b', 'reserved_char'],
    ['question mark', 'why?', 'reserved_char'],
    ['pipe', 'a|b', 'reserved_char'],
    ['asterisk', '*.txt', 'reserved_char'],
    ['double quote', 'say "hi"', 'reserved_char'],
    ['base rule: separator', 'a/b', 'separator'],
    ['base rule: backslash', 'a\\b', 'separator'],
    ['base rule: empty', '', 'empty'],
    ['base rule: path alias', '..', 'path_alias'],
    ['base rule: single dot', '.', 'path_alias'],
    ['base rule: control character', 'a\u0007b', 'control_char'],
    ['base rule: C1 control character', 'a\u0085b', 'control_char'],
    ['base rule: too long (UTF-8 bytes)', 'ж'.repeat(128), 'too_long'],
    ['base rule: too long (ASCII)', 'a'.repeat(256), 'too_long'],
    ['base rule: lone surrogate is not valid UTF-8', 'a\uD800b', 'reserved_char'],
    // PCRE (the server) counts U+180E as a space; ECMAScript's \s does not.
    ['Mongolian vowel separator leading', '\u180Efile', 'leading_or_trailing_space'],
    ['Mongolian vowel separator trailing', 'file\u180E', 'leading_or_trailing_space'],
  ])('%s → %s', (_label, name, reason) => {
    expect(portableNameReason(name)).toBe(reason)
  })

  it('accepts ordinary names', () => {
    expect(portableNameReason('Report 2026.pdf')).toBeNull()
    expect(portableNameReason('CONSOLE.txt')).toBeNull()
    expect(portableNameReason('.gitignore')).toBeNull()
    expect(portableNameReason('a'.repeat(MAX_NAME_LENGTH))).toBeNull()
    expect(portableNameReason('😀 party')).toBeNull()
  })

  it('does not treat a byte order mark at the edge as a space (the server accepts it)', () => {
    expect(portableNameReason('\uFEFFfile')).toBeNull()
    expect(portableNameReason('file\uFEFF')).toBeNull()
  })

  it('judges the NFC form, like the server', () => {
    // "e" + combining acute is one visible letter; precomposed it is one code unit.
    expect(portableNameReason('Résumé.pdf')).toBeNull()
    // 128 decomposed "é" are 256 UTF-8 bytes but only 128 once composed (NFC) = 256 bytes: still too long.
    expect(portableNameReason('é'.repeat(128))).toBe('too_long')
    expect(portableNameReason('é'.repeat(127))).toBeNull()
  })

  it('checks the base rules before the portable ones, as the server does', () => {
    // A name that is both too long and contains a reserved character is "too_long" first.
    expect(portableNameReason('?'.repeat(256))).toBe('too_long')
    // A separator wins over a reserved character.
    expect(portableNameReason('a/b?')).toBe('separator')
  })

  it('lists exactly the closed set of reasons the server may answer', () => {
    expect([...NAME_REASONS].sort()).toEqual([
      'control_char',
      'empty',
      'leading_or_trailing_space',
      'path_alias',
      'reserved_char',
      'reserved_name',
      'separator',
      'too_long',
      'trailing_dot_or_space',
    ])
  })
})
