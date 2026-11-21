#!/usr/bin/env node
import { createHash } from 'node:crypto'
import { readdir, readFile, realpath, stat, mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const researchRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const defaultRequested = [
  '@tiptap-pro/provider',
  '@tiptap-pro/extension-comments',
  '@tiptap-pro/extension-snapshot',
  '@tiptap-pro/extension-snapshot-compare',
  '@tiptap-pro/extension-tracked-changes',
  '@tiptap-pro/compare',
  '@tiptap-pro/extension-convert-kit',
  '@tiptap-pro/extension-export-docx',
  '@tiptap-pro/extension-import-docx',
  '@tiptap-pro/client-ai-toolkit',
]
const ossRoots = [
  '@tiptap/core', '@tiptap/pm', '@tiptap/starter-kit',
  '@tiptap/extension-collaboration', '@tiptap/extension-collaboration-caret',
  '@tiptap/y-tiptap', '@hocuspocus/provider', '@hocuspocus/server',
  '@hocuspocus/transformer', 'yjs', 'y-prosemirror', 'y-protocols',
]

function options(argv) {
  const result = { lab: path.join(researchRoot, 'lab'), output: path.join(researchRoot, 'results/package-inventory.json'), requested: defaultRequested }
  for (let i = 0; i < argv.length; i++) {
    const flag = argv[i]
    if (flag === '--help') {
      process.stdout.write('Usage: node package-inventory.mjs [--lab DIR] [--output FILE] [--requested @tiptap-pro/a,@tiptap-pro/b]\nNo network, environment, npmrc, source text or source maps are read into the report.\n')
      return null
    }
    if (!['--lab', '--output', '--requested'].includes(flag) || !argv[i + 1] || argv[i + 1].startsWith('--')) throw new Error('Invalid CLI arguments; use --help')
    const value = argv[++i]
    if (flag === '--requested') {
      result.requested = value.split(',').filter(Boolean)
      if (result.requested.some(name => !/^@tiptap-pro\/[a-z0-9._-]+$/.test(name))) throw new Error('Requested names must belong to @tiptap-pro')
    } else result[flag.slice(2)] = path.resolve(value)
  }
  return result
}

async function jsonFile(file) {
  try { return JSON.parse(await readFile(file, 'utf8')) } catch (error) {
    if (error.code === 'ENOENT' || error.code === 'ENOTDIR') return null
    throw new Error(`Unable to read package metadata (${error.code ?? 'invalid JSON'})`)
  }
}

function names(map) {
  return Object.keys(map ?? {}).filter(name => /^(?:@[a-z0-9._-]+\/)?[a-z0-9._-]+$/i.test(name)).sort()
}

function dependencies(map) {
  return Object.fromEntries(names(map).map(name => {
    const value = map[name]
    // Registry URLs and local paths can carry credentials or host-specific details.
    const safe = typeof value === 'string' && value.length < 300 && !/[\\?&#\r\n]/.test(value)
      && !/(?:https?:|git[+:]|file:|link:|\/\/)/i.test(value)
      && /^[a-z0-9@/._^~*<>=|+\- :]+$/i.test(value)
    return [name, safe ? value : '[non-version specification omitted]']
  }))
}

function publicPath(value) {
  return typeof value === 'string' && value.length < 500 && !path.isAbsolute(value)
    && !value.split(/[\\/]/).includes('..') && !/[\r\n?&#]/.test(value)
    ? value.replaceAll('\\', '/') : null
}

function declaredTypePaths(manifest) {
  const result = new Set()
  const visit = (value, typeContext = false) => {
    if (typeof value === 'string') {
      if (typeContext || /\.d\.(?:ts|mts|cts)$/.test(value)) {
        const clean = publicPath(value)
        if (clean) result.add(clean)
      }
    } else if (Array.isArray(value)) value.forEach(item => visit(item, typeContext))
    else if (value && typeof value === 'object') Object.entries(value).forEach(([key, item]) => visit(item, typeContext || key === 'types'))
  }
  visit(manifest.types, true)
  visit(manifest.typings, true)
  visit(manifest.typesVersions, true)
  visit(manifest.exports)
  return [...result].sort()
}

async function resolvePackage(name, from) {
  let current = from
  for (;;) {
    const candidate = path.join(current, 'node_modules', ...name.split('/'))
    const manifest = await jsonFile(path.join(candidate, 'package.json'))
    if (manifest) return { directory: await realpath(candidate), manifest }
    const parent = path.dirname(current)
    if (parent === current) return null
    current = parent
  }
}

async function installedVendorNames(lab) {
  try {
    return (await readdir(path.join(lab, 'node_modules/@tiptap-pro'), { withFileTypes: true }))
      .filter(entry => entry.isDirectory() || entry.isSymbolicLink())
      .map(entry => `@tiptap-pro/${entry.name}`).sort()
  } catch (error) {
    if (error.code === 'ENOENT') return []
    throw new Error(`Unable to enumerate vendor packages (${error.code ?? 'unknown'})`)
  }
}

async function fileInventory(directory) {
  const result = { fileCount: 0, totalSizeBytes: 0, typeDeclarations: [] }
  async function walk(relative = '') {
    const entries = await readdir(path.join(directory, relative), { withFileTypes: true })
    for (const entry of entries.sort((a, b) => a.name.localeCompare(b.name))) {
      if (entry.name === 'node_modules' || entry.name === '.git') continue
      const file = path.join(relative, entry.name)
      if (entry.isDirectory()) await walk(file)
      else if (entry.isFile()) {
        const absolute = path.join(directory, file)
        const info = await stat(absolute)
        result.fileCount++
        result.totalSizeBytes += info.size
        if (/\.d\.(?:ts|mts|cts)$/.test(entry.name)) {
          const sha256 = createHash('sha256').update(await readFile(absolute)).digest('hex')
          result.typeDeclarations.push({ path: file.split(path.sep).join('/'), sizeBytes: info.size, sha256 })
        }
      }
      // Symlink contents are not followed: the report must not inspect outside files.
    }
  }
  await walk()
  result.typeDeclarations.sort((a, b) => a.path.localeCompare(b.path))
  return result
}

async function main() {
  const config = options(process.argv.slice(2))
  if (!config) return
  const labManifest = await jsonFile(path.join(config.lab, 'package.json'))
  if (!labManifest) throw new Error('Lab package.json is missing')
  const declared = { ...labManifest.dependencies, ...labManifest.devDependencies, ...labManifest.optionalDependencies }
  const requested = [...new Set([...config.requested, ...names(declared).filter(name => name.startsWith('@tiptap-pro/'))])].sort()
  const roots = [...new Set([...requested, ...ossRoots, ...await installedVendorNames(config.lab)])].sort()
  const packages = new Map()
  const missingDependencies = []

  async function collect(name, from, root = false) {
    const candidate = path.join(from, 'node_modules', ...name.split('/'))
    const rootManifest = root ? await jsonFile(path.join(candidate, 'package.json')) : null
    const found = root
      ? rootManifest ? { directory: await realpath(candidate), manifest: rootManifest } : null
      : await resolvePackage(name, from)
    if (!found) return null
    const { directory, manifest } = found
    const location = path.relative(config.lab, directory).split(path.sep).join('/')
    if (packages.has(directory)) return packages.get(directory)
    const record = {
      name: manifest.name ?? name,
      version: manifest.version ?? null,
      license: typeof manifest.license === 'string' ? manifest.license : manifest.license?.type ?? null,
      location,
      moduleType: manifest.type ?? null,
      exportsKeys: manifest.exports && typeof manifest.exports === 'object' && !Array.isArray(manifest.exports) ? Object.keys(manifest.exports).sort() : [],
      declaredTypePaths: declaredTypePaths(manifest),
      dependencies: dependencies(manifest.dependencies),
      peerDependencies: dependencies(manifest.peerDependencies),
      optionalDependencies: dependencies(manifest.optionalDependencies),
      ...await fileInventory(directory),
    }
    packages.set(directory, record)
    const edges = new Set([...names(manifest.dependencies), ...names(manifest.optionalDependencies), ...names(manifest.peerDependencies)])
    for (const child of [...edges].sort()) {
      if (!await collect(child, directory)) missingDependencies.push({ parent: `${record.name}@${record.version}`, location, name: child, optional: !!manifest.optionalDependencies?.[child] || manifest.peerDependenciesMeta?.[child]?.optional === true })
    }
    return record
  }

  const rootStatus = []
  for (const name of roots) {
    const record = await collect(name, config.lab, true)
    rootStatus.push({ name, status: record ? 'installed' : 'missing', version: record?.version ?? null })
  }
  const result = {
    schema: 'tiptap-package-inventory/v1',
    scope: 'Installed lab packages and their dependency/optional/peer graph; no package execution or network requests',
    fileMetrics: 'Regular package files excluding nested node_modules and .git; symlinks skipped. Source maps counted in sizes only, never emitted.',
    requestedVendorPackages: rootStatus.filter(item => requested.includes(item.name)),
    roots: rootStatus,
    packages: [...packages.values()].sort((a, b) => a.name.localeCompare(b.name) || String(a.version).localeCompare(String(b.version)) || a.location.localeCompare(b.location)),
    missingDependencies,
  }
  await mkdir(path.dirname(config.output), { recursive: true })
  await writeFile(config.output, `${JSON.stringify(result, null, 2)}\n`)
  process.stdout.write(`Package inventory: ${result.packages.length} installed packages; ${result.requestedVendorPackages.filter(item => item.status === 'missing').length} requested vendor packages missing.\n`)
}

main().catch(error => {
  process.stderr.write(`Package inventory failed: ${error.message}\n`)
  process.exitCode = 1
})
