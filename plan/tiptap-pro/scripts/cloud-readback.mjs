import { createRequire } from 'node:module'
import { createHash } from 'node:crypto'
import { mkdir, writeFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { sanitize } from './decode-frame.mjs'
const require = createRequire(new URL('../lab/package.json', import.meta.url))
const { config } = require('dotenv')
const { SignJWT, importPKCS8 } = await import(require.resolve('jose'))
const Y = require('yjs')
const { yDocToProsemirrorJSON } = require('@tiptap/y-tiptap')
config({ path: fileURLToPath(new URL('../../../.env', import.meta.url)), quiet: true })

const args = Object.fromEntries(process.argv.slice(2).map(arg => arg.replace(/^--/, '').split('=')))
const room = args.room
if (!/^tiptap-pro-lab-[A-Za-z0-9_.-]+$/.test(room || '')) throw new Error('Explicit synthetic lab room required')
if (!['agent', 'comments-versions', 'tracked-changes'].includes(args.kind)) throw new Error('Use --kind=agent, --kind=comments-versions, or --kind=tracked-changes')
const evidence = { schema: 'tiptap-pro-independent-readback/v1', room, kind: args.kind, startedAt: new Date().toISOString(), checks: {}, requests: [], limitation: 'Fresh server retrieval after browser closure; no server crash or SQL transaction boundary is observed.' }
const check = (name, value) => { evidence.checks[name] = Boolean(value); if (!value) throw new Error(`Readback failed: ${name}`) }
try {
  const origin = new URL(process.env.TIPTAP_API_URL || `https://${process.env.TIPTAP_APP_ID}.collab.tiptap.cloud`)
  if (origin.protocol !== 'https:' || !origin.hostname.endsWith('.collab.tiptap.cloud') || origin.username || origin.password) throw new Error('Expected Cloud HTTPS origin')
  const token = await new SignJWT({ permissions: [{ action: 'Documents:Api:All', resource: '*' }] })
    .setProtectedHeader({ alg: 'ES256' }).setIssuer(process.env.TIPTAP_ENVIRONMENT_ID)
    .setAudience(['Documents']).setIssuedAt().setExpirationTime('2m')
    .sign(await importPKCS8(process.env.TIPTAP_PRIVATE_KEY.replace(/\\n/g, '\n'), 'ES256'))
  const response = await fetch(`${origin.origin}/api/documents/${encodeURIComponent(room)}?format=yjs`, {
    headers: { authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(15_000), redirect: 'error',
  })
  const bytes = Buffer.from(await response.arrayBuffer())
  evidence.requests.push({ method: 'GET', format: 'yjs', status: response.status, bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') })
  if (!response.ok || bytes.length > 8 * 1024 * 1024) throw new Error(`Readback HTTP ${response.status}`)
  const doc = new Y.Doc()
  Y.applyUpdate(doc, bytes)
  evidence.sharedTypes = [...doc.share.keys()]
  const json = yDocToProsemirrorJSON(doc, 'default')
  evidence.document = json
  const p42 = json.content.find(node => node.attrs?.blockId === 'p42')
  const p71 = json.content.find(node => node.attrs?.blockId === 'p71')
  const text = node => (node?.content || []).map(part => part.text || '').join('')
  if (args.kind !== 'tracked-changes') check('stable-block-identities', Boolean(p42 && p71))
  if (args.kind === 'agent') {
    check('server-agent-payment-30', text(p42) === 'Срок оплаты: 30 рабочих дней.')
    check('bold-preserved', p42.content.some(part => part.text === '30' && part.marks?.some(mark => mark.type === 'bold')))
    check('neighbour-bank-b-preserved', text(p71) === 'Реквизиты: Банк Б.')
  } else if (args.kind === 'comments-versions') {
    const threads = doc.getArray('__tiptapcollab__threads').toJSON()
    evidence.threads = threads.map(thread => ({ id: thread.id, resolvedAt: thread.resolvedAt, comments: (thread.comments || []).map(comment => ({ id: comment.id, content: comment.content, deletedAt: comment.deletedAt })) }))
    const selected = threads.find(thread => thread.id === args.thread)
    check('restored-payment-15', text(p42).includes('15 working days.'))
    check('bold-preserved', p42.content.some(part => part.text === '15' && part.marks?.some(mark => mark.type === 'bold')))
    check('comment-and-reply-preserved', selected?.comments?.length === 2)
    check('resolved-discussion-survives-text-restore', Boolean(selected?.resolvedAt))
  } else {
    check('accepted-replacement-persisted', json.content.map(text).join('\n') === 'Payment in 30 days.')
    check('review-mark-cleared', !JSON.stringify(json).includes('"type":"suggestion"'))
  }
  doc.destroy()
  evidence.status = 'passed'
} catch (error) {
  evidence.status = 'failed'
  evidence.error = sanitize({ name: error.name, message: error.message, causeCode: error.cause?.code })
  process.exitCode = 1
} finally {
  const directory = new URL('../results/', import.meta.url)
  await mkdir(directory, { recursive: true })
  const result = new URL(`readback-${args.kind}-${evidence.startedAt.replace(/[:.]/g, '-')}.json`, directory)
  await writeFile(result, JSON.stringify(sanitize(evidence), null, 2) + '\n')
  console.log(JSON.stringify({ status: evidence.status, checks: evidence.checks, evidence: fileURLToPath(result) }))
}
