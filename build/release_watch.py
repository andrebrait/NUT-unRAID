#!/usr/bin/env python3
"""Decide which official Unraid releases still need NUT package verification, and record the outcome.

Discovery (releases.unraid.net, authoritative ZIP SHA256s) is NerdTools' check-unraid-releases.py at the
pinned commit; this only dedups its report against .github/tested-unraid.json and open issues.
Set NERDTOOLS to that checkout for the version ordering.
"""
import argparse
import datetime
import importlib.util
import json
import os
from pathlib import Path
import re
import sys

REPO = Path(__file__).resolve().parent.parent
PLG = REPO / "plugin/nut3.plg"
# Not under build/: native-nut.yml rebuilds on pushes to build/**.
TESTED = REPO / ".github/tested-unraid.json"


def plg_entity(name):
    return re.search(rf'<!ENTITY {name}\s+"([^"]+)"', PLG.read_text()).group(1)


def issue_title(version):
    return f"Unraid {version}: NUT package verification failed"


def version_key(version):
    path = Path(os.environ["NERDTOOLS"]) / "scripts/check-unraid-releases.py"
    spec = importlib.util.spec_from_file_location("check_unraid_releases", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.version_key(version)


def select(candidates, tested, open_titles, package, version=None, dry_run=False):
    """Releases to verify: an explicit version, the newest one (dry run), or those not yet tested."""
    if version:
        chosen = [r for r in candidates if r["version"] == version]
        if not chosen:
            raise ValueError(f"Unraid {version} is not an official release at or above the supported minimum")
        return chosen
    if dry_run:
        return sorted(candidates, key=lambda r: version_key(r["version"]))[-1:]
    # Same ZIP and same shipped package = same result; a failure keeps an open issue until someone closes it.
    def done(r):
        entry = tested.get(r["version"], {})
        return entry.get("sha256") == r["sha256"] and entry.get("package") == package

    return [r for r in candidates if not done(r) and issue_title(r["version"]) not in open_titles]


VERIFY_STEP = "Install with upgradepkg"


def results(matrix, jobs):
    """Per-version outcome from this run's reusable-workflow jobs, named '<caller job> / verify'.

    Only a failed verification step counts as a failed verification; a runner/apt/artifact failure raises,
    so it is retried on the next run instead of filing a bogus issue.
    """
    outcomes = []
    for release in matrix:
        found = [j for j in jobs if f"verify {release['version']} /" in j["name"] and j["name"].endswith("/ verify")
                 and j["conclusion"] in ("success", "failure")]
        if len(found) != 1:
            raise ValueError(f"Expected one finished verify job for {release['version']}, found {len(found)}")
        job = found[0]
        failed = [s["name"] for s in job["steps"] if s["conclusion"] == "failure"]
        if any(not name.startswith(VERIFY_STEP) for name in failed):
            raise ValueError(f"Unraid {release['version']}: infrastructure failure in {failed}, not a verification result")
        outcomes.append({**release, "ok": job["conclusion"] == "success", "job_url": job["html_url"]})
    return outcomes


def record(tested, outcomes, package, today):
    for item in outcomes:
        if item["ok"]:
            tested[item["version"]] = {"sha256": item["sha256"], "package": package, "verified": today}
    return dict(sorted(tested.items()))


def load(path):
    return json.loads(Path(path).read_text()) if Path(path).exists() else {}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    s = sub.add_parser("select")
    s.add_argument("--report", required=True, help="check-unraid-releases.py JSON report")
    s.add_argument("--issues", required=True, help="`gh issue list --state open --json title` output")
    s.add_argument("--version", default="")
    s.add_argument("--dry-run", action="store_true")
    r = sub.add_parser("results")
    r.add_argument("--matrix", required=True, help="JSON {include: [...]} that was verified")
    r.add_argument("--jobs", required=True, help="JSON array of this run's jobs")
    c = sub.add_parser("record")
    c.add_argument("--results", required=True)
    args = parser.parse_args(argv)
    package = plg_entity("nutNAME")
    if args.command == "select":
        report = json.loads(Path(args.report).read_text())
        open_titles = {i["title"] for i in json.loads(Path(args.issues).read_text())}
        chosen = select(report["matrix"]["include"], load(TESTED), open_titles, package,
                        args.version, args.dry_run)
        print(json.dumps({"include": chosen}, separators=(",", ":")))
    elif args.command == "results":
        print(json.dumps(results(json.loads(args.matrix)["include"], json.loads(Path(args.jobs).read_text()))))
    else:
        outcomes = json.loads(Path(args.results).read_text())
        today = datetime.date.today().isoformat()
        TESTED.write_text(json.dumps(record(load(TESTED), outcomes, package, today), indent=2) + "\n")


if __name__ == "__main__":
    sys.exit(main())
