"""Offline checks of the release verifier; gh is always replaced by a local stub.

Run: PYTHONDONTWRITEBYTECODE=1 python3 scripts/test-release-gate.py
Requires the locally installed PyYAML. Does not validate GitHub API or token access.
"""

import itertools
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[1]


def workflow(name):
    return yaml.safe_load((ROOT / ".github/workflows" / name).read_text())


def check_names(job):
    matrix = job.get("strategy", {}).get("matrix", {})
    cells = matrix.get("include")
    if cells is None:
        cells = [dict(zip(matrix, values)) for values in itertools.product(*matrix.values())]
    return {
        re.sub(r"\$\{\{\s*matrix\.(\w+)\s*\}\}", lambda m: str(cell[m[1]]), job["name"])
        for cell in cells
    }


class ReleaseGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.release = workflow("monorepo-split.yml")
        cls.verifier = cls.release["jobs"]["verify-release-sha"]
        cls.script = cls.verifier["steps"][0]["run"]
        required = re.search(r"required_checks=\((.*?)^\s*\)", cls.script, re.S | re.M)
        cls.required = re.findall(r"'([^']+)'", required[1])
        cls.actual = set()
        for name in ("code-style.yml", "static-analysis.yml", "tests.yml", "frontend.yml"):
            for job in workflow(name)["jobs"].values():
                cls.actual.update(check_names(job))

    def test_required_checks_match_current_ci_matrix(self):
        self.assertEqual(set(self.required), self.actual)
        self.assertEqual(len(self.required), len(self.actual), "duplicate required checks")

    def test_verifier_has_read_permissions_and_gates_split(self):
        permissions = self.verifier.get("permissions", self.release.get("permissions", {}))
        self.assertEqual(permissions, {"contents": "read", "checks": "read"})
        self.assertEqual(self.release["jobs"]["split"]["needs"], "verify-release-sha")

    def test_shell_syntax(self):
        result = subprocess.run(["bash", "-n"], input=self.script, text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_mock_scenarios(self):
        # The stub returns the values AFTER gh --jq. It checks local control flow,
        # not API response parsing, endpoint authorization or the remote CI result.
        stub = '''#!/usr/bin/env python3
import json, os, sys
args = sys.argv[1:]
scenario = os.environ["SCENARIO"]
with open(os.environ["CALL_LOG"], "a") as log:
    log.write(json.dumps(args) + "\\n")
if args[0] != "api":
    sys.exit(90)
if "/compare/" in args[1]:
    if scenario == "compare-error":
        sys.exit(1)
    print({"off-main": "behind", "diverged": "diverged", "identical": "identical"}.get(scenario, "ahead"))
elif args[1:3] == ["--method", "GET"] and args[3].endswith("/check-runs"):
    name = next(a.removeprefix("check_name=") for a in args if a.startswith("check_name="))
    if scenario == "api-error":
        sys.exit(1)
    if name not in json.loads(os.environ["ACTUAL_CHECKS"]):
        print("")
    elif name == json.loads(os.environ["ACTUAL_CHECKS"])[0]:
        print({"missing": "", "pending": "in_progress\\t", "failure": "completed\\tfailure",
               "cancelled": "completed\\tcancelled", "skipped": "completed\\tskipped"}
              .get(scenario, "completed\\tsuccess"))
    else:
        print("completed\\tsuccess")
else:
    sys.exit(91)
'''
        scenarios = {
            "actual-matrix": 0, "identical": 0, "off-main": 1, "diverged": 1,
            "missing": 1, "pending": 1, "failure": 1, "cancelled": 1,
            "skipped": 1, "api-error": 1, "compare-error": 1,
        }
        with tempfile.TemporaryDirectory(prefix="vaulter-release-gate-") as directory:
            path = Path(directory)
            gh = path / "gh"
            gh.write_text(stub)
            gh.chmod(0o755)
            for scenario, expected in scenarios.items():
                with self.subTest(scenario=scenario):
                    log = path / (scenario + ".jsonl")
                    env = {
                        **os.environ, "PATH": directory + os.pathsep + os.environ["PATH"],
                        "GH_TOKEN": "offline-stub", "RELEASE_SHA": "fixture-sha",
                        "REPOSITORY": "fixture/vaulter", "SCENARIO": scenario,
                        "CALL_LOG": str(log), "ACTUAL_CHECKS": json.dumps(sorted(self.actual)),
                    }
                    result = subprocess.run(
                        ["bash", "--noprofile", "--norc", "-e", "-o", "pipefail", "-c", self.script],
                        env=env, text=True, capture_output=True,
                    )
                    print(f"mock {scenario}: exit {result.returncode} (expected {expected})", flush=True)
                    self.assertEqual(result.returncode, expected, result.stderr)
                    if expected == 0:
                        calls = [json.loads(line) for line in log.read_text().splitlines()]
                        names = [arg.removeprefix("check_name=") for call in calls for arg in call
                                 if arg.startswith("check_name=")]
                        self.assertEqual(names, self.required)


if __name__ == "__main__":
    unittest.main(verbosity=2)
