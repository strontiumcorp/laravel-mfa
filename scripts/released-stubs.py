#!/usr/bin/env python3
"""Write stubs/inertia-react/released.json: the SHA-256 of every page,
component and theme file as each release (v* tag) shipped it, keyed by the
stub's path ("pages/settings.tsx", "components/factor-cards.tsx",
"css/theme.css"). mfa:install replaces a published file without --force only
when it still matches one of these: the app never changed it.

Contents are hashed with CRLF turned into LF, as mfa:install compares them.
Run by scripts/release.sh before each release commit; run it by hand with
`python3 scripts/released-stubs.py`.
"""
import hashlib
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
STUBS = "stubs/inertia-react/"
OUT = ROOT / STUBS / "released.json"


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True, text=True).stdout


def key(path: str) -> str | None:
    """Where a stub of that release is published now, as a path under the stubs."""
    rel = path[len(STUBS):]
    parts = rel.split("/")
    if len(parts) == 1 and rel.endswith((".tsx", ".ts")):
        return f"pages/{rel}"  # v0.1 kept the pages at the top
    if len(parts) == 2 and parts[0] in ("pages", "components", "css"):
        return rel
    return None


def main() -> int:
    tags = [t for t in git("tag", "-l", "v*").split() if t]
    released: dict[str, set[str]] = {}
    for tag in tags:
        for path in git("ls-tree", "-r", "--name-only", tag, "--", STUBS).split("\n"):
            k = key(path) if path else None
            if k is None:
                continue
            blob = subprocess.run(["git", "show", f"{tag}:{path}"], cwd=ROOT, check=True, capture_output=True).stdout
            released.setdefault(k, set()).add(hashlib.sha256(blob.replace(b"\r\n", b"\n")).hexdigest())

    OUT.write_text(json.dumps({k: sorted(v) for k, v in sorted(released.items())}, indent=4) + "\n")
    print(f"{OUT.relative_to(ROOT)}: {len(released)} files from {len(tags)} releases")
    return 0


if __name__ == "__main__":
    sys.exit(main())
