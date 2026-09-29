"""Disposable original/current publisher control; fake matrix run, real verifier."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import tempfile

REPO = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(REPO / 'load/gates'))
import test_p7_publication as controls

parser = argparse.ArgumentParser()
parser.add_argument('--original-ref')
args = parser.parse_args()
case = controls.PublicationTest()
case.setUp()
try:
    if args.original_ref:
        repo = case.root / 'original-repo'
        scripts = repo / 'load/scripts'
        scripts.mkdir(parents=True)
        (repo / 'load/gates').symlink_to(controls.GATES)
        (repo / 'load/env').mkdir()
        (repo / 'load/env/S.env').touch()
        script = scripts / 'p7-chaos.sh'
        script.write_bytes(subprocess.check_output(['git', 'show', args.original_ref + ':load/scripts/p7-chaos.sh'], cwd=REPO))
        controls.SCRIPT = script
    dest = case.root / 'published'
    dest.mkdir()
    (dest / 'historical-raw').write_bytes(b'must be preserved')
    before = controls.fingerprint(dest)
    result = case.invoke('matrix', dest)
    preserved = controls.fingerprint(dest) == before
    print(json.dumps({'source': args.original_ref or 'working-tree', 'publisher_exit': result.returncode,
                      'historical_preserved': preserved, 'stdout': result.stdout, 'stderr': result.stderr}, indent=2))
    if args.original_ref:
        assert result.returncode == 0 and not preserved, 'original defect not reproduced'
    else:
        assert result.returncode != 0 and preserved, 'current publisher violated preservation'
finally:
    case.doCleanups()
