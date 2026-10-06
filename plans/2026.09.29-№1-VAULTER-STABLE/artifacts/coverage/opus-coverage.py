#!/usr/bin/env python3
"""Гейт полноты переноса OPUS в план (PLAN2.D06).

OPUS (audits/2026-09-29-stable-audit/opus/*.md) режется на атомарные единицы: абзац, пункт списка (с продолжением),
строка таблицы, блок кода, цитата. Каждая единица получает стабильный id `<файл>:<секция>:<sha8>` (sha8 — от
нормализованного текста), поэтому id не зависит от позиции и ломается только при изменении самого текста.

Команды:
  units                     — пересобрать artifacts/coverage/opus-units.tsv
  check [--phase Pn]        — проверить: единицы не изменились; каждая секция имеет владельца (section-owners.tsv);
                              OPUS совпадает побайтно с opus-sources.sha256, входы render свежие;
                              для детализированных фаз каждая единица их секций есть в ledger-Pn.tsv с item этой фазы
  render --item Pn.m        — напечатать дословный текст всех единиц item (для artifacts/Pn-design/Pn.m-opus.md)
  seed-ledger --phase Pn    — напечатать черновик ledger-Pn.tsv (владелец секции по умолчанию) для ручной доводки

Только stdlib. Выход 0 — OK, 1 — нарушения (печатаются), 2 — ошибка использования.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
PLAN = HERE.parent.parent
REPO = PLAN.parent.parent
OPUS = REPO / "audits" / "2026-09-29-stable-audit" / "opus"
UNITS = HERE / "opus-units.tsv"
OWNERS = HERE / "section-owners.tsv"
SOURCES = HERE / "opus-sources.sha256"

HEADING = re.compile(r"^(#{1,4})\s+(.*)$")
LIST_ITEM = re.compile(r"^(\s*)(?:[-*]|\d+\.)\s+")
TABLE_SEP = re.compile(r"^\|\s*:?-{2,}")
OWNER = re.compile(r"^(P\d+(\.\d+)?|plan:D\d{2})$")


def norm(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def section_key(stack: list[tuple[int, str]]) -> str:
    return " / ".join(title for _, title in stack) if stack else "(preamble)"


def split_units(path: Path) -> list[dict]:
    """Режет файл на единицы; заголовки — контекст, не единицы."""
    units: list[dict] = []
    stack: list[tuple[int, str]] = []
    lines = path.read_text(encoding="utf-8").splitlines()
    i = 0
    seen_sections: list[str] = []
    heading_lines: dict[str, str] = {}

    def emit(kind: str, text: str, *, source_text: str | None = None) -> None:
        body = norm(text)
        if not body or body == "---":
            return
        sha = hashlib.sha256(f"{section_key(stack)}\n{body}".encode()).hexdigest()[:8]
        units.append({
            "file": path.name,
            "section": section_key(stack),
            "kind": kind,
            "sha8": sha,
            "text": body,
            # Normalization stabilizes IDs; rendering must retain executable newlines.
            "source_text": text if source_text is None else source_text,
        })

    while i < len(lines):
        line = lines[i]
        m = HEADING.match(line)
        if m:
            level = len(m.group(1))
            while stack and stack[-1][0] >= level:
                stack.pop()
            if level > 1:
                stack.append((level, norm(m.group(2))))
                seen_sections.append(section_key(stack))
                heading_lines[section_key(stack)] = line
            i += 1
            continue
        if line.startswith("```"):
            block = [line]
            i += 1
            while i < len(lines) and not lines[i].startswith("```"):
                block.append(lines[i])
                i += 1
            if i < len(lines):
                block.append(lines[i])
            i += 1
            emit("code", "\n".join(block))
            continue
        if line.startswith("|"):
            nxt = lines[i + 1] if i + 1 < len(lines) else ""
            if TABLE_SEP.match(nxt):
                emit("table-header", line)
                i += 2
                continue
            emit("row", line)
            i += 1
            continue
        if LIST_ITEM.match(line):
            block = [line]
            indent = len(LIST_ITEM.match(line).group(1))
            i += 1
            while i < len(lines) and lines[i].strip() and not LIST_ITEM.match(lines[i]) \
                    and not HEADING.match(lines[i]) and not lines[i].startswith(("|", "```")) \
                    and (len(lines[i]) - len(lines[i].lstrip())) > indent:
                block.append(lines[i])
                i += 1
            emit("item", "\n".join(block))
            continue
        if line.strip().startswith("<a id=") or not line.strip():
            i += 1
            continue
        block = [line]
        i += 1
        while i < len(lines) and lines[i].strip() and not HEADING.match(lines[i]) \
                and not LIST_ITEM.match(lines[i]) and not lines[i].startswith(("|", "```")):
            block.append(lines[i])
            i += 1
        emit("quote" if line.startswith(">") else "para", "\n".join(block))
    # Секция без тела (например, «N20 … — см. §2») несёт смысл в заголовке: он становится единицей.
    with_body = {u["section"] for u in units}
    for key in seen_sections:
        if key not in with_body:
            stack = [(0, part) for part in key.split(" / ")]
            emit("heading", key.split(" / ")[-1], source_text=heading_lines[key])
    return units


def all_units() -> list[dict]:
    units = []
    for path in sorted(OPUS.glob("*.md")):
        units.extend(split_units(path))
    for u in units:
        u["id"] = f"{u['file'][:2]}:{u['sha8']}"
    seen: dict[str, int] = {}
    for u in units:  # одинаковый текст в одной секции — суффикс порядкового номера
        n = seen.get(u["id"], 0)
        seen[u["id"]] = n + 1
        if n:
            u["id"] = f"{u['id']}.{n}"
    return units


def write_units(units: list[dict]) -> None:
    with UNITS.open("w", encoding="utf-8", newline="") as fh:
        w = csv.writer(fh, delimiter="\t", lineterminator="\n")
        w.writerow(["id", "file", "section", "kind", "text"])
        for u in units:
            w.writerow([u["id"], u["file"], u["section"], u["kind"], u["text"]])
    SOURCES.write_text(source_manifest(), encoding="utf-8")


def source_manifest() -> str:
    return "".join(f"{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}\n"
                   for path in sorted(OPUS.glob("*.md")))


def read_tsv(path: Path) -> list[dict]:
    with path.open(encoding="utf-8", newline="") as fh:
        return list(csv.DictReader(fh, delimiter="\t"))


def owners_map() -> dict[tuple[str, str], list[str]]:
    result: dict[tuple[str, str], list[str]] = {}
    for row in read_tsv(OWNERS):
        result[(row["file"], row["section"])] = [o.strip() for o in row["owners"].split(",") if o.strip()]
    return result


def uses_json_plan() -> bool:
    return (PLAN / "plan.json").exists() or any((PLAN / "phases").glob("P*/phase.json"))


def detailed_phases() -> list[str]:
    phases = []
    for path in (PLAN / "phases").iterdir():
        if not path.is_dir():
            continue
        carrier = path / "phase.json"
        if carrier.exists():
            if json.loads(carrier.read_text(encoding="utf-8")).get("design") == "detailed":
                phases.append(path.name)
        elif not uses_json_plan():
            phases.append(path.name)
    return sorted(phases)


def phase_items(phase: str) -> set[str]:
    directory = PLAN / "phases" / phase
    suffix = "json" if uses_json_plan() else "md"
    return {p.stem for p in directory.glob(f"{phase}.*.{suffix}")
            if re.fullmatch(r"P\d+\.\d+", p.stem)}


def planned_items() -> set[str]:
    """Items детализированных фаз + items, объявленные в backlog (строки `- Pn.m — …`)."""
    known: set[str] = set()
    for phase in detailed_phases():
        known |= phase_items(phase)
    for path in (PLAN / "phases").glob("P*/phase.json"):
        phase = json.loads(path.read_text(encoding="utf-8"))
        # JSON migration retained future-item declarations in the skeleton's
        # sections. They remain declarations, never detailed item contracts.
        for section in phase.get("sections", []):
            known |= set(re.findall(r"^- (P\d+\.\d+) —", section.get("text", ""), re.M))
    if not uses_json_plan():
        for path in (PLAN / "backlog").glob("P*.md"):
            known |= set(re.findall(r"^- (P\d+\.\d+) —", path.read_text(encoding="utf-8"), re.M))
    return known


def plan_decisions() -> set[str]:
    directory = PLAN / "decisions"
    if uses_json_plan():
        return {p.stem for p in directory.glob("D[0-9][0-9].json")}
    return {p.name[:3] for p in directory.glob("D[0-9][0-9]-*.md")}


def owner_phase(owner: str) -> str | None:
    if owner.startswith("plan:"):
        return None
    return owner.split(".")[0]


def check(only_phase: str | None) -> int:
    problems: list[str] = []
    fresh = all_units()
    committed = read_tsv(UNITS) if UNITS.exists() else []
    fields = ("id", "file", "section", "kind", "text")
    if [tuple(r.get(k) for k in fields) for r in committed] != \
            [tuple(u[k] for k in fields) for u in fresh]:
        problems.append("opus-units.tsv устарел или OPUS изменён: `opus-coverage.py units` и разбор разницы (D06 п.5)")
    if not SOURCES.exists() or SOURCES.read_text(encoding="utf-8") != source_manifest():
        problems.append("opus-sources.sha256 отсутствует или OPUS изменён побайтно: стоп и эскалация владельцу (D06 п.4)")
    owners = owners_map()
    sections = {(u["file"], u["section"]) for u in fresh}
    for key in sorted(sections - owners.keys()):
        problems.append(f"секция без владельца: {key[0]} :: {key[1]}")
    for key in sorted(owners.keys() - sections):
        problems.append(f"владелец у несуществующей секции: {key[0]} :: {key[1]}")
    for key, owns in owners.items():
        for o in owns:
            if not OWNER.match(o):
                problems.append(f"неверный владелец {o!r} у {key[0]} :: {key[1]}")
    known_items = planned_items()
    decisions = plan_decisions()
    for key, owns in owners.items():
        for o in owns:
            if o.startswith("plan:") and o[5:] not in decisions:
                problems.append(f"владелец {o}: нет decisions/{o[5:]}-*.md ({key[0]} :: {key[1]})")
            elif OWNER.match(o) and not o.startswith("plan:") and o not in known_items:
                problems.append(f"владелец {o}: item не объявлен ни в phases/, ни в backlog/ ({key[0]} :: {key[1]})")
    by_id = {u["id"]: u for u in fresh}
    ledgers: dict[str, dict[str, dict]] = {}
    for phase in detailed_phases():
        path = HERE / f"ledger-{phase}.tsv"
        if path.exists():
            ledgers[phase] = {r["id"]: r for r in read_tsv(path)}
    for phase in detailed_phases():
        if only_phase and phase != only_phase:
            continue
        ledger_path = HERE / f"ledger-{phase}.tsv"
        expected = [u for u in fresh
                    if any(owner_phase(o) == phase for o in owners.get((u["file"], u["section"]), []))]
        if not ledger_path.exists():
            problems.append(f"{phase}: нет {ledger_path.name} ({len(expected)} единиц)")
            continue
        ledger = ledgers[phase]
        items = phase_items(phase)
        for u in expected:
            row = ledger.get(u["id"])
            if row is None:
                problems.append(f"{phase}: единица {u['id']} ({u['file']} :: {u['section']}) не в ledger")
                continue
        for uid, row in ledger.items():
            if uid not in by_id:
                problems.append(f"{phase}: ledger ссылается на несуществующую единицу {uid}")
            targets = [x.strip() for x in row["items"].split(",") if x.strip()]
            if not targets:
                problems.append(f"{phase}: единица {uid} без item")
            for o in targets:
                if o.startswith("plan:"):
                    if o[5:] not in decisions:
                        problems.append(f"{phase}: единица {uid} → {o}: нет такого решения")
                    continue
                target_phase = owner_phase(o)
                if target_phase == phase:
                    if o not in items:
                        problems.append(f"{phase}: единица {uid} назначена несуществующему item {o}")
                elif o not in known_items:
                    problems.append(f"{phase}: единица {uid} → {o}: item не объявлен")
                elif target_phase in ledgers and uid not in ledgers[target_phase]:
                    problems.append(f"{phase}: единица {uid} передана в {o}, но её нет в ledger-{target_phase}.tsv")
        for item in sorted(items):
            uses_json = uses_json_plan()
            artifact = (PLAN / "knowledge" / f"artifacts-{phase}-design-{item}-opus.json" if uses_json
                        else PLAN / "artifacts" / f"{phase}-design" / f"{item}-opus.md")
            if not artifact.exists():
                problems.append(f"{item}: нет дословного входа {artifact.relative_to(PLAN)}")
                continue
            try:
                if uses_json:
                    expected_sections = literal_sections(item, units=fresh)
                else:
                    expected_text = render_text(item, units=fresh)
            except KeyError as exc:
                problems.append(f"{item}: вход не генерируется: неизвестная единица {exc}")
                continue
            if uses_json:
                try:
                    document = json.loads(artifact.read_text(encoding="utf-8"))
                    actual_sections = [(section["title"], section["text"]) for section in document["sections"]]
                    matches = (document["id"] == artifact.stem
                               and document["title"] == expected_sections[0][0]
                               and actual_sections == expected_sections)
                except (json.JSONDecodeError, KeyError, TypeError):
                    matches = False
            else:
                matches = artifact.read_text(encoding="utf-8") == expected_text
            if not matches:
                problems.append(f"{item}: дословный вход устарел или повреждён; пересоберите `render --item {item}`")
    for p in problems:
        print(p)
    total = len(fresh)
    print(f"opus-coverage {'OK' if not problems else 'FAIL'}: {total} единиц, {len(sections)} секций, "
          f"{len(problems)} нарушений")
    return 1 if problems else 0


def literal_sections(item: str, *, units: list[dict] | None = None) -> list[tuple[str, str]]:
    """The exact rendered headings/bodies stored by the canonical JSON migration.

    Only the outer Markdown heading/separator newlines become JSON structure.
    Unit text, notes, ordering and all internal whitespace remain literal.
    """
    phase = item.split(".")[0]
    by_id = {u["id"]: u for u in (all_units() if units is None else units)}
    rows = [r for r in read_tsv(HERE / f"ledger-{phase}.tsv")
            if item in [x.strip() for x in r["items"].split(",")]]
    sections = [(f"OPUS — дословные требования для {item} ({len(rows)} единиц)",
                 "> Сгенерировано `opus-coverage.py render`; не править руками. Каждая строка обязательна к исполнению\n"
                 "> или к явному отказу через D# плана (D06).")]
    current = None
    for row in rows:
        unit = by_id[row["id"]]
        heading = f"{unit['file']} — {unit['section']}"
        note = f" — _{row['note']}_" if row.get("note") else ""
        body = f"- `{unit['id']}`{note}"
        if unit["kind"] != "heading":
            body += f"\n\n{unit['source_text']}"
        if current == heading:
            title, text = sections[-1]
            sections[-1] = (title, text + "\n\n" + body)
        else:
            sections.append((heading, body))
            current = heading
        if unit["kind"] == "heading":
            # The migration lifted the literal bodyless Markdown heading into
            # JSON structure, after its unit-id marker. Preserve that section
            # rather than misclassifying it as missing unit text.
            match = HEADING.match(unit["source_text"])
            sections.append((match.group(2).strip(), ""))
    return [(title, text.strip()) for title, text in sections]


def render_text(item: str, *, units: list[dict] | None = None) -> str:
    phase = item.split(".")[0]
    ledger_path = HERE / f"ledger-{phase}.tsv"
    by_id = {u["id"]: u for u in (all_units() if units is None else units)}
    rows = [r for r in read_tsv(ledger_path) if item in [x.strip() for x in r["items"].split(",")]]
    out = io.StringIO()
    print(f"# OPUS — дословные требования для {item} ({len(rows)} единиц)\n", file=out)
    print("> Сгенерировано `opus-coverage.py render`; не править руками. Каждая строка обязательна к исполнению", file=out)
    print("> или к явному отказу через D# плана (D06).\n", file=out)
    current = None
    for r in rows:
        u = by_id[r["id"]]
        head = f"{u['file']} — {u['section']}"
        if head != current:
            print(f"\n## {head}\n", file=out)
            current = head
        note = f" — _{r['note']}_" if r.get("note") else ""
        print(f"- `{u['id']}`{note}\n\n{u['source_text']}\n", file=out)
    return out.getvalue()


def render(item: str) -> int:
    try:
        print(render_text(item), end="")
    except (FileNotFoundError, KeyError) as exc:
        print(f"вход {item} не генерируется: {exc}", file=sys.stderr)
        return 2
    return 0


def seed(phase: str) -> int:
    owners = owners_map()
    w = csv.writer(sys.stdout, delimiter="\t", lineterminator="\n")
    w.writerow(["id", "items", "note"])
    for u in all_units():
        owns = [o for o in owners.get((u["file"], u["section"]), []) if owner_phase(o) == phase]
        if owns:
            w.writerow([u["id"], ",".join(o for o in owns if "." in o) or "?", ""])
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("units")
    c = sub.add_parser("check")
    c.add_argument("--phase")
    r = sub.add_parser("render")
    r.add_argument("--item", required=True)
    s = sub.add_parser("seed-ledger")
    s.add_argument("--phase", required=True)
    args = ap.parse_args()
    if args.cmd == "units":
        units = all_units()
        write_units(units)
        print(f"opus-units: {len(units)} единиц")
        return 0
    if args.cmd == "check":
        return check(args.phase)
    if args.cmd == "render":
        return render(args.item)
    return seed(args.phase)


if __name__ == "__main__":
    sys.exit(main())
