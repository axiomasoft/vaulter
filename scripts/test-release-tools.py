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
    files = [
        "release/packages.json", ".github/workflows/monorepo-split.yml", ".changeset/config.json",
        "packages/vue/package.json", "packages/vue-collaboration/package.json",
        "packages/collaboration/src/HocuspocusCollaborationDriver.php",
        "packages/documents/src/Types/RichTextDocumentType.php", "packages/documents/src/Types/MarkdownDocumentType.php",
    ]
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
        def static_matrix(tree):
            workflow = tree / ".github/workflows/monorepo-split.yml"
            workflow.write_text(workflow.read_text().replace(
                "package: ${{ fromJSON(needs.route.outputs.matrix) }}", "package:\n          - core\n          - vaulter", 1))

        result = self.mutated(static_matrix)
        self.assertEqual(result.returncode, 1)
        self.assertIn("matrix", result.stderr)

    def edit_manifest(self, tree, change):
        path = tree / "release/packages.json"
        data = json.loads(path.read_text())
        change(data)
        path.write_text(json.dumps(data))

    def test_missing_or_unknown_channel_in_any_entry_is_rejected(self):
        for value in (None, "beta"):
            def change(data, value=value):
                entry = data["images"][0]
                entry.pop("channel") if value is None else entry.update(channel=value)
            result = self.mutated(lambda tree, change=change: self.edit_manifest(tree, change))
            self.assertEqual(result.returncode, 1)
            self.assertIn("channel must be", result.stderr)

    def test_duplicate_path_and_prefix_collision_are_rejected(self):
        result = self.mutated(lambda tree: self.edit_manifest(tree, lambda d: d["php"][1].update(path=d["php"][0]["path"])))
        self.assertIn("duplicate path", result.stderr)
        result = self.mutated(lambda tree: self.edit_manifest(tree, lambda d: d["images"][0].update(tag_prefix="collaboration-v")))
        self.assertEqual(result.returncode, 1)

    def test_public_package_may_not_reach_a_private_one(self):
        for section in ("require", "require-dev", "suggest"):
            result = self.mutated(lambda tree, section=section: self.edit_composer(
                tree, "drive", lambda d: d.setdefault(section, {}).update({"axioma-studio/vaulter-collaboration": "^1.0"})))
            self.assertEqual(result.returncode, 1, section)
            self.assertIn("references private", result.stderr)

    def test_private_floor_must_be_the_tested_range(self):
        result = self.mutated(lambda tree: self.edit_composer(
            tree, "collaboration", lambda d: d["require"].update({"axioma-studio/vaulter-core": "self.version"})))
        self.assertEqual(result.returncode, 1)
        self.assertIn("expected tested range", result.stderr)

    def test_private_npm_floor_must_match_the_tested_range(self):
        def star(tree):
            path = tree / "packages/vue-collaboration/package.json"
            data = json.loads(path.read_text())
            data["peerDependencies"]["@vaulter/vue"] = "*"
            path.write_text(json.dumps(data))

        result = self.mutated(star)
        self.assertEqual(result.returncode, 1)
        self.assertIn("expected tested floor", result.stderr)

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
            "vaulter-core", "vaulter-media", "vaulter-drive", "vaulter-documents", "vaulter-webhooks", "vaulter-filament", "vaulter")))

    def test_private_channel_artifacts_never_land_in_the_public_set(self):
        private = Path(self.directory.name) / "private-out"
        result = php("build-artifacts.php", "--version=0.3.0", f"--out={private}", f"--root={self.repo}", "--channel=private")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(sorted(p.name for p in private.glob("*.zip")), ["axioma-studio-vaulter-collaboration-0.3.0.zip"])
        self.assertFalse((self.out / "axioma-studio-vaulter-collaboration-0.3.0.zip").exists())

    def test_unknown_channel_option_and_unknown_manifest_channel_fail_closed(self):
        self.assertEqual(php("build-artifacts.php", "--version=0.3.0", f"--out={self.out}", f"--root={self.repo}", "--channel=beta").returncode, 2)
        with tempfile.TemporaryDirectory(prefix="vaulter-bad-channel-") as directory:
            tree = Path(directory) / "repo"
            copy_files(["release/packages.json"], tree)
            path = tree / "release/packages.json"
            data = json.loads(path.read_text())
            data["php"][5]["channel"] = "beta"
            path.write_text(json.dumps(data))
            git("init", "-q", "-b", "main", cwd=tree)
            git("add", "-A", cwd=tree)
            git("commit", "-q", "-m", "fixture", cwd=tree)
            result = php("build-artifacts.php", "--version=0.3.0", f"--out={Path(directory) / 'o'}", f"--root={tree}", "--channel=public")
            self.assertEqual(result.returncode, 1)
            self.assertIn("channel must be", result.stderr)

    def test_azgard_is_skipped_while_its_upstream_is_a_dev_require(self):
        self.assertFalse((self.out / "axioma-studio-vaulter-azgard-0.3.0.zip").exists())
        self.assertIn("skip axioma-studio/vaulter-azgard", self.result.stdout)
        self.assertIn("dev-main", self.result.stdout)

    def test_export_ignore_keeps_tests_and_dev_config_out(self):
        for name in ("vaulter-core", "vaulter-media", "vaulter-drive", "vaulter-documents", "vaulter-webhooks", "vaulter-filament"):
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
            if p["split"] != "axioma-studio/vaulter-azgard" and p["channel"] == "public"
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

    def test_matrix_is_computed_by_the_route_job_from_the_manifest(self):
        self.assertEqual(self.workflow["jobs"]["split"]["strategy"]["matrix"]["package"], "${{ fromJSON(needs.route.outputs.matrix) }}")
        self.assertEqual(self.workflow["jobs"]["split"]["needs"], "route")
        self.assertEqual(self.workflow["jobs"]["route"]["needs"], "verify-release-sha")

    def test_verify_tags_runs_after_the_split(self):
        job = self.workflow["jobs"]["verify-tags"]
        self.assertEqual(job["needs"], "split")
        self.assertEqual(job.get("permissions"), {"contents": "read"})
        script = "\n".join(step.get("run", "") for step in job["steps"])
        self.assertIn("scripts/release/verify-split-tags.sh", script)

    def test_no_credential_is_read_before_the_route_job(self):
        text = (ROOT / ".github/workflows/monorepo-split.yml").read_text()
        route = text[text.index("  route:\n"):text.index("  split:\n")]
        self.assertNotIn("GH_ACCESS_TOKEN", route)
        self.assertIn("route-release.php", route)
        for step in self.workflow["jobs"]["split"]["steps"]:
            if "monorepo-split-github-action" in step.get("uses", ""):
                self.assertEqual(step["if"], "steps.plan.outputs.excluded != 'true'")

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


