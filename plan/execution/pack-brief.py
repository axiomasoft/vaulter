#!/usr/bin/env python3
"""Deterministic Context Packer for Vaulter tasks (0 LLM tokens).

Extracts ONLY the exact task slice from plan/phases/ and plan/dev-plans/,
attaches current artifacts.json SHAs and relevant engineering stop-rails,
writes .vaulter-orch/briefs/<task-id>.md and enforces the <= 24 KB hard ceiling.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import watchdog as wd  # noqa: E402

PLAN_DIR = wd.VAULTER_ROOT / "plan"
MAX_BRIEF_BYTES = 24_576

# Minimal task-specific reference pointers (paths, not full dumps)
TASK_REFS: dict[str, dict[str, list[str]]] = {
    "W0-Scaffold": {
        "read": [
            "plan/repos/vaulter-collaboration.md",
            "plan/repos/vaulter-sheets.md",
            "plan/repos/vaulter-ai.md",
            "plan/repos/vaulter-editor-pro.md",
            "plan/spec/engineering-standards.md",
        ],
        "checks": [
            "test -f /home/vostrikov/projects/packages/vaulter-collaboration/composer.json",
            "test -f /home/vostrikov/projects/packages/vaulter-sheets/composer.json",
            "test -f /home/vostrikov/projects/packages/vaulter-ai/composer.json",
            "test -f /home/vostrikov/projects/packages/vaulter-editor-pro/composer.json",
        ],
    },
    "0.1": {
        "read": ["plan/acceptance.md"],
        "checks": ["python3 plan/checks/verify-plan.py"],
    },
    "0.2": {
        "read": ["plan/spec/database/README.md"],
        "checks": [
            "python3 plan/checks/verify-fixtures.py",
            "PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh",
        ],
    },
    "0.3": {
        "read": [
            "plan/spec/engineering-standards.md",
            "plan/checks/database/unindexed-fks.sql",
        ],
        "checks": [
            "PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh",
            "composer php-api",
        ],
    },
    "1.0": {
        "read": ["plan/spec/database/sql/documents.sql", "plan/spec/database/README.md"],
        "checks": ["PG_BIN=/usr/lib/postgresql/18/bin bash plan/checks/schema.sh"],
    },
}

STOP_RAILS_COMPACT = """### Обязательные 12 стоп-кранов (выжимка из `plan/spec/engineering-standards.md`)
1. **Zero-Royalty лицензии:** запрещены `@tiptap-pro/*`, `@univerjs-pro/*`, `@univer-cli/*`, `handsontable`, `hyperformula`, `dunglas/mercure`, встроенный `minio/minio`, `xlsx@0.18.5`, `exceljs`/`unzipper`, `mammoth`, `libclamav`.
2. **БД / PG 18.7 & 19.0:** `0` неиндексированных FK (`plan/checks/database/unindexed-fks.sql`), запрет `DEFERRABLE INITIALLY DEFERRED` на горячих таблицах, запрет 32-bit `hashtext(` и сессионного `pg_advisory_lock(` (только 64-bit `hashtextextended(..., 0)` + `pg_advisory_xact_lock`).
3. **Laravel / Octane:** запрет `DB::transaction()` напрямую (только `VaulterDatabase::transaction()`), запрет `set_config(..., false)` (только `true`), запрет Eloquent в публичном API.
4. **Безопасность:** `Uri\\WhatWg\\Url` + `CURLOPT_CONNECT_TO` + `CURLOPT_FORBID_REUSE => true` (запрет `parse_url` / `userinfo` / trailing-dot); запрет `ZipArchive::extractTo()` (обязательный `ZipCentralDirectoryGuard`, запрет `S_IFLNK`).
5. **Runtime / Frontend:** Node `>= 26.13.1` LTS (`libatomic` в Docker), `@tiptap/core >= 3.34.0`, `vue >= 3.5.42`, `@hocuspocus/server >= 4.9.0`, `yjs >= 13.6.33`, Univer OSS `1.5.2` (Plugin Mode, `shallowRef(markRaw(...))`, `child_process.fork` без `worker_threads`).
6. **Тесты и CI:** запрещено ослаблять тесты, добавлять `@phpstan-ignore` / `->skip()` без ADR.
"""


def extract_section(text: str, heading_pattern: str, level: int = 2) -> str:
    prefix = "#" * level + " "
    lines = text.splitlines()
    start = None
    pat = re.compile(heading_pattern, re.IGNORECASE)
    for i, ln in enumerate(lines):
        if ln.startswith(prefix) and pat.search(ln):
            start = i
            break
    if start is None:
        return ""
    end = len(lines)
    for j in range(start + 1, len(lines)):
        if re.match(rf"^#{{1,{level}}}\s+", lines[j]):
            end = j
            break
    return "\n".join(lines[start:end]).strip()


def build_brief(task_id: str) -> str:
    if task_id not in wd.TASK_INDEX:
        raise SystemExit(f"Unknown task id: {task_id}")
    meta = wd.TASK_INDEX[task_id]

    parts: list[str] = [
        f"# Task Brief: `{task_id}` (Wave {meta['wave']} · {meta['track']})",
        "",
        f"- **Repo:** `{meta['repo']}`",
        f"- **Executor:** `{meta['executor']}` · **Reviewer:** `{meta['reviewer']}` · **Contour:** `{meta['contour']}`",
        f"- **Deps:** {', '.join(meta['deps']) if meta['deps'] else 'none'}",
        "- **Rule:** Работай строго в границах этой задачи. Возвращай только компактный JSON/резюме (<= 5 строк + SHA + список P2-замечаний).",
        "",
    ]

    if task_id == "W0-Scaffold":
        parts.append("## Задача W0-Scaffold")
        parts.append(
            "Инициализируй базовую структуру в 4 новых локальных репозиториях рядом с `vaulter`:\n"
            "- `/home/vostrikov/projects/packages/vaulter-collaboration`\n"
            "- `/home/vostrikov/projects/packages/vaulter-sheets`\n"
            "- `/home/vostrikov/projects/packages/vaulter-ai`\n"
            "- `/home/vostrikov/projects/packages/vaulter-editor-pro`\n\n"
            "В каждом репозитории по его паспорту `plan/repos/<repo>.md` создай:\n"
            "1. Корневой `composer.json`, `package.json` (`engines.node >=26.13.1`), `phpstan.neon.dist`, `phpunit.xml.dist`, `release/packages.json`, `.gitignore`.\n"
            "2. Базовый CI-workflow (`.github/workflows/ci.yml`) с матрицей PG 18.7/19.0, Node 26.13.1 и лицензионным стоп-краном (0 пакетов из запрещённого списка).\n"
            "3. Для репо с Node-сервисами (`vaulter-collaboration`, `vaulter-sheets`) — шаблон `Dockerfile` на `node:26.13.1` с установкой `libatomic1`/`libatomic` и `RUN node -e \"process.exit(0)\"`.\n"
            "4. Начальный `README.md` и чистый первый коммит в `main` (без `git push`, пока не подтверждено)."
        )
        parts.append("")
    else:
        if meta["phase"]:
            phase_path = PLAN_DIR / "phases" / meta["phase"]
            phase_text = phase_path.read_text(encoding="utf-8")
            sec = extract_section(phase_text, rf"\b{re.escape(task_id)}\b", level=2)
            if not sec:
                sec = extract_section(phase_text, rf"\b{re.escape(task_id)}\b", level=3)
            if sec:
                parts.append(f"## Вырезка из `plan/phases/{meta['phase']}`")
                parts.append(sec)
                parts.append("")
        if meta["dev_plan"]:
            dp_path = PLAN_DIR / "dev-plans" / meta["dev_plan"]
            dp_text = dp_path.read_text(encoding="utf-8")
            key = "AtomicDocumentMutation" if task_id == "Port-ADM" else task_id
            sec = extract_section(dp_text, rf"\b{re.escape(key)}\b", level=3)
            if sec:
                parts.append(f"## Вырезка из `plan/dev-plans/{meta['dev_plan']}`")
                parts.append(sec)
                parts.append("")

    refs = TASK_REFS.get(task_id, {})
    if refs.get("read"):
        parts.append("## Файлы для чтения (минимум)")
        for r in refs["read"]:
            parts.append(f"- `{r}`")
        parts.append("")
    if refs.get("checks"):
        parts.append("## Обязательный Zero-Token Pre-Gate (exit 0 до ревью)")
        for c in refs["checks"]:
            parts.append(f"- `{c}`")
        parts.append("")

    if wd.ARTIFACTS_FILE.exists():
        art = wd.ARTIFACTS_FILE.read_text(encoding="utf-8").strip()
        if len(art) <= 2048:
            parts.append("## Актуальные артефакты (`.vaulter-orch/artifacts.json`)")
            parts.append("```json\n" + art + "\n```")
            parts.append("")

    parts.append(STOP_RAILS_COMPACT)
    return "\n".join(parts).strip() + "\n"


def main() -> None:
    p = argparse.ArgumentParser(description="Pack a bounded <= 24 KB brief for one Vaulter task")
    p.add_argument("--task", required=True, help="Task id, e.g. W0-Scaffold, 0.1, 0.3, 1.0")
    p.add_argument("--out", default=None, help="Output markdown path (default .vaulter-orch/briefs/<task>.md)")
    p.add_argument("--max-bytes", type=int, default=MAX_BRIEF_BYTES)
    args = p.parse_args()

    brief = build_brief(args.task)
    size = len(brief.encode("utf-8"))
    out_path = pathlib.Path(args.out) if args.out else (wd.ORCH_DIR / "briefs" / f"{args.task}.md")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(brief, encoding="utf-8")

    meta = wd.TASK_INDEX[args.task]
    opt_exec = None
    opt_rev = None
    if meta["executor"] == "grok":
        opt_exec = "plan/execution/orch-options/grok-exec.json"
    elif meta["executor"] == "sol":
        opt_exec = "plan/execution/orch-options/sol-exec.json"
    if meta["reviewer"] == "sol":
        opt_rev = "plan/execution/orch-options/review-sol.json"
    elif meta["reviewer"] == "astra":
        opt_rev = "plan/execution/orch-options/review-astra.json"

    over = size > args.max_bytes
    result = {
        "task": args.task,
        "brief_path": str(out_path),
        "bytes": size,
        "max_bytes": args.max_bytes,
        "over_budget": over,
        "executor": meta["executor"],
        "reviewer": meta["reviewer"],
        "contour": meta["contour"],
        "exec_options": opt_exec,
        "review_options": opt_rev,
    }
    print(json.dumps(result, ensure_ascii=False))
    if over:
        sys.exit(2)


if __name__ == "__main__":
    main()
