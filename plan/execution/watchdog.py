#!/usr/bin/env python3
"""Deterministic state manager & context-hygiene watchdog for the 5-repo Vaulter build.

Zero LLM tokens. Keeps .vaulter-orch/state.json compact (< 4 KB), enforces:
- Executor != Reviewer (different models/providers)
- Hard Cap = 2 review cycles (auto-escalate on round > 2)
- Brief size <= 24 KB & diff size <= 15 files warnings
- Wave checkpoint / compact trigger so the thin orchestrator never bloats.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import pathlib
import subprocess
import sys
from typing import Any

PACKAGES_ROOT = pathlib.Path("/home/vostrikov/projects/packages")
VAULTER_ROOT = PACKAGES_ROOT / "vaulter"
ORCH_DIR = VAULTER_ROOT / ".vaulter-orch"
STATE_FILE = ORCH_DIR / "state.json"
ARTIFACTS_FILE = ORCH_DIR / "artifacts.json"
POLISH_FILE = VAULTER_ROOT / "plan" / "polish-backlog.md"

REPOS = {
    "vaulter": PACKAGES_ROOT / "vaulter",
    "vaulter-collaboration": PACKAGES_ROOT / "vaulter-collaboration",
    "vaulter-sheets": PACKAGES_ROOT / "vaulter-sheets",
    "vaulter-ai": PACKAGES_ROOT / "vaulter-ai",
    "vaulter-editor-pro": PACKAGES_ROOT / "vaulter-editor-pro",
}

# Canonical task DAG synchronized with plan/execution/models-and-orchestration.md
TASKS: list[dict[str, Any]] = [
    {"id": "W0-Scaffold", "wave": "W0", "track": "MVP-1", "repo": "4-new-repos", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": [], "phase": None, "dev_plan": None},
    {"id": "0.1", "wave": "W0", "track": "MVP-1", "repo": "vaulter", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": [], "phase": "00-baseline.md", "dev_plan": "vaulter.md"},
    {"id": "0.2", "wave": "W0", "track": "MVP-1", "repo": "vaulter", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": [], "phase": "00-baseline.md", "dev_plan": "vaulter.md"},
    {"id": "0.3", "wave": "W0.5", "track": "MVP-1", "repo": "vaulter", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["0.1", "0.2"], "phase": "00-baseline.md", "dev_plan": "vaulter.md"},
    {"id": "1.0", "wave": "W1", "track": "MVP-1", "repo": "vaulter", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["0.3"], "phase": "01-documents.md", "dev_plan": "vaulter.md"},
    {"id": "1.1", "wave": "W1", "track": "MVP-1", "repo": "vaulter-sheets", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["W0-Scaffold", "0.1"], "phase": "01-documents.md", "dev_plan": "vaulter-sheets.md"},
    {"id": "2.0", "wave": "W1", "track": "MVP-1", "repo": "vaulter-collaboration", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["W0-Scaffold", "0.3"], "phase": "02-collaboration.md", "dev_plan": "vaulter-collaboration.md"},
    {"id": "4.1", "wave": "W1", "track": "MVP-1", "repo": "vaulter-sheets", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": ["W0-Scaffold", "0.2"], "phase": "04-sheets.md", "dev_plan": "vaulter-sheets.md"},
    {"id": "1.2", "wave": "W2", "track": "MVP-1", "repo": "vaulter", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["1.0", "1.1"], "phase": "01-documents.md", "dev_plan": "vaulter.md"},
    {"id": "2.1", "wave": "W2", "track": "MVP-1", "repo": "vaulter-collaboration", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": ["2.0"], "phase": "02-collaboration.md", "dev_plan": "vaulter-collaboration.md"},
    {"id": "3.1", "wave": "W2", "track": "MVP-1", "repo": "vaulter", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["1.0", "2.0"], "phase": "03-text-sdk.md", "dev_plan": "vaulter.md"},
    {"id": "Port-ADM", "wave": "W3", "track": "MVP-1", "repo": "vaulter", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["1.0", "3.1"], "phase": "03-text-sdk.md", "dev_plan": "vaulter.md"},
    {"id": "1.3", "wave": "W3", "track": "MVP-1", "repo": "vaulter", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": ["1.2"], "phase": "01-documents.md", "dev_plan": "vaulter.md"},
    {"id": "2.2", "wave": "W3", "track": "MVP-1", "repo": "vaulter-collaboration", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["1.0", "2.1"], "phase": "02-collaboration.md", "dev_plan": "vaulter-collaboration.md"},
    {"id": "3.2", "wave": "W3", "track": "MVP-1", "repo": "vaulter-ai", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["W0-Scaffold", "0.2", "3.1"], "phase": "03-text-sdk.md", "dev_plan": "vaulter-ai.md"},
    {"id": "4.2", "wave": "W3", "track": "MVP-1", "repo": "vaulter-sheets", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["Port-ADM", "1.1", "4.1"], "phase": "04-sheets.md", "dev_plan": "vaulter-sheets.md"},
    {"id": "8.1", "wave": "W3", "track": "MVP-2", "repo": "vaulter-editor-pro", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["W0-Scaffold", "1.0", "3.1"], "phase": "08-editor-pro-review.md", "dev_plan": "vaulter-editor-pro.md"},
    {"id": "10.1", "wave": "W3", "track": "MVP-2", "repo": "vaulter-editor-pro", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["W0-Scaffold", "1.0", "3.1"], "phase": "10-docx-exchange.md", "dev_plan": "vaulter-editor-pro.md"},
    {"id": "3.3", "wave": "W4", "track": "MVP-1", "repo": "vaulter-ai", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["Port-ADM", "3.2"], "phase": "03-text-sdk.md", "dev_plan": "vaulter-ai.md"},
    {"id": "4.3", "wave": "W4", "track": "MVP-2", "repo": "vaulter-sheets", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["4.2"], "phase": "04-sheets.md", "dev_plan": "vaulter-sheets.md"},
    {"id": "6.1", "wave": "W4", "track": "MVP-2", "repo": "vaulter-sheets", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["2.2", "4.2"], "phase": "06-extensions.md", "dev_plan": "vaulter-sheets.md"},
    {"id": "6.2", "wave": "W4", "track": "MVP-2", "repo": "vaulter-sheets", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": ["4.2"], "phase": "06-extensions.md", "dev_plan": "vaulter-sheets.md"},
    {"id": "3.4", "wave": "W5", "track": "MVP-1", "repo": "vaulter-ai", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["3.3"], "phase": "03-text-sdk.md", "dev_plan": "vaulter-ai.md"},
    {"id": "3.5", "wave": "W5", "track": "MVP-1", "repo": "vaulter-collaboration", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["2.2", "3.3"], "phase": "03-text-sdk.md", "dev_plan": "vaulter-collaboration.md"},
    {"id": "5.1", "wave": "W5", "track": "MVP-1", "repo": "vaulter-ai", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["3.3", "4.2"], "phase": "05-sheet-sdk.md", "dev_plan": "vaulter-ai.md"},
    {"id": "8.2", "wave": "W5", "track": "MVP-2", "repo": "vaulter-editor-pro", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["3.3", "8.1"], "phase": "08-editor-pro-review.md", "dev_plan": "vaulter-editor-pro.md"},
    {"id": "10.2", "wave": "W5", "track": "MVP-2", "repo": "vaulter-editor-pro", "executor": "neon", "reviewer": "astra", "contour": "run_workflow", "deps": ["3.3", "10.1"], "phase": "10-docx-exchange.md", "dev_plan": "vaulter-editor-pro.md"},
    {"id": "5.2", "wave": "W6", "track": "MVP-1", "repo": "vaulter-ai", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": ["3.4", "5.1"], "phase": "05-sheet-sdk.md", "dev_plan": "vaulter-ai.md"},
    {"id": "7.1", "wave": "W6", "track": "MVP-1", "repo": "vaulter-ai", "executor": "neon", "reviewer": "astra", "contour": "run_workflow", "deps": ["3.4", "5.1"], "phase": "07-agent-workspace.md", "dev_plan": "vaulter-ai.md"},
    {"id": "8.3", "wave": "W6", "track": "MVP-2", "repo": "vaulter-editor-pro", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["3.5", "8.2"], "phase": "08-editor-pro-review.md", "dev_plan": "vaulter-editor-pro.md"},
    {"id": "7.2", "wave": "W7", "track": "MVP-1", "repo": "vaulter-ai", "executor": "grok", "reviewer": "neon", "contour": "orch.py", "deps": ["7.1"], "phase": "07-agent-workspace.md", "dev_plan": "vaulter-ai.md"},
    {"id": "9.1", "wave": "W7", "track": "MVP-1", "repo": "vaulter-ai", "executor": "sol", "reviewer": "astra", "contour": "orch.py", "deps": ["3.3", "7.1"], "phase": "09-ai-chat.md", "dev_plan": "vaulter-ai.md"},
    {"id": "9.2", "wave": "W7", "track": "MVP-1", "repo": "vaulter-ai", "executor": "neon", "reviewer": "sol", "contour": "run_workflow", "deps": ["3.4", "7.2", "9.1"], "phase": "09-ai-chat.md", "dev_plan": "vaulter-ai.md"},
]

TASK_INDEX = {t["id"]: t for t in TASKS}
WAVE_ORDER = ["W0", "W0.5", "W1", "W2", "W3", "W4", "W5", "W6", "W7"]
ESCALATION_LADDER = {"grok": "neon", "neon": "sol", "sol": "human"}
MAX_BRIEF_BYTES = 24_576
MAX_DIFF_FILES = 15
MAX_REVIEW_ROUNDS = 2
MAX_TURNS_BEFORE_COMPACT = 12


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def ensure_git_excludes() -> None:
    for path in REPOS.values():
        exclude = path / ".git" / "info" / "exclude"
        if not exclude.exists():
            continue
        lines = exclude.read_text(encoding="utf-8").splitlines()
        changed = False
        for entry in (".vaulter-orch/", ".swissknifeman/"):
            if entry not in lines:
                lines.append(entry)
                changed = True
        if changed:
            exclude.write_text("\n".join(lines) + "\n", encoding="utf-8")


def load_state() -> dict[str, Any]:
    if not STATE_FILE.exists():
        raise SystemExit("State not initialized. Run: python3 plan/execution/watchdog.py init")
    return json.loads(STATE_FILE.read_text(encoding="utf-8"))


def save_state(state: dict[str, Any]) -> None:
    state["updated_at"] = now_iso()
    ORCH_DIR.mkdir(parents=True, exist_ok=True)
    (ORCH_DIR / "briefs").mkdir(exist_ok=True)
    (ORCH_DIR / "logs").mkdir(exist_ok=True)
    STATE_FILE.write_text(json.dumps(state, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def cmd_init(args: argparse.Namespace) -> None:
    ensure_git_excludes()
    ORCH_DIR.mkdir(parents=True, exist_ok=True)
    (ORCH_DIR / "briefs").mkdir(exist_ok=True)
    (ORCH_DIR / "logs").mkdir(exist_ok=True)
    if STATE_FILE.exists() and not args.force:
        print(json.dumps({"status": "already_initialized", "state_file": str(STATE_FILE)}))
        return
    state: dict[str, Any] = {
        "schema": "vaulter-orch/v1",
        "priority_track": args.track,
        "session_turns": 0,
        "completed_waves": [],
        "updated_at": now_iso(),
        "tasks": {
            t["id"]: {
                "status": "pending",
                "sha": None,
                "rounds": 0,
                "escalated_to": None,
                "note": "",
            }
            for t in TASKS
        },
    }
    save_state(state)
    if not ARTIFACTS_FILE.exists():
        ARTIFACTS_FILE.write_text(
            json.dumps({"schema": "vaulter-artifacts/v1", "updated_at": now_iso(), "repos": {}}, indent=2) + "\n",
            encoding="utf-8",
        )
    print(json.dumps({"status": "initialized", "tasks": len(TASKS), "track": args.track}))


def cmd_next(args: argparse.Namespace) -> None:
    state = load_state()
    tasks_state = state["tasks"]
    track_filter = args.track or state.get("priority_track", "MVP-1")
    ready = []
    for t in TASKS:
        st = tasks_state[t["id"]]
        if st["status"] not in ("pending", "escalated"):
            continue
        if track_filter != "ALL" and t["track"] != track_filter:
            continue
        if all(tasks_state[d]["status"] == "done" for d in t["deps"]):
            if t["executor"] == t["reviewer"]:
                raise SystemExit(f"INVARIANT VIOLATION: executor == reviewer for {t['id']}")
            ready.append(
                {
                    "id": t["id"],
                    "wave": t["wave"],
                    "track": t["track"],
                    "repo": t["repo"],
                    "executor": st["escalated_to"] or t["executor"],
                    "reviewer": t["reviewer"],
                    "contour": t["contour"],
                }
            )
    if ready:
        first_wave = min(ready, key=lambda x: WAVE_ORDER.index(x["wave"]))["wave"]
        if not args.all_waves:
            ready = [r for r in ready if r["wave"] == first_wave]
    print(json.dumps({"track": track_filter, "ready": ready}, ensure_ascii=False))


def cmd_record(args: argparse.Namespace) -> None:
    state = load_state()
    if args.task not in state["tasks"]:
        raise SystemExit(f"Unknown task id: {args.task}")
    st = state["tasks"][args.task]
    meta = TASK_INDEX[args.task]
    st["status"] = args.status
    if args.sha:
        st["sha"] = args.sha
    if args.rounds is not None:
        st["rounds"] = args.rounds
    if args.note is not None:
        st["note"] = args.note[:180]
    state["session_turns"] = int(state.get("session_turns", 0)) + 1

    alerts: list[str] = []
    if st["rounds"] > MAX_REVIEW_ROUNDS and st["status"] != "done":
        cur_exec = st["escalated_to"] or meta["executor"]
        nxt = ESCALATION_LADDER.get(cur_exec, "human")
        st["status"] = "escalated"
        st["escalated_to"] = nxt
        alerts.append(f"HARD_CAP_EXCEEDED: task {args.task} escalated {cur_exec} -> {nxt}")

    # Check if the task's wave just completed for the priority track
    wave = meta["wave"]
    track = state.get("priority_track", "MVP-1")
    wave_tasks = [t for t in TASKS if t["wave"] == wave and (track == "ALL" or t["track"] == track)]
    wave_done = all(state["tasks"][t["id"]]["status"] == "done" for t in wave_tasks)
    compact_recommended = False
    if wave_done and wave not in state["completed_waves"]:
        state["completed_waves"].append(wave)
        compact_recommended = True
        alerts.append(f"WAVE_COMPLETE:{wave} -> pause and request /compact or fresh Neon session")
    if state["session_turns"] >= MAX_TURNS_BEFORE_COMPACT:
        compact_recommended = True
        alerts.append(f"TURN_LIMIT:{state['session_turns']} >= {MAX_TURNS_BEFORE_COMPACT} -> request /compact")

    save_state(state)
    print(
        json.dumps(
            {
                "task": args.task,
                "status": st["status"],
                "wave_done": wave_done,
                "compact_recommended": compact_recommended,
                "alerts": alerts,
            },
            ensure_ascii=False,
        )
    )


def cmd_reset_turns(_: argparse.Namespace) -> None:
    state = load_state()
    state["session_turns"] = 0
    save_state(state)
    print(json.dumps({"status": "ok", "session_turns": 0}))


def cmd_add_polish(args: argparse.Namespace) -> None:
    if not POLISH_FILE.exists():
        raise SystemExit(f"Missing {POLISH_FILE}")
    row = f"| {args.task} | {args.repo} | {args.reviewer} | {args.note.replace('|', '/')} | open |\n"
    text = POLISH_FILE.read_text(encoding="utf-8")
    if "| *(пока пусто)* |" in text:
        text = text.replace("| *(пока пусто)* | — | — | — | — |\n", row)
    else:
        text = text.rstrip() + "\n" + row
    POLISH_FILE.write_text(text, encoding="utf-8")
    print(json.dumps({"status": "logged_polish", "task": args.task}))


def cmd_check_diff(args: argparse.Namespace) -> None:
    repo_path = REPOS.get(args.repo)
    if not repo_path:
        raise SystemExit(f"Unknown repo: {args.repo}")
    res = subprocess.run(
        ["git", "-C", str(repo_path), "status", "--porcelain"],
        capture_output=True,
        text=True,
        check=True,
    )
    changed = [line[3:].strip() for line in res.stdout.splitlines() if line.strip()]
    alert = len(changed) > MAX_DIFF_FILES
    print(
        json.dumps(
            {
                "repo": args.repo,
                "changed_files": len(changed),
                "max_files": MAX_DIFF_FILES,
                "split_warning": alert,
                "files": changed[:25],
            },
            ensure_ascii=False,
        )
    )
    if alert and args.strict:
        sys.exit(2)


def cmd_health(_: argparse.Namespace) -> None:
    state = load_state()
    alerts: list[str] = []

    state_bytes = STATE_FILE.stat().st_size
    if state_bytes > 8192:
        alerts.append(f"STATE_BLOAT: state.json is {state_bytes} bytes (> 8192)")

    briefs_dir = ORCH_DIR / "briefs"
    oversized_briefs = []
    if briefs_dir.exists():
        for b in sorted(briefs_dir.glob("*.md")):
            sz = b.stat().st_size
            if sz > MAX_BRIEF_BYTES:
                oversized_briefs.append({"file": b.name, "bytes": sz})
    if oversized_briefs:
        alerts.append(f"OVERSIZED_BRIEFS: {oversized_briefs}")

    dirty_repos = {}
    for name, path in REPOS.items():
        if not (path / ".git").exists():
            continue
        r = subprocess.run(["git", "-C", str(path), "status", "--porcelain"], capture_output=True, text=True)
        lines = [ln for ln in r.stdout.splitlines() if ln.strip()]
        if lines:
            dirty_repos[name] = len(lines)

    counts = {"pending": 0, "running": 0, "review": 0, "done": 0, "escalated": 0}
    for st in state["tasks"].values():
        counts[st["status"]] = counts.get(st["status"], 0) + 1

    compact_needed = state.get("session_turns", 0) >= MAX_TURNS_BEFORE_COMPACT
    if compact_needed:
        alerts.append(f"COMPACT_RECOMMENDED: session_turns={state['session_turns']}")

    out = {
        "healthy": len(alerts) == 0,
        "priority_track": state.get("priority_track"),
        "session_turns": state.get("session_turns", 0),
        "compact_recommended": compact_needed,
        "completed_waves": state.get("completed_waves", []),
        "counts": counts,
        "state_bytes": state_bytes,
        "dirty_repos": dirty_repos,
        "alerts": alerts,
    }
    print(json.dumps(out, ensure_ascii=False))


def main() -> None:
    p = argparse.ArgumentParser(description="Vaulter orchestration state & context-hygiene watchdog")
    sub = p.add_subparsers(dest="cmd", required=True)

    p_init = sub.add_parser("init")
    p_init.add_argument("--track", choices=["MVP-1", "MVP-2", "ALL"], default="MVP-1")
    p_init.add_argument("--force", action="store_true")
    p_init.set_defaults(func=cmd_init)

    p_next = sub.add_parser("next")
    p_next.add_argument("--track", choices=["MVP-1", "MVP-2", "ALL"], default=None)
    p_next.add_argument("--all-waves", action="store_true")
    p_next.set_defaults(func=cmd_next)

    p_rec = sub.add_parser("record")
    p_rec.add_argument("--task", required=True)
    p_rec.add_argument("--status", choices=["pending", "running", "review", "done", "escalated"], required=True)
    p_rec.add_argument("--sha", default=None)
    p_rec.add_argument("--rounds", type=int, default=None)
    p_rec.add_argument("--note", default=None)
    p_rec.set_defaults(func=cmd_record)

    p_rst = sub.add_parser("reset-turns")
    p_rst.set_defaults(func=cmd_reset_turns)

    p_pol = sub.add_parser("add-polish")
    p_pol.add_argument("--task", required=True)
    p_pol.add_argument("--repo", required=True)
    p_pol.add_argument("--reviewer", required=True)
    p_pol.add_argument("--note", required=True)
    p_pol.set_defaults(func=cmd_add_polish)

    p_diff = sub.add_parser("check-diff")
    p_diff.add_argument("--repo", required=True)
    p_diff.add_argument("--strict", action="store_true")
    p_diff.set_defaults(func=cmd_check_diff)

    p_h = sub.add_parser("health")
    p_h.set_defaults(func=cmd_health)

    args = p.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