SHA = "a" * 40
OTHER_SHA = "b" * 40


def evidence_for(tag, **override):
    """A fixture qualification document, built from the routed entries of the real manifest (no network)."""
    kinds = {"php": MANIFEST["php"], "npm": MANIFEST["npm"], "images": MANIFEST["images"]}
    for kind, entries in kinds.items():
        for entry in entries:
            if tag.startswith(entry["tag_prefix"]):
                line, channel = entry["release_line"], entry["channel"]
                members = [e for e in entries if e["release_line"] == line and not e.get("excluded_unless_tagged")]
                identity = {
                    "php": {"split_commit": "c" * 40, "content_sha256": "d" * 64},
                    "npm": {"tgz_sha256": "e" * 64, "integrity": "sha512-" + "A" * 86 + "=="},
                    "images": {"digest": "sha256:" + "f" * 64},
                }[kind]
                document = {
                    "schema": "vaulter.release-qualification/v1", "source_sha": SHA, "ci_gate": {"sha": SHA, "conclusion": "success"},
                    "release_line": line, "channel": channel, "artifacts": {m["name"]: dict(identity) for m in members},
                }
                document.update(override)
                return document, members, kind
    raise AssertionError(tag)


def route_release(tag, *args, root=None):
    arguments = [f"--tag={tag}", *args]
    if root is not None:
        arguments.append(f"--root={root}")
    return php("route-release.php", *arguments)


