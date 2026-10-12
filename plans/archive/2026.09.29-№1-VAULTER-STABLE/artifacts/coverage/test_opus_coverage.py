"""Regression checks for literal OPUS inputs and the coverage gate, on temporary files."""
from __future__ import annotations

import contextlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location("opus_coverage", Path(__file__).with_name("opus-coverage.py"))
coverage = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(coverage)

CODE = "```php\n$first = run(); // keep the next call executable\n$second = run();\n```"
LIST = "- Execute the steps:\n  first step\n  second step"
TREE = "```text\nRoot\n  ├── first\n  └── second\n```"
SOURCE = f"# Audit\n\n## Contract\n\n{CODE}\n\n{LIST}\n\n{TREE}\n"


class CoverageGateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.plan = root / "plans" / "PLAN"
        self.here = self.plan / "artifacts" / "coverage"
        self.opus = root / "opus"
        self.here.mkdir(parents=True)
        self.opus.mkdir()
        phase = self.plan / "phases" / "P0"
        phase.mkdir(parents=True)
        (phase / "P0.1.md").write_text("# P0.1\n", encoding="utf-8")
        (self.plan / "decisions").mkdir()
        self.source = self.opus / "02-source.md"
        self.source.write_text(SOURCE, encoding="utf-8")
        for name, value in {
            "HERE": self.here, "PLAN": self.plan, "OPUS": self.opus,
            "UNITS": self.here / "opus-units.tsv",
            "OWNERS": self.here / "section-owners.tsv",
            "SOURCES": self.here / "opus-sources.sha256",
        }.items():
            p = patch.object(coverage, name, value)
            p.start()
            self.addCleanup(p.stop)
        units = coverage.all_units()
        coverage.write_units(units)
        coverage.OWNERS.write_text("file\tsection\towners\n02-source.md\tContract\tP0.1\n", encoding="utf-8")
        (self.here / "ledger-P0.tsv").write_text(
            "id\titems\tnote\n" + "".join(f"{u['id']}\tP0.1\t\n" for u in units), encoding="utf-8")
        self.artifact = self.plan / "artifacts" / "P0-design" / "P0.1-opus.md"
        self.artifact.parent.mkdir()
        self.artifact.write_text(coverage.render_text("P0.1"), encoding="utf-8")

    def gate(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            result = coverage.check(None)
        return result, out.getvalue()

    def test_literal_code_list_and_tree_survive_render(self):
        rendered = self.artifact.read_text(encoding="utf-8")
        for text in (CODE, LIST, TREE):
            self.assertIn(text + "\n", rendered)
        self.assertEqual(self.gate()[0], 0)

    def test_collapsed_artifact_is_rejected(self):
        self.artifact.write_text(self.artifact.read_text().replace(CODE, coverage.norm(CODE)))
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("дословный вход устарел или повреждён", output)

    def test_missing_artifact_is_rejected(self):
        self.artifact.unlink()
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("нет дословного входа", output)

    def test_source_whitespace_change_keeps_ids_but_is_rejected(self):
        ids = [u["id"] for u in coverage.all_units()]
        self.source.write_text(SOURCE.replace("// keep", "//  keep"))
        self.assertEqual([u["id"] for u in coverage.all_units()], ids)
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("OPUS изменён побайтно", output)

    def test_registry_text_tampering_is_rejected(self):
        coverage.UNITS.write_text(coverage.UNITS.read_text().replace("keep", "lose"))
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("opus-units.tsv устарел", output)

    def test_bodyless_heading_keeps_original_markdown(self):
        self.source.write_text(SOURCE + "\n### Empty contract\n")
        unit = next(u for u in coverage.all_units() if u["kind"] == "heading")
        self.assertEqual(unit["source_text"], "### Empty contract")


class JsonCoverageGateTest(CoverageGateTest):
    def setUp(self):
        super().setUp()
        phase = self.plan / "phases" / "P0"
        (phase / "P0.1.md").unlink()
        (phase / "P0.1.json").write_text(json.dumps({"id": "P0.1"}))
        (phase / "phase.json").write_text(json.dumps({"id": "P0", "design": "detailed"}))
        self.artifact.unlink()
        self.artifact = self.plan / "knowledge" / "artifacts-P0-design-P0.1-opus.json"
        self.artifact.parent.mkdir()
        ids = [unit["id"] for unit in coverage.all_units()]
        self.document = {
            "id": self.artifact.stem,
            "title": "OPUS — дословные требования для P0.1 (3 единиц)",
            "sections": [
                {"id": "intro", "title": "OPUS — дословные требования для P0.1 (3 единиц)",
                 "text": "> Сгенерировано `opus-coverage.py render`; не править руками. Каждая строка обязательна к исполнению\n"
                         "> или к явному отказу через D# плана (D06)."},
                {"id": "contract", "title": "02-source.md — Contract",
                 "text": f"- `{ids[0]}`\n\n{CODE}\n\n- `{ids[1]}`\n\n{LIST}\n\n- `{ids[2]}`\n\n{TREE}"},
            ],
        }
        self.write_document()

    def write_document(self):
        self.artifact.write_text(json.dumps(self.document, ensure_ascii=False, indent=2), encoding="utf-8")

    def test_literal_code_list_and_tree_survive_render(self):
        body = json.loads(self.artifact.read_text())["sections"][1]["text"]
        for text in (CODE, LIST, TREE):
            self.assertIn(text, body)
        self.assertEqual(self.gate()[0], 0)

    def test_collapsed_artifact_is_rejected(self):
        self.document["sections"][1]["text"] = self.document["sections"][1]["text"].replace(CODE, coverage.norm(CODE))
        self.write_document()
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("дословный вход устарел или повреждён", output)

    def test_json_formatting_does_not_change_literal_freshness(self):
        self.artifact.write_text(json.dumps(self.document, ensure_ascii=True), encoding="utf-8")
        self.assertEqual(self.gate()[0], 0)

    def add_bodyless_heading(self):
        self.source.write_text(SOURCE + "\n## Empty contract\n", encoding="utf-8")
        units = coverage.all_units()
        coverage.write_units(units)
        coverage.OWNERS.write_text(
            "file\tsection\towners\n02-source.md\tContract\tP0.1\n02-source.md\tEmpty contract\tP0.1\n")
        (self.here / "ledger-P0.tsv").write_text(
            "id\titems\tnote\n" + "".join(f"{unit['id']}\tP0.1\t\n" for unit in units))
        self.document["title"] = "OPUS — дословные требования для P0.1 (4 единиц)"
        self.document["sections"][0]["title"] = self.document["title"]
        self.document["sections"].extend([
            {"id": "heading-unit", "title": "02-source.md — Empty contract", "text": f"- `{units[-1]['id']}`"},
            {"id": "empty-contract", "title": "Empty contract", "text": ""},
        ])
        self.write_document()

    def test_bodyless_unit_heading_is_preserved_as_a_json_section(self):
        self.add_bodyless_heading()
        self.assertEqual(self.gate()[0], 0)

    def test_removed_bodyless_unit_heading_is_rejected(self):
        self.add_bodyless_heading()
        self.document["sections"].pop()
        self.write_document()
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("дословный вход устарел или повреждён", output)

    def test_changed_bodyless_unit_heading_is_rejected(self):
        self.add_bodyless_heading()
        self.document["sections"][-1]["title"] = "Changed contract"
        self.write_document()
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("дословный вход устарел или повреждён", output)

    def test_unit_whitespace_tampering_is_rejected(self):
        self.document["sections"][1]["text"] = self.document["sections"][1]["text"].replace("// keep", "//  keep")
        self.write_document()
        self.assertEqual(self.gate()[0], 1)

    def test_omitted_unit_and_changed_ledger_note_are_rejected(self):
        original = self.document["sections"][1]["text"]
        self.document["sections"][1]["text"] = original[:original.rfind("\n\n- `")]
        self.write_document()
        self.assertEqual(self.gate()[0], 1)
        self.document["sections"][1]["text"] = original
        self.write_document()
        ledger = self.here / "ledger-P0.tsv"
        ledger.write_text(ledger.read_text().replace("\tP0.1\t\n", "\tP0.1\tTransferred work\n", 1))
        self.assertEqual(self.gate()[0], 1)

    def test_section_order_and_heading_tampering_are_rejected(self):
        self.document["sections"][1]["title"] = "02-source.md — Wrong contract"
        self.write_document()
        self.assertEqual(self.gate()[0], 1)
        self.document["sections"][1]["title"] = "02-source.md — Contract"
        self.document["sections"].reverse()
        self.write_document()
        self.assertEqual(self.gate()[0], 1)

    def test_malformed_json_is_rejected(self):
        self.artifact.write_text("{")
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("дословный вход устарел или повреждён", output)

    def test_json_carrier_cannot_fall_back_to_a_stale_markdown_copy(self):
        old_artifact = self.plan / "artifacts" / "P0-design" / "P0.1-opus.md"
        old_artifact.write_text(coverage.render_text("P0.1"), encoding="utf-8")
        self.artifact.unlink()
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("нет дословного входа knowledge/", output)

    def test_json_decision_owner_requires_the_declared_decision(self):
        coverage.OWNERS.write_text("file\tsection\towners\n02-source.md\tContract\tP0.1,plan:D06\n")
        decision = self.plan / "decisions" / "D06.json"
        decision.write_text(json.dumps({"id": "D06"}))
        self.assertEqual(self.gate()[0], 0)
        decision.unlink()
        (self.plan / "decisions" / "D06-stale.md").write_text("# D06")
        self.assertEqual(self.gate()[0], 1)

    def test_skeleton_item_declarations_do_not_require_a_detailed_ledger(self):
        phase = self.plan / "phases" / "P3"
        phase.mkdir()
        (phase / "phase.json").write_text(json.dumps({
            "id": "P3", "design": "skeleton",
            "sections": [{"title": "Planned Items", "text": "- P3.1 — Future contract"}],
        }))
        coverage.OWNERS.write_text("file\tsection\towners\n02-source.md\tContract\tP0.1,P3.1\n")
        self.assertEqual(self.gate()[0], 0)
        (phase / "phase.json").write_text(json.dumps({"id": "P3", "design": "skeleton", "sections": []}))
        (self.plan / "backlog").mkdir()
        (self.plan / "backlog" / "P3.md").write_text("- P3.1 — Stale future contract")
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("P3.1: item не объявлен", output)

    def test_missing_detailed_json_item_is_rejected(self):
        (self.plan / "phases" / "P0" / "P0.1.json").unlink()
        result, output = self.gate()
        self.assertEqual(result, 1)
        self.assertIn("несуществующему item P0.1", output)


if __name__ == "__main__":
    unittest.main()
