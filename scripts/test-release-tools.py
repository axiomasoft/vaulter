"""Offline checks of the release tools in scripts/release; git is replaced by a PATH stub where a remote is involved.

Run: PYTHONDONTWRITEBYTECODE=1 python3 scripts/test-release-tools.py
Requires the locally installed PyYAML and PHP 8.4 (scripts/php84). consumer-smoke.sh is only
syntax-checked here: it needs Packagist and runs in the phase run.
"""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

import yaml


ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT / "scripts" / "release"
MANIFEST = json.loads((ROOT / "release" / "packages.json").read_text())
PHP_PACKAGES = [Path(p["path"]).name for p in MANIFEST["php"]]


def php(script, *args, cwd=None):
    return subprocess.run(
        [str(ROOT / "scripts" / "php84"), str(RELEASE / script), *args],
        cwd=cwd, text=True, capture_output=True,
    )


def tracked_and_new(path):
    """Files under path that git would archive after a commit: tracked plus untracked-not-ignored."""
    out = subprocess.run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard", "--", path],
        cwd=ROOT, check=True, capture_output=True,
    ).stdout.decode().split("\0")
    return [f for f in out if f and (ROOT / f).is_file()]


def copy_files(files, target):
    for name in files:
        destination = target / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / name, destination)


def release_tree(target, full_packages=False):
    """A copy of the release inputs: manifest, workflow, composer.json files and the npm client manifest."""
    files = ["release/packages.json", ".github/workflows/monorepo-split.yml", "packages/vue/package.json"]
    for package in PHP_PACKAGES:
        files += tracked_and_new(f"packages/{package}") if full_packages else [f"packages/{package}/composer.json"]
    copy_files(files, target)
    return target


def git(*args, cwd):
    subprocess.run(
        ["git", "-c", "user.name=fixture", "-c", "user.email=fixture@example.test", "-c", "commit.gpgsign=false", *args],
        cwd=cwd, check=True, capture_output=True,
    )


class CheckVersionsTest(unittest.TestCase):
    def test_current_tree_passes(self):
        result = php("check-versions.php")
        self.assertEqual(result.returncode, 0, result.stderr)

    def mutated(self, mutate):
        with tempfile.TemporaryDirectory(prefix="vaulter-check-versions-") as directory:
            tree = release_tree(Path(directory))
            mutate(tree)
            return php("check-versions.php", f"--root={tree}")

    def edit_composer(self, tree, package, change):
        path = tree / "packages" / package / "composer.json"
        data = json.loads(path.read_text())
        change(data)
        path.write_text(json.dumps(data, indent=4))

    def test_dev_require_of_a_sibling_is_rejected(self):
        result = self.mutated(lambda tree: self.edit_composer(
            tree, "media", lambda d: d["require"].update({"axioma-studio/vaulter-core": "dev-main"})))
        self.assertEqual(result.returncode, 1)
        self.assertIn("axioma-studio/vaulter-media", result.stderr)
        self.assertIn("dev-main", result.stderr)

    def test_sibling_must_be_self_version(self):
        result = self.mutated(lambda tree: self.edit_composer(
            tree, "drive", lambda d: d["require"].update({"axioma-studio/vaulter-media": "^0.3"})))
        self.assertEqual(result.returncode, 1)
        self.assertIn('expected "self.version"', result.stderr)

    def test_dev_constraint_on_a_third_party_package_is_rejected(self):
        result = self.mutated(lambda tree: self.edit_composer(
            tree, "documents", lambda d: d["require"].update({"firebase/php-jwt": "@dev"})))
        self.assertEqual(result.returncode, 1)
        self.assertIn("firebase/php-jwt", result.stderr)

    def test_excluded_bridge_may_pin_its_untagged_upstream(self):
        # The shipped azgard composer.json does exactly this and the current tree passes.
        azgard = json.loads((ROOT / "packages/azgard/composer.json").read_text())
        self.assertTrue(azgard["require"]["axiomasoft/azguard"].startswith("dev-"))

    def test_dev_upstream_is_only_exempt_while_the_package_is_actually_excluded(self):
        # azguard is tagged in require, yet a dev constraint remains in require-dev: the package ships, so it must fail.
        def change(d):
            d["require"]["axiomasoft/azguard"] = "^0.2"
            d["require-dev"] = {**d.get("require-dev", {}), "axiomasoft/azguard": "dev-main"}

        result = self.mutated(lambda tree: self.edit_composer(tree, "azgard", change))
        self.assertEqual(result.returncode, 1)
        self.assertIn("require-dev axiomasoft/azguard uses dev constraint", result.stderr)

    def test_matrix_must_match_the_manifest(self):
        def drop_matrix_entry(tree):
            workflow = tree / ".github/workflows/monorepo-split.yml"
            workflow.write_text(workflow.read_text().replace("          - vaulter\n", "", 1))

        result = self.mutated(drop_matrix_entry)
        self.assertEqual(result.returncode, 1)
        self.assertIn("matrix", result.stderr)

    def test_npm_client_must_declare_the_api_line(self):
        def drop_api(tree):
            path = tree / "packages/vue/package.json"
            data = json.loads(path.read_text())
            data.pop("vaulter")
            path.write_text(json.dumps(data))

        result = self.mutated(drop_api)
        self.assertEqual(result.returncode, 1)
        self.assertIn('"api": "1"', result.stderr)


class BuildArtifactsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory(prefix="vaulter-build-artifacts-")
        cls.repo = Path(cls.directory.name) / "repo"
        cls.out = Path(cls.directory.name) / "out"
        release_tree(cls.repo, full_packages=True)
        git("init", "-q", "-b", "main", cwd=cls.repo)
        git("add", "-A", cwd=cls.repo)
        git("commit", "-q", "-m", "fixture", cwd=cls.repo)
        cls.result = php("build-artifacts.php", "--version=0.3.0", f"--out={cls.out}", f"--root={cls.repo}")

    @classmethod
    def tearDownClass(cls):
        cls.directory.cleanup()

    def zip_of(self, name):
        return zipfile.ZipFile(self.out / f"axioma-studio-{name}-0.3.0.zip")

    def test_builds_every_releasable_package(self):
        self.assertEqual(self.result.returncode, 0, self.result.stderr)
        built = sorted(p.name for p in self.out.glob("*.zip"))
        self.assertEqual(built, sorted(f"axioma-studio-{n}-0.3.0.zip" for n in (
            "vaulter-core", "vaulter-media", "vaulter-drive", "vaulter-documents", "vaulter-webhooks", "vaulter-collaboration", "vaulter-filament", "vaulter")))

    def test_azgard_is_skipped_while_its_upstream_is_a_dev_require(self):
        self.assertFalse((self.out / "axioma-studio-vaulter-azgard-0.3.0.zip").exists())
        self.assertIn("skip axioma-studio/vaulter-azgard", self.result.stdout)
        self.assertIn("dev-main", self.result.stdout)

    def test_export_ignore_keeps_tests_and_dev_config_out(self):
        for name in ("vaulter-core", "vaulter-media", "vaulter-drive", "vaulter-documents", "vaulter-webhooks", "vaulter-collaboration", "vaulter-filament"):
            entries = self.zip_of(name).namelist()
            with self.subTest(package=name):
                self.assertIn("composer.json", entries)
                self.assertTrue([e for e in entries if e.startswith("src/")], "src/ must ship")
                for leaked in ("tests/", "phpunit.xml", "phpstan.neon", "pint.json", "rector.php", ".gitattributes", ".claude/", "vendor/"):
                    self.assertFalse([e for e in entries if e.startswith(leaked)], f"{leaked} leaked into {name}")

    def test_versions_are_pinned_in_the_staged_composer_json(self):
        core = json.loads(self.zip_of("vaulter-core").read("composer.json"))
        self.assertEqual(core["version"], "0.3.0")
        self.assertNotIn("repositories", core)
        self.assertNotIn("self.version", json.dumps(core))

        meta = json.loads(self.zip_of("vaulter").read("composer.json"))
        self.assertEqual(meta["type"], "metapackage")
        self.assertEqual(meta["version"], "0.3.0")
        self.assertEqual(meta["require"]["axioma-studio/vaulter-core"], "0.3.0")

    def test_requires_version_and_output_directory(self):
        self.assertEqual(php("build-artifacts.php", "--version=latest", f"--out={self.out}").returncode, 2)
        self.assertEqual(php("build-artifacts.php", "--version=0.3.0").returncode, 2)