class RouteReleaseTest(unittest.TestCase):
    """Routing + fail-closed D18 qualification, executed before any publisher credential is available."""

    TAGS = {
        "v1.2.3": "php-public", "collaboration-v1.0.2": "php-collaboration", "collaboration-hub-v1.0.2": "image-collaboration-hub",
        "@vaulter/vue@1.2.3": "npm-vue", "@vaulter/vue-collaboration@1.0.2": "npm-vue-collaboration",
    }

    def route(self, tag, override=None, visibility="private", sha=SHA, drop_evidence=False, mutate=None):
        with tempfile.TemporaryDirectory(prefix="vaulter-route-") as directory:
            path = Path(directory)
            document, members, kind = evidence_for(tag, **(override or {}))
            if mutate:
                mutate(document)
            if not drop_evidence:
                (path / "e.json").write_text(json.dumps(document))
            destinations = {(m["split"] if kind == "php" else m["name"]): visibility for m in members}
            (path / "v.json").write_text(json.dumps(destinations if visibility else {}))
            return route_release(tag, f"--sha={sha}", f"--evidence={path / 'e.json'}", f"--visibility={path / 'v.json'}")

    def test_five_independent_release_lines(self):
        lines = {e["release_line"] for kind in ("php", "npm", "images") for e in MANIFEST[kind]}
        self.assertEqual(lines, set(self.TAGS.values()))
        for tag, line in self.TAGS.items():
            with self.subTest(tag=tag):
                result = self.route(tag)
                self.assertEqual(result.returncode, 0, result.stderr)
                routed = json.loads(result.stdout)
                self.assertEqual(routed["line"], line)
                # a patch of one line never carries an entry of another
                members = {e["name"] for kind in ("php", "npm", "images") for e in MANIFEST[kind] if e["release_line"] == line}
                self.assertTrue(set(routed["entries"]) <= members)
        public = json.loads(self.route("v1.2.3").stdout)["entries"]
        self.assertNotIn("axioma-studio/vaulter-collaboration", public)
        self.assertNotIn("axioma-studio/vaulter-azgard", public)

    def test_strict_semver_and_prefix(self):
        for tag in ("v1.2.3-rc.1", "v01.2.3", "v1.2", "collaboration-v1.0.2-beta", "latest", "x1.0.0", "vaulter-collaboration-hub-v1.0.0", "collaboration-hub-v1.0"):
            with self.subTest(tag=tag):
                result = route_release(tag, f"--sha={SHA}", "--evidence=/nonexistent")
                self.assertEqual(result.returncode, 1)
                self.assertIn("REFUSED", result.stderr)
                self.assertNotIn("no qualification evidence", result.stderr)  # refused on the tag, before evidence

    def test_retired_image_prefix_names_itself(self):
        self.assertIn("retired prefix", route_release("vaulter-collaboration-hub-v1.0.0", f"--sha={SHA}", "--evidence=/x").stderr)

    def test_no_evidence_is_not_qualified_and_there_is_no_sha_only_fallback(self):
        result = self.route("v1.2.3", drop_evidence=True)
        self.assertEqual(result.returncode, 1)
        self.assertIn("no qualification evidence", result.stderr)
        self.assertEqual(result.stdout, "")

    def test_evidence_must_bind_this_sha_and_the_ci_gate_of_this_sha(self):
        self.assertIn("source_sha differs", self.route("v1.2.3", sha=OTHER_SHA).stderr)
        for gate in ({"sha": OTHER_SHA, "conclusion": "success"}, {"sha": SHA, "conclusion": "failure"}, {}):
            with self.subTest(gate=gate):
                result = self.route("v1.2.3", override={"ci_gate": gate})
                self.assertEqual(result.returncode, 1)
                self.assertIn("CI gate", result.stderr)

    def test_evidence_must_name_the_routed_line_and_channel(self):
        self.assertIn("release_line/channel", self.route("collaboration-v1.0.2", override={"release_line": "php-public"}).stderr)
        self.assertIn("release_line/channel", self.route("v1.2.3", override={"channel": "private"}).stderr)

    def test_every_routed_entry_needs_an_exact_artifact_identity(self):
        def drop_one(document):
            document["artifacts"].pop("axioma-studio/vaulter-drive")

        result = self.route("v1.2.3", mutate=drop_one)
        self.assertIn("axioma-studio/vaulter-drive: no artifact identity", result.stderr)

        def break_digest(document):
            (next(iter(document["artifacts"].values())))["content_sha256"] = "xyz"

        self.assertIn("content_sha256 is missing or malformed", self.route("v1.2.3", mutate=break_digest).stderr)
        self.assertIn("no artifact identity", self.route("collaboration-hub-v1.0.2", mutate=lambda d: d["artifacts"].popitem()).stderr)
        self.assertEqual(self.route("collaboration-hub-v1.0.2", mutate=lambda d: next(iter(d["artifacts"].values())).update(digest="latest")).returncode, 1)
        self.assertEqual(self.route("@vaulter/vue@1.2.3", mutate=lambda d: next(iter(d["artifacts"].values())).update(integrity="md5-1")).returncode, 1)

    def test_private_destination_must_be_probed_private(self):
        for tag in ("collaboration-v1.0.2", "collaboration-hub-v1.0.2", "@vaulter/vue-collaboration@1.0.2"):
            for state in ("public", None, "internal"):
                with self.subTest(tag=tag, visibility=state):
                    result = self.route(tag, visibility=state)
                    self.assertEqual(result.returncode, 1)
                    self.assertIn("destination visibility", result.stderr)
        self.assertEqual(self.route("collaboration-v1.0.2", visibility="private").returncode, 0)

    def test_one_bad_private_entry_refuses_a_public_route_too(self):
        with tempfile.TemporaryDirectory(prefix="vaulter-route-bad-") as directory:
            tree = Path(directory) / "repo"
            for name, mutate in {
                "unknown channel": lambda d: d["php"][5].update(channel="beta"),
                "missing tag_prefix": lambda d: d["php"][5].pop("tag_prefix"),
                "public prefix on private": lambda d: d["php"][5].update(tag_prefix="v"),
                "missing access": lambda d: d["php"][5].pop("access"),
                "missing private split": lambda d: d["php"][5].pop("split"),
                "missing release_line": lambda d: d["npm"][1].pop("release_line"),
                "image without registry": lambda d: d["images"][0].pop("registry"),
                "duplicate path": lambda d: d["php"][5].update(path="packages/core"),
            }.items():
                with self.subTest(case=name):
                    shutil.rmtree(tree, ignore_errors=True)
                    copy_files(["release/packages.json"], tree)
                    manifest = json.loads((tree / "release/packages.json").read_text())
                    mutate(manifest)
                    (tree / "release/packages.json").write_text(json.dumps(manifest))
                    result = route_release("v1.2.3", f"--sha={SHA}", "--evidence=/nonexistent", root=tree)
                    self.assertEqual(result.returncode, 1)
                    self.assertIn("REFUSED", result.stderr)
                    self.assertNotIn("no qualification evidence", result.stderr)  # refused on the manifest, before evidence

    def test_publishers_read_credentials_only_after_the_route_job(self):
        expectations = {
            "monorepo-split.yml": ("split", "GH_ACCESS_TOKEN"),
            "release-npm.yml": ("release", "NPM_TOKEN"),
            "collaboration-hub-image.yml": ("image", "docker/login-action"),
        }
        for name, (job, secret) in expectations.items():
            workflow = yaml.safe_load((ROOT / ".github/workflows" / name).read_text())
            text = (ROOT / ".github/workflows" / name).read_text()
            with self.subTest(workflow=name):
                needs = workflow["jobs"][job]["needs"]
                self.assertIn("route", needs if isinstance(needs, list) else [needs])
                route = yaml.safe_dump(workflow["jobs"]["route"])
                self.assertNotIn(secret, route)
                self.assertNotIn("NPM_TOKEN", route)
                self.assertIn("route-release.php", route)
                self.assertIn(secret, text)
        npm = yaml.safe_load((ROOT / ".github/workflows/release-npm.yml").read_text())
        self.assertNotIn("NPM_TOKEN", yaml.safe_dump(npm["jobs"]["version-pr"]))


