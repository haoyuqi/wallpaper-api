"""Regression tests for the exact SQLite line coverage gate."""

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "ci-summary.py"


class CoverageGateTest(unittest.TestCase):
    def collect(self, covered, statements):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        root = Path(directory.name)
        data = root / "ci-data"
        data.mkdir()
        (data / "junit.xml").write_text(
            '<testsuites><testsuite tests="1" failures="0" errors="0" skipped="0"/></testsuites>'
        )
        if statements is not None:
            (data / "coverage.xml").write_text(
                f'<coverage><project><metrics statements="{statements}" '
                f'coveredstatements="{covered}"/></project></coverage>'
            )

        environment = os.environ.copy()
        environment["MIN_LINE_COVERAGE"] = "100"
        result = subprocess.run(
            [sys.executable, str(SCRIPT), "collect", "sqlite", "success"],
            cwd=root,
            env=environment,
            capture_output=True,
            text=True,
            check=False,
        )
        metrics = json.loads((data / "sqlite.json").read_text())
        return result, metrics

    def test_exactly_complete_coverage_passes(self):
        result, metrics = self.collect(100, 100)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("100.00%", metrics["coverage"])
        self.assertTrue(metrics["coverage_passed"])

    def test_rounded_100_percent_with_an_uncovered_line_fails(self):
        result, metrics = self.collect(999_999, 1_000_000)

        self.assertNotEqual(0, result.returncode)
        self.assertEqual("100.00%", metrics["coverage"])
        self.assertFalse(metrics["coverage_passed"])
        self.assertEqual("failure", metrics["result"])

    def test_missing_coverage_report_fails(self):
        result, metrics = self.collect(0, None)

        self.assertNotEqual(0, result.returncode)
        self.assertIsNone(metrics["coverage"])
        self.assertFalse(metrics["coverage_passed"])

    def test_equal_negative_coverage_counters_fail(self):
        result, metrics = self.collect(-1, -1)

        self.assertNotEqual(0, result.returncode)
        self.assertEqual("100.00%", metrics["coverage"])
        self.assertFalse(metrics["coverage_passed"])

    def test_summary_uses_raw_gate_result_instead_of_rounded_percentage(self):
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            data = root / "ci-data"
            data.mkdir()
            (root / "ci-needs.json").write_text(
                json.dumps(
                    {
                        "lint": {"result": "success"},
                        "test": {"result": "success"},
                        "postgres-test": {"result": "success"},
                        "container": {"result": "success"},
                    }
                )
            )
            (data / "sqlite.json").write_text(
                json.dumps(
                    {
                        "name": "sqlite",
                        "coverage": "100.00%",
                        "coverage_passed": False,
                        "tests": 1,
                        "failures": 0,
                        "skipped": 0,
                        "php": None,
                        "laravel": None,
                    }
                )
            )
            summary = root / "summary.md"
            environment = os.environ.copy()
            environment.update(
                {
                    "GITHUB_STEP_SUMMARY": str(summary),
                    "MIN_LINE_COVERAGE": "100",
                }
            )

            result = subprocess.run(
                [sys.executable, str(SCRIPT), "render", "api"],
                cwd=root,
                env=environment,
                capture_output=True,
                text=True,
                check=False,
            )

            self.assertEqual(0, result.returncode, result.stderr)
            self.assertIn("`app/` · minimum 100% · Failed", summary.read_text())


if __name__ == "__main__":
    unittest.main()
