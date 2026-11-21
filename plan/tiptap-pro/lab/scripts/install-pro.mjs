import { spawn } from 'node:child_process'
import { readFile, mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import process from 'node:process'
import { parse } from 'dotenv'

const labRoot = fileURLToPath(new URL('../', import.meta.url))
const envPath = fileURLToPath(new URL('../../../../.env', import.meta.url))
const packages = [
  { name: '@tiptap-pro/provider', feature: 'Cloud collaboration transport', source: 'https://tiptap.dev/docs/collaboration/provider/integration' },
  { name: '@tiptap-pro/extension-comments', feature: 'Comments', source: 'https://tiptap.dev/docs/comments/getting-started/install' },
  { name: '@tiptap-pro/extension-snapshot', feature: 'Snapshots / versions', source: 'https://tiptap.dev/docs/collaboration/documents/snapshot' },
  { name: '@tiptap-pro/extension-snapshot-compare', feature: 'Snapshot compare', source: 'https://tiptap.dev/docs/collaboration/documents/snapshot-compare' },
  { name: '@tiptap-pro/extension-tracked-changes', feature: 'Tracked changes', source: 'https://tiptap.dev/docs/tracked-changes/getting-started/install' },
  { name: '@tiptap-pro/compare', feature: 'Document compare (separate add-on)', source: 'https://tiptap.dev/docs/compare/getting-started/overview' },
  { name: '@tiptap-pro/extension-convert-kit', feature: 'Conversion editor schema', source: 'https://tiptap.dev/docs/conversion/getting-started/guides/end-to-end-walkthrough' },
  { name: '@tiptap-pro/extension-export-docx', feature: 'DOCX export', source: 'https://tiptap.dev/docs/conversion/getting-started/guides/end-to-end-walkthrough' },
  { name: '@tiptap-pro/extension-import-docx', feature: 'DOCX import', source: 'https://tiptap.dev/docs/conversion/getting-started/guides/end-to-end-walkthrough' },
  { name: '@tiptap-pro/extension-pages', feature: 'Paginated page layout', source: 'https://tiptap.dev/docs/pages/getting-started/install' },
  { name: '@tiptap-pro/extension-pages-tablekit', feature: 'Pagination-safe tables', source: 'https://tiptap.dev/docs/pages/getting-started/install' },
  { name: '@tiptap-pro/extension-pages-pagekit', feature: 'Pages composition kit', source: 'https://tiptap.dev/docs/pages/guides/pagekit-usage' },
  { name: '@tiptap-pro/extension-paste-handler', feature: 'Paste interception and transforms', source: 'https://tiptap.dev/docs/editor/extensions/functionality/paste-handler' },
  { name: '@tiptap-pro/extension-content-protection', feature: 'Editor content protection', source: 'https://tiptap.dev/docs/composable-docs/content-protection/getting-started/overview' },
  { name: '@tiptap-pro/extension-slot', feature: 'Composable content slots', source: 'https://tiptap.dev/docs/composable-docs/slots/getting-started/overview' },
  { name: '@tiptap-pro/extension-variable', feature: 'Document variables', source: 'https://tiptap.dev/docs/composable-docs/variables/getting-started/overview' },
  { name: '@tiptap-pro/extension-diagrams', feature: 'Diagram nodes', source: 'https://tiptap.dev/docs/diagrams/getting-started/install' },
  { name: '@tiptap-pro/extension-whiteboard', feature: 'Whiteboard integration', source: 'https://tiptap.dev/docs/editor/whiteboard/getting-started/overview' },
  { name: '@tiptap-pro/extension-export-doc', feature: 'DOC export wrapper', source: 'https://tiptap.dev/docs/conversion/export/doc/editor-extension' },
  { name: '@tiptap-pro/extension-export-pdf', feature: 'PDF export wrapper', source: 'https://tiptap.dev/docs/conversion/getting-started/install' },
  { name: '@tiptap-pro/extension-export-odt', feature: 'ODT export wrapper', source: 'https://tiptap.dev/docs/conversion/getting-started/install' },
  { name: '@tiptap-pro/extension-export-epub', feature: 'EPUB export wrapper', source: 'https://tiptap.dev/docs/conversion/getting-started/install' },
  { name: '@tiptap-pro/extension-export-markdown', feature: 'Markdown export wrapper', source: 'https://tiptap.dev/docs/conversion/getting-started/install' },
  { name: '@tiptap-pro/client-ai-toolkit', feature: 'AI Toolkit client (separate add-on)', source: 'https://tiptap.dev/docs/ai/ai-toolkit/client/install' },
]

async function installedVersion(name) {
  try {
    const manifest = JSON.parse(await readFile(path.join(labRoot, 'node_modules', name, 'package.json'), 'utf8'))
    return manifest.version
  } catch { return null }
}

async function npmCommand(args, token) {
  return new Promise(resolve => {
    // dotenv.parse above does not populate process.env with the root signing key.
    // The npm token travels only in the process environment, never in argv or files.
    const child = spawn('npm', args, {
      cwd: labRoot,
      env: { ...process.env, TIPTAP_PRO_TOKEN: token },
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    let output = ''
    const collect = chunk => { output = (output + chunk.toString()).slice(-128000) }
    child.stdout.on('data', collect)
    child.stderr.on('data', collect)
    let timedOut = false
    const timer = setTimeout(() => { timedOut = true; child.kill('SIGTERM') }, 120000)
    child.once('error', () => { clearTimeout(timer); resolve({ ok: false, code: 'NPM_PROCESS_ERROR' }) })
    child.once('close', code => {
      clearTimeout(timer)
      // Never echo npm output: registry errors and debug URLs may carry auth data.
      const knownCode = output.match(/npm (?:ERR!|error) code ([A-Z][A-Z0-9_]{1,79})/)?.[1] || output.match(/\b(E401|E403|E404|EACCESS|EACCES|ERESOLVE|ETIMEDOUT|ENOTFOUND|ECONNREFUSED|ECONNRESET|EINTEGRITY)\b/)?.[1]
      resolve({ ok: code === 0, code: timedOut ? 'TIMEOUT' : code === 0 ? 'OK' : knownCode || 'NPM_INSTALL_FAILED' })
    })
  })
}

const flags = new Set(process.argv.slice(2))
if ([...flags].some(flag => !['--list', '--check'].includes(flag) && !flag.startsWith('--only='))) {
  console.error('Usage: node scripts/install-pro.mjs [--list | --check] [--only=@tiptap-pro/package,...]')
  process.exit(2)
}
if (flags.has('--list')) {
  console.log(JSON.stringify({ verifiedAt: '2026-11-21', packages }, null, 2))
  process.exit(0)
}

let rootEnv = {}
try { rootEnv = parse(await readFile(envPath)) } catch (error) {
  if (error.code !== 'ENOENT') { console.error('Unable to read the root .env; no values displayed.'); process.exit(1) }
}
const token = process.env.TIPTAP_PRO_TOKEN || rootEnv.TIPTAP_PRO_TOKEN || ''
const installed = await Promise.all(packages.map(async item => ({ name: item.name, version: await installedVersion(item.name) })))
if (flags.has('--check')) {
  console.log(JSON.stringify({ tokenPresent: Boolean(token), installed }, null, 2))
  process.exit(0)
}
if (!token) {
  console.error('TIPTAP_PRO_TOKEN is absent. Add it to the ignored repository root .env. No registry requests were sent.')
  process.exit(1)
}

const requested = [...flags].find(flag => flag.startsWith('--only='))?.slice(7).split(',')
if (requested?.some(name => !packages.some(item => item.name === name))) {
  console.error('--only contains an unrecognized package; use --list.')
  process.exit(2)
}
const selected = requested ? packages.filter(item => requested.includes(item.name)) : packages
const results = []
for (const item of selected) {
  console.log(`Installing ${item.name}…`)
  const installArgs = ['install', '--save-exact', '--no-audit', '--no-fund', '--fetch-retries=1', '--fetch-retry-mintimeout=1000', '--fetch-retry-maxtimeout=5000', '--fetch-timeout=30000', item.name]
  let result = await npmCommand(installArgs, token)
  if (!result.ok && /^(ERR_SSL_|ETIMEDOUT|ECONNRESET)/.test(result.code)) {
    console.log(`Retrying ${item.name} after a transport failure (${result.code})…`)
    result = await npmCommand(installArgs, token)
  }
  const entry = { name: item.name, status: result.ok ? 'installed' : 'unavailable', code: result.code, version: await installedVersion(item.name) }
  results.push(entry)
  console.log(JSON.stringify(entry))
}
const shrinkwrap = results.some(result => result.status === 'installed')
  ? await npmCommand(['shrinkwrap', '--no-audit', '--no-fund'], token)
  : { ok: true, code: 'NO_SUCCESSFUL_INSTALLS' }
let previous = []
try { previous = JSON.parse(await readFile(path.join(labRoot, 'artifacts', 'pro-install-report.json'), 'utf8')).results || [] } catch { /* First installation. */ }
const combined = [...previous.filter(old => !results.some(item => item.name === old.name)), ...results]
const report = { at: new Date().toISOString(), results: combined, shrinkwrap, note: 'Registry access does not prove runtime feature entitlement. No registry response bodies or tokens retained.' }
await mkdir(path.join(labRoot, 'artifacts'), { recursive: true })
await writeFile(path.join(labRoot, 'artifacts', 'pro-install-report.json'), JSON.stringify(report, null, 2) + '\n', { mode: 0o600 })
console.log('Sanitized package availability report saved to ignored artifacts/pro-install-report.json. Restart Vite after installing the provider.')
if (results.some(result => result.status !== 'installed') || !shrinkwrap.ok) process.exitCode = 1
