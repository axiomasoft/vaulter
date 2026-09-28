#!/usr/bin/env python3
"""Read-only lab admission for run.sh; never changes host settings.

--facts supplies run-bound L-3/L-4/L-6/L-7 checks and raw evidence (reference-env.md
§5). L-1/L-2/L-3/L-5 are always replaced by current host/container observations.
Identity fields must match the run's summary metadata. Missing facts fail closed.
The supplied L-3 evidence covers host background jobs and reserved cores (the
automatic check covers foreign Docker stacks). The supplied L-6 evidence must declare warm/cold cache; L-7 contains reset commands,
outputs and exits. No profile name, previous run, or bare boolean qualifies a lab.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
from pathlib import Path

from r03_summary import IDENTITY_FIELDS, lab_admission


def source_digest(root):
    names = subprocess.check_output(
        ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', 'packages', 'load'], cwd=root)
    digest = hashlib.sha256()
    for name in sorted(set(names.split(b'\0')) - {b''}):
        file = root / os.fsdecode(name)
        if file.is_file():
            digest.update(name + b'\0' + file.read_bytes() + b'\0')
    return digest.hexdigest()


def observe_host(project, stand, sys_root=Path('/sys'), proc_root=Path('/proc'), run=subprocess.run):
    governors = {str(p): p.read_text().strip() for p in
                 (sys_root / 'devices/system/cpu').glob('cpu[0-9]*/cpufreq/scaling_governor')}
    boost = {}
    for relative in ['devices/system/cpu/intel_pstate/no_turbo', 'devices/system/cpu/cpufreq/boost']:
        file = sys_root / relative
        if file.exists():
            boost[relative] = file.read_text().strip()
    boost_disabled = bool(boost) and all(value == ('1' if name.endswith('no_turbo') else '0')
                                        for name, value in boost.items())
    swaps_file = proc_root / 'swaps'
    swaps = swaps_file.read_text() if swaps_file.exists() else None
    listing = run(['docker', 'ps', '-q'], capture_output=True, text=True)
    inspect = run(['docker', 'inspect', *listing.stdout.split()], capture_output=True, text=True) if listing.stdout.strip() else None
    containers = json.loads(inspect.stdout) if inspect and inspect.returncode == 0 else []
    observed = listing.returncode == 0 and (not listing.stdout.strip() or (inspect and inspect.returncode == 0))
    # Keep only resource facts; docker inspect Config.Env can contain secrets.
    containers = [{
        'Id': row['Id'], 'Config': {'Labels': {key: value for key, value in
            (row.get('Config', {}).get('Labels') or {}).items() if key.startswith('com.docker.compose.')}},
        'HostConfig': {key: row.get('HostConfig', {}).get(key) for key in
                       ['CpusetCpus', 'Memory', 'MemorySwap', 'CpuQuota', 'NanoCpus']},
    } for row in containers]
    foreign = [row['Id'] for row in containers if
               (row.get('Config', {}).get('Labels') or {}).get('com.docker.compose.project') != project]
    # Match actual SUT limits, not merely the declared compose environment.
    sut = [row for row in containers if (row.get('Config', {}).get('Labels') or {}).get(
        'com.docker.compose.service') in {'app', 'postgres', 'pgbouncer', 'minio'} and
        (row.get('Config', {}).get('Labels') or {}).get('com.docker.compose.project') == project]
    swap_limits_ok = len(sut) == 4 and all(row['HostConfig'].get('Memory', 0) > 0
        and row['HostConfig'].get('MemorySwap') == row['HostConfig']['Memory'] for row in sut)
    services = stand.get('services') or {}
    groups = {'app': 'app', 'pgbouncer': 'app', 'postgres': 'pg', 'minio': 'minio', 'k6': 'k6'}
    topology = {}
    assigned = {}
    pinning_ok = True
    for service, group in groups.items():
        spec = services.get(service) or {}
        cpuset = spec.get('cpuset', '')
        ids = set()
        try:
            for part in cpuset.split(','):
                bounds = [int(x) for x in part.split('-')]
                ids.update(range(bounds[0], bounds[-1] + 1))
        except ValueError:
            pinning_ok = False
        if not ids or spec.get('cpu_quota', 0) or spec.get('cpus', 0):
            pinning_ok = False
        for cpu in ids:
            folder = sys_root / f'devices/system/cpu/cpu{cpu}/topology'
            try:
                core = (folder / 'physical_package_id').read_text().strip() + ':' + (folder / 'core_id').read_text().strip()
                topology[str(cpu)] = core
                if core in assigned and assigned[core] != group:
                    pinning_ok = False
                assigned[core] = group
            except OSError:
                pinning_ok = False
        if service != 'k6':
            actual = [row for row in sut if row['Config']['Labels'].get('com.docker.compose.service') == service]
            if len(actual) != 1 or actual[0]['HostConfig'].get('CpusetCpus') != cpuset or actual[0]['HostConfig'].get('CpuQuota', 0) not in (None, 0, -1) or actual[0]['HostConfig'].get('NanoCpus', 0):
                pinning_ok = False
    k6 = services.get('k6') or {}
    generator_swap_ok = k6.get('mem_limit', 0) > 0 and k6.get('mem_limit') == k6.get('memswap_limit')
    return {
        'L-2': {'ok': pinning_ok, 'evidence': {'cpu_topology': topology,
                 'declared_cpusets': {key: (services.get(key) or {}).get('cpuset') for key in groups}}},
        'L-1': {'ok': bool(governors) and all(g == 'performance' for g in governors.values()) and boost_disabled,
                'evidence': {'governors': governors, 'boost': boost}},
        'L-3': {'ok': bool(observed and not foreign),
                'evidence': {'docker_ps_exit': listing.returncode, 'foreign_container_ids': foreign,
                             'containers': containers, 'project': project}},
        'L-5': {'ok': swaps is not None and len(swaps.strip().splitlines()) == 1 and swap_limits_ok and generator_swap_ok,
                'evidence': {'proc_swaps': swaps, 'sut_memory_limits': [
                    {'id': row['Id'], 'memory': row['HostConfig']['Memory'],
                     'memory_swap': row['HostConfig'].get('MemorySwap')} for row in sut],
                             'generator_memory': k6.get('mem_limit'), 'generator_memory_swap': k6.get('memswap_limit')}},
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-digest', action='store_true')
    parser.add_argument('--facts', type=Path)
    parser.add_argument('--project')
    parser.add_argument('--stand', type=Path)
    parser.add_argument('--out', type=Path)
    args = parser.parse_args()
    if args.source_digest:
        print(source_digest(Path(__file__).resolve().parents[2]))
        return 0
    if not args.facts or not args.project or not args.out or not args.stand:
        parser.error('--facts, --project, --stand and --out are required for admission')
    facts = json.loads(args.facts.read_text())
    meta = {field: os.environ.get(field.upper()) for field in IDENTITY_FIELDS}
    supplied = facts.get('checks') or {}
    observed = observe_host(args.project, json.loads(args.stand.read_text()))
    host_jobs = supplied.get('L-3') or {}
    observed['L-3']['ok'] = observed['L-3']['ok'] and host_jobs.get('ok') is True and bool(host_jobs.get('evidence'))
    observed['L-3']['evidence']['host_jobs_and_reserved_cores'] = host_jobs.get('evidence')
    facts['checks'] = {**supplied, **observed}
    report = lab_admission(meta, facts)
    args.out.write_text(json.dumps(facts, indent=2, sort_keys=True) + '\n')
    print(json.dumps({k: v for k, v in report.items() if k != 'facts'}, sort_keys=True))
    return 0 if report['admitted'] else 2


if __name__ == '__main__':
    raise SystemExit(main())
