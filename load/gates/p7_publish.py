"""Local P7 publication: same-filesystem staging and atomic no-replace rename.

Linux renameat2 is required. Unsupported platforms/filesystems fail closed;
there is no check-then-rename or cross-filesystem copy fallback.
"""

import argparse
import ctypes
import json
import os
from pathlib import Path
import re
import sys
import tempfile


def run_identity(value):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,127}', value):
        raise ValueError('unsafe run_id')
    return value


def stage(destination, run_id):
    run_identity(run_id)
    if os.path.lexists(destination):
        raise FileExistsError(f'occupied destination: {destination}')
    destination.parent.mkdir(parents=True, exist_ok=True)
    return tempfile.mkdtemp(prefix='.p7-staging-', dir=destination.parent)


def publish(staging, destination, run_id):
    run_identity(run_id)
    if staging.is_symlink() or not staging.is_dir():
        raise ValueError('staging must be a private directory')
    manifest = json.loads((staging / 'run-manifest.json').read_text())
    if manifest['run_id'] != run_id:
        raise ValueError('staging run_id mismatch')
    libc = ctypes.CDLL(None, use_errno=True)
    rename = libc.renameat2
    rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    rename.restype = ctypes.c_int
    # AT_FDCWD, RENAME_NOREPLACE: even an empty directory or dangling symlink
    # is occupied. Two publishers cannot replace or nest inside the winner.
    if rename(-100, os.fsencode(staging), -100, os.fsencode(destination), 1) != 0:
        code = ctypes.get_errno()
        raise OSError(code, os.strerror(code), str(destination))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('stage', 'publish'))
    parser.add_argument('--destination', required=True, type=Path)
    parser.add_argument('--run-id', required=True)
    parser.add_argument('--staging', type=Path)
    args = parser.parse_args()
    try:
        if args.action == 'stage':
            print(stage(args.destination, args.run_id))
        else:
            if args.staging is None:
                parser.error('--staging required for publish')
            publish(args.staging, args.destination, args.run_id)
    except (OSError, ValueError, KeyError, AttributeError) as error:
        print(f'p7 publication refused: {error}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
