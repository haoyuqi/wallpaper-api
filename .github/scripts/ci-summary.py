"""Collect measured CI data and render one GitHub Actions run summary."""

import json
import os
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path


def xml_root(path):
    try:
        return ET.parse(path).getroot()
    except (FileNotFoundError, ET.ParseError):
        return None


def installed_version(code):
    if not Path("vendor/autoload.php").exists():
        return None
    try:
        return subprocess.check_output(["php", "-r", code], text=True).strip()
    except (OSError, subprocess.CalledProcessError):
        return None


def collect(name, result):
    junit = xml_root("ci-data/junit.xml")
    suites = [junit] if junit is not None and junit.get("tests") else list(junit.findall("./testsuite")) if junit is not None else []
    count = lambda key: sum(int(suite.get(key, "0")) for suite in suites) if suites else None
    clover = xml_root("ci-data/coverage.xml")
    metrics = clover.find("./project/metrics") if clover is not None else None
    statements = int(metrics.get("statements", "0")) if metrics is not None else 0
    covered = int(metrics.get("coveredstatements", "0")) if metrics is not None else 0
    coverage = covered / statements * 100 if statements else None
    minimum = float(os.environ["MIN_LINE_COVERAGE"]) if name == "sqlite" and os.environ.get("MIN_LINE_COVERAGE") else None
    below_minimum = minimum is not None and (coverage is None or coverage < minimum)
    data = {
        "name": name,
        "result": "failure" if below_minimum else result,
        "php": installed_version('echo PHP_VERSION;'),
        "laravel": installed_version('require "vendor/autoload.php"; echo Illuminate\\Foundation\\Application::VERSION;'),
        "tests": count("tests"),
        "failures": count("failures") + count("errors") if suites else None,
        "skipped": count("skipped"),
        "coverage": f"{coverage:.2f}%" if coverage is not None else None,
    }
    Path("ci-data").mkdir(exist_ok=True)
    Path(f"ci-data/{name}.json").write_text(json.dumps(data))
    if below_minimum:
        raise SystemExit(f"SQLite app/ line coverage {display(data['coverage'])} is below the {minimum:g}% minimum")


def display(value):
    return "Not available" if value is None else str(value)


def status(value):
    return {"success": "Passed", "failure": "Failed", "cancelled": "Cancelled", "skipped": "Skipped"}.get(value, "Not available")


def render(repo):
    needs = json.loads(Path("ci-needs.json").read_text())
    data = {}
    for path in Path("ci-data").glob("*.json"):
        item = json.loads(path.read_text())
        data[item["name"]] = item

    if repo == "api":
        jobs = [("Code Quality & Lint", "lint"), ("SQLite PHPUnit", "test"),
                ("PostgreSQL 18 persistence and rollback", "postgres-test"),
                ("PostgreSQL 18 production container smoke", "container")]
        suites = [("SQLite", "sqlite"), ("PostgreSQL 18", "postgres-18")]
        version_source = data.get("sqlite", {})
    else:
        jobs = [("Laravel Pint", "pint"), ("Production installation smoke", "production-smoke"),
                ("SQLite tests", "sqlite-tests"), ("Database matrix", "php-tests"),
                ("Security checks", "security-checks"), ("Browser tests", "browser-tests")]
        suites = [("SQLite", "sqlite"), ("MySQL 8.0", "mysql-8.0"),
                  ("MySQL 8.4", "mysql-8.4"), ("PostgreSQL 17", "postgres-17"),
                  ("PostgreSQL 18", "postgres-18")]
        version_source = data.get("sqlite", {})

    failed = [(label, key) for label, key in jobs if needs.get(key, {}).get("result") != "success"]
    sqlite = data.get("sqlite", {})
    total, failures, skipped = sqlite.get("tests"), sqlite.get("failures"), sqlite.get("skipped")
    passed = total - failures - skipped if None not in (total, failures, skipped) else None
    minimum = os.environ.get("MIN_LINE_COVERAGE")
    measured = version_source.get("coverage")
    coverage_result = "Not available" if measured is None or minimum is None else "Passed" if float(measured.rstrip("%")) >= float(minimum) else "Failed"
    lines = [f"# {'🔴 CI failed' if failed else '🟢 CI passed'}", "",
             "| Tests · SQLite | Line coverage | Checks |", "| :--- | :--- | :--- |",
             f"| **{display(passed)} / {display(total)} passed**<br>{display(skipped)} skipped | **{display(measured)}**<br>`app/` · minimum {minimum + '%' if minimum else 'Not available'} · {coverage_result} | **{len(jobs) - len(failed)} / {len(jobs)} passed** |",
             "", f"**Environment:** PHP {display(version_source.get('php'))} · Laravel {display(version_source.get('laravel'))}",
             "", "## Tested databases", "", "| Target | Result | Passed / total | Skipped |", "| --- | --- | ---: | ---: |"]
    for label, key in suites:
        item = data.get(key, {})
        total, failures, skipped = item.get("tests"), item.get("failures"), item.get("skipped")
        passed = total - failures - skipped if None not in (total, failures, skipped) else None
        lines.append(f"| {label} | {status(item.get('result'))} | {display(passed)} / {display(total)} | {display(skipped)} |")
    if failed:
        lines += ["", "## Checks needing attention", "", "| Job | Result |", "| --- | --- |"]
        lines += [f"| {label} | {status(needs.get(key, {}).get('result'))} |" for label, key in failed]
    lines += ["", f"[Download test and coverage reports]({os.environ.get('GITHUB_SERVER_URL', 'https://github.com')}/{os.environ.get('GITHUB_REPOSITORY', '')}/actions/runs/{os.environ.get('GITHUB_RUN_ID', '')}#artifacts). These are files saved by this CI run."]
    if repo == "lab":
        lines += ["The database matrix rows reflect each job that uploaded a result. The browser job uses PostgreSQL 18."]
    Path(os.environ["GITHUB_STEP_SUMMARY"]).write_text("\n".join(lines) + "\n")


if __name__ == "__main__":
    if sys.argv[1] == "collect":
        collect(sys.argv[2], sys.argv[3])
    elif sys.argv[1] == "render":
        render(sys.argv[2])
    else:
        raise SystemExit("Expected collect or render")
