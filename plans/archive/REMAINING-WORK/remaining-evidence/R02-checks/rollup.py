#!/usr/bin/env python3
"""Roll up completed R02 raw summaries into one machine table."""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent / "raw"
ROWS = [
    "filament-sqlite",
    "media-sqlite",
    "media-pgsql",
    "documents-pgsql",
    "drive-pgsql",
    "core-sqlite",
    "core-pgsql",
]


def main() -> None:
    out = []
    for name in ROWS:
        summary = ROOT / name / "summary.json"
        failures = ROOT / name / "failures.json"
        row: dict = {"id": name, "state": "missing"}
        if summary.exists():
            s = json.loads(summary.read_text())
            m = s.get("mutations") or {}
            row.update(
                {
                    "state": "complete" if s.get("score_percent_reported") is not None else "no-score",
                    "exit_code": s.get("exit_code"),
                    "package": s.get("package"),
                    "driver": s.get("driver"),
                    "score": s.get("score_percent_reported"),
                    "killed": m.get("tested_killed"),
                    "timeout": m.get("timeout"),
                    "escaped": m.get("untested_escaped"),
                    "total": m.get("total_from_summary"),
                    "duration_s": s.get("duration_seconds_reported"),
                    "cache_ids": s.get("cache_result_ids"),
                    "initial_tests": s.get("initial_tests"),
                }
            )
        if failures.exists():
            row["failures"] = json.loads(failures.read_text())
        union = ROOT / "media-union.json"
        out.append(row)
    result = {"runs": out}
    union = ROOT / "media-union.json"
    if union.exists():
        result["media_union"] = json.loads(union.read_text())
        result["media_union"].pop("escaped", None)
    core_union = ROOT / "core-union.json"
    if core_union.exists():
        result["core_union"] = json.loads(core_union.read_text())
        result["core_union"].pop("escaped", None)
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
