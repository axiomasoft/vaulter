#!/usr/bin/env python3
"""P4.1 — read-only PID + cgroup v2 sampler for the isolated memory lane."""

from __future__ import annotations

import argparse
import json
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any


class SamplerError(RuntimeError):
    def __init__(self, code: str, message: str):
        super().__init__(f'{code}: {message}')
        self.code = code


@dataclass(frozen=True)
class PidIdentity:
    pid: int
    starttime_ticks: int

    def as_dict(self) -> dict[str, int]:
        return {'pid': self.pid, 'starttime_ticks': self.starttime_ticks}


def read_text(path: Path) -> str:
    try:
        return path.read_text(encoding='utf-8', errors='replace').strip()
    except OSError as exc:
        raise SamplerError('READ_FAILED', f'{path}: {exc}') from exc


def parse_int_field(text: str, key: str) -> int | None:
    prefix = f'{key}:'
    for line in text.splitlines():
        if line.startswith(prefix):
            raw = line[len(prefix) :].strip()
            if not raw or raw.lower() == 'max':
                return None
            try:
                return int(raw.split()[0])
            except ValueError:
                return None
    return None


def read_pid_identity(pid: int) -> PidIdentity:
    stat = read_text(Path('/proc') / str(pid) / 'stat')
    close_paren = stat.rfind(')')
    if close_paren == -1:
        raise SamplerError('PID_STAT', f'cannot parse /proc/{pid}/stat')
    rest = stat[close_paren + 2 :].split()
    if len(rest) < 20:
        raise SamplerError('PID_STAT', f'/proc/{pid}/stat too short')
    try:
        starttime = int(rest[19])
    except ValueError as exc:
        raise SamplerError('PID_STAT', f'starttime missing for pid {pid}') from exc
    return PidIdentity(pid=pid, starttime_ticks=starttime)


def read_smaps_rollup(pid: int) -> dict[str, int | None]:
    text = read_text(Path('/proc') / str(pid) / 'smaps_rollup')
    keys = (
        'Rss',
        'Pss',
        'Pss_Anon',
        'Pss_File',
        'Pss_Shmem',
        'Shared_Clean',
        'Shared_Dirty',
        'Private_Clean',
        'Private_Dirty',
        'Anonymous',
        'Swap',
        'SwapPss',
    )
    out: dict[str, int | None] = {}
    for key in keys:
        out[key] = parse_int_field(text, key)
    if out.get('Pss') is None and out.get('Pss_Anon') is None:
        raise SamplerError('SMAPS_ROLLUP', f'/proc/{pid}/smaps_rollup missing PSS fields')
    return out


def read_proc_status(pid: int) -> dict[str, int | None]:
    text = read_text(Path('/proc') / str(pid) / 'status')
    return {
        'VmRSS': parse_int_field(text, 'VmRSS'),
        'VmHWM': parse_int_field(text, 'VmHWM'),
    }


def cgroup_root() -> Path:
    unified = Path('/sys/fs/cgroup')
    if (unified / 'cgroup.controllers').is_file():
        return unified
    raise SamplerError('CGROUP_V1', 'unified cgroup v2 mount missing (cgroup.controllers)')


def detect_cgroup_v1_legacy() -> None:
    if Path('/sys/fs/cgroup/memory/memory.stat').is_file() and not Path(
        '/sys/fs/cgroup/cgroup.controllers'
    ).is_file():
        raise SamplerError('CGROUP_V1', 'legacy cgroup v1 memory controller detected')


def resolve_cgroup_path(pid: int) -> Path:
    detect_cgroup_v1_legacy()
    root = cgroup_root()
    raw = read_text(Path('/proc') / str(pid) / 'cgroup')
    for line in raw.splitlines():
        if not line.strip():
            continue
        parts = line.split(':', 2)
        if len(parts) != 3:
            continue
        _ids, controllers, path = parts
        if controllers == '' or 'memory' in controllers.split(','):
            rel = path.lstrip('/') if path and path != '/' else ''
            return root / rel if rel else root
    raise SamplerError('CGROUP_PATH', f'no memory cgroup for pid {pid}')


