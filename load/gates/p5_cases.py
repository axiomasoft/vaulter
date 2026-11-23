#!/usr/bin/env python3
"""P5.2 case matrix: expected 409 class, OP/SLO labels, and contract cells.

The cell inventory is the P5.1 catalog. This module only classifies it.
Primitive SQL is not an operation and not an SLO. The 1m dirty cell is not
a tier-L claim. HTTP 5xx is never success.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path


def _lane():
    path = Path(__file__).resolve().with_name('p5_concurrency.py')
    spec = importlib.util.spec_from_file_location('p5_concurrency_lane', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


LANE = _lane()

PRODUCT_OP = {
    'upload-tus': ('OP-02', 'SLO-40'),
    'upload-s3': ('OP-06', 'SLO-43'),
    'cas-lock': (None, None),
    'subtree-move': ('OP-20', 'SLO-13'),
    'sweeper-overlap': ('OP-44', None),
}


def classify(cell: dict) -> dict:
    row = {
        'cell_id': cell['cell_id'],
        'http_5xx_success': False,
        'op': None,
        'slo': None,
        'error_budget': 'not-a-product-slo',
        'expected_409': 'not-applicable',
        'progress_owners': None,
        'tier_l_claim': False,
        'expected_statuses': [],
    }
    plane = cell['plane']
    shape = cell['shape']

    if plane == 'primitive-control':
        return row

    if plane == 'dirty-cardinality-probe':
        row['op'] = 'OP-44'
        row['expected_409'] = 'not-applicable'
        row['tier_l_claim'] = False
        return row

    op, slo = PRODUCT_OP[cell['surface']]
    row['op'] = op
    row['slo'] = None if shape == 'live-patch-overlap' else slo
    row['error_budget'] = 'section-0.4-5xx'
    row['expected_409'] = 'not-expected'
    row['expected_statuses'] = [200]

    if plane == 'product-worst-case':
        row['error_budget'] = 'excluded-from-5pct'
        row['expected_409'] = 'not-compared-to-5pct'

    if shape == 'same-resource':
        row['expected_409'] = 'protocol-owner-plus-conflicts'
        row['progress_owners'] = 1
        row['expected_statuses'] = [204, 409]
        row['error_budget'] = 'excluded-from-5pct'
    elif shape == 'different-resource':
        row['expected_409'] = 'budgeted-5pct'
        row['error_budget'] = 'within-5pct'
        row['expected_statuses'] = [204]
    elif shape == 'int4-collision':
        row['expected_409'] = 'not-protocol'
        row['expected_statuses'] = [200]
        row['error_budget'] = 'section-0.4-5xx'
    elif shape == 'live-patch-overlap':
        row['op'] = 'OP-02+OP-44'
        row['expected_409'] = 'not-expected'
        row['expected_statuses'] = [204]
        row['error_budget'] = 'section-0.4-5xx'

    return row


def matrix_signature() -> list[dict]:
    return [classify(cell) for cell in LANE.catalog()]


def contract_cells() -> list[dict]:
    seen = set()
    selected = []
    for cell in LANE.catalog():
        if cell['shape'] == 'dirty-1m' or cell['n'] != 1 or cell['surface'] in seen:
            continue
        seen.add(cell['surface'])
        selected.append(cell)
    return selected
