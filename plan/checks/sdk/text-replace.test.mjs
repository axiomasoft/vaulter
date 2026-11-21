// node --test plan/checks/sdk/text-replace.test.mjs   (after npm ci in the reference directory;
// plan/checks/sdk/run.sh does both). Exercises the ProseMirror reference, compares it with the
// PHP mirror on the same vectors, shows the sensitivity of each rule against a naive variant and
// replays verify(at_commit)/verify(current) of the wire exchange.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { schema } from '../../examples/reference/text-replace/schema.mjs';
import {
  ReplaceExactError, applyReplaceExact, blockText, findBlock, occurrences, planReplaceExact,
  readSegments, replaceExact, verifyTextAndMarks, Transform,
} from '../../examples/reference/text-replace/replace-exact.mjs';

const dir = fileURLToPath(new URL('../../examples/reference/text-replace/', import.meta.url));
const vectors = JSON.parse(readFileSync(dir + 'vectors.json', 'utf8'));
const exchange = JSON.parse(readFileSync(dir + 'exchange.json', 'utf8'));
const seed = () => schema.nodeFromJSON(vectors.doc);
const byId = Object.fromEntries(vectors.cases.map((c) => [c.id, c]));

function outcome(fn) {
  try {
    return { ok: fn() };
  } catch (e) {
    if (!(e instanceof ReplaceExactError)) throw e;
    return { error: [e.code, e.reason] };
  }
}

function canonicalDoc(json) {
  // Order-insensitive mark sets and member order, so PM toJSON and PHP arrays compare by value.
  if (Array.isArray(json)) return json.map(canonicalDoc);
  if (json && typeof json === 'object') {
    const out = {};
    for (const key of Object.keys(json).sort()) out[key] = canonicalDoc(json[key]);
    if (out.marks) out.marks = out.marks.map((m) => JSON.stringify(m)).sort().map((m) => JSON.parse(m));
    return out;
  }
  return json;
}

function linkHrefs(doc, blockId) {
  const hrefs = [];
  findBlock(doc, blockId).node.forEach((child) => {
    for (const mark of child.marks) if (mark.type.name === 'link') hrefs.push([child.text, mark.attrs.href]);
  });
  return hrefs;
}

const results = { cases: {}, commit_after: {} };

test('shared vectors: ProseMirror reference', () => {
  for (const c of vectors.cases) {
    const r = outcome(() => {
      const doc = seed();
      const pinned = planReplaceExact(doc, c.args);
      return { pinned, ...applyReplaceExact(doc, c.args, pinned) };
    });
    if (c.error) {
      assert.deepEqual(r.error, c.error, c.id);
      results.cases[c.id] = { error: r.error };
      continue;
    }
    assert.ok(r.ok, `${c.id}: ${JSON.stringify(r.error)}`);
    const block = findBlock(r.ok.doc, c.args.blockId).node;
    assert.equal(blockText(block).text, c.ok.block_text, c.id);
    assert.equal(r.ok.pinned.startUtf16, c.ok.start_utf16, c.id + ' UTF-16 offset');
    assert.deepEqual(readSegments(r.ok.doc, c.args.blockId), c.ok.segments, c.id + ' segments');
    // Every other block is untouched.
    r.ok.doc.forEach((node, _offset, index) => {
      if (node.attrs.blockId !== c.args.blockId) assert.ok(node.eq(seed().child(index)), `${c.id}: ${node.attrs.blockId} changed`);
    });
    results.cases[c.id] = { doc: r.ok.doc.toJSON(), evidence: r.ok.evidence };
  }
  // The link survives an edit next to it and an edit inside it, with its href.
  const href = 'https://example.test/terms?a=1&b=2';
  assert.deepEqual(linkHrefs(replaceExact(seed(), byId.unique_after_astral.args).doc, 'p-terms'), [['условия', href]]);
  assert.deepEqual(linkHrefs(replaceExact(seed(), byId.inside_link_keeps_href.args).doc, 'p-terms'), [['условия договора', href]]);
});

test('commit after a later edit: neighbour survives, target or href change conflicts', () => {
  for (const v of vectors.commit_after) {
    const args = byId[v.case].args;
    const pinned = planReplaceExact(seed(), args);
    let fresh = seed();
    if (v.edit) fresh = replaceExact(fresh, v.edit).doc;
    if (v.set_link_href) {
      const { node, pos } = findBlock(fresh, args.blockId);
      const tr = new Transform(fresh);
      node.forEach((child, offset) => {
        const link = child.marks.find((m) => m.type.name === 'link');
        if (link) {
          const from = pos + 1 + offset;
          tr.removeMark(from, from + child.nodeSize, link.type);
          tr.addMark(from, from + child.nodeSize, schema.marks.link.create({ ...link.attrs, href: v.set_link_href }));
        }
      });
      fresh = tr.doc;
    }
    const r = outcome(() => applyReplaceExact(fresh, args, pinned));
    const got = r.error ?? 'ok';
    assert.deepEqual(got, v.result, v.id);
    if (got === 'ok') {
      assert.equal(blockText(findBlock(r.ok.doc, 'p71').node).text, 'Срок рассмотрения претензии — 12 рабочих дней.');
      assert.equal(blockText(findBlock(r.ok.doc, 'p-terms').node).text, byId[v.case].ok.block_text);
    }
    results.commit_after[v.id] = got;
  }
});

