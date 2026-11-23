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
        # The single required check is the `CI gate` job of ci.yml (it needs every lane); job ids are not the contract.
        for job in workflow("ci.yml")["jobs"].values():
            if job.get("name") == "CI gate":
                cls.actual.update(check_names(job))

    def test_required_checks_match_current_ci_matrix(self):
        self.assertEqual(set(self.required), self.actual)
        self.assertEqual(len(self.required), len(self.actual), "duplicate required checks")

    def test_verifier_has_read_permissions_and_gates_split(self):
        permissions = self.verifier.get("permissions", self.release.get("permissions", {}))
        self.assertEqual(permissions, {"contents": "read", "checks": "read"})
        # verify-release-sha gates route, route (routing + qualification) gates split
        self.assertEqual(self.release["jobs"]["route"]["needs"], "verify-release-sha")
        self.assertEqual(self.release["jobs"]["prepare"]["needs"], "route")
        self.assertEqual(self.release["jobs"]["split"]["needs"], ["route", "prepare"])

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


class RouteGateTest(unittest.TestCase):
    """The `route` job of monorepo-split.yml executed for real (its own `run` text, php shim, no network, no secrets):
    same-SHA + artifact-identity evidence is accepted, everything else is refused before the split job can start."""

    SHA = "1" * 40

    @classmethod
    def setUpClass(cls):
        cls.release = workflow("monorepo-split.yml")
        cls.route = cls.release["jobs"]["route"]
        cls.step = next(step for step in cls.route["steps"] if step.get("id") == "route")["run"]

    def run_route(self, tag, evidence=None, visibility=None, sha=None):
        with tempfile.TemporaryDirectory(prefix="vaulter-route-gate-") as directory:
            tree = Path(directory) / "tree"
            (tree / "scripts").mkdir(parents=True)
            subprocess.run(["cp", "-r", str(ROOT / "scripts/release"), str(tree / "scripts/release")], check=True)
            (tree / "release").mkdir()
            (tree / "release/packages.json").write_text((ROOT / "release/packages.json").read_text())
            for entry in json.loads((ROOT / "release/packages.json").read_text())["php"]:
                (tree / entry["path"]).mkdir(parents=True, exist_ok=True)
                (tree / entry["path"] / "composer.json").write_text((ROOT / entry["path"] / "composer.json").read_text())
            (tree / "release-evidence").mkdir()
            if evidence is not None:
                (tree / "release-evidence/php-public.json").write_text(json.dumps(evidence))
            if visibility is not None:
                (tree / "release-evidence/visibility.json").write_text(json.dumps(visibility))
            shim = Path(directory) / "bin"
            shim.mkdir()
            (shim / "php").write_text(f'#!/usr/bin/env bash\nexec "{ROOT}/scripts/php84" php "$@"\n')
            (shim / "php").chmod(0o755)
            output = Path(directory) / "github-output"
            env = {**os.environ, "PATH": f"{shim}{os.pathsep}{os.environ['PATH']}", "GITHUB_REF_NAME": tag,
                   "GITHUB_SHA": sha or self.SHA, "GITHUB_OUTPUT": str(output)}
            result = subprocess.run(["bash", "--noprofile", "--norc", "-e", "-o", "pipefail", "-c", self.step], cwd=tree, env=env, text=True, capture_output=True)
            return result, (output.read_text() if output.exists() else "")

    def evidence(self, line, channel, names, **override):
        document = {
            "schema": "vaulter.release-qualification/v1", "source_sha": self.SHA, "ci_gate": {"sha": self.SHA, "conclusion": "success"},
            "release_line": line, "channel": channel,
            "artifacts": {n: {"split_commit": "c" * 40, "content_sha256": "d" * 64} for n in names},
        }
        document.update(override)
        return document

    def public_names(self):
        manifest = json.loads((ROOT / "release/packages.json").read_text())
        return [p["name"] for p in manifest["php"] if p["channel"] == "public" and not p.get("excluded_unless_tagged")]

    def test_route_runs_between_the_same_sha_gate_and_the_split(self):
        self.assertEqual(self.route["needs"], "verify-release-sha")
        self.assertEqual(self.release["jobs"]["prepare"]["needs"], "route")
        self.assertEqual(self.release["jobs"]["split"]["needs"], ["route", "prepare"])
        self.assertNotIn("GH_ACCESS_TOKEN", yaml.safe_dump(self.route))

    def test_qualified_public_tag_outputs_the_dynamic_matrix(self):
        result, output = self.run_route("v1.0.0", self.evidence("php-public", "public", self.public_names()))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("qualified=true", output)
        matrix = json.loads(next(l for l in output.splitlines() if l.startswith("matrix="))[7:])
        self.assertNotIn("collaboration", matrix)
        self.assertNotIn("azguard", matrix)
        self.assertIn("core", matrix)

    def test_refusals_leave_no_qualified_output(self):
        good = self.evidence("php-public", "public", self.public_names())
        cases = {
            "no evidence": dict(tag="v1.0.0"),
            "evidence of another sha": dict(tag="v1.0.0", evidence=dict(good, source_sha="2" * 40)),
            "green gate of another sha": dict(tag="v1.0.0", evidence=dict(good, ci_gate={"sha": "2" * 40, "conclusion": "success"})),
            "red gate": dict(tag="v1.0.0", evidence=dict(good, ci_gate={"sha": self.SHA, "conclusion": "failure"})),
            "missing artifact identity": dict(tag="v1.0.0", evidence=dict(good, artifacts={})),
            "wrong release line": dict(tag="v1.0.0", evidence=dict(good, release_line="npm-vue")),
            "prerelease tag": dict(tag="v1.0.0-rc.1", evidence=good),
            "unknown prefix": dict(tag="release-1.0.0", evidence=good),
            "retired add-on tag": dict(tag="collaboration-v1.0.0", evidence=good),
        }
        for name, arguments in cases.items():
            with self.subTest(case=name):
                result, output = self.run_route(**arguments)
                self.assertNotEqual(result.returncode, 0, name)
                self.assertNotIn("qualified=true", output)
                self.assertIn("REFUSED", result.stderr)

if __name__ == "__main__":
    unittest.main(verbosity=2)
