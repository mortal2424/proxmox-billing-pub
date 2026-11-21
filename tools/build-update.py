#!/usr/bin/env python3
"""Build a conflict-aware update manifest from two Git commits."""
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--base", required=True)
parser.add_argument("--target", required=True)
parser.add_argument("--from-version", required=True)
parser.add_argument("--version", required=True)
parser.add_argument("--changelog", type=Path, required=True)
args = parser.parse_args()
for value in (args.from_version, args.version):
    if not re.fullmatch(r"\d+\.\d+\.\d+(?:-[A-Za-z0-9.]+)?", value):
        parser.error("Invalid version")

def git(*values):
    return subprocess.check_output(["git", *values])

base = git("rev-parse", "--verify", args.base + "^{commit}").decode().strip()
target = git("rev-parse", "--verify", args.target + "^{commit}").decode().strip()
files = {}
for row in git("diff", "--name-status", "--no-renames", base, target).decode().splitlines():
    status, path = row.split("\t", 1)
    if path == "install/sql-install.sql" or path.startswith(("updates/", "tools/", ".github/", "mobile/")) or path in ("README.md", ".DS_Store"):
        continue
    if status == "D":
        parser.error("Deletion needs a separately reviewed migration: " + path)
    if (path != ".htaccess" and not re.fullmatch(r"[A-Za-z0-9_-][A-Za-z0-9_./ -]*", path)) or ".." in path.split("/"):
        parser.error("Unsupported path: " + path)
    if path.startswith(("uploads/", "logs/", "backups/", "vendor/")) or path == "includes/config.php":
        parser.error("Runtime/configuration path: " + path)
    before = None if status == "A" else hashlib.sha256(git("show", base + ":" + path)).hexdigest()
    files[path] = {"before": before, "sha256": hashlib.sha256(git("show", target + ":" + path)).hexdigest()}
manifest = {"format": 1, "version": args.version, "from": args.from_version,
            "source_commit": target, "changelog": args.changelog.read_text(), "files": files}
destination = Path("updates") / args.version / "manifest.json"
destination.parent.mkdir(parents=True, exist_ok=True)
destination.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
print(destination)
print("Commit the manifest, then add its commit and SHA-256 to updates/index.json.")
print(hashlib.sha256(destination.read_bytes()).hexdigest())
