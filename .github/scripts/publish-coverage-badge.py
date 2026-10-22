"""Publish the measured line coverage as a Shields endpoint badge."""

import base64
import json
import os
import re
import sys
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen


API = f"https://api.github.com/repos/{os.environ['GITHUB_REPOSITORY']}"
TOKEN = os.environ["GH_TOKEN"]
BRANCH = "coverage-badge"
FILE = "coverage.json"


def request(method, path, data=None, missing_ok=False):
    body = json.dumps(data).encode() if data is not None else None
    req = Request(
        f"{API}/{path}",
        data=body,
        method=method,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {TOKEN}",
            "Content-Type": "application/json",
            "User-Agent": "coverage-badge-publisher",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    try:
        with urlopen(req, timeout=30) as response:
            return json.load(response)
    except HTTPError as error:
        if missing_ok and error.code == 404:
            return None
        raise


metrics = json.loads(Path(sys.argv[1]).read_text())
coverage = metrics.get("coverage")
if not isinstance(coverage, str) or not re.fullmatch(r"(?:100(?:\.0+)?|\d{1,2}(?:\.\d+)?)%", coverage):
    raise SystemExit("Measured line coverage is missing or invalid")

source_sha = os.environ.get("SOURCE_SHA")
source_branch = os.environ.get("SOURCE_BRANCH")
if source_sha and source_branch:
    head = request("GET", f"git/ref/heads/{source_branch}")
    if head["object"]["sha"] != source_sha:
        print("Skipping coverage from an older default-branch commit")
        raise SystemExit(0)

badge = json.dumps(
    {"schemaVersion": 1, "label": "coverage · PHP 8.4", "message": coverage, "color": "blue"},
    separators=(",", ":"),
).encode()

if request("GET", f"git/ref/heads/{BRANCH}", missing_ok=True) is None:
    request("POST", "git/refs", {"ref": f"refs/heads/{BRANCH}", "sha": source_sha or os.environ["GITHUB_SHA"]})

current = request("GET", f"contents/{FILE}?ref={BRANCH}", missing_ok=True)
if current is not None and base64.b64decode(current["content"]) == badge:
    print(f"Coverage badge already shows {coverage}")
    raise SystemExit(0)

update = {
    "message": f"chore(ci): update coverage badge to {coverage}",
    "content": base64.b64encode(badge).decode(),
    "branch": BRANCH,
}
if current is not None:
    update["sha"] = current["sha"]
request("PUT", f"contents/{FILE}", update)
print(f"Published coverage badge: {coverage}")
