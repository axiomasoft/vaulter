/**
 * Regenerates the static wire examples of the reference after a deliberate profile change:
 *   node plan/checks/workbook-operation/write-examples.mjs
 * check.mjs then requires the runtime to reproduce them byte-for-byte.
 */
import {spawnSync} from 'node:child_process';
import {writeFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const plan = join(dirname(fileURLToPath(import.meta.url)), '../..');
const ref = join(plan, 'examples/reference/workbook-operation');
const {sha256Canonical} = await import(pathToFileURL(join(ref, 'src/wire.ts')).href);
const node = (args, input) => {
    const result = spawnSync(process.execPath, args, {input, encoding: 'utf8'});
    if (result.status !== 0) throw new Error(result.stderr);
    return JSON.parse(result.stdout);
};
const runtime = request => node([join(ref, 'src/cli.ts')], `${JSON.stringify(request)}\n`);
const write = (name, value) => writeFileSync(join(ref, 'examples', name), `${JSON.stringify(value, null, 2)}\n`);
const apply = (requestId, workbook, position, commands) => ({
    version: 1, request_id: requestId, operation: 'apply',
    base: {generation: 1, position, sha256: sha256Canonical(workbook)}, profile_id: workbook.profile.id, workbook, commands,
});

const groceries = node([join(ref, 'src/seed.ts'), 'workbook', join(plan, 'examples/workbook.json')]);
const editRequest = apply('change-pear-price#1', groceries, 1, [
    {kind: 'set_number', sheet_id: 'detail', row: 3, column: 3, expected: {number: 5}, value: 7},
]);
write('apply-request.json', editRequest);
write('apply-response.json', runtime(editRequest));

const current = node([join(ref, 'src/seed.ts'), 'office', join(plan, 'examples/office-sdk.json'), 'sheet_current_rows']);
const inserted = runtime(apply('human-insert#1', current, 11, [
    {kind: 'insert_row_before', sheet_id: 'expenses', row: 3, count: 1, new_row_ids: {costs: ['r-telecom2']}},
    {kind: 'set_number', sheet_id: 'expenses', row: 3, column: 2, expected: null, value: 40},
    {kind: 'set_number', sheet_id: 'expenses', row: 3, column: 3, expected: null, value: 55},
])).workbook;
const scopeRequest = apply('sheet-2#1', inserted, 12, [{
    kind: 'fill_formula', sheet_id: 'expenses', table_id: 'costs', scope: 'current_rows', row_ids: ['r-telecom', 'r-travel'],
    destination_column_id: 'delta', expression: {op: 'subtract', left_column_id: 'actual', right_column_id: 'plan'},
    expected_destination: [null, null],
}]);
write('scope-changed-request.json', scopeRequest);
write('scope-changed-response.json', runtime(scopeRequest));
console.log('examples written');