class CompatibilityTuplesTest(unittest.TestCase):
    """D17: the manifest tuples are checked against the runtime constants, not against themselves."""

    def mutated(self, mutate):
        with tempfile.TemporaryDirectory(prefix="vaulter-d17-") as directory:
            tree = release_tree(Path(directory))
            mutate(tree)
            return php("check-versions.php", f"--root={tree}")

    def test_runtime_constants_are_what_the_manifest_declares(self):
        from_runtime = subprocess.run(
            [str(ROOT / "scripts" / "php84"), "php", "-r", "require $argv[1]; echo json_encode(runtimeWireState($argv[2]));", str(RELEASE / "manifest-lib.php"), str(ROOT)],
            text=True, capture_output=True)
        self.assertEqual(from_runtime.returncode, 0, from_runtime.stderr)
        runtime = json.loads(from_runtime.stdout)
        declared = MANIFEST["compatibility"]["collaboration"]
        self.assertEqual(runtime["accepted"], sorted(declared["accepted"], key=lambda r: json.dumps(r, separators=(",", ":"))))
        self.assertEqual({r["document_type"] for r in runtime["produced"]}, {"vaulter/rich-text", "vaulter/markdown"})
        self.assertEqual((runtime["protocol"], runtime["version"]), (declared["protocol"], declared["version"]))

    def test_changed_runtime_format_breaks_the_manifest(self):
        def change_accepted_format(tree):
            driver = tree / "packages/collaboration/src/HocuspocusCollaborationDriver.php"
            driver.write_text(driver.read_text().replace("yjs-update-v1", "yjs-update-v2"))

        result = self.mutated(change_accepted_format)
        self.assertEqual(result.returncode, 1)
        self.assertIn("compatibility.collaboration", result.stderr)

    def test_produced_state_the_driver_does_not_accept_is_rejected(self):
        def produce_new_format(tree):
            doc = tree / "packages/documents/src/Types/MarkdownDocumentType.php"
            doc.write_text(doc.read_text().replace("'yjs-update-v1'", "'yjs-update-v9'"))

        result = self.mutated(produce_new_format)
        self.assertEqual(result.returncode, 1)
        self.assertIn("does not accept", result.stderr)

    def test_manifest_without_or_with_wrong_tuples_is_rejected(self):
        def edit(change):
            def run(tree):
                path = tree / "release/packages.json"
                data = json.loads(path.read_text())
                change(data)
                path.write_text(json.dumps(data))
            return run

        self.assertIn("is missing", self.mutated(edit(lambda d: d.pop("compatibility"))).stderr)
        self.assertEqual(self.mutated(edit(lambda d: d["compatibility"]["collaboration"].update(accepted=[]))).returncode, 1)
        self.assertEqual(self.mutated(edit(lambda d: d["compatibility"]["collaboration"].update(version="yjs/3"))).returncode, 1)

    def test_floor_must_be_satisfied_by_the_version_the_public_line_really_carries(self):
        def too_high(tree):
            path = tree / "release/packages.json"
            data = json.loads(path.read_text())
            data["php"][5]["tested_ranges"]["axioma-studio/vaulter-core"] = "^1.1"
            path.write_text(json.dumps(data))
            composer = tree / "packages/collaboration/composer.json"
            body = json.loads(composer.read_text())
            body["require"]["axioma-studio/vaulter-core"] = "^1.1"
            composer.write_text(json.dumps(body))

        result = self.mutated(too_high)
        self.assertEqual(result.returncode, 1)
        self.assertIn("not satisfied by its branch-alias", result.stderr)

    def test_changeset_default_access_is_restricted(self):
        def public_default(tree):
            path = tree / ".changeset/config.json"
            path.write_text(path.read_text().replace('"restricted"', '"public"'))

        result = self.mutated(public_default)
        self.assertIn("default access must be", result.stderr)
        config = json.loads((ROOT / ".changeset/config.json").read_text())
        self.assertEqual(config["access"], "restricted")
        for entry in MANIFEST["npm"]:
            package = json.loads((ROOT / entry["path"] / "package.json").read_text())
            self.assertEqual(package["publishConfig"]["access"], entry["access"])