test('PHP mirror gives the same documents, evidence and refusals', () => {
  const php = spawnSync('php', [dir + 'replace_exact.php', dir + 'vectors.json'], { encoding: 'utf8' });
  assert.equal(php.status, 0, php.stderr);
  const theirs = JSON.parse(php.stdout);
  assert.deepEqual(Object.keys(theirs.cases), Object.keys(results.cases));
  for (const [id, mine] of Object.entries(results.cases)) {
    const other = theirs.cases[id];
    if (mine.error) {
      assert.deepEqual(other.error, mine.error, id);
      continue;
    }
    assert.deepEqual(canonicalDoc(other.doc), canonicalDoc(mine.doc), id + ' document');
    assert.deepEqual(canonicalDoc(other.evidence), canonicalDoc(mine.evidence), id + ' evidence');
  }
  assert.deepEqual(theirs.commit_after, results.commit_after);
});

test('sensitivity: naive variants are caught by the same vectors', () => {
  // 1. Code-point offsets used as ProseMirror positions (Array.from / PHP mb_strpos).
  const c = byId.unique_after_astral;
  const doc = seed();
  const { node, pos } = findBlock(doc, c.args.blockId);
  const text = blockText(node).text;
  const cp = Array.from(text.slice(0, text.indexOf(c.args.expected))).length;
  const wrong = new Transform(doc).replaceWith(pos + 1 + cp, pos + 1 + cp + c.args.expected.length, schema.text(c.args.replacement)).doc;
  assert.notEqual(blockText(findBlock(wrong, c.args.blockId).node).text, c.ok.block_text);
  // 2. Replacement text without the matched run marks loses the link and its href.
  const l = byId.inside_link_keeps_href;
  const p = planReplaceExact(seed(), l.args);
  const lb = findBlock(seed(), l.args.blockId);
  const lost = new Transform(seed()).replaceWith(lb.pos + 1 + p.startUtf16, lb.pos + 1 + p.endUtf16, schema.text(l.args.replacement)).doc;
  assert.deepEqual(linkHrefs(lost, 'p-terms'), []);
  // 3. Non-overlapping counting (PHP substr_count, String.split) accepts an ambiguous target.
  const o = byId.overlapping_ambiguity;
  const codeText = blockText(findBlock(seed(), 'p-code').node).text;
  assert.equal(codeText.split(o.args.expected).length - 1, 1);
  assert.equal(occurrences(codeText, o.args.expected).length, 2);
  // 4. Ignoring run boundaries would rewrite part of the link with plain text.
  assert.throws(() => planReplaceExact(seed(), byId.crosses_link.args), { reason: 'crosses_formatting_or_inline' });
});

test('wire exchange: same receipt, verify(at_commit) passes, verify(current) fails after a later edit', () => {
  const steps = exchange.steps;
  const prepare = steps.find((s) => s.request.operation === 'office.prepare' && s.response.ok).request.input.arguments;
  const args = { blockId: prepare.block_id, expected: prepare.expected, replacement: prepare.replacement };
  const read = steps[0].response.result.data;
  assert.deepEqual(read.segments, readSegments(seed(), args.blockId), 'office.read projection');
  const ambiguous = steps.find((s) => s.request.operation === 'office.prepare' && !s.response.ok);
  const a = ambiguous.request.input.arguments;
  assert.throws(() => planReplaceExact(seed(), { blockId: a.block_id, expected: a.expected, replacement: a.replacement }),
    { code: ambiguous.response.error.code });
  const committed = replaceExact(seed(), args);
  const edit = exchange.events.find((e) => e.type === 'human_edits_block').details;
  const current = replaceExact(committed.doc, { blockId: edit.block_id, ...edit.replace }).doc;
  const verifies = steps.filter((s) => s.request.operation === 'office.verify');
  for (const v of verifies) {
    const state = v.request.input.mode === 'at_commit' ? committed.doc : current;
    const mine = verifyTextAndMarks(state, committed.evidence);
    assert.equal(v.response.result.outcome, mine.outcome, `${v.request.input.mode} outcome`);
    assert.deepEqual(v.response.result.checks, mine.checks, `${v.request.input.mode} checks`);
  }
  assert.deepEqual(verifies.map((v) => v.response.result.outcome), ['passed', 'failed', 'passed']);
  // A later href change is invisible in mark names but fails the marks check.
  const inLink = replaceExact(seed(), byId.inside_link_keeps_href.args);
  const { node, pos } = findBlock(inLink.doc, 'p-terms');
  let tr = new Transform(inLink.doc);
  node.forEach((child, offset) => {
    if (child.marks.length) tr = tr.addMark(pos + 1 + offset, pos + 1 + offset + child.nodeSize, schema.marks.link.create({ href: 'https://evil.test/' }));
  });
  const marks = verifyTextAndMarks(tr.doc, inLink.evidence).checks.find((x) => x.name === 'marks');
  assert.deepEqual([marks.passed, marks.actual], [false, ['link']]);
  assert.equal(verifyTextAndMarks(inLink.doc, inLink.evidence).outcome, 'passed');
  // Minimal data: the agent receives one block projection instead of the document.
  const readBytes = Buffer.byteLength(JSON.stringify(steps[0].response));
  const docBytes = Buffer.byteLength(JSON.stringify(vectors.doc));
  console.log(`office.read response ${readBytes} B (segments only, no href/attrs) vs stored document ${docBytes} B`);
  assert.ok(!JSON.stringify(steps[0].response).includes('example.test'));
});