class VerifySplitTagsTest(unittest.TestCase):
    STUB = """#!/usr/bin/env python3
import json, os, sys
args = sys.argv[1:]
if args[:1] != ["ls-remote"]:
    sys.exit(90)
remote = args[1]
split = remote.removeprefix(os.environ["SPLIT_REMOTE_BASE"] + "/").removesuffix(".git")
tags = json.loads(os.environ["REMOTE_TAGS"]).get(split)
if tags is None:
    print("fatal: repository not found", file=sys.stderr)
    sys.exit(128)
for ref, sha in tags.items():
    print(f"{sha}\\t{ref}")
"""

    @classmethod
    def setUpClass(cls):
        cls.splits = [
            p["split"] for p in MANIFEST["php"]
            if p["split"] != "axioma-studio/vaulter-azgard"
        ]

    def run_verify(self, remote_tags, expected=None, tag="v0.3.0"):
        with tempfile.TemporaryDirectory(prefix="vaulter-verify-tags-") as directory:
            path = Path(directory)
            stub = path / "git"
            stub.write_text(self.STUB)
            stub.chmod(0o755)
            args = [str(RELEASE / "verify-split-tags.sh"), tag]
            if expected is not None:
                (path / "expected.json").write_text(json.dumps(expected))
                args.append(str(path / "expected.json"))
            env = {
                **os.environ, "PATH": directory + os.pathsep + os.environ["PATH"],
                "SPLIT_REMOTE_BASE": "https://remote.test", "REMOTE_TAGS": json.dumps(remote_tags),
            }
            return subprocess.run(args, env=env, text=True, capture_output=True)

    def tags(self, tag="v0.3.0", **overrides):
        return {split: {f"refs/tags/{tag}": overrides.get(split, f"c{index}")} for index, split in enumerate(self.splits)}

    def expected(self):
        return {split: f"c{index}" for index, split in enumerate(self.splits)}

    def test_all_tags_on_the_expected_commits_pass(self):
        result = self.run_verify(self.tags(), self.expected())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f"{len(self.splits)} split repositories", result.stdout)

    def test_azgard_is_not_queried_while_excluded(self):
        # The stub fails with 128 for an unknown repository, so any query for azgard would surface as an error.
        result = self.run_verify(self.tags(), self.expected())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("skip axioma-studio/vaulter-azgard", result.stdout)

    def test_annotated_tag_is_compared_by_its_peeled_commit(self):
        tags = self.tags()
        tags["axioma-studio/vaulter-core"] = {"refs/tags/v0.3.0": "tagobject", "refs/tags/v0.3.0^{}": "c0"}
        result = self.run_verify(tags, self.expected())
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_missing_tag_fails_and_is_listed(self):
        tags = self.tags()
        tags["axioma-studio/vaulter-drive"] = {}
        result = self.run_verify(tags, self.expected())
        self.assertEqual(result.returncode, 1)
        self.assertIn("axioma-studio/vaulter-drive: tag v0.3.0 is missing", result.stderr)

    def test_tag_on_a_foreign_commit_fails_and_is_listed(self):
        result = self.run_verify(self.tags(**{"axioma-studio/vaulter-media": "deadbeef"}), self.expected())
        self.assertEqual(result.returncode, 1)
        self.assertIn("axioma-studio/vaulter-media: tag v0.3.0 points at deadbeef", result.stderr)

    def test_unreachable_remote_fails(self):
        tags = self.tags()
        del tags["axioma-studio/vaulter-documents"]
        result = self.run_verify(tags, self.expected())
        self.assertEqual(result.returncode, 1)
        self.assertIn("axioma-studio/vaulter-documents", result.stderr)

    def test_expected_commit_is_required_for_every_shipped_split(self):
        expected = self.expected()
        del expected["axioma-studio/vaulter-core"]
        result = self.run_verify(self.tags(), expected)
        self.assertEqual(result.returncode, 1)
        self.assertIn("no expected commit recorded", result.stderr)

    def test_excluded_listing_fails_closed_on_an_unreadable_manifest(self):
        env = {**os.environ, "RELEASE_MANIFEST": "/nonexistent/packages.json"}
        result = subprocess.run([str(RELEASE / "verify-split-tags.sh"), "--excluded"], env=env, text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def test_excluded_listing_fails_closed_on_an_unreadable_composer_json(self):
        with tempfile.TemporaryDirectory(prefix="vaulter-excluded-") as directory:
            manifest = json.loads((ROOT / "release/packages.json").read_text())
            for package in manifest["php"]:
                if package.get("excluded_unless_tagged"):
                    package["path"] = "packages/does-not-exist"
            path = Path(directory) / "packages.json"
            path.write_text(json.dumps(manifest))
            env = {**os.environ, "RELEASE_MANIFEST": str(path)}
            result = subprocess.run([str(RELEASE / "verify-split-tags.sh"), "--excluded"], env=env, text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)

    def test_excluded_listing_names_the_bridge(self):
        result = subprocess.run([str(RELEASE / "verify-split-tags.sh"), "--excluded"], text=True, capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.split(), ["axioma-studio/vaulter-azgard"])


class WorkflowAndShellTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = yaml.safe_load((ROOT / ".github/workflows/monorepo-split.yml").read_text())

    def test_shell_syntax(self):
        for script in sorted(RELEASE.glob("*.sh")):
            with self.subTest(script=script.name):
                result = subprocess.run(["bash", "-n", str(script)], text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)

    def test_matrix_equals_manifest(self):
        matrix = self.workflow["jobs"]["split"]["strategy"]["matrix"]["package"]
        self.assertEqual(sorted(matrix), sorted(PHP_PACKAGES))

    def test_verify_tags_runs_after_the_split(self):
        job = self.workflow["jobs"]["verify-tags"]
        self.assertEqual(job["needs"], "split")
        self.assertEqual(job.get("permissions"), {"contents": "read"})
        script = "\n".join(step.get("run", "") for step in job["steps"])
        self.assertIn("scripts/release/verify-split-tags.sh", script)

    def test_excluded_package_is_not_split(self):
        for step in self.workflow["jobs"]["split"]["steps"]:
            if "monorepo-split-github-action" in step.get("uses", ""):
                self.assertIn("steps.plan.outputs.excluded", step["if"])

    def test_split_repository_names_come_from_the_manifest(self):
        # vaulter-${matrix.package} would turn the metapackage into axioma-studio/vaulter-vaulter.
        text = (ROOT / ".github/workflows/monorepo-split.yml").read_text()
        self.assertNotIn("vaulter-${{ matrix.package }}", text)
        split = self.workflow["jobs"]["split"]["steps"]
        action = next(s for s in split if "monorepo-split-github-action" in s.get("uses", ""))
        self.assertEqual(action["with"]["repository_name"], "${{ steps.plan.outputs.name }}")
        plan = next(s for s in split if s.get("id") == "plan")
        self.assertIn("release/packages.json", plan["run"])

    def test_every_php_package_excludes_tests_from_dist(self):
        for package in PHP_PACKAGES:
            if package == "vaulter":
                continue
            attributes = (ROOT / "packages" / package / ".gitattributes").read_text().split("\n")
            with self.subTest(package=package):
                for line in ("/tests export-ignore", "/phpunit.xml export-ignore", "/pint.json export-ignore", "/rector.php export-ignore", "/.gitattributes export-ignore"):
                    self.assertIn(line, attributes)


if __name__ == "__main__":
    unittest.main(verbosity=2)
