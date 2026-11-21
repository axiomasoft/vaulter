import { fileURLToPath } from 'node:url'
import { config } from 'dotenv'
import { importPKCS8 } from 'jose'

config({ path: fileURLToPath(new URL('../../../../.env', import.meta.url)), quiet: true })
const names = ['TIPTAP_ENVIRONMENT_ID', 'TIPTAP_APP_ID', 'TIPTAP_WS_URL', 'TIPTAP_PRIVATE_KEY', 'TIPTAP_PRO_TOKEN']
const presence = Object.fromEntries(names.map(name => [name, Boolean(process.env[name])]))
let privateKeyValid = false
if (presence.TIPTAP_PRIVATE_KEY) {
  try {
    await importPKCS8(process.env.TIPTAP_PRIVATE_KEY.replace(/\\n/g, '\n'), 'ES256')
    privateKeyValid = true
  } catch { /* Report presence/validity only, never key or parser details. */ }
}
console.log(JSON.stringify({ presence, privateKeyValid }, null, 2))
if (presence.TIPTAP_PRIVATE_KEY && !privateKeyValid) process.exitCode = 1
