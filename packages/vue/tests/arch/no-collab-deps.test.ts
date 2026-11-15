import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

// `@vaulter/vue` does not pull the realtime stack into a host's bundle. It talks to a
// collaboration adapter (src/collaboration-adapter.ts, types only); the Yjs / Hocuspocus code lives
// in `@vaulter/vue-collaboration`. Type-only imports count as well.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const src = join(root, 'src')

const forbiddenPackages = [
  /^yjs$/, /^yjs\//, /^y-/, /^lib0(\/|$)/, /^@hocuspocus\//, /^@vaulter\/vue-collaboration(\/|$)/,
]

const forbiddenDependencies = ['@hocuspocus/provider', '@hocuspocus/server', 'yjs', 'y-protocols', 'y-prosemirror', 'lib0']

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

function isForbidden(specifier: string): boolean {
  return forbiddenPackages.some((pattern) => pattern.test(specifier))
}

describe('@vaulter/vue has no collaboration stack (D15)', () => {
  const files = sourceFiles(src)

  it('scans the source folder', () => {
    expect(files.length).toBeGreaterThan(0)
  })

  it('imports no yjs, y-*, @hocuspocus/* in src', () => {
    const violations = files.flatMap((file) =>
      specifiersOf(readFileSync(file, 'utf8')).flatMap(({ line, specifier }) =>
        isForbidden(specifier) ? [`${relative(root, file)}:${line} '${specifier}'`] : []),
    )

    expect(violations).toEqual([])
  })

  it('declares none of them as a dependency, peer or optional dependency', () => {
    const manifest = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8')) as Record<string, Record<string, string> | undefined>
    const declared = ['dependencies', 'peerDependencies', 'optionalDependencies']
      .flatMap((field) => Object.keys(manifest[field] ?? {}).map((name) => `${field}.${name}`))

    expect(declared.filter((entry) => forbiddenDependencies.includes(entry.slice(entry.indexOf('.') + 1)))).toEqual([])
  })

  it('does not externalize them in the library build either', () => {
    const config = readFileSync(join(root, 'vite.config.ts'), 'utf8')

    expect(config).not.toMatch(/['"]yjs['"]|@hocuspocus/)
  })

  it('detects the forbidden shapes', () => {
    for (const specifier of ['yjs', 'yjs/dist/yjs.mjs', 'y-protocols/awareness', 'y-prosemirror', 'lib0/encoding',
      '@hocuspocus/provider', '@hocuspocus/server', '@vaulter/vue-collaboration']) {
      expect(isForbidden(specifier), specifier).toBe(true)
    }
    for (const specifier of ['vue', '@tiptap/vue-3', '@vaulter/vue', 'tus-js-client', './collaboration-adapter', 'year-month']) {
      expect(isForbidden(specifier), specifier).toBe(false)
    }
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
