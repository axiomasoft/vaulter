#!/usr/bin/env python3
"""Offline R20 readback. Record current facts; do not launch CI/live or accept risk."""

from __future__ import annotations

import hashlib
import json
import re
import subprocess
from pathlib import Path

REPO = Path(__file__).resolve().parents[3]
LEDGER = REPO / "plans/REMAINING-WORK.md"
DEFERRED = ("14", "16c", "17a", "20", "21", "22", "23", "25", "26", "30", "31", "34")
REPAIR_COMMITS = {
    "R05-overlap": "d042695",
    "R05-capture": "56015c8",
    "R05-live-evidence": "ea77e00",
    "R03-harness": "531393a",
    "R27-bind-limit": "0160ce3",
    "R03-preflight": "15170d5",
    "R04-gates": "326f69b",
    "R21-gc": "c6b43c0",
    "R24-webhook": "6f477ef",
    "R05-B07-02": "9bd90da",
    "R11-B14-01": "a6c6b91",
    "R11-B14-02": "85c0afd",
    "R11-B14-03": "dd23b68",
    "R11-B14-04": "2c1e70f",
    "R11-B14-05": "cf967c7",
    "R11-B14-06": "9c8497a",
}
EVIDENCE = (
    "plans/remaining-evidence/B07-audit.md",
    "plans/remaining-evidence/B14-audit.md",
    "plans/remaining-evidence/R03.md",
    "plans/remaining-evidence/R09.md",
    "plans/remaining-evidence/R10.md",
    "plans/remaining-evidence/R14.md",
    "plans/remaining-evidence/R19.md",
    "plans/remaining-evidence/R21-checks/checks.json",
    "plans/remaining-evidence/R24-checks/checks.json",
    "plans/remaining-evidence/R25-checks/checks.json",
    "plans/remaining-evidence/R11-B14-06-checks/checks.json",
    "plans/VLT-LOAD/slo.md",
    "plans/VLT-LOAD/slo-gates.md",
    "plans/archive/VLT-REMED/handoff.md",
    "plans/archive/VLT-CANON/handoff.md",
    "plans/archive/VLT-REL/handoff.md",
    ".github/workflows/tests.yml",
    ".github/workflows/monorepo-split.yml",
    ".github/workflows/mutation.yml",
    "docs/reference/commands.md",
    "docs/adr/0018-editor-engine-canon.md",
    "packages/core/config/vaulter.php",
)
FINGERPRINT = (
    "plans/VLT-LOAD/slo.md",
    "plans/VLT-LOAD/slo-gates.md",
    ".github/workflows/tests.yml",
    ".github/workflows/monorepo-split.yml",
    ".github/workflows/mutation.yml",
    "docs/reference/commands.md",
    "docs/adr/0018-editor-engine-canon.md",
    "composer.json",
    "plans/archive/VLT-REMED/handoff.md",
    "plans/archive/VLT-CANON/handoff.md",
    "plans/archive/VLT-REL/handoff.md",
    "plans/remaining-evidence/R09.md",
    "plans/remaining-evidence/R10.md",
    "plans/remaining-evidence/R14.md",
    "plans/remaining-evidence/B07-audit.md",
    "plans/remaining-evidence/B14-audit.md",
    "plans/remaining-evidence/R11-B14-06-checks/checks.json",
    "plans/remaining-evidence/R21-checks/checks.json",
    "plans/remaining-evidence/R24-checks/checks.json",
    "plans/remaining-evidence/R25-checks/checks.json",
)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=REPO, text=True).rstrip("\n")


def main() -> int:
    errors: list[str] = []
    ledger = LEDGER.read_text()
    head = git("rev-parse", "HEAD")
    short = git("rev-parse", "--short=7", "HEAD")
    status = git("status", "--porcelain")
    tags = [line for line in git("tag", "-l", "v*").splitlines() if line]
    schedule = subprocess.run(
        ["rg", "-n", r"Schedule::", "packages"],
        cwd=REPO,
        capture_output=True,
        text=True,
    )

    missing = [rel for rel in EVIDENCE if not (REPO / rel).exists()]
    if missing:
        errors.append(f"missing evidence: {missing}")

    for step in DEFERRED:
        pattern = rf"^\| {re.escape(step)} \| ОТЛОЖЕНО \|"
        if not re.search(pattern, ledger, re.M):
            errors.append(f"deferred row {step} is not ОТЛОЖЕНО")

    if "| **35** | **СЛЕДУЮЩИЙ** |" not in ledger and "| **35** | **ГОТОВО** |" not in ledger:
        errors.append("row 35 is not the R20 execution cell")
    if "R20 — текущие факты и owner handoff без стенда" not in ledger:
        errors.append("R20 row text missing")

    tests_yml = (REPO / ".github/workflows/tests.yml").read_text()
    composer = (REPO / "composer.json").read_text()
    if "--min=80" not in tests_yml or '"test:coverage": "vendor/bin/pest --coverage --min=80"' not in composer:
        errors.append("coverage floor is not 80")
    if "remediation/audit-vlt" in tests_yml.split("on:", 1)[1].split("jobs:", 1)[0]:
        errors.append("tests.yml unexpectedly triggers on remediation/audit-vlt")

    commands = (REPO / "docs/reference/commands.md").read_text()
    if "Schedule::command('vaulter:relay-outbox')" not in commands:
        errors.append("host schedule sample missing from commands.md")

    commits = {}
    for label, rev in REPAIR_COMMITS.items():
        try:
            commits[label] = {
                "rev": git("rev-parse", rev),
                "subject": git("log", "-1", "--format=%s", rev),
            }
        except subprocess.CalledProcessError:
            errors.append(f"missing repair commit {label}={rev}")

    if schedule.returncode not in (0, 1):
        errors.append(f"rg Schedule:: failed: {schedule.returncode}")
    elif schedule.stdout.strip():
        errors.append(f"package Schedule:: registrations found: {schedule.stdout.strip()}")

    coverage_xml = (REPO / "coverage.xml").exists()
    clover = list((REPO / "plans").glob("**/coverage*.xml"))

    out = {
        "task": "R20",
        "date": "2026-09-29",
        "head": head,
        "head_short": short,
        "dirty": status.splitlines(),
        "tags": tags,
        "coverage_floor": 80,
        "tests_yml_min": "--min=80",
        "local_coverage_xml": coverage_xml,
        "plan_coverage_xml": [str(p.relative_to(REPO)) for p in clover],
        "package_schedule_registrations": schedule.stdout.splitlines(),
        "deferred_rows": list(DEFERRED),
        "repair_commits": commits,
        "evidence_present": sorted(rel for rel in EVIDENCE if (REPO / rel).exists()),
        "sha256": {rel: sha256(REPO / rel) for rel in FINGERPRINT if (REPO / rel).exists()},
        "errors": errors,
        "live_run": None,
        "ci_dispatched": False,
        "risk_accepted": False,
    }
    print(json.dumps(out, indent=2, ensure_ascii=False))
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
