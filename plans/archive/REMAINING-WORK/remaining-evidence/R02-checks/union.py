#!/usr/bin/env python3
"""Union killed-if-killed-in-either-driver, keyed by Pest mutation ID."""
from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path

KILLED = {"tested", "timeout", "Tested", "Timeout"}


def load(path: Path) -> dict:
    cache = json.loads((path / "cache-results.json").read_text())
    summary = json.loads((path / "summary.json").read_text())
    results = cache.get("results") or {}
    escaped_ids = {row["id"] for row in summary.get("escaped") or []}
    return {
        "summary": summary,
        "results": results,
        "escaped_ids": escaped_ids,
        "escaped_rows": {row["id"]: row for row in summary.get("escaped") or []},
    }


def is_killed(value: str) -> bool:
    return value.lower() in {x.lower() for x in KILLED}


def main() -> None:
    a = load(Path(sys.argv[1]))
    b = load(Path(sys.argv[2]))
    ids = set(a["results"]) | set(b["results"]) | a["escaped_ids"] | b["escaped_ids"]
    killed = set()
    escaped = set()
    only_a = set()
    only_b = set()
    for i in ids:
        ra = a["results"].get(i)
        rb = b["results"].get(i)
        if ra is None and i in a["escaped_ids"]:
            ra = "untested"
        if rb is None and i in b["escaped_ids"]:
            rb = "untested"
        if ra is not None and rb is None:
            only_a.add(i)
        if rb is not None and ra is None:
            only_b.add(i)
        if is_killed(ra or "") or is_killed(rb or ""):
            killed.add(i)
        else:
            escaped.add(i)
    total = len(ids)
    score = (len(killed) / total * 100.0) if total else 0.0
    escaped_rows = []
    for i in sorted(escaped):
        row = a["escaped_rows"].get(i) or b["escaped_rows"].get(i) or {"id": i}
        escaped_rows.append(row)
    out = {
        "drivers": [a["summary"]["driver"], b["summary"]["driver"]],
        "package": a["summary"]["package"],
        "ids": total,
        "killed_union": len(killed),
        "escaped_union": len(escaped),
        "only_first_driver": len(only_a),
        "only_second_driver": len(only_b),
        "score_percent": round(score, 2),
        "first_cache_counts": dict(Counter(a["results"].values())),
        "second_cache_counts": dict(Counter(b["results"].values())),
        "escaped": escaped_rows,
    }
    print(json.dumps(out, indent=2))


if __name__ == "__main__":
    main()
