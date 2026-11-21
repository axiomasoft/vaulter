/**
 * Vue call point. Target: packages/vue/src/api/workbooks.ts, used by
 * packages/vue/src/composables/useWorkbook.ts. VaulterSpreadsheet.vue normalizes a finished
 * cell edit (Univer command after Enter) into one typed command and calls
 * `useWorkbook().submit(command)`, which calls `submitWorkbookCommand` with the ETag of the
 * last accepted version. The browser never computes the authoritative result: it shows the
 * local input as "saving" until the PHP receipt arrives, then reloads the accepted snapshot.
 */
import type {CellReadback, Command, RangeSelector, WorkbookV2} from './wire.ts';

export type FetchLike = (url: string, init?: {method?: string; headers?: Record<string, string>; body?: string}) => Promise<{
    status: number;
    headers: {get(name: string): string | null};
    json(): Promise<unknown>;
}>;

export interface OpenedWorkbook {etag: string; marker: {generation: number; position: number}; workbook: WorkbookV2}

export interface Receipt {
    command_id: string;
    receipt_id: string;
    state: 'committed';
    marker: {generation: number; position: number};
    candidate_sha256: string;
    changed_ranges: RangeSelector[];
    cells: CellReadback[];
    replayed?: true;
}

export type CommandOutcome =
    | {kind: 'committed'; receipt: Receipt; etag: string}
    | {kind: 'conflict'; code: string; status: number}
    | {kind: 'rejected'; code: string; status: number}
    | {kind: 'retry_later'; code: string; status: number};

export interface ConfirmedRange {
    marker: {generation: number; position: number};
    calculated_at_sha256: string;
    ranges: {sheet_id: string; range: string; cells: CellReadback[]}[];
}

export async function openWorkbook(fetchImpl: FetchLike, baseUrl: string, nodeId: string): Promise<OpenedWorkbook> {
    const response = await fetchImpl(`${baseUrl}/workbooks/${encodeURIComponent(nodeId)}`);
    if (response.status !== 200) throw new Error(`open failed with ${response.status}`);
    return (await response.json()) as OpenedWorkbook;
}

/**
 * Write policy of an editor command. Pure cell edits are rebased by the owner onto a newer head when every
 * `expected` cell still holds (another user's edit elsewhere is not a conflict); anything that moves rows or
 * columns requires the exact version the editor showed.
 */
export function policyFor(commands: Command[]): 'rebase_cells' | 'exact_base' {
    return commands.every(c => c.kind === 'set_number' || c.kind === 'set_text' || c.kind === 'set_formula' || c.kind === 'set_values') ? 'rebase_cells' : 'exact_base';
}

/** Sends one user command under the ETag the editor was showing (`If-Match`). */
export async function submitWorkbookCommand(
    fetchImpl: FetchLike, baseUrl: string, nodeId: string,
    input: {commandId: string; ifMatch: string; commands: Command[]},
): Promise<CommandOutcome> {
    const response = await fetchImpl(`${baseUrl}/workbooks/${encodeURIComponent(nodeId)}/commands`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'If-Match': input.ifMatch},
        body: JSON.stringify({command_id: input.commandId, policy: policyFor(input.commands), commands: input.commands}),
    });
    const body = (await response.json()) as {receipt?: Receipt; error?: {code: string}};
    if (response.status === 200 && body.receipt !== undefined) {
        return {kind: 'committed', receipt: body.receipt, etag: response.headers.get('ETag') ?? ''};
    }
    const code = body.error?.code ?? 'unknown';
    if (response.status === 409) return {kind: 'conflict', code, status: response.status};
    if (response.status >= 500) return {kind: 'retry_later', code, status: response.status};
    return {kind: 'rejected', code, status: response.status};
}

/** After a lost response the composable asks for the stored outcome of the same command_id. */
export async function commandStatus(fetchImpl: FetchLike, baseUrl: string, nodeId: string, commandId: string): Promise<Receipt | null> {
    const response = await fetchImpl(`${baseUrl}/workbooks/${encodeURIComponent(nodeId)}/commands/${encodeURIComponent(commandId)}`);
    if (response.status === 404) return null;
    return ((await response.json()) as {receipt: Receipt}).receipt;
}

export async function readConfirmedRange(fetchImpl: FetchLike, baseUrl: string, nodeId: string, selector: RangeSelector): Promise<ConfirmedRange> {
    const query = new URLSearchParams({sheet_id: selector.sheet_id, range: selector.range});
    const response = await fetchImpl(`${baseUrl}/workbooks/${encodeURIComponent(nodeId)}/ranges?${query.toString()}`);
    if (response.status !== 200) throw new Error(`range read failed with ${response.status}`);
    return (await response.json()) as ConfirmedRange;
}