def read_cgroup_memory(cg: Path) -> dict[str, Any]:
    if not (cg / 'memory.current').is_file():
        raise SamplerError('CGROUP_V1', f'{cg}: memory.current missing (not cgroup v2 memory?)')

    def read_num(name: str) -> int | None:
        path = cg / name
        if not path.is_file():
            return None
        raw = read_text(path)
        try:
            return int(raw)
        except ValueError:
            return None

    stat: dict[str, int | None] = {}
    stat_path = cg / 'memory.stat'
    if stat_path.is_file():
        for line in read_text(stat_path).splitlines():
            if ' ' not in line:
                continue
            key, value = line.split(None, 1)
            if key in {'anon', 'file', 'kernel', 'kernel_stack', 'pagetables', 'sock', 'shmem'}:
                try:
                    stat[key] = int(value)
                except ValueError:
                    stat[key] = None

    events: dict[str, int | None] = {}
    events_path = cg / 'memory.events'
    if events_path.is_file():
        for line in read_text(events_path).splitlines():
            if ' ' not in line:
                continue
            key, value = line.split(None, 1)
            try:
                events[key] = int(value)
            except ValueError:
                events[key] = None

    return {
        'path': str(cg),
        'current': read_num('memory.current'),
        'peak': read_num('memory.peak'),
        'stat': stat,
        'events': events,
    }


def sample_once(
    *,
    pid: int,
    identity: PidIdentity,
    phase: str,
    php_peak_bytes: int | None,
    monotonic_ns: int | None = None,
) -> dict[str, Any]:
    current = read_pid_identity(pid)
    if current != identity:
        raise SamplerError(
            'PID_REUSE',
            f'pid {pid} starttime changed {identity.starttime_ticks} -> {current.starttime_ticks}',
        )
    return {
        'monotonic_ns': monotonic_ns if monotonic_ns is not None else time.monotonic_ns(),
        'phase': phase,
        'php_peak_bytes': php_peak_bytes,
        'proc': {
            **read_proc_status(pid),
            'smaps_rollup': read_smaps_rollup(pid),
        },
        'cgroup': read_cgroup_memory(resolve_cgroup_path(pid)),
    }


def preflight(*, pid: int | None = None) -> dict[str, Any]:
    detect_cgroup_v1_legacy()
    cgroup_root()
    probe_pid = pid if pid is not None else __import__('os').getpid()
    identity = read_pid_identity(probe_pid)
    read_smaps_rollup(probe_pid)
    read_cgroup_memory(resolve_cgroup_path(probe_pid))
    return {
        'cgroup_mode': 'v2',
        'smaps_rollup': True,
        'pss_fields': True,
        'worker_pid_unique': True,
        'pid_identity': identity.as_dict(),
    }


def cmd_preflight(args: argparse.Namespace) -> int:
    payload = preflight(pid=args.pid)
    print(json.dumps(payload, indent=2, sort_keys=True))
    return 0


def cmd_sample(args: argparse.Namespace) -> int:
    identity = PidIdentity(pid=args.pid, starttime_ticks=args.starttime)
    row = sample_once(
        pid=args.pid,
        identity=identity,
        phase=args.phase,
        php_peak_bytes=args.php_peak,
    )
    print(json.dumps(row, sort_keys=True))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description='P4 memory lane sampler (read-only)')
    sub = parser.add_subparsers(dest='command', required=True)
    pf = sub.add_parser('preflight', help='hard checks for cgroup v2 + smaps_rollup + PSS')
    pf.add_argument('--pid', type=int, default=None)
    pf.set_defaults(func=cmd_preflight)

    sample = sub.add_parser('sample', help='emit one JSON sample line')
    sample.add_argument('--pid', type=int, required=True)
    sample.add_argument('--starttime', type=int, required=True)
    sample.add_argument('--phase', required=True, choices=['before', 'during', 'after'])
    sample.add_argument('--php-peak', type=int, default=None)
    sample.set_defaults(func=cmd_sample)

    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except SamplerError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