class NpmWorkspaceFloorTest(unittest.TestCase):
    """Isolated offline npm workspace: a real `npm install --package-lock-only --offline`, empty cache, no registry."""

    PRIVATE = "@vaulter/vue-collaboration"

    def workspace(self, directory, vue_version):
        root = Path(directory)
        root.mkdir(parents=True)
        (root / "package.json").write_text(json.dumps({"name": "fixture", "private": True, "workspaces": ["packages/*"]}))
        for entry in MANIFEST["npm"]:
            source = json.loads((ROOT / entry["path"] / "package.json").read_text())
            reduced = {"name": source["name"], "version": source["version"]}
            for section in ("peerDependencies", "devDependencies", "dependencies"):
                kept = {k: v for k, v in source.get(section, {}).items() if k.startswith("@vaulter/")}
                if kept:
                    reduced[section] = kept
            if source["name"] == "@vaulter/vue":
                reduced["version"] = vue_version
            target = root / entry["path"]
            target.mkdir(parents=True)
            (target / "package.json").write_text(json.dumps(reduced))
        home = root / "home"
        home.mkdir()
        env = {**os.environ, "HOME": str(home), "npm_config_cache": str(home / "cache"), "npm_config_userconfig": str(home / "npmrc"), "npm_config_update_notifier": "false"}
        return subprocess.run(
            ["npm", "install", "--package-lock-only", "--ignore-scripts", "--no-audit", "--no-fund", "--offline"],
            cwd=root, env=env, text=True, capture_output=True, timeout=120)

    def test_matching_local_version_links_and_zero_version_is_rejected(self):
        floor = MANIFEST["npm"][1]["tested_ranges"]["@vaulter/vue"]
        with tempfile.TemporaryDirectory(prefix="vaulter-npm-fixture-") as directory:
            matching = self.workspace(Path(directory) / "match", "1.0.0")
            self.assertEqual(matching.returncode, 0, matching.stderr)
            lock = json.loads((Path(directory) / "match" / "package-lock.json").read_text())
            self.assertTrue(lock["packages"]["node_modules/@vaulter/vue"].get("link"))
            self.assertEqual(lock["packages"]["node_modules/@vaulter/vue"]["resolved"], "packages/vue")
            self.assertEqual(lock["packages"]["packages/vue"]["version"], "1.0.0")
        with tempfile.TemporaryDirectory(prefix="vaulter-npm-fixture-") as directory:
            rejected = self.workspace(Path(directory) / "zero", "0.0.0")
            self.assertNotEqual(rejected.returncode, 0, f"0.0.0 satisfied {floor}")
            self.assertTrue(
                any(code in rejected.stderr for code in ("ETARGET", "ENOTCACHED", "E404", "offline")),
                rejected.stderr)
            lock = Path(directory) / "zero" / "package-lock.json"
            self.assertFalse(lock.exists() and "node_modules/@vaulter/vue" in lock.read_text())

    def test_actual_workspace_versions_and_lock_agree(self):
        lock = json.loads((ROOT / "package-lock.json").read_text())["packages"]
        for entry in MANIFEST["npm"]:
            package = json.loads((ROOT / entry["path"] / "package.json").read_text())
            with self.subTest(package=entry["name"]):
                self.assertEqual(lock[entry["path"]]["version"], package["version"])
                self.assertEqual(lock["node_modules/" + entry["name"]]["resolved"], entry["path"])
        vue = json.loads((ROOT / "packages/vue/package.json").read_text())["version"]
        self.assertEqual(vue, "1.0.0")
        floor = json.loads((ROOT / "packages/vue-collaboration/package.json").read_text())["peerDependencies"]["@vaulter/vue"]
        self.assertEqual(floor, MANIFEST["npm"][1]["tested_ranges"]["@vaulter/vue"])


