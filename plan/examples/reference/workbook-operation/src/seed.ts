/**
 * Trusted import of the two test corpora into a v2 wrapper (runs once, before the first
 * publication; read-only opening never assigns identities). Prints canonical JSON to stdout.
 *   node src/seed.ts workbook <plan/examples/workbook.json>
 *   node src/seed.ts office <plan/examples/office-sdk.json> <scenario_id>
 * Target in vaulter-sheets: tests/fixtures loader used by tests/consumer and runtime tests.
 */
import {readFileSync} from 'node:fs';
import {parseA1} from './coordinates.ts';
import {UniverWorkbookSession} from './engine/univer.ts';
import {DEFAULT_LIMITS, PROFILE} from './profile.ts';
import {canonicalize, type JsonObject, type TableBinding, type WorkbookV2} from './wire.ts';

interface FixtureCell {text?: string; number?: number; formula?: string; numberFormat?: string}
interface FixtureSheet {id: string; name: string; cells: {[a1: string]: FixtureCell}}

function emptyNative(id: string, sheets: {id: string; name: string}[]): JsonObject {
    const native: JsonObject = {id, name: id, locale: 'enUS', sheetOrder: sheets.map(sheet => sheet.id), sheets: {}};
    for (const sheet of sheets) (native.sheets as JsonObject)[sheet.id] = {id: sheet.id, name: sheet.name, rowCount: 100, columnCount: 20};
    return native;
}

async function build(id: string, sheets: FixtureSheet[], tables: TableBinding[]): Promise<WorkbookV2> {
    const session = UniverWorkbookSession.open(emptyNative(id, sheets));
    try {
        for (const sheet of sheets) {
            for (const [a1, cell] of Object.entries(sheet.cells)) {
                const coord = parseA1(a1);
                if (cell.formula !== undefined) session.writeFormula(sheet.id, coord, cell.formula);
                else if (cell.number !== undefined) session.writeLiteral(sheet.id, coord, {number: cell.number});
                else if (cell.text !== undefined) session.writeLiteral(sheet.id, coord, {text: cell.text});
                if (cell.numberFormat !== undefined) session.setNumberFormat(sheet.id, coord, cell.numberFormat);
            }
        }
        await session.settle(DEFAULT_LIMITS.calculation_timeout_ms);
        return {format_version: 2, profile: PROFILE, native: session.save(), tables};
    } finally {
        session.dispose();
    }
}

interface SeedRow {row_id: string; [column: string]: string | number | null}
interface SeedDetails {resource_id: string; sheet_id: string; table_id: string; columns_by_address: {[letter: string]: string}; first_data_row: number; rows: SeedRow[]}
interface CatalogTable {sheet_id: string; sheet_title: string; table_id: string; columns: {column_id: string; title: string}[]}
interface Scenario {
    id: string;
    events: {type: string; details: SeedDetails}[];
    steps: {response: {result?: {data?: {kind?: string; tables?: CatalogTable[]}}}}[];
}

async function office(path: string, scenarioId: string): Promise<WorkbookV2> {
    const corpus = JSON.parse(readFileSync(path, 'utf8')) as {scenarios: Scenario[]};
    const scenario = corpus.scenarios.find(candidate => candidate.id === scenarioId);
    if (scenario === undefined) throw new Error(`scenario ${scenarioId} not found`);
    const seed = scenario.events.find(event => event.type === 'seed_table')?.details;
    const catalog = scenario.steps.map(step => step.response.result?.data).find(data => data?.kind === 'sheet_catalog')?.tables?.find(t => t.table_id === seed?.table_id);
    if (seed === undefined || catalog === undefined) throw new Error(`scenario ${scenarioId} has no seed_table/catalog`);
    const cells: {[a1: string]: FixtureCell} = {};
    const columns = Object.entries(seed.columns_by_address).map(([letter, columnId]) => ({letter, columnId, column: parseA1(`${letter}1`).column}));
    for (const {letter, columnId} of columns) {
        const title = catalog.columns.find(column => column.column_id === columnId)?.title;
        if (title === undefined) throw new Error(`no title for ${columnId}`);
        cells[`${letter}${seed.first_data_row - 1}`] = {text: title};
    }
    seed.rows.forEach((row, index) => {
        for (const {letter, columnId} of columns) {
            const value = row[columnId];
            if (typeof value === 'number') cells[`${letter}${seed.first_data_row + index}`] = {number: value};
            else if (value !== null) throw new Error(`unsupported seed value ${String(value)}`);
        }
    });
    const table: TableBinding = {
        table_id: seed.table_id, sheet_id: seed.sheet_id, header_row: seed.first_data_row - 1,
        columns: columns.map(({columnId, column}) => ({column_id: columnId, column})).sort((a, b) => a.column - b.column),
        rows: seed.rows.map((row, index) => ({row_id: row.row_id, row: seed.first_data_row + index})),
    };
    return build(seed.resource_id, [{id: seed.sheet_id, name: catalog.sheet_title, cells}], [table]);
}

const [mode, path, scenarioId] = process.argv.slice(2);
let result: WorkbookV2;
if (mode === 'workbook' && path !== undefined) {
    const fixture = JSON.parse(readFileSync(path, 'utf8')) as {bookId: string; sheets: FixtureSheet[]};
    result = await build(fixture.bookId, fixture.sheets, []);
} else if (mode === 'office' && path !== undefined && scenarioId !== undefined) {
    result = await office(path, scenarioId);
} else {
    throw new Error('usage: seed.ts workbook <workbook.json> | office <office-sdk.json> <scenario_id>');
}
process.stdout.write(`${canonicalize(result)}\n`);
