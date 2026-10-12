#!/usr/bin/env python3
"""Tests for scripts/strip-task-codes.php against scripts/fixtures-strip."""
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FIX = ROOT / "scripts" / "fixtures-strip"
SCRIPT = ROOT / "scripts" / "strip-task-codes.php"
PHP84 = ROOT / "scripts" / "php84"


def run(*args):
    return subprocess.run([str(PHP84), str(SCRIPT), *args], capture_output=True, text=True)


class StripTaskCodesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="strip-")
        self.root = Path(self.tmp) / "tree"
        shutil.copytree(FIX / "in", self.root)

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_check_fails_on_dirty_tree(self):
        r = run("--check", f"--root={self.root}")
        self.assertEqual(r.returncode, 1, r.stdout + r.stderr)
        self.assertIn("Sample.php", r.stdout)

    def test_write_matches_expected_and_keeps_literals(self):
        self.assertEqual(run("--write", f"--root={self.root}").returncode, 0)
        for exp in (FIX / "expected").rglob("*"):
            if exp.is_file():
                got = self.root / exp.relative_to(FIX / "expected")
                self.assertEqual(got.read_text(), exp.read_text(), str(exp))

    def test_check_green_after_write_and_idempotent(self):
        run("--write", f"--root={self.root}")
        self.assertEqual(run("--check", f"--root={self.root}").returncode, 0)
        before = (self.root / "packages/core/src/Sample.php").read_text()
        run("--write", f"--root={self.root}")
        self.assertEqual((self.root / "packages/core/src/Sample.php").read_text(), before)

    def test_requires_mode(self):
        self.assertEqual(run().returncode, 2)


if __name__ == "__main__":
    os.environ["PYTHONDONTWRITEBYTECODE"] = "1"
    unittest.main()
