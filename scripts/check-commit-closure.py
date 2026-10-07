#!/usr/bin/env python3
"""Fail when a commit's changed PHP sources name a Vaulter class that does not exist in that commit.

A per-item commit must be a self-consistent snapshot: every `use Vaulter\\…;` import and every
`Vaulter\\…::class` reference in a changed `packages/*/src/**/*.php` file has to resolve (through the
packages' PSR-4 maps) to a file present in the same revision. Bisect, revert and cherry-pick depend on it.

    python3 scripts/check-commit-closure.py [--all] <rev> [<rev> ...]

By default only the PHP files the commit changed are checked; `--all` checks every source file in the
revision's tree (what a bisect or revert actually builds).

Exit 0 when every revision is closed, 1 when a class is missing, 2 on usage or git errors.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
USE = re.compile(r"^use\s+(?!function\b|const\b)([^;]+);", re.M)
CLASS_CONST = re.compile(r"\\?(Vaulter(?:\\[A-Za-z_][A-Za-z0-9_]*)+)::class")


def git(*args: str, check: bool = True) -> str:
    result = subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True)
    if check and result.returncode != 0:
        raise SystemExit(f"git {' '.join(args)}: {result.stderr.strip()}")
    return result.stdout


def psr4_map() -> dict[str, str]:
    """Namespace prefix -> repository-relative source directory, from every package composer.json."""
    mapping: dict[str, str] = {}
    for composer in sorted((ROOT / "packages").glob("*/composer.json")):
        data = json.loads(composer.read_text(encoding="utf-8"))
        base = composer.parent.relative_to(ROOT).as_posix()
        for prefix, directory in data.get("autoload", {}).get("psr-4", {}).items():
            for entry in [directory] if isinstance(directory, str) else directory:
                mapping[prefix] = f"{base}/{entry.strip('/')}"
    return mapping


def expand_use(statement: str) -> list[str]:
    statement = re.sub(r"\s+", " ", statement.strip())
    names: list[str] = []
    group = re.match(r"^(.*?)\\\{(.*)\}$", statement)
    if group:
        prefix = group.group(1).lstrip("\\")
        for part in group.group(2).split(","):
            part = part.strip()
            if part and not part.startswith(("function ", "const ")):
                names.append(f"{prefix}\\{re.split(r'\s+as\s+', part)[0]}")
        return names
    for part in statement.split(","):
        names.append(re.split(r"\s+as\s+", part.strip())[0].lstrip("\\"))
    return names


def resolve(name: str, mapping: dict[str, str]) -> str | None:
    best = max((p for p in mapping if name.startswith(p)), key=len, default=None)
    if best is None:
        return None
    return f"{mapping[best]}/{name[len(best):].replace(chr(92), '/')}.php"


def exists(rev: str, path: str) -> bool:
    return subprocess.run(["git", "cat-file", "-e", f"{rev}:{path}"], cwd=ROOT, capture_output=True).returncode == 0


def check(rev: str, mapping: dict[str, str], whole_tree: bool = False) -> list[str]:
    if whole_tree:
        changed = git("ls-tree", "-r", "--name-only", rev, "--", "packages").split("\n")
    else:
        changed = git("diff-tree", "--no-commit-id", "--name-only", "-r", "--diff-filter=AM", rev).split("\n")
    missing: list[str] = []
    for path in filter(None, changed):
        if not re.match(r"^packages/[^/]+/src/.*\.php$", path):
            continue
        source = git("show", f"{rev}:{path}", check=False)
        names = {n for s in USE.findall(source) for n in expand_use(s)} | set(CLASS_CONST.findall(source))
        for name in sorted(n for n in names if n.startswith("Vaulter\\")):
            target = resolve(name, mapping)
            # A name below a mapped namespace must exist as a class file; namespace-only imports
            # (`use Vaulter\Enums\Access;`) have no file and are ignored when the directory exists.
            if target is None or exists(rev, target):
                continue
            directory = target[: -len(".php")]
            if subprocess.run(["git", "ls-tree", "-d", rev, directory], cwd=ROOT, capture_output=True, text=True).stdout.strip():
                continue
            missing.append(f"{rev[:10]} {path} -> {name} (expected {target})")
    return missing


def main(argv: list[str]) -> int:
    whole_tree = "--all" in argv
    revisions = [a for a in argv if a != "--all"]
    if not revisions:
        print(__doc__)
        return 2
    mapping = psr4_map()
    failures = [line for rev in revisions for line in check(rev, mapping, whole_tree)]
    for line in failures:
        print(line)
    print("closed" if not failures else f"{len(failures)} unresolved Vaulter reference(s)")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
