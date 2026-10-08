#!/usr/bin/env bash
# Cut a release: changelog commit + annotated tag, pushed to origin.
# Run through `make release` (see usage below). Composer reads the version
# from the tag, so no file carries a version number.
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/release.sh [--major|--minor|--patch] [vX.Y.Z] [--dry-run] [--yes]
       make release ARGS="..."

  1. Picks the tag: inferred from the commits since the last release
     (breaking -> major, feat -> minor, else patch; below 1.0.0 a breaking
     change bumps minor), overridden by a flag or an explicit vX.Y.Z.
  2. Checks: on main, clean tree, up to date with origin/main, `make ci` green.
  3. Prepends the release notes to CHANGELOG.md and commits it
     ("chore: release vX.Y.Z").
  4. Creates an annotated tag carrying the release notes.
  5. Asks for confirmation, then pushes main and the tag atomically.

  --dry-run  Preview the tag and notes; changes nothing (works on a dirty tree).
  --yes      Accept the inferred tag and skip the push confirmation.
USAGE
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
changelog_tool="scripts/update-changelog.py"
branch="main"

bump="auto"
assume_yes="false"
dry_run="false"
tag=""

while [ "$#" -gt 0 ]; do
  case "$1" in
    --major) bump="major" ;;
    --minor) bump="minor" ;;
    --patch) bump="patch" ;;
    -y|--yes) assume_yes="true" ;;
    --dry-run) dry_run="true" ;;
    -h|--help) usage; exit 0 ;;
    *)
      if [[ "$1" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        tag="v${1#v}"
      else
        echo "Unknown argument: $1 (tags must look like v1.2.3)" >&2
        usage >&2
        exit 1
      fi
      ;;
  esac
  shift
done

fail() { echo "release: $*" >&2; exit 1; }

# --- Preconditions (a dry run only needs a repository) ---------------------

if [ "$dry_run" != "true" ]; then
  [ "$(git rev-parse --abbrev-ref HEAD)" = "$branch" ] || fail "releases are cut from $branch (on $(git rev-parse --abbrev-ref HEAD))"
  [ -z "$(git status --porcelain)" ] || { git status --short >&2; fail "the working tree must be clean"; }
  git remote get-url origin >/dev/null 2>&1 || fail "no 'origin' remote; add it first: git remote add origin git@github.com:strontiumcorp/laravel-mfa.git"

  git fetch --quiet --tags origin
  if git rev-parse --verify --quiet "origin/$branch" >/dev/null; then
    [ -z "$(git rev-list "HEAD..origin/$branch")" ] || fail "$branch is behind origin/$branch; pull first"
  fi
fi

# --- Tag --------------------------------------------------------------------

last_release="$(git tag --list 'v*' --merged HEAD --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1 || true)"
if [ -n "$last_release" ] && [ -z "$(git rev-list "$last_release..HEAD")" ]; then
  fail "no commits since $last_release; nothing to release"
fi

default_tag="$(python3 "$changelog_tool" next --bump "$bump")"

if [ -z "$tag" ]; then
  if [ "$assume_yes" = "true" ] || [ "$dry_run" = "true" ]; then
    tag="$default_tag"
  else
    read -r -p "Release tag [$default_tag]: " entered
    entered="${entered:-$default_tag}"
    [[ "$entered" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "not a version: $entered"
    tag="v${entered#v}"
  fi
fi

if git rev-parse --verify --quiet "refs/tags/$tag" >/dev/null; then
  fail "tag $tag already exists"
fi

notes="$(mktemp)"
trap 'rm -f "$notes" "${preview_changelog:-}"' EXIT

# --- Dry run ----------------------------------------------------------------

if [ "$dry_run" = "true" ]; then
  preview_changelog="$(mktemp)"
  [ -f CHANGELOG.md ] && cp CHANGELOG.md "$preview_changelog"
  python3 "$changelog_tool" notes "$tag" --notes "$notes" --changelog "$preview_changelog"

  echo "Dry run: would release $tag (nothing was changed)."
  echo
  cat "$notes"
  exit 0
fi

# --- Release ------------------------------------------------------------------

echo "Preparing $tag: running make ci"
make ci

python3 "$changelog_tool" notes "$tag" --notes "$notes" --changelog CHANGELOG.md
[ -n "$(git status --porcelain -- CHANGELOG.md)" ] || fail "CHANGELOG.md did not change; nothing to release"

git add CHANGELOG.md
git commit --quiet -m "chore: release $tag"
git tag -a --cleanup=verbatim "$tag" -F "$notes"

echo
cat "$notes"
echo

if [ "$assume_yes" != "true" ]; then
  read -r -p "Push $branch and $tag to origin? [y/N] " answer
  if [[ ! "$answer" =~ ^[Yy]$ ]]; then
    echo "Not pushed. The release commit and tag are local. To publish: git push --atomic origin $branch $tag"
    echo "To undo: git tag -d $tag && git reset --hard HEAD~1"
    exit 0
  fi
fi

git push --atomic origin "$branch" "$tag"
echo "Released $tag"
