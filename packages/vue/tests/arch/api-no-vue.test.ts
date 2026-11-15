import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

// `src/api` stays framework-free so it can later be split out as `@vaulter/client`
// without a rewrite. It may import only itself and framework-neutral npm packages: no Vue,
// no editor/realtime stack, no other `src/` folder. Type-only imports count as well.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const src = join(root, 'src')
const api = join(src, 'api')

const forbiddenPackages = [
  /^vue$/, /^vue\//, /^@vue\//, /^vue-/, /^@vueuse\//, /^pinia$/,
  /^@tiptap\//, /^prosemirror-/, /^yjs$/, /^yjs\//, /^y-/, /^@hocuspocus\//,
  /^@vaulter\/vue(\/|$)/,
]

// One specifier per match: `import … from 'x'`, `export … from 'x'` (the closing line of a
// multi-line clause), side-effect `import 'x'`, dynamic `import('x')`, `require('x')` and
// `/// <reference types="x" />`.
const specifierPatterns = [
  /\bfrom\s+['"]([^'"]+)['"]/g,
  /^\s*import\s+['"]([^'"]+)['"]/g,
  /\bimport\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
  /\brequire\s*\(\s*['"]([^'"]+)['"]\s*\)/g,
  /^\s*\/\/\/\s*<reference\s+(?:types|path)=['"]([^'"]+)['"]/g,
]

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) {
      return sourceFiles(path)
    }
    return /\.(ts|tsx|mts|cts|js|mjs|vue)$/.test(name) ? [path] : []
  })
}

function specifiersOf(text: string): Array<{ line: number, specifier: string }> {
  const found: Array<{ line: number, specifier: string }> = []
  text.split('\n').forEach((raw, index) => {
    const code = /^\s*\/\/\//.test(raw) ? raw : raw.replace(/^\s*(\/\/|\*|\/\*).*$/, '')
    for (const pattern of specifierPatterns) {
      for (const match of code.matchAll(pattern)) {
        found.push({ line: index + 1, specifier: match[1] })
      }
    }
  })
  return found
}

function specifiers(file: string): Array<{ line: number, specifier: string }> {
  return specifiersOf(readFileSync(file, 'utf8'))
}

function violation(file: string, specifier: string): string | null {
  if (specifier.startsWith('.')) {
    const target = resolve(dirname(file), specifier)
    return target === api || target.startsWith(api + sep) ? null : 'imports src/ outside src/api'
  }
  if (specifier === '@' || specifier.startsWith('@/')) {
    return specifier === '@/api' || specifier.startsWith('@/api/') ? null : 'imports src/ outside src/api'
  }
  return forbiddenPackages.some((pattern) => pattern.test(specifier)) ? 'imports a Vue/editor/realtime package' : null
}

describe('src/api has no Vue imports (D15)', () => {
  const files = sourceFiles(api)

  it('scans the api folder', () => {
    expect(files.length).toBeGreaterThan(0)
  })

  it('imports only itself and framework-neutral packages', () => {
    const violations = files.flatMap((file) =>
      specifiers(file).flatMap(({ line, specifier }) => {
        const reason = violation(file, specifier)
        return reason === null ? [] : [`${relative(root, file)}:${line} '${specifier}' ${reason}`]
      }),
    )

    expect(violations).toEqual([])
  })

  it('detects the forbidden shapes', () => {
    const file = join(api, 'client.ts')
    expect(violation(file, 'vue')).not.toBeNull()
    expect(violation(file, '@vue/reactivity')).not.toBeNull()
    expect(violation(file, 'vue-router')).not.toBeNull()
    expect(violation(file, '@tiptap/core')).not.toBeNull()
    expect(violation(file, 'yjs')).not.toBeNull()
    expect(violation(file, '@vaulter/vue')).not.toBeNull()
    expect(violation(file, 'pinia')).not.toBeNull()
    expect(violation(file, '@hocuspocus/provider')).not.toBeNull()
    expect(violation(file, '../composables/useDrive')).not.toBeNull()
    expect(violation(file, '@/components/Foo.vue')).not.toBeNull()
    expect(violation(file, './transport')).toBeNull()
    expect(violation(join(api, 'uploads', 'tus.ts'), '../types')).toBeNull()
    expect(violation(file, '@/api/errors')).toBeNull()
    expect(violation(file, 'tus-js-client')).toBeNull()
  })

  it('finds every import shape in a source text', () => {
    const text = [
      "import { a } from 'one'",
      'import {',
      '  b,',
      "} from 'two'",
      "import type { C } from 'three'",
      "export * from 'four'",
      "import 'five'",
      "const m = await import('six')",
      "const r = require('seven')",
      '/// <reference types="eight" />',
      "// import { x } from 'commented'",
      " * import { y } from 'doc-comment'",
    ].join('\n')

    expect(specifiersOf(text).map(({ specifier }) => specifier).sort())
      .toEqual(['eight', 'five', 'four', 'one', 'seven', 'six', 'three', 'two'])
  })
})
