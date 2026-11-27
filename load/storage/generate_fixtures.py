#!/usr/bin/env python3
"""Deterministic storage fixtures. This generator makes no database timing claims."""
import argparse
import hashlib
import json
import math
import platform
from pathlib import Path


def encoded(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def save(path, value):
    payload = encoded(value)
    path.write_bytes(payload + b"\n")
    return {"path": path.name, "bytes_with_final_lf": len(payload) + 1,
            "sha256": hashlib.sha256(payload + b"\n").hexdigest()}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path(__file__).parent / "fixtures")
    parser.add_argument("--seed", type=int, default=20261120)
    parser.add_argument("--fanout-formulas", type=int, default=0,
                        help="Additional artificial formulas depending on B2/B3; default only D2 is a formula.")
    args = parser.parse_args()
    if not 0 <= args.fanout_formulas <= 99980:
        parser.error("--fanout-formulas must be between 0 and 99980")
    args.output.mkdir(parents=True, exist_ok=True)

    blocks = []
    for logical_page in range(1, 101):
        heading_id = f"h{logical_page}"
        heading = "Оплата" if logical_page == 2 else f"Раздел {logical_page}. Условия договора"
        blocks.append({"type": "heading", "attrs": {"blockId": heading_id, "level": 2},
                       "content": [{"type": "text", "text": heading}]})
        for offset in range(1, 11):
            n = (logical_page - 1) * 10 + offset
            token = hashlib.sha256(f"{args.seed}:paragraph:{n}".encode()).hexdigest()[:24]
            paragraph = {
                "type": "paragraph", "attrs": {"blockId": f"p{n}"},
                "content": [
                    {"type": "text", "text": f"Пункт {n}. Стороны согласуют порядок передачи документов, сроки проверки и условия исполнения обязательств. "},
                    {"type": "text", "text": "Изменения подтверждаются уполномоченными представителями. ", "marks": [{"type": "bold"}]},
                    {"type": "text", "text": f"В приложении зафиксирован учебный идентификатор {token}; он не содержит сведений о реальных организациях. "},
                    {"type": "text", "text": "Ссылка на приложение", "marks": [{"type": "link", "attrs": {"href": "https://example.invalid/appendix", "target": "_blank"}}]},
                    {"type": "text", "text": ". Условия настоящего раздела применяются после согласования сторонами и регистрации соответствующего уведомления.", "marks": [{"type": "italic"}]},
                ],
            }
            if n == 17:
                paragraph["content"] = [{"type": "text", "text": "Срок 15 дней"}]
            blocks.append(paragraph)
    contract = {"type": "doc", "content": blocks}

    # Exactly 100,000 nonempty cells in a 10,000 x 1,000 address space (1% occupancy).
    # A coprime modular permutation gives deterministic, unique sparse coordinates.
    row_count, column_count, nonempty_count = 10000, 1000, 100000
    space = row_count * column_count
    assert math.gcd(7919, space) == 1
    cells = {}
    for row in range(2, 7):
        for col in range(2, 6):
            cells[f"{row}:{col}"] = {"v": (row * 37 + col * 11) % 10000, "t": "n"}
    cells["2:2"] = {"v": 12, "t": "n"}
    cells["3:2"] = {"v": 8, "t": "n"}
    cells["2:4"] = {"f": "=SUM(B2:B3)", "v": 20, "t": "n", "calc_source_seq": 1}
    i = 0
    added = 0
    while len(cells) < nonempty_count:
        flat = (i * 7919 + args.seed) % space
        i += 1
        row, col = flat // column_count + 1, flat % column_count + 1
        key = f"{row}:{col}"
        if key in cells:
            continue
        if added < args.fanout_formulas:
            value = {"f": "=SUM($B$2,$B$3)", "v": 20, "t": "n", "calc_source_seq": 1}
        elif added % 17 == 0:
            value = {"v": hashlib.sha256(f"{args.seed}:{key}".encode()).hexdigest()[:24], "t": "s"}
        else:
            value = {"v": (row * 37 + col * 11) % 10000, "t": "n"}
        cells[key] = value
        added += 1
    workbook = {"format": "vaulter-storage-benchmark-fixture-1", "sheets": {
        "s1": {"name": "Учебный лист", "rowCount": row_count, "columnCount": column_count, "cells": cells}}}
    target_index = next(i for i, b in enumerate(blocks) if b["attrs"]["blockId"] == "p17")
    expected_text = {"generation": 1, "sourceSeq": 1,
                     "blocks": [{"id": b["attrs"]["blockId"], "node": b}
                                for b in blocks[target_index - 1:target_index + 2]]}
    expected_cells = {"generation": 1, "sourceSeq": 1,
                      "cells": [{"row": r, "col": c, "cell": cells.get(f"{r}:{c}")}
                                for r in range(2, 7) for c in range(2, 6)]}
    files = [save(args.output / "contract.json", contract),
             save(args.output / "workbook.json", workbook),
             save(args.output / "expected_text.json", expected_text),
             save(args.output / "expected_cells.json", expected_cells)]
    manifest = {
        "fixture_version": 1, "seed": args.seed, "generator_python": platform.python_version(),
        "logical_pages": 100,
        "pagination_note": "100 logical groups, 1 heading + 10 paragraphs each. No rendered A4 page count is asserted.",
        "blocks": len(blocks), "headings": 100, "paragraphs": 1000,
        "text_target": "p17", "text_target_array_index_zero_based": target_index,
        "text_response_blocks": 3,
        "sheet_rows": row_count, "sheet_columns": column_count,
        "nonempty_cells": len(cells), "occupancy_fraction": len(cells) / space,
        "range_one_based_inclusive": {"rows": [2, 6], "columns": [2, 5]},
        "range_requested_cells": 20, "formulas": 1 + args.fanout_formulas,
        "formula_note": "Cached values are fixed fixture data; no spreadsheet engine was run.",
        "files": files,
        "performance_status": "NOT_RUN",
    }
    if args.seed == 20261120 and args.fanout_formulas == 0:
        pinned = json.loads((Path(__file__).parent / "pinned-manifest.json").read_text())
        pinned["generator_python"] = manifest["generator_python"]
        if manifest != pinned:
            raise ValueError("Default generated inputs differ from the pinned benchmark corpus")
    save(args.output / "fixture_manifest.json", manifest)
    print(json.dumps(manifest, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
