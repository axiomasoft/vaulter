// @ts-check
// verify(current) for a live (journal) document: did a later merge touch what the agent wrote?
//
// CRDT merge converges but does not preserve meaning: an offline "15 → 20" and the agent's "15 → 30"
// both delete the same characters and both insertions survive ("2030"). Checking the whole block
// (agent-sdk.md, text_and_marks) catches that, but also fails on any unrelated typo fix elsewhere in
// the block. Yjs relative positions follow the characters themselves, so the owner stores, with the
// receipt, two anchors around the replaced span widened by one neighbour character on each side.
// Later the anchors are resolved in the current state: the window must still read exactly as at commit.
// Any insertion inside or directly adjacent to the span, or deletion of the span or its neighbours,
// changes the window; edits elsewhere in the block do not.
//
// Target in the product: evidence of v_collaboration_commands (computed by the room owner at commit)
// and the `effect_anchor` check of office.verify(mode=current). Snapshot documents have no item IDs and
// keep the whole-block rule.

import { FIELD, Y } from './room-sequencer.mjs'

/** @param {Y.Doc} doc @param {string} blockId @returns {Y.XmlText | null} */
function blockText(doc, blockId) {
  const element = doc.getXmlFragment(FIELD).toArray()
    .find((node) => node instanceof Y.XmlElement && node.getAttribute('blockId') === blockId)
  if (!(element instanceof Y.XmlElement)) return null
  const text = element.toArray().find((node) => node instanceof Y.XmlText)

  return text instanceof Y.XmlText ? text : null
}

/** Plain text in UTF-16 units; embeds count as one U+FFFC like the SDK offsets. @param {Y.XmlText} text */
function plain(text) {
  return text.toDelta().map((/** @type {any} */ op) => (typeof op.insert === 'string' ? op.insert : '\uFFFC')).join('')
}

const encode = (/** @type {Y.RelativePosition} */ rp) => Buffer.from(Y.encodeRelativePosition(rp)).toString('base64')
const decode = (/** @type {string} */ b64) => Y.decodeRelativePosition(new Uint8Array(Buffer.from(b64, 'base64')))

/**
 * Evidence for the receipt. `baseDoc` is the copy the command was prepared on, `committedDoc` the state
 * at the receipt marker (both built from the binary log, so item IDs are the stored ones).
 * @param {{ baseDoc: Y.Doc, committedDoc: Y.Doc, commandId: string, effects: any[], insertedBlockId: (commandId: string, index: number) => string, widen?: number }} input
 *   `widen` = neighbour characters on each side (1; 0 only for the mutation check: anchors on the span alone)
 */
export function anchorEffects({ baseDoc, committedDoc, commandId, effects, insertedBlockId, widen = 1 }) {
  return effects.map((effect, index) => {
    if (effect.effect === 'replace_exact') {
      const before = blockText(baseDoc, effect.block_id)
      const after = blockText(committedDoc, effect.block_id)
      if (!before || !after) throw new Error(`block ${effect.block_id} not found`)
      const from = plain(before).indexOf(effect.expected)
      const committed = plain(after)
      const start = Math.max(from - widen, 0)
      const end = Math.min(from + effect.replacement.length + widen, committed.length)

      return {
        kind: 'anchor', block_id: effect.block_id, window: committed.slice(start, end),
        start: encode(Y.createRelativePositionFromTypeIndex(after, start, 0)), // sticks to the left neighbour
        end: encode(Y.createRelativePositionFromTypeIndex(after, end, -1)), // sticks to the right neighbour
      }
    }
    const blockId = insertedBlockId(commandId, index)
    const inserted = blockText(committedDoc, blockId)

    return { kind: 'block', block_id: blockId, text: inserted ? plain(inserted) : null }
  })
}

/**
 * office.verify(mode=current) checks for the stored evidence against the current state.
 * @param {Y.Doc} doc @param {ReturnType<typeof anchorEffects>} evidence
 * @returns {{ name: string, block_id: string, passed: boolean, expected: string | null, actual: string | null }[]}
 */
export function checkEffects(doc, evidence) {
  return evidence.map((item) => {
    if (item.kind === 'anchor') {
      const start = Y.createAbsolutePositionFromRelativePosition(decode(/** @type {string} */ (item.start)), doc)
      const end = Y.createAbsolutePositionFromRelativePosition(decode(/** @type {string} */ (item.end)), doc)
      const actual = start && end && start.type === end.type && start.type instanceof Y.XmlText
        ? plain(start.type).slice(start.index, end.index)
        : null

      return { name: 'effect_anchor', block_id: item.block_id, passed: actual === item.window, expected: /** @type {string} */ (item.window), actual }
    }
    const current = blockText(doc, item.block_id)
    const actual = current ? plain(current) : null

    return { name: 'inserted_block', block_id: item.block_id, passed: actual !== null && actual === item.text, expected: item.text ?? null, actual }
  })
}

/** The rule agent-sdk.md has today for text_and_marks(current): the whole block equals the committed one. */
export function wholeBlockEqual(committedDoc, doc, blockId) {
  const a = blockText(committedDoc, blockId)
  const b = blockText(doc, blockId)

  return a !== null && b !== null && plain(a) === plain(b)
}

export { blockText, plain }
