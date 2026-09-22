// P5.2 product scenarios. Contract mode exercises one lightweight cell per
// surface and does not seed the 1m dirty profile. Live mode waits on an
// explicit barrier URL; it does not use sleep() as synchronization.
import http from 'k6/http';
import exec from 'k6/execution';
import { check } from 'k6';
import {
  actorResource,
  cellById,
  classify,
  contractCells,
  contractMode,
} from '../lib/concurrency-cases.js';

const CONTRACT = contractMode();
const LIGHTWEIGHT = contractCells();

export const options = CONTRACT
  ? {
      scenarios: {
        contract: {
          executor: 'shared-iterations',
          vus: 1,
          iterations: LIGHTWEIGHT.length,
          exec: 'contractCell',
        },
      },
    }
  : {
      scenarios: {
        product: {
          executor: 'per-vu-iterations',
          vus: Number(__ENV.P5_N || '1'),
          iterations: 1,
          maxDuration: '30s',
          exec: 'productCell',
        },
      },
    };

export function contractCell() {
  const cell = LIGHTWEIGHT[exec.scenario.iterationInTest];
  const klass = classify(cell);
  const actor = actorResource(cell, 0);

  check(cell, {
    'lightweight cell is present': (value) => value && value.n === 1 && value.shape !== 'dirty-1m',
  });
  check(klass, {
    '5xx is not success': (value) => value.http_5xx_success === false,
    'primitive sql is not an OP': (value) => cell.plane !== 'primitive-control' || value.op === null,
    'same-resource 409 is outside the 5% budget': (value) =>
      cell.shape !== 'same-resource' || value.error_budget === 'excluded-from-5pct',
    'different-resource 409 stays inside the 5% budget': (value) =>
      cell.shape !== 'different-resource' || value.expected_409 === 'budgeted-5pct',
    'collision is not a SHA-256 collision signal': (value) =>
      cell.shape !== 'int4-collision' || value.expected_409 === 'not-protocol',
    '1m probe is not in the contract set': () => cell.shape !== 'dirty-1m',
  });
  check(actor, {
    'actor zero maps to a resource': (value) => Number.isInteger(value.resource),
  });
}

export function productCell() {
  const cell = cellById(__ENV.P5_CELL || '');

  if (!cell) {
    throw new Error('P5_CELL must name a catalog cell for a live run');
  }

  if (!__ENV.P5_BARRIER_URL) {
    throw new Error('P5_BARRIER_URL is required; sleep is not a barrier');
  }

  const actorIndex = exec.vu.idInTest - 1;
  const mapping = actorResource(cell, actorIndex);
  const klass = classify(cell);

  if (klass.http_5xx_success) {
    throw new Error('5xx must not be classified as success');
  }

  const response = http.post(
    __ENV.P5_BARRIER_URL,
    JSON.stringify({
      cell_id: cell.cell_id,
      actor: actorIndex,
      resource: mapping.resource,
      role: mapping.role,
      n: cell.n,
      plane: cell.plane,
    }),
    {
      headers: { 'Content-Type': 'application/json' },
      tags: { plane: cell.plane, shape: cell.shape },
      timeout: '5s',
    },
  );

  check(response, {
    'barrier accepted': (value) => value.status === 200,
    '5xx is not a passing response': (value) => value.status < 500,
  });
}
