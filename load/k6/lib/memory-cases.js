// 15-cell memory path matrix (5 paths × 3 sizes).
export const MEMORY_PATHS = [
  { path_id: 'tus_final_patch', op: 'OP-02', ceiling_mib: 64, protocol: 'tus' },
  { path_id: 's3_putpart', op: 'OP-06', ceiling_mib: 160, protocol: 's3' },
  { path_id: 's3_complete_postfix', op: 'OP-07', ceiling_mib: 160, protocol: 's3' },
  { path_id: 'cas_stage_probe', op: 'CAS', ceiling_mib: 64, protocol: 'cli' },
  { path_id: 'download', op: 'OP-08', ceiling_mib: 16, protocol: 'download' },
];

export const MEMORY_SIZES = [
  { size_id: '5m', label: '5MiB', bytes: 5 * 1024 * 1024, contract: true },
  { size_id: '500m', label: '500MiB', bytes: 500 * 1024 * 1024, contract: false },
  { size_id: '5g', label: '5GiB', bytes: 5 * 1024 * 1024 * 1024, contract: false },
];

/**
 * @returns {Array<{cell_id: string, path_id: string, size_id: string, size_bytes: number, contract: boolean, ceiling_mib: number, op: string, protocol: string, dry_run: boolean}>}
 */
function matrixLiveOverride() {
  return (
    (typeof process !== 'undefined' && process.env?.P4_MATRIX_LIVE === '1') ||
    (typeof __ENV !== 'undefined' && __ENV.P4_MATRIX_LIVE === '1')
  );
}

export function memoryMatrixCells() {
  const forceLive = matrixLiveOverride();
  const cells = [];

  for (const path of MEMORY_PATHS) {
    for (const size of MEMORY_SIZES) {
      cells.push({
        cell_id: `${path.path_id}/${size.size_id}`,
        path_id: path.path_id,
        size_id: size.size_id,
        size_bytes: size.bytes,
        contract: size.contract,
        ceiling_mib: path.ceiling_mib,
        op: path.op,
        protocol: path.protocol,
        dry_run: forceLive ? false : !size.contract,
      });
    }
  }

  return cells;
}

/** Execution order: all paths at 5MiB, then 500MiB, then 5GiB. */
export function memoryMatrixExecutionOrder() {
  const byId = Object.fromEntries(memoryMatrixCells().map((cell) => [cell.cell_id, cell]));
  const order = [];

  for (const size of MEMORY_SIZES) {
    for (const path of MEMORY_PATHS) {
      const cell = byId[`${path.path_id}/${size.size_id}`];
      if (cell) {
        order.push(cell);
      }
    }
  }

  return order;
}

export function memoryCellById(cellId) {
  return memoryMatrixCells().find((cell) => cell.cell_id === cellId) || null;
}

export function contractCells() {
  return memoryMatrixCells().filter((cell) => cell.contract);
}
