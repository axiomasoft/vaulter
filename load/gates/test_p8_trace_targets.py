#!/usr/bin/env python3
"""Fixture checks for the P8.8 tracing-target reducer.

Ranking fixtures run before any live P3–P7 values are read.
"""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

GATES = Path(__file__).resolve().parent
FIXTURES = GATES / 'fixtures' / 'p8-tracing'
spec = importlib.util.spec_from_file_location('p8_trace_targets', GATES / 'p8_trace_targets.py')
gate = importlib.util.module_from_spec(spec)
sys.modules['p8_trace_targets'] = gate
spec.loader.exec_module(gate)

COMMIT = 'b' * 40


def _write(path: Path, payload) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + '\n', encoding='utf-8')


def _report_files(root: Path) -> dict[str, Path]:
    reports = {}
    for producer, name in (
        ('P3', 'db-profile.md'),
        ('P4', 'memory-profile.md'),
        ('P5', 'concurrency-report.md'),
        ('P6', 'queue-report.md'),
        ('P7', 'chaos-report.md'),
    ):
        path = root / name
        path.write_text(f'# {name}\n', encoding='utf-8')
        reports[producer] = path
    return reports


def _spec(root: Path) -> Path:
    path = root / 'observability-spec.md'
    path.write_text(
        '## 19. other\n\nskip\n\n'
        '## 20. P8.8–P8.11 tracing and deferred-metric contract (`PLAN1.D41`)\n\n'
        'frozen fixture section\n',
        encoding='utf-8',
    )
    return path


def _row(*, evidence_id, target_id, producer, raw, report, finding='L-FIX',
         validity='valid', severity='major', slo_red=False,
         integrity='availability-lifecycle', duration=None, window=None, notes=None):
    row = {
        'evidence_id': evidence_id,
        'target_id': target_id,
        'producer': producer,
        'report': str(report),
        'raw_ids': [str(raw)],
        'validity': validity,
        'severity': severity,
        'slo_red': slo_red,
        'integrity_class': integrity,
        'observed_duration_ns': duration,
        'observation_window_ns': window,
        'finding_id': finding,
    }
    if notes is not None:
        row['notes'] = notes
    return row


def _deferred(*, blob=False, webhook=False, blob_ids=None, webhook_ids=None):
    return {
        'blob_dedup_hit_total': {
            'enabled': blob,
            'producer': 'P5',
            'evidence_ids': blob_ids or ['P5:blob'],
            'reason': 'fixture blob condition',
        },
        'webhook_dlq_total': {
            'enabled': webhook,
            'producer': 'P6',
            'evidence_ids': webhook_ids or ['P6:dlq'],
            'reason': 'fixture dlq condition',
        },
    }


class TraceTargetReducerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.reports = _report_files(self.root)
        self.spec = _spec(self.root)
        self.raw = self.root / 'raw.json'
        self.raw.write_text('{}\n', encoding='utf-8')

    def tearDown(self):
        self.tmp.cleanup()

    def test_archived_raw_and_report_resolve_without_rewriting_frozen_references(self):
        old_raw = Path('plans/VLT-LOAD/artifacts/P3/raw.json')
        old_report = Path('plans/VLT-LOAD/db-profile.md')
        archived = self.root / 'plans/archive/VLT-LOAD'
        _write(archived / 'artifacts/P3/raw.json', {'historical': True})
        (archived / 'db-profile.md').write_text('# Frozen report\n', encoding='utf-8')
        before = {path: path.read_bytes() for path in archived.rglob('*') if path.is_file()}

        for prefix in (Path(), self.root):
            with self.subTest(prefix=prefix):
                row = _row(
                    evidence_id='P3:archived', target_id='tree.read', producer='P3',
                    raw=prefix / old_raw, report=prefix / old_report,
                )
                frozen = json.dumps(row, sort_keys=True)
                gate.validate_row(row, repo=self.root)
                self.assertEqual(json.dumps(row, sort_keys=True), frozen)
                self.assertEqual(gate.resolve_raw_id(str(prefix / old_raw), repo=self.root),
                                 archived / 'artifacts/P3/raw.json')

        self.assertFalse((self.root / 'plans/VLT-LOAD').exists())
        self.assertEqual({path: path.read_bytes() for path in archived.rglob('*') if path.is_file()}, before)

    def test_existing_raw_path_takes_precedence_over_archive(self):
        relative = Path('plans/VLT-LOAD/artifacts/P3/raw.json')
        original = self.root / relative
        archived = self.root / 'plans/archive/VLT-LOAD/artifacts/P3/raw.json'
        _write(original, {'source': 'original'})
        _write(archived, {'source': 'archive'})

        resolved = gate.resolve_raw_id(str(relative), repo=self.root)

        self.assertEqual(resolved, original)
        self.assertEqual(json.loads(resolved.read_text()), {'source': 'original'})

    def test_archive_fallback_still_rejects_missing_raw_and_report(self):
        old_raw = 'plans/VLT-LOAD/artifacts/P3/missing.json'
        old_report = 'plans/VLT-LOAD/missing-report.md'
        row = _row(evidence_id='P3:missing', target_id='tree.read', producer='P3',
                   raw=old_raw, report=old_report)

        with self.assertRaisesRegex(gate.GateError, 'EVIDENCE_UNRESOLVED.*raw id'):
            gate.validate_row(row, repo=self.root)

        _write(self.root / 'plans/archive/VLT-LOAD/artifacts/P3/missing.json', {})
        with self.assertRaisesRegex(gate.GateError, 'EVIDENCE_UNRESOLVED.*report'):
            gate.validate_row(row, repo=self.root)

    def _select(self, rows, deferred=None):
        bundle = {
            'schema_version': gate.EVIDENCE_SCHEMA,
            'rows': rows,
            'deferred_metrics': deferred or _deferred(),
        }
        return gate.select_targets(
            bundle,
            repo=self.root,
            resolve_raw=True,
            source_commit=COMMIT,
            spec_path=self.spec,
            reports=self.reports,
        )

    def test_cross_report_tie_uses_smaller_evidence_id(self):
        rows = [
            _row(
                evidence_id='P6:z-later', target_id='webhook.pipeline', producer='P6',
                raw=self.raw, report=self.reports['P6'], duration=10, window=100,
            ),
            _row(
                evidence_id='P3:a-earlier', target_id='webhook.pipeline', producer='P3',
                raw=self.raw, report=self.reports['P3'], duration=10, window=100,
            ),
            _row(
                evidence_id='P5:blob', target_id='upload.sweep', producer='P5',
                raw=self.raw, report=self.reports['P5'], validity='limitation', notes='placeholder',
            ),
            _row(
                evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                raw=self.raw, report=self.reports['P6'], validity='limitation', notes='placeholder',
            ),
        ]
        manifest = self._select(rows)
        self.assertEqual(manifest['selected'][0]['authoritative_evidence_id'], 'P3:a-earlier')

    def test_rank_order_integrity_then_slo_then_severity_then_ppm_then_id(self):
        rows = [
            _row(
                evidence_id='P5:low-integrity', target_id='upload.sweep', producer='P5',
                raw=self.raw, report=self.reports['P5'], integrity='other',
                severity='blocker', slo_red=True, duration=50, window=100,
            ),
            _row(
                evidence_id='P6:high-integrity', target_id='webhook.pipeline', producer='P6',
                raw=self.raw, report=self.reports['P6'], integrity='integrity-safety',
                severity='observation', slo_red=False, duration=1, window=100,
            ),
            _row(
                evidence_id='P5:blob', target_id='cas.gc', producer='P5',
                raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a',
            ),
            _row(
                evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a',
            ),
        ]
        selected = [row['target_id'] for row in self._select(rows)['selected']]
        self.assertEqual(selected[0], 'webhook.pipeline')
        self.assertEqual(selected[1], 'upload.sweep')

    def test_target_id_tie_break_is_ascending(self):
        common = dict(integrity='other', severity='observation', slo_red=False, duration=0, window=1)
        rows = [
            _row(evidence_id='P5:b', target_id='upload.sweep', producer='P5',
                 raw=self.raw, report=self.reports['P5'], **common),
            _row(evidence_id='P5:a', target_id='cas.gc', producer='P5',
                 raw=self.raw, report=self.reports['P5'], **common),
            _row(evidence_id='P5:blob', target_id='tree.move', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        selected = [row['target_id'] for row in self._select(rows)['selected']]
        self.assertEqual(selected, ['cas.gc', 'upload.sweep'])

    def test_missing_window_is_invalid_not_zero(self):
        rows = [
            _row(
                evidence_id='P5:no-window', target_id='upload.sweep', producer='P5',
                raw=self.raw, report=self.reports['P5'], duration=10, window=None,
            ),
            _row(evidence_id='P5:blob', target_id='cas.gc', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        with self.assertRaises(gate.GateError) as caught:
            self._select(rows)
        self.assertIn('EVIDENCE_INVALID', str(caught.exception))
        self.assertIn('window', str(caught.exception))

    def test_inferred_only_does_not_select(self):
        rows = [
            _row(
                evidence_id='P3:inferred', target_id='tree.read', producer='P3',
                raw=self.raw, report=self.reports['P3'], validity='inferred',
                notes='anchor rejected',
            ),
            _row(evidence_id='P5:blob', target_id='upload.sweep', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        manifest = self._select(rows)
        self.assertEqual(manifest['mode'], 'no-op')
        self.assertEqual(manifest['selected'], [])
        self.assertEqual(manifest['producer_readiness']['mode'], 'empty-by-decision')
        self.assertEqual(manifest['producer_readiness']['owner_decision'], 'D41')

    def test_evidence_invalid_cannot_select(self):
        rows = [
            _row(
                evidence_id='P5:invalid', target_id='upload.sweep', producer='P5',
                raw=self.raw, report=self.reports['P5'], validity='evidence-invalid',
                notes='missing sample',
            ),
            _row(evidence_id='P5:blob', target_id='cas.gc', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        self.assertEqual(self._select(rows)['selected'], [])

    def test_max_six_targets(self):
        ids = [
            ('tree.read', 'P3'),
            ('tree.move', 'P5'),
            ('upload.tus', 'P4'),
            ('upload.s3', 'P4'),
            ('download.stream', 'P4'),
            ('cas.commit', 'P4'),
            ('cas.gc', 'P5'),
            ('upload.sweep', 'P5'),
        ]
        rows = [
            _row(
                evidence_id=f'{producer}:{target_id}',
                target_id=target_id,
                producer=producer,
                raw=self.raw,
                report=self.reports[producer],
                duration=index + 1,
                window=100,
            )
            for index, (target_id, producer) in enumerate(ids)
        ]
        rows.extend([
            _row(evidence_id='P5:blob', target_id='cas.gc', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ])
        selected = [row['target_id'] for row in self._select(rows)['selected']]
        self.assertEqual(len(selected), 6)
        self.assertNotIn('tree.read', selected)
        self.assertEqual(selected[0], 'upload.sweep')

    def test_unknown_target_rejected(self):
        rows = [
            _row(
                evidence_id='P5:x', target_id='not.a.target', producer='P5',
                raw=self.raw, report=self.reports['P5'],
            ),
            _row(evidence_id='P5:blob', target_id='upload.sweep', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        with self.assertRaises(gate.GateError) as caught:
            self._select(rows)
        self.assertIn('TARGET_NOT_ALLOWLISTED', str(caught.exception))

    def test_deferred_true_requires_valid_producer_evidence(self):
        rows = [
            _row(
                evidence_id='P5:blob', target_id='cas.commit', producer='P5',
                raw=self.raw, report=self.reports['P5'],
            ),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        deferred = _deferred(blob=True, blob_ids=['P5:blob'], webhook_ids=['P6:dlq'])
        manifest = self._select(rows, deferred=deferred)
        self.assertTrue(manifest['deferred_metrics']['blob_dedup_hit_total'])
        self.assertFalse(manifest['deferred_metrics']['webhook_dlq_total'])

        deferred_bad = _deferred(blob=True, blob_ids=['P6:dlq'], webhook_ids=['P6:dlq'])
        with self.assertRaises(gate.GateError):
            self._select(rows, deferred=deferred_bad)

    def test_verify_is_byte_stable(self):
        rows = [
            _row(
                evidence_id='P5:sweep', target_id='upload.sweep', producer='P5',
                raw=self.raw, report=self.reports['P5'], slo_red=True,
            ),
            _row(evidence_id='P5:blob', target_id='cas.gc', producer='P5',
                 raw=self.raw, report=self.reports['P5'], validity='limitation', notes='n/a'),
            _row(evidence_id='P6:dlq', target_id='webhook.pipeline', producer='P6',
                 raw=self.raw, report=self.reports['P6'], validity='limitation', notes='n/a'),
        ]
        first = self._select(rows)
        second = gate.verify_manifest(
            first, {
                'schema_version': gate.EVIDENCE_SCHEMA,
                'rows': rows,
                'deferred_metrics': _deferred(),
            },
            repo=self.root,
            spec_path=self.spec,
            reports=self.reports,
        )
        third = gate.verify_manifest(
            json.loads(gate.canonical_json(first)),
            {
                'schema_version': gate.EVIDENCE_SCHEMA,
                'rows': rows,
                'deferred_metrics': _deferred(),
            },
            repo=self.root,
            spec_path=self.spec,
            reports=self.reports,
        )
        self.assertEqual(second['manifest_sha256'], third['manifest_sha256'])
        self.assertEqual(first['mode'], 'nonempty-candidates')


class LiveEvidenceContractTest(unittest.TestCase):
    def test_live_fixture_exists_and_names_all_allowlisted_targets(self):
        path = FIXTURES / 'live-evidence.json'
        self.assertTrue(path.is_file())
        bundle = json.loads(path.read_text(encoding='utf-8'))
        targets = {row['target_id'] for row in bundle['rows']}
        self.assertEqual(targets, set(gate.ALLOWLIST))

    def test_live_reducer_selects_two_allowlisted_targets(self):
        bundle = gate.load_json(FIXTURES / 'live-evidence.json')
        first = gate.select_targets(bundle)
        self.assertEqual(first['mode'], 'nonempty-candidates')
        self.assertEqual(
            [row['target_id'] for row in first['selected']],
            ['webhook.pipeline', 'upload.sweep'],
        )
        self.assertFalse(first['deferred_metrics']['blob_dedup_hit_total'])
        self.assertFalse(first['deferred_metrics']['webhook_dlq_total'])
        second = gate.verify_manifest(first, bundle)
        third = gate.verify_manifest(json.loads(gate.canonical_json(first)), bundle)
        self.assertEqual(second['manifest_sha256'], third['manifest_sha256'])
        self.assertEqual(first['producer_readiness']['consumer_item'], 'P8.9')
        self.assertEqual(first['producer_readiness']['mode'], 'nonempty-candidates')


if __name__ == '__main__':
    unittest.main()
