"""Reproduce the producer/reader regression against current or saved source, offline."""

import argparse
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--original-ref')
    args = parser.parse_args()
    repo = Path(__file__).resolve().parents[3]
    gates = repo / 'load/gates'
    env = dict(os.environ, PYTHONDONTWRITEBYTECODE='1')
    command = [sys.executable, '-B', '-m', 'unittest', 'discover', '-s', str(gates),
               '-p', 'test_p7_integrity_contract.py', '-v']
    with tempfile.TemporaryDirectory(prefix='p7-integrity-source-') as directory:
        if args.original_ref:
            gates = Path(directory) / 'load/gates'
            gates.mkdir(parents=True)
            for source in (repo / 'load/gates').glob('*.py'):
                shutil.copyfile(source, gates / source.name)
            original = subprocess.run(['git', 'show', f'{args.original_ref}:load/gates/p7_live_matrix.py'],
                                      cwd=repo, capture_output=True, check=True)
            (gates / 'p7_live_matrix.py').write_bytes(original.stdout)
            command[6] = str(gates)
        return subprocess.run(command, cwd=repo, env=env, check=False).returncode


if __name__ == '__main__':
    raise SystemExit(main())
