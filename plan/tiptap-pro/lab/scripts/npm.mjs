// Run npm in this isolated lab with only its registry credential from root .env.
import { parseEnv as parse } from 'node:util'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { spawn } from 'node:child_process'
let values = {}
try { values = parse(readFileSync(new URL('../../../../.env', import.meta.url), 'utf8')) } catch (error) { if (error.code !== 'ENOENT') throw error }
const env = { ...process.env }
if (values.TIPTAP_PRO_TOKEN) env.TIPTAP_PRO_TOKEN = values.TIPTAP_PRO_TOKEN
for (const key of ['TIPTAP_PRIVATE_KEY', 'TIPTAP_LEGACY_DOCUMENT_SECRET', 'TIPTAP_LEGACY_API_SECRET']) delete env[key]
const args = process.argv.slice(2)
if (!args.length) throw new Error('Pass an npm command, for example: node scripts/npm.mjs ci')
const child = spawn('npm', args, { cwd: fileURLToPath(new URL('../', import.meta.url)), env, stdio: 'inherit' })
child.once('error', () => { process.exitCode = 1 })
child.once('exit', code => { process.exitCode = code ?? 1 })
