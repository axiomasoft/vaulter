// Portable reference of text.replace_exact@1 and the text_and_marks verification profile
// on a ProseMirror document (prosemirror-model 1.25.12, prosemirror-transform 1.12.2 —
// the versions of the root package-lock.json of Vaulter). The PHP mirror is
// replace_exact.php; both implement the rules of plan/spec/agent-sdk.md «Команды содержимого».
//
// Text model of a target block: text nodes are concatenated; every non-text inline leaf
// (hardBreak, image, mention…) contributes U+FFFC. Offsets are UTF-16 code units of that
// string, so `blockPos + 1 + offset` is the ProseMirror position. A run is a maximal
// sequence of adjacent text with the same mark set including attributes (what ProseMirror
// keeps as one normalized text node).
import { Mark, Node as PMNode } from 'prosemirror-model';
import { Transform } from 'prosemirror-transform';
import { ADDRESSABLE } from './schema.mjs';

export const LEAF = '\uFFFC';

export class ReplaceExactError extends Error {
  /** @param {'invalid_input'|'precondition_failed'|'unsupported_content'} code wire code
   *  @param {string} reason stable internal reason, never shown to the model */
  constructor(code, reason) {
    super(`${code}: ${reason}`);
    this.code = code;
    this.reason = reason;
  }
}

/** @param {PMNode} doc @param {string} blockId @returns {{node: PMNode, pos: number}} */
export function findBlock(doc, blockId) {
  const found = [];
  doc.descendants((node, pos) => {
    if (node.isTextblock) {
      if (node.attrs.blockId === blockId) found.push({ node, pos });
      return false;
    }
    return true;
  });
  if (found.length === 0) throw new ReplaceExactError('precondition_failed', 'block_missing');
  if (found.length > 1) throw new ReplaceExactError('unsupported_content', 'duplicate_block_id');
  if (!ADDRESSABLE.has(found[0].node.type.name)) {
    throw new ReplaceExactError('unsupported_content', 'unsupported_block');
  }
  return found[0];
}

/** @param {PMNode} block @returns {{text: string, runs: {start:number,end:number,marks:readonly Mark[]|null}[]}} */
export function blockText(block) {
  let text = '';
  const runs = [];
  block.forEach((child) => {
    const start = text.length;
    if (child.isText) {
      text += child.text;
      const last = runs.at(-1);
      if (last && last.marks && last.end === start && Mark.sameSet(last.marks, child.marks)) {
        last.end = text.length;
      } else {
        runs.push({ start, end: text.length, marks: child.marks });
      }
    } else {
      text += LEAF;
      runs.push({ start, end: text.length, marks: null });
    }
  });
  return { text, runs };
}

/** Every start offset, overlapping matches included ("1-1" occurs twice in "1-1-1"). */
export function occurrences(text, expected) {
  const starts = [];
  for (let at = text.indexOf(expected); at !== -1; at = text.indexOf(expected, at + 1)) starts.push(at);
  return starts;
}

function validArguments({ blockId, expected, replacement }) {
  const strings = [blockId, expected, replacement];
  if (!strings.every((s) => typeof s === 'string' && s.isWellFormed())) return false;
  return blockId !== '' && expected !== '' && !expected.includes(LEAF) && !replacement.includes(LEAF);
}

/**
 * Prepare-time resolution on the read state. Returns the private preconditions the owner pins
 * under proposal_id (they are not part of the wire digest).
 * @param {PMNode} doc
 * @param {{blockId: string, expected: string, replacement: string}} args
 */
export function planReplaceExact(doc, args) {
  if (!validArguments(args)) throw new ReplaceExactError('invalid_input', 'invalid_arguments');
  const { node } = findBlock(doc, args.blockId);
  const { text, runs } = blockText(node);
  const starts = occurrences(text, args.expected);
  if (starts.length === 0) throw new ReplaceExactError('precondition_failed', 'expected_absent');
  if (starts.length > 1) throw new ReplaceExactError('invalid_input', 'ambiguous_target');
  const start = starts[0];
  const end = start + args.expected.length;
  const run = runs.find((r) => r.marks && r.start <= start && end <= r.end);
  if (!run) throw new ReplaceExactError('unsupported_content', 'crosses_formatting_or_inline');
  return {
    blockId: args.blockId,
    block: node.toJSON(),
    startUtf16: start,
    endUtf16: end,
    marks: run.marks.map((m) => m.toJSON()),
  };
}