class VerifySplitTagsV2Test(VerifySplitTagsTest):
    """The same stub, now routing by channel/prefix of the v2 manifest."""

    def test_private_tag_checks_only_the_private_split(self):
        private = "axioma-studio/vaulter-collaboration"
        result = self.run_verify({private: {"refs/tags/collaboration-v1.0.2": "c0"}}, expected={private: "c0"}, tag="collaboration-v1.0.2")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("1 split repositories carry collaboration-v1.0.2", result.stdout)

    def test_public_tag_never_queries_the_private_split(self):
        result = self.run_verify(self.tags(), expected=self.expected())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn("vaulter-collaboration", result.stdout + result.stderr)

    def test_tag_that_fits_no_prefix_is_refused(self):
        for tag in ("v1.0.0-rc.1", "collaboration-hub-v1.0.0", "1.0.0"):
            with self.subTest(tag=tag):
                result = self.run_verify(self.tags(), tag=tag)
                self.assertEqual(result.returncode, 2)
                self.assertIn("fits no php tag_prefix", result.stderr)

    def test_manifest_without_channel_is_refused(self):
        with tempfile.TemporaryDirectory(prefix="vaulter-verify-v1-") as directory:
            broken = json.loads((ROOT / "release/packages.json").read_text())
            broken["php"][0].pop("channel")
            path = Path(directory) / "m.json"
            path.write_text(json.dumps(broken))
            result = subprocess.run([str(RELEASE / "verify-split-tags.sh"), "v1.0.0"], env={**os.environ, "RELEASE_MANIFEST": str(path)}, text=True, capture_output=True)
            self.assertEqual(result.returncode, 2)
            self.assertIn("valid vaulter.release/v2", result.stderr)



if __name__ == "__main__":
    unittest.main(verbosity=2)
