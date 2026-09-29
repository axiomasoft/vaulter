#!/usr/bin/env python3
"""Parse one Pest mutation log into a machine-readable sidecar."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

LOG_DIR = Path(sys.argv[1])
PKG = sys.argv[2]
DRIVER = sys.argv[3]
EXIT = int(sys.argv[4])
STAMP = sys.argv[5]

text = (LOG_DIR / "pest.log").read_text(errors="replace")
text = re.sub(r"\x1b\[[0-9;]*[A-Za-z]", "", text)
untested_re = re.compile(
    r"UNTESTED\s+(?P<path>\S+)\s+>\s+Line\s+(?P<line>\d+):\s+(?P<mutator>\S+)\s+-\s+ID:\s+(?P<id>\S+)"
)
uncovered_re = re.compile(
    r"UNCOVERED\s+(?P<path>\S+)\s+>\s+Line\s+(?P<line>\d+):\s+(?P<mutator>\S+)\s+-\s+ID:\s+(?P<id>\S+)"
)
summary_re = re.compile(
    r"Mutations:\s+(?:(?P<untested>\d+) untested,\s*)?(?:(?P<uncovered>\d+) uncovered,\s*)?(?:(?P<pending>\d+) pending,\s*)?(?:(?P<timeout>\d+) timeout,\s*)?(?P<tested>\d+) tested"
)
score_re = re.compile(r"Score:\s+([0-9.]+)%")
duration_re = re.compile(r"Duration:\s+([0-9.]+)s")
tests_re = re.compile(r"Tests:\s+(?:(?P<failed>\d+) failed,\s*)?(?:(?P<skipped>\d+) skipped,\s*)?(?P<passed>\d+) passed")

untested = [m.groupdict() for m in untested_re.finditer(text)]
uncovered = [m.groupdict() for m in uncovered_re.finditer(text)]
for row in untested:
    row["line"] = int(row["line"])
for row in uncovered:
    row["line"] = int(row["line"])

summary_m = list(summary_re.finditer(text))
score_m = list(score_re.finditer(text))
duration_m = list(duration_re.finditer(text))
tests_m = list(tests_re.finditer(text))

summary = summary_m[-1].groupdict() if summary_m else {}
for key in ("untested", "uncovered", "pending", "timeout", "tested"):
    summary[key] = int(summary[key]) if summary.get(key) else 0

tested = summary["tested"]
timeout = summary["timeout"]
escaped = summary["untested"]
uncovered_n = summary["uncovered"]
pending = summary["pending"]
total = tested + timeout + escaped + uncovered_n + pending
score_reported = float(score_m[-1].group(1)) if score_m else None
score_recomputed = ((tested + timeout) / total * 100.0) if total else None

cache = {}
cache_path = LOG_DIR / "cache-results.json"
if cache_path.exists():
    cache = json.loads(cache_path.read_text())

cache_results = cache.get("results") or {}
from collections import Counter

cache_counts = Counter(cache_results.values())

out = {
    "package": PKG,
    "driver": DRIVER,
    "stamp": STAMP,
    "exit_code": EXIT,
    "parallel": False,
    "min_flag": None,
    "initial_tests": tests_m[0].groupdict() if tests_m else None,
    "mutations": {
        "tested_killed": tested,
        "timeout": timeout,
        "untested_escaped": escaped,
        "uncovered": uncovered_n,
        "pending": pending,
        "total_from_summary": total,
    },
    "score_percent_reported": score_reported,
    "score_percent_recomputed": None if score_recomputed is None else round(score_recomputed, 2),
    "duration_seconds_reported": float(duration_m[-1].group(1)) if duration_m else None,
    "escaped": untested,
    "uncovered_rows": uncovered,
    "cache_result_counts": dict(cache_counts),
    "cache_result_ids": len(cache_results),
    "log": str(LOG_DIR / "pest.log"),
}

(LOG_DIR / "summary.json").write_text(json.dumps(out, indent=2) + "\n")
print(json.dumps({k: out[k] for k in ("package", "driver", "exit_code", "mutations", "score_percent_reported", "score_percent_recomputed", "cache_result_ids")}, indent=2))