/**
 * Commit-time application on the fresh owner state. v1 context rule: the whole target block
 * (type, attrs, text, marks with attributes) equals the pinned one; other blocks may change.
 * @param {PMNode} doc fresh state
 * @param {{blockId: string, expected: string, replacement: string}} args
 * @param {ReturnType<typeof planReplaceExact>} pinned
 */
export function applyReplaceExact(doc, args, pinned) {
  const schema = doc.type.schema;
  const { node, pos } = findBlock(doc, args.blockId);
  if (!node.eq(schema.nodeFromJSON(pinned.block))) {
    throw new ReplaceExactError('precondition_failed', 'target_changed');
  }
  const from = pos + 1 + pinned.startUtf16;
  const to = pos + 1 + pinned.endUtf16;
  if (doc.textBetween(from, to, '', LEAF) !== args.expected) {
    throw new ReplaceExactError('precondition_failed', 'target_changed');
  }
  const marks = pinned.marks.map((m) => schema.markFromJSON(m));
  const tr = new Transform(doc);
  if (args.replacement === '') tr.delete(from, to);
  else tr.replaceWith(from, to, schema.text(args.replacement, marks));
  const after = findBlock(tr.doc, args.blockId).node;
  return {
    doc: tr.doc,
    steps: tr.steps.map((s) => s.toJSON()),
    evidence: {
      block_id: args.blockId,
      start_utf16: pinned.startUtf16,
      length_utf16: args.replacement.length,
      replacement: args.replacement,
      marks: args.replacement === '' ? [] : pinned.marks,
      committed_text: blockText(after).text,
    },
  };
}

/** prepare + commit on the same state, for vectors. */
export function replaceExact(doc, args) {
  return applyReplaceExact(doc, args, planReplaceExact(doc, args));
}

/**
 * text_and_marks on a given state: the stored snapshot at the receipt (mode at_commit) or the
 * current owner state (mode current). text_exact.actual is the receipt range of that state;
 * it passes when the block text still equals the committed block text. marks.actual lists
 * mark names of the range; it passes when every character carries exactly the committed
 * marks including attributes.
 * @param {PMNode} doc
 * @param {ReturnType<typeof applyReplaceExact>['evidence']} evidence
 */
export function verifyTextAndMarks(doc, evidence) {
  let block;
  try {
    block = findBlock(doc, evidence.block_id).node;
  } catch {
    return { outcome: 'failed', checks: [
      { name: 'text_exact', passed: false, actual: '' },
      { name: 'marks', passed: false, actual: [] },
    ] };
  }
  const { text, runs } = blockText(block);
  const start = evidence.start_utf16;
  const end = start + evidence.length_utf16;
  const actualText = text.slice(start, end);
  const touched = runs.filter((r) => r.start < end && start < r.end);
  const expected = evidence.marks.map((m) => doc.type.schema.markFromJSON(m));
  const marksPassed = touched.every((r) => r.marks && Mark.sameSet(r.marks, expected));
  const names = [...new Set(touched.flatMap((r) => (r.marks ?? []).map((m) => m.type.name)))];
  const checks = [
    { name: 'text_exact', passed: text === evidence.committed_text && actualText === evidence.replacement, actual: actualText },
    { name: 'marks', passed: marksPassed, actual: names },
  ];
  return { outcome: checks.every((c) => c.passed) ? 'passed' : 'failed', checks };
}

/** office.read text_block projection: segments are runs, marks are names only. */
export function readSegments(doc, blockId) {
  const { node } = findBlock(doc, blockId);
  const { text, runs } = blockText(node);
  return runs.map((r) => ({ text: text.slice(r.start, r.end), marks: (r.marks ?? []).map((m) => m.type.name) }));
}

export { PMNode, Transform };
