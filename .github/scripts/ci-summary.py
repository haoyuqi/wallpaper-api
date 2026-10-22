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
    data = {
        "name": name,
        "result": result,
        "php": installed_version('echo PHP_VERSION;'),
        "laravel": installed_version('require "vendor/autoload.php"; echo Illuminate\\Foundation\\Application::VERSION;'),
        "tests": count("tests"),
        "failures": count("failures") + count("errors") if suites else None,
        "skipped": count("skipped"),
        "coverage": f"{covered / statements * 100:.2f}%" if statements else None,
    }
    Path("ci-data").mkdir(exist_ok=True)
    Path(f"ci-data/{name}.json").write_text(json.dumps(data))


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

    all_passed = all(needs.get(key, {}).get("result") == "success" for _, key in jobs)
    lines = ["# CI run summary", "", f"**Overall:** {'Passed' if all_passed else 'Failed'}", "",
             "## Checks", "", "| Job | Result |", "| --- | --- |"]
    lines += [f"| {label} | {status(needs.get(key, {}).get('result'))} |" for label, key in jobs]
    lines += ["", "## Database test results", "", "| Target | Result | Passed / total | Skipped |", "| --- | --- | ---: | ---: |"]
    for label, key in suites:
        item = data.get(key, {})
        total, failures, skipped = item.get("tests"), item.get("failures"), item.get("skipped")
        passed = total - failures - skipped if None not in (total, failures, skipped) else None
        lines.append(f"| {label} | {status(item.get('result'))} | {display(passed)} / {display(total)} | {display(skipped)} |")
    lines += ["", "## Coverage", "", "| Scope | Line coverage |", "| --- | ---: |",
              f"| `app/` from SQLite tests | {display(version_source.get('coverage'))} |", "",
              "## Installed environment", "", "| Tool | Version |", "| --- | --- |",
              f"| PHP | {display(version_source.get('php'))} |",
              f"| Laravel | {display(version_source.get('laravel'))} |", "",
              "## Reports", "", f"[Download this run's reports]({os.environ.get('GITHUB_SERVER_URL', 'https://github.com')}/{os.environ.get('GITHUB_REPOSITORY', '')}/actions/runs/{os.environ.get('GITHUB_RUN_ID', '')}#artifacts): `php-coverage` and `ci-data-*` when available."]
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
