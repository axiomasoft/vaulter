// @ts-check
// BOUNDARY: OwnerPort implemented with the SQL functions of plan/checks/collaboration/live-write-owner.sql.
// The product owner is PHP (CommandJournal / RoomOwnership / UpdateLog, plan/spec/implementation/vaulter.md 3.5);
// this adapter keeps the same tables, lock order and fencing so the Node side can be tested against a real
// PostgreSQL 18 commit. Every call is a separate `psql` process: a separate connection and transaction.
// Values reach SQL as psql variables (:'name'), which psql quotes itself.

import { spawnSync } from 'node:child_process'

/**
 * @param {string} sql statement using :'p0', :'p1', ... placeholders
 * @param {Array<string | number | null>} params
 * @returns {any}
 */
export function psqlJson(sql, params = []) {
  const psql = process.env.PSQL ?? 'psql'
  const args = ['-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1']
  params.forEach((value, index) => {
    args.push('-v', `p${index}=${value === null ? '' : String(value)}`)
  })
  const result = spawnSync(psql, args, { input: `${sql}\n`, encoding: 'utf8' })

  if (result.status !== 0) {
    throw new Error(`psql failed (${result.status}): ${result.stderr.trim()}`)
  }
  const text = result.stdout.trim()

  return text === '' ? null : JSON.parse(text)
}

/** A NULL-able bigint parameter: an empty psql variable becomes NULL. */
const nullableBigint = (name) => `NULLIF(:'${name}', '')::bigint`

/**
 * @param {string} documentId
 * @returns {import('./room-sequencer.mjs').OwnerPort & {
 *   acquire(ownerId: string, ttlMs: number): { fence: number, generation: number, head_seq: number } | { error: string, retry_after_ms?: number },
 *   release(ownerId: string, fence: number): boolean,
 *   registerIntent(input: { project: string, subject: string, commandId: string, fingerprint: string, intent: object, readPosition: number }): any,
 *   status(input: { project: string, subject: string, commandId: string }): any,
 *   outbox(): Array<{ event_id: string, payload: import('./room-sequencer.mjs').CommittedEvent }>,
 *   counts(): Record<string, number>,
 * }}
 */
export function createPsqlOwner(documentId) {
  const doc = documentId

  return {
    acquire: (ownerId, ttlMs) => psqlJson(`SELECT live_write_owner.acquire(:'p0'::uuid, :'p1'::uuid, :'p2'::integer)`, [doc, ownerId, ttlMs]),
    release: (ownerId, fence) => psqlJson(`SELECT to_jsonb(live_write_owner.release(:'p0'::uuid, :'p1'::uuid, :'p2'::bigint))`, [doc, ownerId, fence]),
    head: () => psqlJson(`SELECT live_write_owner.head(:'p0'::uuid)`, [doc]),
    load: (afterSeq = null, throughSeq = null) => psqlJson(
      `SELECT live_write_owner.load(:'p0'::uuid, ${nullableBigint('p1')}, ${nullableBigint('p2')})`,
      [doc, afterSeq, throughSeq],
    ),
    appendUpdate: ({ ownerId, fence, expectedHead, update }) => psqlJson(
      `SELECT live_write_owner.append_update(:'p0'::uuid, :'p1'::uuid, :'p2'::bigint, :'p3'::bigint, :'p4', decode(:'p5', 'base64'), :'p6')`,
      [doc, ownerId, fence, expectedHead, update.update_id, update.payload, update.sha256],
    ),
    commandById: (id) => psqlJson(`SELECT live_write_owner.command_json(c) FROM v_collaboration_commands c WHERE c.id = :'p0'::uuid`, [id]),
    settleCommit: ({ id, ownerId, fence, baseSeq, updates, evidence }) => psqlJson(
      `SELECT live_write_owner.settle_commit(:'p0'::uuid, :'p1'::uuid, :'p2'::bigint, :'p3'::bigint, :'p4'::jsonb, :'p5'::jsonb)`,
      [id, ownerId, fence, baseSeq, JSON.stringify(updates), JSON.stringify(evidence)],
    ),
    settleReject: ({ id, ownerId, fence, code }) => psqlJson(
      `SELECT live_write_owner.settle_reject(:'p0'::uuid, :'p1'::uuid, :'p2'::bigint, :'p3')`,
      [id, ownerId, fence, code],
    ),
    registerIntent: ({ project, subject, commandId, fingerprint, intent, readPosition }) => psqlJson(
      `SELECT live_write_owner.register_intent(:'p0'::uuid, :'p1', :'p2', :'p3', decode(:'p4', 'hex'), :'p5'::jsonb, :'p6'::bigint)`,
      [doc, project, subject, commandId, fingerprint, JSON.stringify(intent), readPosition],
    ),
    status: ({ project, subject, commandId }) => psqlJson(
      `SELECT live_write_owner.status(:'p0'::uuid, :'p1', :'p2', :'p3')`,
      [doc, project, subject, commandId],
    ),
    outbox: () => psqlJson(`SELECT live_write_owner.outbox(:'p0'::uuid)`, [doc]),
    counts: () => psqlJson(`SELECT live_write_owner.counts(:'p0'::uuid)`, [doc]),
  }
}

/**
 * Create the document of a scenario: journal mode, generation `generation`, checkpoint at `headSeq`.
 * @param {{ documentId: string, nodeId: string, driveId: string, generation: number, headSeq: number, fence: number, checkpoint: Uint8Array }} input
 */
export function setupDocument({ documentId, nodeId, driveId, generation, headSeq, fence, checkpoint }) {
  psqlJson(
    `SELECT to_jsonb(live_write_owner.setup(:'p0'::uuid, :'p1'::uuid, :'p2'::uuid, :'p3'::bigint, :'p4'::bigint, :'p5'::bigint, decode(:'p6', 'base64'))::text)`,
    [documentId, nodeId, driveId, generation, headSeq, fence, Buffer.from(checkpoint).toString('base64')],
  )
}
