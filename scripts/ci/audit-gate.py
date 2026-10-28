#!/usr/bin/env python3
"""Fail-closed Composer/npm advisory gate. Approval digest is external authority, never report data."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import sys

SEVERITIES = {'info', 'low', 'moderate', 'medium', 'high', 'critical'}
BLOCKING = {'high', 'critical'}


def read(path):
    value = json.loads(Path(path).read_text())
    if not isinstance(value, dict) or 'error' in value or 'errors' in value:
        raise ValueError('invalid/error audit report')
    return value


def severity(value):
    if value not in SEVERITIES:
        raise ValueError('unknown severity: ' + str(value))
    return value


def composer_findings(doc, status):
    # Composer 2.x JSON audit protocol: advisories by package, abandoned separately.
    if not isinstance(doc.get('advisories'), (dict, list)) or not isinstance(doc.get('abandoned'), (dict, list)):
        raise ValueError('missing Composer advisories/abandoned schema')
    if set(doc) - {'advisories', 'abandoned'}:
        raise ValueError('unsupported Composer report category')
    if doc['abandoned']:
        raise ValueError('abandoned packages require explicit owner disposition')
    advisories = doc['advisories']
    if isinstance(advisories, list):
        if advisories: raise ValueError('invalid Composer advisory mapping')
        advisories = {}
    rows = []
    for package, entries in advisories.items():
        if isinstance(entries, dict): entries = list(entries.values())
        if not isinstance(entries, list) or not entries:
            raise ValueError('invalid Composer advisory entries')
        for entry in entries:
            if not isinstance(entry, dict) or entry.get('packageName') != package:
                raise ValueError('invalid Composer advisory package')
            ident = entry.get('advisoryId') or entry.get('cve')
            if not isinstance(ident, str) or not ident.strip(): raise ValueError('missing Composer advisory id')
            rows.append({'ecosystem': 'composer', 'package': package, 'id': ident, 'severity': severity(entry.get('severity'))})
    # For the captured Composer protocol, 1 means security findings. Other nonzero statuses are tooling/policy errors.
    if status not in (0, 1) or (status == 0 and rows) or (status == 1 and not rows):
        raise ValueError('incoherent Composer report/exit')
    return rows


def npm_findings(doc, status):
    if type(doc.get('auditReportVersion')) is not int or doc['auditReportVersion'] != 2 or not isinstance(doc.get('vulnerabilities'), dict):
        raise ValueError('unsupported npm audit report schema')
    metadata = doc.get('metadata')
    if not isinstance(metadata, dict) or not isinstance(metadata.get('vulnerabilities'), dict):
        raise ValueError('missing npm metadata')
    counts = metadata['vulnerabilities']
    if set(counts) != {'info', 'low', 'moderate', 'high', 'critical', 'total'} or any(type(v) is not int or v < 0 for v in counts.values()):
        raise ValueError('invalid npm vulnerability counts')
    nodes = doc['vulnerabilities']
    for name, node in nodes.items():
        if not isinstance(node, dict) or node.get('name') != name or not isinstance(node.get('via'), list) or not node['via']:
            raise ValueError('invalid npm vulnerability node')
        severity(node.get('severity'))
    def visit(name, visiting):
        if name not in nodes: raise ValueError('unresolved npm advisory reference')
        if name in visiting: return []
        out = []
        for via in nodes[name]['via']:
            if isinstance(via, str): out += visit(via, visiting | {name})
            elif isinstance(via, dict):
                level = severity(via.get('severity'))
                if not isinstance(via.get('name'), str) or not isinstance(via.get('url'), str): raise ValueError('invalid npm advisory')
                ident = via['url'].rstrip('/').split('/')[-1]
                if not re.fullmatch(r'(?:GHSA-[A-Za-z0-9-]+|CVE-[0-9-]+)', ident): raise ValueError('unknown npm advisory id')
                out.append((ident, level))
            else: raise ValueError('invalid npm via')
        return out
    rows = []
    for name, node in nodes.items():
        advisories = visit(name, set())
        if not advisories: raise ValueError('npm advisory cycle has no resolvable advisory')
        for ident, level in sorted(set(advisories)):
            # Preserve wrapper severity as well as source advisory severity.
            if node['severity'] in BLOCKING and level != 'critical': level = node['severity']
            rows.append({'ecosystem': 'npm', 'package': name, 'id': ident, 'severity': level})
    actual_counts = {k: sum(n['severity'] == k for n in nodes.values()) for k in ('info','low','moderate','high','critical')}
    if any(counts[k] != v for k,v in actual_counts.items()) or counts['total'] != len(nodes):
        raise ValueError('inconsistent npm report counts')
    high = any(row['severity'] in BLOCKING for row in rows)
    if status not in (0, 1) or (status == 0 and high) or (status == 1 and not high):
        raise ValueError('incoherent npm runtime-high report/exit')
    return rows


def suppressions(path, approved_digest, today):
    data = Path(path).read_bytes()
    entries = json.loads(data)
    if not isinstance(entries, list): raise ValueError('suppression policy must be a list')
    # An empty policy grants no waivers: every finding still reaches the full severity gate.
    # Neither the candidate's empty bytes nor their hash constitute approval authority.
    if not entries:
        return {}
    if not re.fullmatch('[0-9a-f]{64}', approved_digest or '') or hashlib.sha256(data).hexdigest() != approved_digest:
        raise ValueError('nonempty suppression policy lacks matching external approval digest')
    result = {}
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {'id','package','owner','reason','expires'}:
            raise ValueError('invalid suppression fields')
        if any(not isinstance(v,str) or not v.strip() or '*' in v for v in entry.values()): raise ValueError('unbounded/empty suppression')
        if not re.fullmatch(r'(?:GHSA-[A-Za-z0-9-]+|CVE-[0-9-]+|PKSA-[A-Za-z0-9-]+)', entry['id']): raise ValueError('invalid suppression id')
        expiry = datetime.date.fromisoformat(entry['expires'])
        key = (entry['id'],entry['package'])
        if key in result: raise ValueError('duplicate suppression')
        result[key] = dict(entry, expired=expiry <= today)
    return result


def evaluate(composer, composer_exit, npm, npm_exit, policy, today=None):
    today = today or datetime.date.today()
    rows = composer_findings(composer, composer_exit) + npm_findings(npm, npm_exit)
    used, blocked = set(), []
    expired = [entry for entry in policy.values() if entry['expired']]
    for row in rows:
        key = (row['id'],row['package'])
        row['suppressed'] = key in policy and not policy[key]['expired']
        if key in policy: used.add(key)
        if row['severity'] in BLOCKING and not row['suppressed']: blocked.append(row)
    return {'exit': 1 if blocked or expired else 0, 'findings': rows, 'blocking': blocked,
            'policy_mode': 'approved_suppressions' if policy else 'no_suppressions',
            'waiver_authority': 'protected_external_digest' if policy else 'no_waiver_authority',
            'expired_suppressions': expired, 'unused_suppressions': [entry for key,entry in policy.items() if key not in used],
            'profile': 'Root Composer locked (including dev); root npm runtime omit=dev high threshold; independent hub and build-dev not scanned',
            'other_supply_chain_scans': {'secret':'pending','license':'pending','OCI':'pending','build_dev':'pending'}}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('composer-report','npm-report','suppressions'): parser.add_argument('--'+name, required=True)
    for name in ('composer-exit','npm-exit'): parser.add_argument('--'+name, required=True, type=int)
    parser.add_argument('--format', choices=['json'], default='json')
    args = parser.parse_args(argv)
    try:
        policy = suppressions(args.suppressions, os.environ.get('AUDIT_SUPPRESSIONS_SHA256'), datetime.date.today())
        result = evaluate(read(args.composer_report), args.composer_exit, read(args.npm_report), args.npm_exit, policy)
    except (ValueError, OSError, TypeError, KeyError) as error:
        result = {'exit': 2, 'status': 'incomplete', 'error': str(error)}
    print(json.dumps(result, sort_keys=True))
    return result['exit']


if __name__ == '__main__':
    sys.exit(main())
