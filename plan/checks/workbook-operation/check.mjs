/**
 * Two vectors for one workbook operation across Vue client -> PHP owner -> Node runtime.
 * Run through run.sh (installs the reference with npm ci first). Every runtime call is a
 * separate `node src/cli.ts` process that receives only the serialized snapshot; every owner
 * call is a separate PHP process (CLI) or a request to `php -S`.
 * Expected values are computed here from the corpus inputs, never copied from engine output.
 */
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {dirname, join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const plan = join(here, '../..');
const ref = join(plan, 'examples/reference/workbook-operation');
const load = rel => import(pathToFileURL(join(ref, rel)).href);
const {canonicalize, sha256Canonical, parseStrict} = await load('src/wire.ts');
const {UniverWorkbookSession} = await load('src/engine/univer.ts');
const {runOperation} = await load('src/operation.ts');
const {PROFILE, PROFILE_RESOURCES} = await load('src/profile.ts');
const client = await load('src/vue-client.ts');

const work = mkdtempSync(join(tmpdir(), 'vaulter-workbook-op-'));
const cli = join(ref, 'src/cli.ts');
const ownerCli = join(ref, 'php/owner-cli.php');
const say = (label, value) => console.log(`OK ${label}${value === undefined ? '' : ' ' + JSON.stringify(value)}`);

function runtime(requestOrLine) {
    const input = typeof requestOrLine === 'string' ? requestOrLine : `${JSON.stringify(requestOrLine)}\n`;
    const result = spawnSync(process.execPath, [cli], {input, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return JSON.parse(result.stdout);
}
function owner(...args) {
    const result = spawnSync('php', [ownerCli, ...args], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr + result.stdout);
    return JSON.parse(result.stdout);
}
function counts(dir) {
    let text = '';
    try { text = readFileSync(join(dir, 'events.log'), 'utf8'); } catch { /* no events yet */ }
    const lines = text.split('\n').filter(Boolean);
    return {runtime: lines.filter(l => l === 'runtime_call').length, published: lines.filter(l => l === 'published').length};
}
function head(dir) {
    return JSON.parse(readFileSync(join(dir, 'head.json'), 'utf8'));
}
function seed(dir, nodeId, args, generation, position) {
    const seeded = spawnSync(process.execPath, [join(ref, 'src/seed.ts'), ...args], {encoding: 'utf8'});
    assert.equal(seeded.status, 0, seeded.stderr);
    const file = join(work, `${nodeId}-seed.json`);
    writeFileSync(file, seeded.stdout);
    const result = owner('seed', dir, nodeId, file, String(generation), String(position));
    assert.equal(result.ok, true);
    return result.result.etag;
}
function byA1(cells) {
    return Object.fromEntries(cells.map(c => [`${c.sheet_id}!${c.a1}`, {formula: c.formula, value: c.value}]));
}
function freeport() {
    return new Promise(resolve => {
        const server = createServer().listen(0, '127.0.0.1', () => {
            const {port} = server.address();
            server.close(() => resolve(port));
        });
    });
}
/** Starts an owner commit that stops at the explicit barrier, runs `whilePaused`, then releases it. */
async function commitAcrossBarrier(args, whilePaused) {
    const child = spawn('php', [ownerCli, ...args, '--barrier'], {stdio: ['pipe', 'pipe', 'pipe']});
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', d => { stdout += d; });
    const closed = new Promise(resolve => child.on('close', resolve));
    await new Promise((resolve, reject) => {
        child.stderr.on('data', d => {
            stderr += d;
            if (stderr.includes('BARRIER before_publish\n')) resolve();
        });
        closed.then(() => reject(new Error(`owner exited before the barrier: ${stderr}${stdout}`)));
    });
    const other = whilePaused();
    child.stdin.end('\n');
    assert.equal(await closed, 0, stderr);
    return {paused: JSON.parse(stdout), other};
}

let server;
try {
    // ---------- Vector A: plan/examples/workbook.json through the Vue call point ----------
    const fixture = JSON.parse(readFileSync(join(plan, 'examples/workbook.json'), 'utf8'));
    const d = fixture.sheets.find(s => s.id === 'detail').cells;
    const edit = fixture.steps.find(s => s.commandId === 'change-pear-price');
    const insert = fixture.steps.find(s => s.action === 'insert_row_before');
    const stale = fixture.steps.find(s => s.commandId === 'another-stale-price');
    const apples = d.B2.number * d.C2.number;
    const pears = d.B3.number * d.C3.number;
    const pearsAfter = d.B3.number * edit.value;
    assert.deepEqual([apples, pears, apples + pears, pearsAfter, apples + pearsAfter], [20, 15, 35, 21, 41]);

    const stores = join(work, 'stores');
    const groceries = join(stores, 'budget-probe');
    const etag1 = seed(groceries, 'budget-probe', ['workbook', join(plan, 'examples/workbook.json')], 1, fixture.initialRevision);
    const port = await freeport();
    server = spawn('php', ['-S', `127.0.0.1:${port}`, join(ref, 'php/http.php')], {env: {...process.env, VAULTER_STORE_ROOT: stores, VAULTER_NODE: process.execPath}, stdio: 'ignore'});
    const base = `http://127.0.0.1:${port}`;
    for (let i = 0; ; i++) {
        try { await fetch(`${base}/workbooks/budget-probe`); break; } catch (error) { if (i > 100) throw error; await new Promise(r => setTimeout(r, 50)); }
    }
    const opened = await client.openWorkbook(fetch, base, 'budget-probe');
    assert.equal(opened.etag, etag1);
    const before = byA1((await client.readConfirmedRange(fetch, base, 'budget-probe', {sheet_id: 'summary', range: 'B2'})).ranges[0].cells);
    assert.deepEqual(before['summary!B2'], {formula: '=SUM(Detail!D2:D3)', value: apples + pears});
    say('A0 open via PHP, confirmed total', before['summary!B2']);

    const editCommand = {kind: 'set_number', sheet_id: edit.target.sheetId, ...{row: 3, column: 3}, expected: {number: edit.target.expectedNumber}, value: edit.value};
    const first = await client.submitWorkbookCommand(fetch, base, 'budget-probe', {commandId: edit.commandId, ifMatch: etag1, commands: [editCommand]});
    assert.equal(first.kind, 'committed');
    assert.equal(first.receipt.marker.position, edit.expect.revision);
    const afterEdit = await client.readConfirmedRange(fetch, base, 'budget-probe', {sheet_id: 'detail', range: 'C3:D3'});
    const total1 = await client.readConfirmedRange(fetch, base, 'budget-probe', {sheet_id: 'summary', range: 'B2'});
    assert.deepEqual(byA1(afterEdit.ranges[0].cells)['detail!D3'], {formula: '=B3*C3', value: pearsAfter});
    assert.equal(total1.ranges[0].cells[0].value, apples + pearsAfter);
    assert.equal(total1.calculated_at_sha256, first.receipt.candidate_sha256);
    say('A1 Vue set_number C3=7 -> receipt position, D3, total', [first.receipt.marker.position, pearsAfter, total1.ranges[0].cells[0].value]);

    const second = await client.submitWorkbookCommand(fetch, base, 'budget-probe', {
        commandId: insert.commandId, ifMatch: first.etag,
        commands: [{kind: 'insert_row_before', sheet_id: insert.target.sheetId, row: insert.target.row, count: 1}],
    });
    assert.equal(second.kind, 'committed');
    assert.equal(second.receipt.marker.position, insert.expect.revision);
    say('A2 insert_row_before row 2 -> position', second.receipt.marker.position);

    const countsBeforeRetry = counts(groceries);
    const retry = await client.submitWorkbookCommand(fetch, base, 'budget-probe', {commandId: edit.commandId, ifMatch: etag1, commands: [editCommand]});
    assert.equal(retry.kind, 'committed');
    assert.equal(retry.receipt.replayed, true);
    assert.equal(retry.receipt.receipt_id, first.receipt.receipt_id);
    assert.equal(retry.receipt.marker.position, 2);
    assert.deepEqual(counts(groceries), countsBeforeRetry);
    const changedBody = await client.submitWorkbookCommand(fetch, base, 'budget-probe', {commandId: edit.commandId, ifMatch: etag1, commands: [{...editCommand, value: 8}]});
    assert.deepEqual([changedBody.kind, changedBody.code], ['conflict', 'idempotency_conflict']);
    say('A3 retry returns original receipt, 0 runtime calls / 0 publications; changed body -> idempotency_conflict');

    const staleCommand = {...editCommand, value: stale.value};
    const staleEtag = await client.submitWorkbookCommand(fetch, base, 'budget-probe', {commandId: stale.commandId, ifMatch: etag1, commands: [staleCommand]});
    assert.deepEqual([staleEtag.kind, staleEtag.code, staleEtag.status], ['conflict', 'version_conflict', 409]);
    assert.deepEqual(counts(groceries), countsBeforeRetry);
    const staleAddress = await client.submitWorkbookCommand(fetch, base, 'budget-probe', {commandId: `${stale.commandId}-fresh-etag`, ifMatch: second.etag, commands: [staleCommand]});
    assert.deepEqual([staleAddress.kind, staleAddress.code], ['conflict', 'version_conflict']);
    assert.equal(counts(groceries).runtime, countsBeforeRetry.runtime + 1);
    assert.equal(counts(groceries).published, countsBeforeRetry.published);
    const moved = byA1((await client.readConfirmedRange(fetch, base, 'budget-probe', {sheet_id: 'detail', range: 'A3:C4'})).ranges[0].cells);
    assert.deepEqual([moved['detail!A3'].value, moved['detail!C3'].value, moved['detail!A4'].value, moved['detail!C4'].value], [d.A2.text, d.C2.number, d.A3.text, edit.value]);
    say('A4 stale C3 (old ETag: rejected before runtime; fresh ETag: runtime precondition_failed) -> apples keep', moved['detail!C3']);

    // New process, only the serialized snapshot: shifted references computed by hand.
    const stored = (await client.openWorkbook(fetch, base, 'budget-probe')).workbook;
    assert.equal(sha256Canonical(stored), head(groceries).sha256);
    const coldRequest = {
        version: 1, request_id: 'cold-open', operation: 'read',
        base: {generation: 1, position: 3, sha256: sha256Canonical(stored)}, profile_id: PROFILE.id, workbook: stored,
        selector: {ranges: [{sheet_id: 'detail', range: 'C3:D4'}, {sheet_id: 'summary', range: 'B2'}]},
    };
    const cold = runtime(coldRequest);
    assert.equal(cold.ok, true);
    const coldCells = byA1(cold.data.ranges.flatMap(r => r.cells));
    assert.deepEqual(coldCells, {
        'detail!C3': {formula: null, value: d.C2.number}, 'detail!D3': {formula: '=B3*C3', value: apples},
        'detail!C4': {formula: null, value: edit.value}, 'detail!D4': {formula: '=B4*C4', value: pearsAfter},
        'summary!B2': {formula: '=SUM(Detail!D3:D4)', value: apples + pearsAfter},
    });
    assert.deepEqual(cold.calculation, {state: 'ready', source_sha256: coldRequest.base.sha256});
    say('A5 cold reopen in a new Node process: D3/D4 formulas, total', coldCells['summary!B2']);

    // False cache 999: never returned as a confirmed value.
    const poisoned = structuredClone(stored);
    const cache = poisoned.native.sheets.summary.cellData['1']['1'];
    assert.equal(cache.v, apples + pearsAfter, 'snapshot carries a cached total');
    cache.v = 999;
    const poisonedRead = runtime({...coldRequest, request_id: 'cold-999', base: {...coldRequest.base, sha256: sha256Canonical(poisoned)}, workbook: poisoned});
    assert.equal(byA1(poisonedRead.data.ranges.flatMap(r => r.cells))['summary!B2'].value, apples + pearsAfter);
    const naive = UniverWorkbookSession.open(poisoned.native);
    const naiveValue = naive.cell('summary', {row: 2, column: 2}).getRawValue();
    naive.dispose();
    assert.equal(naiveValue, 999);
    const payloadFile = join(groceries, head(groceries).payload_file);
    const goodBytes = readFileSync(payloadFile);
    writeFileSync(payloadFile, canonicalize(poisoned));
    const corruptedRead = await fetch(`${base}/workbooks/budget-probe/ranges?sheet_id=summary&range=B2`);
    assert.equal(corruptedRead.status, 500);
    assert.equal((await corruptedRead.json()).error.code, 'storage_integrity');
    writeFileSync(payloadFile, goodBytes);
    say('A6 cache 999: runtime barrier -> 41; naive open without barrier -> 999; owner refuses stored bytes with foreign digest');

    const lost = await client.commandStatus(fetch, base, 'budget-probe', edit.commandId);
    assert.equal(lost.receipt_id, first.receipt.receipt_id);
    say('A7 lost response recovered by GET status', lost.marker);

    // Concurrency, exact_base: a second writer commits while the first waits at the barrier.
    const etag3 = second.etag;
    const before4 = counts(groceries);
    const race = await commitAcrossBarrier(
        ['commit', groceries, 'race-pears', 'exact_base', etag3, '-', JSON.stringify([{kind: 'set_number', sheet_id: 'detail', row: 4, column: 3, expected: {number: edit.value}, value: 8}])],
        () => owner('commit', groceries, 'race-apples', 'exact_base', etag3, '-', JSON.stringify([{kind: 'set_number', sheet_id: 'detail', row: 3, column: 3, expected: {number: d.C2.number}, value: 11}])),
    );
    assert.equal(race.other.ok, true);
    assert.deepEqual([race.paused.ok, race.paused.error.code, race.paused.error.status], [false, 'version_conflict', 409]);
    assert.deepEqual(counts(groceries), {runtime: before4.runtime + 2, published: before4.published + 1});
    const afterRace = owner('read', groceries, JSON.stringify([{sheet_id: 'detail', range: 'C3:C4'}]));
    assert.deepEqual(afterRace.result.ranges[0].cells.map(c => c.value), [11, edit.value]);
    say('A8 barrier: candidate computed on position 3 is refused after another commit (position 4)');

    // ---------- Vector D: two editors at once, rebase of pure cell edits (policy rebase_cells) ----------
    const etagOf = dir => { const h = head(dir); return `"${h.node_id}-g${h.generation}-p${h.position}"`; };
    const cell = (dir, range) => byA1(owner('read', dir, JSON.stringify([{sheet_id: range.split('!')[0], range: range.split('!')[1]}])).result.ranges[0].cells);
    const setNum = (row, column, expected, value) => ({kind: 'set_number', sheet_id: 'detail', row, column, expected: expected === null ? null : {number: expected}, value});
    const rebaseStore = join(stores, 'rebase-probe');
    const etagD0 = seed(rebaseStore, 'rebase-probe', ['workbook', join(plan, 'examples/workbook.json')], 1, fixture.initialRevision);
    assert.equal(client.policyFor([setNum(3, 3, 5, 7)]), 'rebase_cells');
    assert.equal(client.policyFor([{kind: 'insert_row_before', sheet_id: 'detail', row: 2, count: 1}]), 'exact_base');
    // Bob changes the apples quantity B2 2 -> 4; Alice, still on etagD0, changes the pears price C3 5 -> 7.
    assert.equal(owner('commit', rebaseStore, 'd-bob-qty', 'exact_base', etagD0, '-', JSON.stringify([setNum(2, 2, d.B2.number, 4)])).ok, true);
    const aliceExact = owner('commit', rebaseStore, 'd-alice-exact', 'exact_base', etagD0, '-', JSON.stringify([setNum(3, 3, d.C3.number, edit.value)]));
    assert.equal(aliceExact.error.code, 'version_conflict');
    const aliceRebased = owner('commit', rebaseStore, 'd-alice', 'rebase_cells', etagD0, '-', JSON.stringify([setNum(3, 3, d.C3.number, edit.value)]));
    assert.equal(aliceRebased.ok, true, JSON.stringify(aliceRebased));
    assert.equal(aliceRebased.result.marker.position, fixture.initialRevision + 2);
    const both = {...cell(rebaseStore, 'detail!D2:D3'), ...cell(rebaseStore, 'summary!B2')};
    assert.deepEqual([both['detail!D2'].value, both['detail!D3'].value, both['summary!B2'].value], [4 * d.C2.number, d.B3.number * edit.value, 4 * d.C2.number + d.B3.number * edit.value]);
    say('D1 edits of different cells: exact_base -> version_conflict, rebase_cells -> both kept', [both['detail!D2'].value, both['detail!D3'].value, both['summary!B2'].value]);
    // Same cell: Bob sets C3 7 -> 6 first; Alice's rebase from the older ETag must not overwrite it.
    const etagAfterAlice = etagOf(rebaseStore);
    assert.equal(owner('commit', rebaseStore, 'd-bob-price', 'exact_base', etagAfterAlice, '-', JSON.stringify([setNum(3, 3, edit.value, 6)])).ok, true);
    const sameCell = owner('commit', rebaseStore, 'd-alice-again', 'rebase_cells', etagAfterAlice, '-', JSON.stringify([setNum(3, 3, edit.value, 9)]));
    assert.deepEqual([sameCell.error.code, sameCell.error.detail.runtime_code], ['version_conflict', 'precondition_failed']);
    assert.equal(cell(rebaseStore, 'detail!C3')['detail!C3'].value, 6);
    say('D2 same cell: rebased command meets a changed expected value -> version_conflict, Bob keeps', 6);
    // Rows moved: Bob inserts plums above the pears with the same price 5, so C3 holds 5 again but names another record.
    const rebaseRows = join(stores, 'rebase-rows');
    const etagS0 = seed(rebaseRows, 'rebase-rows', ['workbook', join(plan, 'examples/workbook.json')], 1, fixture.initialRevision);
    const plums = [{kind: 'insert_row_before', sheet_id: 'detail', row: 3, count: 1},
        {kind: 'set_text', sheet_id: 'detail', row: 3, column: 1, expected: null, value: 'Сливы'}, setNum(3, 2, null, 1), setNum(3, 3, null, d.C3.number)];
    assert.equal(owner('commit', rebaseRows, 'd-bob-plums', 'exact_base', etagS0, '-', JSON.stringify(plums)).ok, true);
    const mutantStore = join(stores, 'rebase-rows-mutant');
    cpSync(rebaseRows, mutantStore, {recursive: true});
    const alicePears = JSON.stringify([setNum(3, 3, d.C3.number, edit.value)]);
    const guarded = owner('commit', rebaseRows, 'd-alice-pears', 'rebase_cells', etagS0, '-', alicePears);
    assert.equal(guarded.error.code, 'version_conflict');
    assert.deepEqual(guarded.error.detail.structural_receipts, ['d-bob-plums']);
    const rows = cell(rebaseRows, 'detail!A3:C4');
    assert.deepEqual([rows['detail!A3'].value, rows['detail!C3'].value, rows['detail!A4'].value, rows['detail!C4'].value], ['Сливы', d.C3.number, d.A3.text, d.C3.number]);
    const unguarded = owner('commit', mutantStore, 'd-alice-pears', 'rebase_cells', etagS0, '-', alicePears, '--mutant=no-structure-guard');
    assert.equal(unguarded.ok, true);
    const wrong = cell(mutantStore, 'detail!A3:C4');
    assert.deepEqual([wrong['detail!A3'].value, wrong['detail!C3'].value, wrong['detail!C4'].value], ['Сливы', edit.value, d.C3.number]);
    const mixed = owner('commit', rebaseRows, 'd-mixed', 'rebase_cells', etagOf(rebaseRows), '-', JSON.stringify(plums));
    assert.deepEqual([mixed.error.code, mixed.error.status], ['invalid_command', 422]);
    say('D3 rows moved since If-Match: guard -> version_conflict; mutant without guard writes 7 into the plums row, pears keep 5', guarded.error.detail.structural_receipts);

    // ---------- Vector B: office-sdk.json sheet_fixed_members ----------
    const corpus = JSON.parse(readFileSync(join(plan, 'examples/office-sdk.json'), 'utf8'));
    const scenario = id => corpus.scenarios.find(s => s.id === id);
    const fixed = scenario('sheet_fixed_members');
    const seedOf = sc => sc.events.find(e => e.type === 'seed_table').details;
    const humanOf = sc => sc.events.find(e => e.type === 'human_inserts_row').details;
    const letter = (sc, columnId) => Object.entries(seedOf(sc).columns_by_address).find(([, id]) => id === columnId)[0];
    const fixedSeed = seedOf(fixed);
    const human = humanOf(fixed);
    const delta = row => row.actual - row.plan;
    const order = (sc) => {
        const rows = [...seedOf(sc).rows];
        rows.splice(rows.findIndex(r => r.row_id === humanOf(sc).before_row_id), 0, humanOf(sc).row);
        return rows.map((row, i) => ({...row, at: seedOf(sc).first_data_row + i}));
    };
    const fixedOrder = order(fixed);
    const at = (rows, id) => rows.find(r => r.row_id === id);
    const fixedStore = join(work, 'fixed');
    const fixedEtag = seed(fixedStore, fixedSeed.resource_id, ['office', join(plan, 'examples/office-sdk.json'), fixed.id], fixedSeed.marker.generation, fixedSeed.marker.position);
    const initialBinding = owner('open', fixedStore).result.workbook.tables[0];
    const travelRow = initialBinding.rows.find(r => r.row_id === human.before_row_id).row;
    const [B, C] = [letter(fixed, 'plan'), letter(fixed, 'actual')];
    const col = l => l.charCodeAt(0) - 64;
    const humanCommands = newRowIds => [
        {kind: 'insert_row_before', sheet_id: fixedSeed.sheet_id, row: travelRow, count: 1, ...(newRowIds ? {new_row_ids: newRowIds} : {})},
        {kind: 'set_number', sheet_id: fixedSeed.sheet_id, row: travelRow, column: col(B), expected: null, value: human.row.plan},
        {kind: 'set_number', sheet_id: fixedSeed.sheet_id, row: travelRow, column: col(C), expected: null, value: human.row.actual},
    ];
    const noIdentity = owner('commit', fixedStore, 'human-no-id', 'exact_base', fixedEtag, '-', JSON.stringify(humanCommands()));
    assert.deepEqual([noIdentity.error.code, noIdentity.error.detail.runtime_code], ['invalid_command', 'identity_required']);
    assert.equal(head(fixedStore).position, fixedSeed.marker.position);
    const humanCommit = owner('commit', fixedStore, 'human-insert', 'exact_base', fixedEtag, '-', JSON.stringify(humanCommands({[fixedSeed.table_id]: [human.row.row_id]})));
    assert.equal(humanCommit.result.marker.position, human.marker.position);
    say('B0 human row insertion needs an owner-issued row ID; with it -> position', human.marker.position);
    const fixedAt12 = join(work, 'fixed-at-12');
    cpSync(fixedStore, fixedAt12, {recursive: true});

    const fixedPrepare = fixed.steps.find(s => s.request.operation === 'office.prepare');
    const fixedCommitStep = fixed.steps.find(s => s.request.operation === 'office.commit');
    const fixedVerify = fixed.steps.find(s => s.request.operation === 'office.verify').response.result.checks;
    const fixedArgs = fixedPrepare.request.input.arguments;
    const fillCommand = rowIds => ({
        kind: 'fill_formula', sheet_id: fixedArgs.sheet_id, table_id: fixedArgs.table_id, scope: fixedArgs.scope, row_ids: rowIds,
        destination_column_id: fixedArgs.destination_column_id, expression: fixedArgs.expression, expected_destination: rowIds.map(() => null),
    });
    const targets = fixedPrepare.response.result.targets;
    const fixedCommit = owner('commit', fixedStore, fixedCommitStep.request.input.command_id, 'revalidate_by_id', '-', String(fixedSeed.marker.generation), JSON.stringify([fillCommand(targets)]));
    assert.equal(fixedCommit.ok, true, JSON.stringify(fixedCommit));
    assert.equal(fixedCommit.result.marker.position, fixedCommitStep.response.result.marker.position);
    const expectFormula = r => `=${C}${r.at}-${B}${r.at}`;
    const expectedFixed = targets.map(id => at(fixedOrder, id));
    assert.deepEqual(fixedCommit.result.cells.map(c => [c.a1, c.formula, c.value]), expectedFixed.map(r => [`D${r.at}`, expectFormula(r), delta(r)]));
    assert.deepEqual(expectedFixed.map(r => r.at), [2, 4]);
    assert.deepEqual(expectedFixed.map(delta), [20, -20]);
    assert.deepEqual(fixedVerify.find(c => c.name === 'formulas').actual, expectedFixed.map(expectFormula));
    assert.deepEqual(fixedVerify.find(c => c.name === 'calculated_values').actual, expectedFixed.map(delta));
    assert.equal(fixedVerify.find(c => c.name === 'sum').actual, expectedFixed.map(delta).reduce((a, b) => a + b, 0));
    const fixedRead = byA1(owner('read', fixedStore, JSON.stringify([{sheet_id: fixedSeed.sheet_id, range: 'D2:D5'}])).result.ranges[0].cells);
    const assertCells = fixed.events.find(e => e.type === 'assert_cells_at_marker').details;
    for (const [a1, cell] of Object.entries(assertCells.cells)) {
        assert.deepEqual(fixedRead[`${fixedSeed.sheet_id}!${a1}`], {formula: null, value: cell.value});
        assert.equal(at(fixedOrder, cell.row_id).at, Number(a1.slice(1)));
    }
    assert.equal(fixedRead[`${fixedSeed.sheet_id}!D3`].value, null, 'coordinate-based fill would have written =C3-B3 = 15 here');
    const fixedBinding = owner('open', fixedStore).result.workbook.tables[0].rows;
    assert.deepEqual(fixedBinding, fixedOrder.map(r => ({row_id: r.row_id, row: r.at})));
    say('B1 fixed_members after insertion: D2/D4 formulas+values, D3 empty, D5 77', fixedCommit.result.cells.map(c => `${c.a1}${c.formula}=${c.value}`));
    const fixedCounts = counts(fixedStore);
    const fixedRetry = owner('commit', fixedStore, fixedCommitStep.request.input.command_id, 'revalidate_by_id', '-', String(fixedSeed.marker.generation), JSON.stringify([fillCommand(targets)]));
    assert.deepEqual([fixedRetry.result.replayed, fixedRetry.result.receipt_id], [true, fixedCommit.result.receipt_id]);
    assert.deepEqual(counts(fixedStore), fixedCounts);
    say('B2 SDK retry of sheet-1 replays the receipt without a runtime call');

    // Concurrency, revalidate_by_id: insertion lands while the candidate waits; owner recomputes by ID.
    const beforeRebase = counts(fixedAt12);
    const etag12 = JSON.parse(readFileSync(join(fixedAt12, 'head.json'), 'utf8'));
    const rebased = await commitAcrossBarrier(
        ['commit', fixedAt12, 'sheet-1-race', 'revalidate_by_id', '-', '1', JSON.stringify([fillCommand(targets)])],
        () => owner('commit', fixedAt12, 'late-insert', 'exact_base', `"${etag12.node_id}-g${etag12.generation}-p${etag12.position}"`, '-',
            JSON.stringify([{kind: 'insert_row_before', sheet_id: fixedSeed.sheet_id, row: at(fixedOrder, 'r-travel').at, count: 1, new_row_ids: {[fixedSeed.table_id]: ['r-late']}}])),
    );
    assert.equal(rebased.other.ok, true);
    assert.equal(rebased.paused.ok, true, JSON.stringify(rebased.paused));
    assert.equal(rebased.paused.result.marker.position, human.marker.position + 2);
    const travelLate = at(fixedOrder, 'r-travel').at + 1;
    assert.deepEqual(rebased.paused.result.cells.map(c => [c.a1, c.formula]), [['D2', '=C2-B2'], [`D${travelLate}`, `=C${travelLate}-B${travelLate}`]]);
    assert.deepEqual(counts(fixedAt12), {runtime: beforeRebase.runtime + 3, published: beforeRebase.published + 2});
    say('B3 barrier: position moved during calculation -> owner re-ran runtime, travel formula now at', `D${travelLate}`);

    // ---------- Vector B': sheet_current_rows ----------
    const current = scenario('sheet_current_rows');
    const currentSeed = seedOf(current);
    const currentHuman = humanOf(current);
    const currentOrder = order(current);
    const currentStore = join(work, 'current');
    const currentEtag = seed(currentStore, currentSeed.resource_id, ['office', join(plan, 'examples/office-sdk.json'), current.id], currentSeed.marker.generation, currentSeed.marker.position);
    const currentTravel = owner('open', currentStore).result.workbook.tables[0].rows.find(r => r.row_id === currentHuman.before_row_id).row;
    const currentHumanCommit = owner('commit', currentStore, 'human-insert', 'exact_base', currentEtag, '-', JSON.stringify([
        {kind: 'insert_row_before', sheet_id: currentSeed.sheet_id, row: currentTravel, count: 1, new_row_ids: {[currentSeed.table_id]: [currentHuman.row.row_id]}},
        {kind: 'set_number', sheet_id: currentSeed.sheet_id, row: currentTravel, column: col(B), expected: null, value: currentHuman.row.plan},
        {kind: 'set_number', sheet_id: currentSeed.sheet_id, row: currentTravel, column: col(C), expected: null, value: currentHuman.row.actual},
    ]));
    assert.equal(currentHumanCommit.result.marker.position, currentHuman.marker.position);
    const [oldPrepare, newPrepare] = current.steps.filter(s => s.request.operation === 'office.prepare');
    const [oldCommit, newCommit] = current.steps.filter(s => s.request.operation === 'office.commit');
    const currentCommand = rowIds => ({...fillCommand(rowIds), scope: 'current_rows', sheet_id: currentSeed.sheet_id, table_id: currentSeed.table_id});
    const beforeScope = counts(currentStore);
    const scopeChanged = owner('commit', currentStore, oldCommit.request.input.command_id, 'revalidate_by_id', '-', '1', JSON.stringify([currentCommand(oldPrepare.response.result.targets)]));
    assert.deepEqual([scopeChanged.ok, scopeChanged.error.code, scopeChanged.error.status], [false, oldCommit.response.error.code, 409]);
    assert.deepEqual(scopeChanged.error.detail.runtime_detail.actual, currentOrder.map(r => r.row_id));
    assert.deepEqual(counts(currentStore), {runtime: beforeScope.runtime + 1, published: beforeScope.published});
    assert.equal(head(currentStore).position, currentHuman.marker.position);
    say('C1 current_rows with old membership -> scope_changed, nothing published');
    assert.deepEqual(newPrepare.response.result.targets, currentOrder.map(r => r.row_id));
    const currentCommit = owner('commit', currentStore, newCommit.request.input.command_id, 'revalidate_by_id', '-', '1', JSON.stringify([currentCommand(newPrepare.response.result.targets)]));
    assert.equal(currentCommit.result.marker.position, newCommit.response.result.marker.position);
    assert.deepEqual(currentCommit.result.cells.map(c => [c.formula, c.value]), currentOrder.map(r => [expectFormula(r), delta(r)]));
    assert.deepEqual(currentOrder.map(delta), [20, 15, -20]);
    const currentVerify = current.steps.find(s => s.request.operation === 'office.verify').response.result.checks;
    assert.deepEqual(currentVerify.find(c => c.name === 'formulas').actual, currentOrder.map(expectFormula));
    assert.deepEqual(currentVerify.find(c => c.name === 'calculated_values').actual, currentOrder.map(delta));
    assert.equal(currentVerify.find(c => c.name === 'sum').actual, currentOrder.map(delta).reduce((a, b) => a + b, 0));
    say('C2 new current_rows proposal -> 20, 15, -20', currentCommit.result.cells.map(c => c.formula));

    // ---------- Sensitivity: digest, resources, wire strictness ----------
    const fixedHead = head(fixedStore);
    const tampered = owner('commit', fixedStore, 'tamper', 'exact_base', `"${fixedHead.node_id}-g1-p${fixedHead.position}"`, '-',
        JSON.stringify([{kind: 'set_number', sheet_id: fixedSeed.sheet_id, row: 2, column: 2, expected: {number: fixedSeed.rows[0].plan}, value: 101}]), '--fault=tamper-candidate');
    assert.deepEqual([tampered.error.code, tampered.error.status], ['runtime_integrity', 503]);
    assert.equal(head(fixedStore).sha256, fixedHead.sha256);
    say('S1 candidate bytes changed after candidate_sha256 -> owner refuses, head unchanged', tampered.error.message);

    const withResources = structuredClone(owner('open', groceries).result.workbook);
    withResources.native.resources.push({name: 'SHEET_CONDITIONAL_FORMATTING_PLUGIN', data: '{"detail":[]}'}, {name: 'VAULTER_UNREGISTERED_SENTINEL', data: '{"kept":true}'});
    const resourceSeed = join(work, 'resource-seed.json');
    writeFileSync(resourceSeed, canonicalize(withResources));
    const resourceStore = join(work, 'resources');
    const resourceEtag = owner('seed', resourceStore, 'resources', resourceSeed, '1', '1').result.etag;
    const resourceCommit = owner('commit', resourceStore, 'needs-cf', 'exact_base', resourceEtag, '-', JSON.stringify([{kind: 'insert_row_before', sheet_id: 'detail', row: 2, count: 1}]));
    assert.deepEqual([resourceCommit.error.code, resourceCommit.error.detail.runtime_detail.reason, resourceCommit.error.detail.runtime_detail.resources],
        ['unsupported_content', 'unknown_resource', ['SHEET_CONDITIONAL_FORMATTING_PLUGIN', 'VAULTER_UNREGISTERED_SENTINEL']]);
    assert.deepEqual(counts(resourceStore), {runtime: 1, published: 0});
    const widened = [...PROFILE_RESOURCES, 'VAULTER_UNREGISTERED_SENTINEL'];
    const sentinelOnly = structuredClone(owner('open', groceries).result.workbook);
    sentinelOnly.native.resources.push({name: 'VAULTER_UNREGISTERED_SENTINEL', data: '{"kept":true}'});
    await assert.rejects(runOperation({
        version: 1, request_id: 'widened', operation: 'apply', base: {generation: 1, position: 1, sha256: sha256Canonical(sentinelOnly)},
        profile_id: PROFILE.id, workbook: sentinelOnly, commands: [{kind: 'insert_row_before', sheet_id: 'detail', row: 2, count: 1}],
    }, PROFILE, widened), e => e.code === 'unsupported_content' && e.detail.reason === 'resource_lost' && e.detail.resources[0] === 'VAULTER_UNREGISTERED_SENTINEL');
    say('S2 resource outside profile -> unsupported_content/unknown_resource before engine; widened allow-list -> resource_lost after save()');

    const good = {...coldRequest, request_id: 'strict'};
    const dup = `${JSON.stringify(good).replace('"request_id":"strict"', '"request_id":"strict","request_id":"other"')}\n`;
    assert.equal(runtime(dup).error.code, 'invalid_message');
    assert.equal(runtime({...good, base: {...good.base, sha256: '0'.repeat(64)}}).error.code, 'base_mismatch');
    const otherProfile = structuredClone(stored);
    otherProfile.profile.plugins[0].version = '1.5.1';
    assert.equal(runtime({...good, base: {...good.base, sha256: sha256Canonical(otherProfile)}, workbook: otherProfile}).error.code, 'profile_mismatch');
    assert.equal(runtime({...good, operation: 'apply', selector: undefined, commands: [{kind: 'delete_rows', sheet_id: 'detail'}]}).error.code, 'unsupported_command');
    assert.equal(runtime(JSON.stringify(good)).error.code, 'invalid_message', 'missing LF');
    say('S3 runtime refuses duplicate key, foreign base digest, other profile, unknown command, missing LF');

    // Digest parity PHP <-> Node (RFC 8785): Cyrillic, empty {} vs [], fractions, UTF-16 key order, random doubles.
    const rfcSample = '{"numbers":[333333333.33333329,1E30,4.50,2e-3,0.000000000000000000000000001],"string":"\\u20ac$\\u000F\\u000aA\'\\u0042\\u0022\\u005c\\\\\\"\\/","literals":[null,true,false]}';
    const vectors = [
        rfcSample,
        '{"имя":"Груши","пусто":{},"список":[],"дробь":0.1,"минус":-0.0,"big":1e21,"small":1e-7,"cents":19.99}',
        '{"\\uff61":1,"\\ud83d\\ude00":2,"a":3,"\\u00e9":4,"":5}',
        '[10000000000000000,123456789012345680000,-9007199254740991]',
        '[9007199254740993]', '{"a":1,"a":2}', '[1e400]',
    ];
    let seedValue = 0x9e3779b9;
    const nextU32 = () => { seedValue ^= seedValue << 13; seedValue ^= seedValue >>> 17; seedValue ^= seedValue << 5; return seedValue >>> 0; };
    const view = new DataView(new ArrayBuffer(8));
    for (let i = 0; vectors.length < 400 + 7; i++) {
        view.setUint32(0, nextU32()); view.setUint32(4, nextU32());
        const x = view.getFloat64(0);
        if (Number.isFinite(x)) vectors.push(JSON.stringify([x, x / 1e300, Math.fround(x % 1000)]));
    }
    const php = spawnSync('php', [join(ref, 'php/canonical-cli.php')], {input: vectors.join('\n') + '\n', encoding: 'utf8'});
    assert.equal(php.status, 0, php.stderr);
    const phpOut = php.stdout.trim().split('\n').map(line => JSON.parse(line));
    vectors.forEach((text, i) => {
        let node;
        try { const c = canonicalize(parseStrict(text)); node = {ok: true, canonical: c, sha256: sha256Canonical(parseStrict(text))}; } catch (e) { node = {ok: false, code: e.code}; }
        if (node.ok) assert.deepEqual([phpOut[i].ok, phpOut[i].canonical, phpOut[i].sha256], [true, node.canonical, node.sha256], text);
        else assert.deepEqual([phpOut[i].ok, phpOut[i].code], [false, node.code], text);
    });
    assert.equal(phpOut[0].canonical, '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$\\u000f\\nA\'B\\"\\\\\\\\\\"/"}');
    assert.equal(phpOut[2].canonical, '{"":5,"a":3,"é":4,"😀":2,"｡":1}');
    assert.deepEqual(phpOut.slice(4, 7).map(o => o.code), ['invalid_message', 'invalid_message', 'invalid_message']);
    say(`S4 PHP and Node canonical bytes + SHA-256 equal on ${vectors.length} vectors (RFC 8785 sample, Cyrillic, {} vs [], UTF-16 order, random doubles); lossy int/dup key/1e400 refused by both`);

    // Single index conversion: every engine call with indices goes through engineIndex().
    const engineSource = readFileSync(join(ref, 'src/engine/univer.ts'), 'utf8');
    const indexCalls = engineSource.match(/\.(getRange|insertRowsBefore)\([^)]*\)/g);
    assert.ok(indexCalls.every(call => call.includes('engineIndex(')), indexCalls.join(' '));
    for (const file of ['src/operation.ts', 'src/table-bindings.ts', 'src/seed.ts', 'src/cli.ts']) {
        assert.doesNotMatch(readFileSync(join(ref, file), 'utf8'), /getRange\(|engineIndex\(|getSheetBySheetId\(/, file);
    }
    say('S5 zero-based indices appear only in engine/univer.ts via engineIndex()', indexCalls);

    // Static wire examples are exactly what the runtime produces for them.
    const exampleRequest = JSON.parse(readFileSync(join(ref, 'examples/apply-request.json'), 'utf8'));
    const exampleResponse = JSON.parse(readFileSync(join(ref, 'examples/apply-response.json'), 'utf8'));
    assert.deepEqual(runtime(exampleRequest), exampleResponse);
    assert.equal(sha256Canonical(exampleResponse.workbook), exampleResponse.candidate_sha256);
    const exampleError = JSON.parse(readFileSync(join(ref, 'examples/scope-changed-response.json'), 'utf8'));
    const exampleErrorRequest = JSON.parse(readFileSync(join(ref, 'examples/scope-changed-request.json'), 'utf8'));
    assert.deepEqual(runtime(exampleErrorRequest), exampleError);
    say('S6 examples/apply-*.json and scope-changed-*.json reproduced byte-for-byte by the runtime');

    // Univer setNumberFormat registers a style under a random ID: the same import is not byte-stable,
    // so idempotency must rest on the command fingerprint and the stored receipt, not on re-computed digests.
    const seedTwice = [1, 2].map(() => {
        const seeded = spawnSync(process.execPath, [join(ref, 'src/seed.ts'), 'workbook', join(plan, 'examples/workbook.json')], {encoding: 'utf8'});
        assert.equal(seeded.status, 0, seeded.stderr);
        return parseStrict(seeded.stdout.trim());
    });
    const styleIdsOf = book => Object.keys(book.native.styles ?? {}).sort();
    const cellsWithoutStyle = book => canonicalize(Object.fromEntries(Object.entries(book.native.sheets).map(([id, sheet]) => [id,
        Object.fromEntries(Object.entries(sheet.cellData ?? {}).map(([r, row]) => [r,
            Object.fromEntries(Object.entries(row).map(([c, {s, ...cell}]) => [c, cell]))]))])));
    const stylesWithoutId = book => canonicalize(Object.values(book.native.styles ?? {}).map(canonicalize).sort());
    assert.notEqual(sha256Canonical(seedTwice[0]), sha256Canonical(seedTwice[1]), 'two imports unexpectedly produced equal digests');
    assert.equal(cellsWithoutStyle(seedTwice[0]), cellsWithoutStyle(seedTwice[1]), 'cell values/formulas must be identical');
    assert.equal(stylesWithoutId(seedTwice[0]), stylesWithoutId(seedTwice[1]), 'style bodies must be identical');
    assert.notDeepEqual(styleIdsOf(seedTwice[0]), styleIdsOf(seedTwice[1]), 'style IDs are expected to be random');
    say('S7 two imports of workbook.json: equal cells and style bodies, different style IDs and digests', seedTwice.map(sha256Canonical).map(s => s.slice(0, 8)));
    console.log(`PASS workbook-operation on node ${process.version}, ${spawnSync('php', ['-r', 'echo PHP_VERSION;'], {encoding: 'utf8'}).stdout}`);
} finally {
    server?.kill();
    rmSync(work, {recursive: true, force: true});
}
