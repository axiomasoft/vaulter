#!/usr/bin/env node
// npm declaration surface (D14, PLAN4 P6.2/U2; knowledge examples-p6-p8#bc-npm).
// Order: public declaration-only emit -> public API report -> private emit resolved against the PUBLIC declaration output
// (no ../vue/src alias) -> private report -> consumer type fixtures -> built-in negative probes. Everything is emitted to a
// temp dir: no dist, no Vite, no JS bundle. Types only: runtime ESM/CJS, tgz, peer ranges and Vue behavior are P8.2.
// Usage: node npm-surface.mjs <package-dir-name> [--update]   (--update only rewrites etc/*.api.md; the check never writes)
import { spawnSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, relative, resolve } from 'node:path'

const root = resolve(dirname(new URL(import.meta.url).pathname), '../..')
const require = createRequire(join(root, 'package.json'))
const { Extractor, ExtractorConfig } = require('@microsoft/api-extractor')
const [name, ...flags] = process.argv.slice(2)
const update = flags.includes('--update')
const pkgDir = (n) => join(root, 'packages', n)
const readJson = (p) => JSON.parse(readFileSync(p, 'utf8'))
const fail = (m) => { console.error(`npm-surface: ${m}`); process.exit(1) }
if (!name || !existsSync(join(pkgDir(name), 'package.json'))) fail('usage: npm-surface.mjs <package-dir-name> [--update]')

const tmp = mkdtempSync(join(tmpdir(), 'vaulter-npm-surface-'))
const run = (bin, args, cwd) => spawnSync(process.execPath, [join(root, 'node_modules', bin), ...args], { cwd, encoding: 'utf8' })

function walk(dir, pick, out = []) {
  for (const e of readdirSync(dir)) {
    const p = join(dir, e)
    if (statSync(p).isDirectory()) walk(p, pick, out)
    else if (pick(p)) out.push(p)
  }
  return out
}

// siblings this package imports by name (peer/dev deps) are consumed as their PUBLIC declaration output
function siblings(pkg) {
  const j = readJson(join(pkgDir(pkg), 'package.json'))
  const names = Object.keys({ ...j.peerDependencies, ...j.devDependencies, ...j.dependencies }).filter((d) => d.startsWith('@vaulter/'))
  return names.filter((d) => existsSync(join(pkgDir(d.slice(9)), 'package.json'))).map((d) => d.slice(9))
}

const emitted = new Map() // package dir name -> declaration output dir
function emit(pkg, stage = pkg) {
  if (emitted.has(stage)) return emitted.get(stage)
  const paths = {}
  for (const s of siblings(pkg)) paths[`@vaulter/${s}`] = [join(emit(s), 'index.d.ts')]
  const out = join(tmp, 'decl', stage)
  const cfg = join(tmp, `tsconfig.${stage}.json`)
  writeFileSync(cfg, JSON.stringify({
    extends: join(pkgDir(pkg), 'tsconfig.api.json'),
    compilerOptions: { noEmit: false, declaration: true, emitDeclarationOnly: true, outDir: out, rootDir: join(pkgDir(pkg), 'src'), baseUrl: root, paths },
    include: [join(pkgDir(pkg), 'src/**/*.ts'), join(pkgDir(pkg), 'src/**/*.vue')],
    exclude: [join(pkgDir(pkg), 'tests'), join(pkgDir(pkg), 'dist'), join(pkgDir(pkg), 'node_modules')],
  }))
  const r = run('vue-tsc/bin/vue-tsc.js', ['-p', cfg], root)
  if (r.status !== 0) fail(`declaration emit of ${pkg} failed:\n${r.stdout}${r.stderr}`)
  const src = join(pkgDir(pkg), 'src')
  for (const d of walk(src, (p) => p.endsWith('.d.ts'))) { // tsc does not copy hand-written .d.ts
    const to = join(out, relative(src, d)); mkdirSync(dirname(to), { recursive: true }); cpSync(d, to)
  }
  emitted.set(stage, out)
  return out
}

