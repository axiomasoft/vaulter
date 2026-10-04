"""Regression checks for literal OPUS inputs and the coverage gate, on temporary files."""
from __future__ import annotations

import contextlib
import importlib.util
import io
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


if __name__ == "__main__":
    unittest.main()
