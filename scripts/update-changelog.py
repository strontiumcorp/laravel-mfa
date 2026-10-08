#!/usr/bin/env python3
"""Release notes, CHANGELOG.md and the next version, from Conventional Commits.

  update-changelog.py next [--bump auto|major|minor|patch]
      Print the next tag. "auto" reads the commits since the latest release:
      breaking change -> major, feat -> minor, anything else -> patch. Below
      1.0.0 a breaking change bumps the minor version instead.

  update-changelog.py notes TAG [--notes FILE] [--changelog FILE]
      Write release notes for the commits since the latest release and
      prepend them to the changelog (replacing an existing TAG section).

  update-changelog.py notes TAG --extract-existing [--notes FILE]
      Copy the existing TAG section of the changelog into the notes file.

Only strict vX.Y.Z tags reachable from HEAD count as releases.
"""
import argparse
import datetime as dt
import pathlib
import re
import subprocess
import sys

SEMVER_TAG = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
SUBJECT = re.compile(r"^(?P<type>[a-z]+)(?:\((?P<scope>[^)]+)\))?(?P<breaking>!)?: (?P<summary>.+)$")

# Changelog sections, in order. Unknown or unconventional types go to "Other".
SECTIONS = [
    ("feat", "Features"),
    ("fix", "Fixes"),
    ("perf", "Performance"),
    ("refactor", "Refactoring"),
    ("docs", "Documentation"),
]
OTHER = "Other"

FIELD = "\x1f"
RECORD = "\x1e"


def git(*args: str) -> str:
    return subprocess.run(["git", *args], check=True, capture_output=True, text=True).stdout


def latest_release() -> tuple[str, tuple[int, int, int]] | None:
    """The highest strict vX.Y.Z tag reachable from HEAD."""
    tags = []
    for tag in git("tag", "--list", "v*", "--merged", "HEAD").split():
        match = SEMVER_TAG.match(tag)
        if match:
            tags.append((tuple(int(part) for part in match.groups()), tag))
    if not tags:
        return None
    version, tag = max(tags)
    return tag, version


def commits_since(tag: str | None) -> list[dict]:
    revision_range = f"{tag}..HEAD" if tag else "HEAD"
    output = git("log", "--no-merges", f"--format=%h{FIELD}%s{FIELD}%b{RECORD}", revision_range)
    commits = []
    release_commit = re.compile(r"^chore: release v\d+\.\d+\.\d+$")
    for record in output.split(RECORD):
        record = record.strip("\n")
        if not record:
            continue
        sha, subject, body = (record.split(FIELD) + ["", ""])[:3]
        if release_commit.match(subject):
            continue  # the previous release's own changelog commit
        match = SUBJECT.match(subject)
        breaking = bool(re.search(r"^BREAKING[ -]CHANGE:", body, re.MULTILINE))
        if match:
            commits.append({
                "sha": sha,
                "type": match["type"],
                "scope": match["scope"],
                "summary": match["summary"],
                "breaking": breaking or bool(match["breaking"]),
            })
        else:
            commits.append({"sha": sha, "type": None, "scope": None, "summary": subject, "breaking": breaking})
    return commits


def infer_bump(commits: list[dict]) -> str:
    if any(c["breaking"] for c in commits):
        return "major"
    if any(c["type"] == "feat" for c in commits):
        return "minor"
    return "patch"


def next_tag(bump: str) -> str:
    release = latest_release()
    major, minor, patch = release[1] if release else (0, 0, 0)

    if bump == "auto":
        bump = infer_bump(commits_since(release[0] if release else None))
    if bump == "major" and major == 0:
        bump = "minor"  # 0.x: breaking changes bump the minor version

    if bump == "major":
        return f"v{major + 1}.0.0"
    if bump == "minor":
        return f"v{major}.{minor + 1}.0"
    return f"v{major}.{minor}.{patch + 1}"


def line(commit: dict) -> str:
    scope = f"**{commit['scope']}:** " if commit["scope"] else ""
    return f"- {scope}{commit['summary']} ({commit['sha']})"


def render_notes(tag: str, prior: str | None, commits: list[dict]) -> str:
    today = dt.date.today().isoformat()  # the releaser's local date
    intro = f"Changes since `{prior}`." if prior else "Initial release."
    parts = [f"## {tag} - {today}", f"_{intro}_"]

    # Breaking changes are listed once, above the type sections. A first
    # release has nothing to break, so they stay in their type section.
    breaking = [c for c in commits if c["breaking"]] if prior else []
    if breaking:
        parts.append("### Breaking changes\n\n" + "\n".join(line(c) for c in breaking))

    rest = [c for c in commits if c not in breaking]
    known = {key for key, _ in SECTIONS}
    groups = [(title, [c for c in rest if c["type"] == key]) for key, title in SECTIONS]
    groups.append((OTHER, [c for c in rest if c["type"] not in known]))

    for title, items in groups:
        if items:
            parts.append(f"### {title}\n\n" + "\n".join(line(c) for c in items))

    if not commits:
        parts.append("- No changes recorded.")

    return "\n\n".join(parts) + "\n"


def update_changelog(changelog: pathlib.Path, tag: str, notes: str) -> None:
    existing = changelog.read_text(encoding="utf-8") if changelog.exists() else ""
    body = existing.removeprefix("# Changelog").strip()

    # Drop an existing section for this tag (re-running a release).
    kept, skipping = [], False
    for row in body.splitlines():
        if row.startswith("## "):
            skipping = row.startswith(f"## {tag} - ")
        if not skipping:
            kept.append(row)
    rest = "\n".join(kept).strip()

    changelog.write_text("# Changelog\n\n" + notes + (f"\n{rest}\n" if rest else ""), encoding="utf-8")


def extract_section(changelog: pathlib.Path, tag: str) -> str | None:
    if not changelog.exists():
        return None
    section, collecting = [], False
    for row in changelog.read_text(encoding="utf-8").splitlines():
        if row.startswith("## "):
            if collecting:
                break
            collecting = row.startswith(f"## {tag} - ")
        if collecting:
            section.append(row)
    return "\n".join(section).strip() + "\n" if section else None


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)

    nxt = commands.add_parser("next", help="Print the next release tag")
    nxt.add_argument("--bump", choices=["auto", "major", "minor", "patch"], default="auto")

    notes = commands.add_parser("notes", help="Write release notes and update the changelog")
    notes.add_argument("tag")
    notes.add_argument("--notes", default="release-notes.md")
    notes.add_argument("--changelog", default="CHANGELOG.md")
    notes.add_argument("--extract-existing", action="store_true")

    args = parser.parse_args()

    if args.command == "next":
        print(next_tag(args.bump))
        return

    if not SEMVER_TAG.match(args.tag):
        sys.exit(f"Expected a tag like v1.2.3, got: {args.tag}")

    if args.extract_existing:
        section = extract_section(pathlib.Path(args.changelog), args.tag)
        if section is None:
            sys.exit(f"No changelog section found for {args.tag}")
        pathlib.Path(args.notes).write_text(section, encoding="utf-8")
        return

    release = latest_release()
    prior = release[0] if release else None
    rendered = render_notes(args.tag, prior, commits_since(prior))
    pathlib.Path(args.notes).write_text(rendered, encoding="utf-8")
    update_changelog(pathlib.Path(args.changelog), args.tag, rendered)


if __name__ == "__main__":
    main()