// every exported entrypoint with a `types` condition; "." -> index
function entrypoints(pkg) {
  const j = readJson(join(pkgDir(pkg), 'package.json'))
  const eps = []
  for (const [key, v] of Object.entries(j.exports ?? {})) {
    if (!v || typeof v !== 'object' || !v.types) continue
    eps.push({ key, slug: key === '.' ? 'index' : key.replace(/^\.\//, '').replace(/[^A-Za-z0-9]+/g, '-'), types: v.types })
  }
  if (!eps.length) fail(`${pkg}: no exported entrypoint with types`)
  return eps
}

function extract(pkg, declDir, ep, { localBuild, reportFolder = join(pkgDir(pkg), 'etc') }) {
  const entry = join(declDir, ep.types.replace(/^\.\/dist\//, ''))
  if (!existsSync(entry)) fail(`${pkg}: entrypoint ${ep.key} declaration ${entry} was not emitted`)
  const cfgPath = join(pkgDir(pkg), 'api-extractor.json')
  const obj = ExtractorConfig.loadFile(cfgPath)
  const paths = {}
  for (const s of siblings(pkg)) paths[`@vaulter/${s}`] = [join(emitted.get(s) ?? emit(s), 'index.d.ts')]
  obj.mainEntryPointFilePath = entry
  obj.apiReport = { ...obj.apiReport, enabled: true, reportFileName: `${ep.slug}.api.md`, reportFolder, reportTempFolder: join(tmp, 'report-temp', ep.slug) }
  obj.compiler = { overrideTsconfig: { compilerOptions: { target: 'ES2022', module: 'ESNext', moduleResolution: 'Bundler', lib: ['ES2022', 'DOM', 'DOM.Iterable'], strict: true, skipLibCheck: true, baseUrl: root, paths }, files: [entry] } }
  const config = ExtractorConfig.prepare({ configObject: obj, configObjectFullPath: cfgPath, packageJsonFullPath: join(pkgDir(pkg), 'package.json') })
  return Extractor.invoke(config, { localBuild, showVerboseMessages: false, messageCallback: (m) => { m.handled = true } })
}

// declarations live in a temp dir, so third-party types (vue) resolve through the repo node_modules
const withModules = (paths) => ({ ...paths, '*': [join(root, 'node_modules/*')] })

// Slot components are rolled up by API Extractor as __VLS_WithSlots<...>: props and emits are NOT in the .api.md. This
// snapshot lists every exported constructor's own $props (name, optionality, type) and $emit signatures via the checker.
function componentSurface(pkg, declDir) {
  const ts = require('typescript')
  const paths = {}
  for (const s of siblings(pkg)) paths[`@vaulter/${s}`] = [join(emitted.get(s) ?? emit(s), 'index.d.ts')]
  const entry = join(declDir, 'index.d.ts')
  const program = ts.createProgram([entry], { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext, moduleResolution: ts.ModuleResolutionKind.Bundler, strict: true, skipLibCheck: true, noEmit: true, types: [], baseUrl: root, paths: withModules(paths) })
  const checker = program.getTypeChecker()
  const sf = program.getSourceFile(entry)
  const flags = ts.TypeFormatFlags.NoTruncation | ts.TypeFormatFlags.UseAliasDefinedOutsideCurrentScope
  const own = (sym) => (sym.declarations ?? []).some((d) => !d.getSourceFile().fileName.includes('/node_modules/'))
  const lines = []
  for (let sym of checker.getExportsOfModule(checker.getSymbolAtLocation(sf))) {
    const exp = sym.name
    if (sym.flags & ts.SymbolFlags.Alias) sym = checker.getAliasedSymbol(sym)
    const type = checker.getTypeOfSymbolAtLocation(sym, sf)
    const ctor = type.getConstructSignatures()[0]
    if (!ctor) continue
    const inst = ctor.getReturnType()
    const props = inst.getProperty('$props')
    const emit = inst.getProperty('$emit')
    if (!props || !emit) continue
    for (const pr of checker.getPropertiesOfType(checker.getTypeOfSymbolAtLocation(props, sf))) {
      if (!own(pr)) continue
      lines.push(`${exp} prop ${pr.name}${pr.flags & ts.SymbolFlags.Optional ? '?' : ''}: ${checker.typeToString(checker.getTypeOfSymbolAtLocation(pr, sf), undefined, flags)}`)
    }
    for (const sig of checker.getTypeOfSymbolAtLocation(emit, sf).getCallSignatures()) lines.push(`${exp} emit ${checker.signatureToString(sig, undefined, flags)}`)
  }
  return `${lines.sort().join('\n')}\n`
}

function consumerCompile(pkg, declDir, files) {
  const paths = { [readJson(join(pkgDir(pkg), 'package.json')).name]: [join(declDir, 'index.d.ts')] }
  for (const s of siblings(pkg)) paths[`@vaulter/${s}`] = [join(emitted.get(s) ?? emit(s), 'index.d.ts')]
  const cfg = join(tmp, `tsconfig.consumer.${pkg}.json`)
  writeFileSync(cfg, JSON.stringify({
    compilerOptions: { target: 'ES2022', module: 'ESNext', moduleResolution: 'Bundler', lib: ['ES2022', 'DOM', 'DOM.Iterable'], strict: true, noEmit: true, skipLibCheck: true, types: [], baseUrl: root, paths: withModules(paths) },
    files,
  }))
  return run('typescript/bin/tsc', ['-p', cfg], root)
}

const eps = entrypoints(name)
const pub = emit(name)
const fixtures = existsSync(join(pkgDir(name), 'tests/api-surface/consumer')) ? walk(join(pkgDir(name), 'tests/api-surface/consumer'), (p) => p.endsWith('.ts')) : []
if (!fixtures.length) fail(`${name}: no consumer type fixtures`)
const problems = []

for (const ep of eps) {
  const r = extract(name, pub, ep, { localBuild: update })
  if (!r.succeeded || (!update && r.apiReportChanged)) problems.push(`${name} ${ep.key}: API report differs from etc/${ep.slug}.api.md or extractor errors (${r.errorCount} errors)`)
}
const surfaceFile = join(pkgDir(name), 'etc/components.surface.txt')
const surface = componentSurface(name, pub)
if (update) writeFileSync(surfaceFile, surface)
else if (!existsSync(surfaceFile) || readFileSync(surfaceFile, 'utf8') !== surface) problems.push(`${name}: component props/emits surface differs from etc/components.surface.txt`)
const c = consumerCompile(name, pub, fixtures)
if (c.status !== 0) problems.push(`${name}: consumer type fixtures failed:\n${c.stdout}${c.stderr}`)

// negative probes (scenario 8): each mutation of the emitted declarations must be detected by the report (API .api.md for
// removed exports, component surface for props/emits) AND break the consumer fixtures
const probes = readJson(join(pkgDir(name), 'tests/api-surface/probes.json')).mutations
for (const pr of probes) {
  const mut = join(tmp, 'mut', pr.id)
  cpSync(pub, mut, { recursive: true })
  const f = join(mut, pr.file)
  const before = readFileSync(f, 'utf8')
  const after = before.split(pr.from).join(pr.to)
  if (after === before) { problems.push(`probe ${pr.id}: '${pr.from}' not found in ${pr.file}`); continue }
  writeFileSync(f, after)
  const rr = extract(name, mut, eps[0], { localBuild: false })
  const apiDiff = !(rr.succeeded && !rr.apiReportChanged)
  const surfaceDiff = componentSurface(name, mut) !== surface
  if (pr.report === 'api' ? !apiDiff : !surfaceDiff) problems.push(`probe ${pr.id}: ${pr.report} report did NOT detect the change`)
  if (consumerCompile(name, mut, fixtures).status === 0) problems.push(`probe ${pr.id}: consumer fixtures did NOT fail`)
}
rmSync(tmp, { recursive: true, force: true })
if (problems.length) fail(problems.join('\n'))
console.log(`npm-surface ${name}: ${update ? 'reports updated' : 'ok'} (${eps.length} entrypoint(s), ${fixtures.length} fixture(s), ${probes.length} negative probe(s))`)
