#!/usr/bin/env bash
# Cut a release in two steps, because main only accepts changes through a
# reviewed pull request:
#
#   make release      opens a "chore: release vX.Y.Z" pull request with the
#                     CHANGELOG.md update.
#   make release-tag  after that pull request is merged, tags the merged release
#                     commit and pushes the tag (tags aren't branch-protected).
#
# Composer reads the version from the tag, so no file carries a version number.
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/release.sh [--major|--minor|--patch] [vX.Y.Z] [--dry-run] [--yes]
       scripts/release.sh --tag [--dry-run] [--yes]
       make release ARGS="..."   |   make release-tag ARGS="..."

Step 1, open the release pull request (default):
  1. Picks the tag: inferred from the commits on origin/main since the last
     release (breaking -> major, feat -> minor, else patch; below 1.0.0 a
     breaking change bumps minor), overridden by a flag or an explicit vX.Y.Z.
  2. Checks: clean tree, the previous release is tagged, no open release
     pull request.
  3. Creates branch release/vX.Y.Z from origin/main and runs `make ci` on it.
  4. Prepends the release notes to CHANGELOG.md and commits it
     ("chore: release vX.Y.Z").
  5. Asks for confirmation, then pushes the branch and opens the pull request.

Step 2, tag it (--tag), once the pull request is merged (any merge method):
  1. Finds the newest release in origin/main's CHANGELOG.md and its merged
     "chore: release vX.Y.Z" commit.
  2. Creates an annotated tag on that commit carrying its changelog section.
  3. Asks for confirmation, then pushes the tag. The tag push runs the full
     matrix, which publishes the GitHub Release when it passes.

  --dry-run  Preview; changes nothing (step 1 works on a dirty tree).
  --yes      Accept the inferred tag and skip the confirmations.
USAGE
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
changelog_tool="scripts/update-changelog.py"
branch="main"
remote="origin"

mode="prepare"
bump="auto"
assume_yes="false"
dry_run="false"
tag=""

while [ "$#" -gt 0 ]; do
  case "$1" in
    --tag) mode="tag" ;;
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

confirm() {
  [ "$assume_yes" = "true" ] && return 0
  read -r -p "$1 [y/N] " answer
  [[ "$answer" =~ ^[Yy]$ ]]
}

tag_exists() {
  git rev-parse --verify --quiet "refs/tags/$1" >/dev/null
}

# The newest "## vX.Y.Z - date" heading of the CHANGELOG.md on stdin.
newest_changelog_tag() {
  { grep -m 1 -oE '^## v[0-9]+\.[0-9]+\.[0-9]+ - ' || true; } | sed -E 's/^## (v[^ ]+) - $/\1/'
}

# The release commit for a tag on origin/main. A merge keeps the branch commit,
# a rebase rewrites it with the same subject, a squash appends " (#123)".
release_commit() {
  local escaped="${1//./\\.}"
  git log "$remote/$branch" -1 --format=%H -E --grep "^chore: release $escaped( \(#[0-9]+\))?$"
}

notes="$(mktemp)"
start=""
cleanup() {
  rm -f "$notes" "${preview_changelog:-}" "${merged_changelog:-}"
  if [ -n "$start" ]; then git switch --quiet "$start" 2>/dev/null || true; fi
}
trap cleanup EXIT

git remote get-url "$remote" >/dev/null 2>&1 || fail "no '$remote' remote; add it first: git remote add origin git@github.com:strontiumcorp/laravel-mfa.git"

# --- Step 2: tag the merged release ------------------------------------------

if [ "$mode" = "tag" ]; then
  git fetch --quiet --tags "$remote"

  newest="$(git show "$remote/$branch:CHANGELOG.md" 2>/dev/null | newest_changelog_tag)"
  [ -n "$newest" ] || fail "no release in $remote/$branch's CHANGELOG.md; run make release first"
  [ -z "$tag" ] || [ "$tag" = "$newest" ] || fail "$remote/$branch's newest release is $newest, not $tag"
  tag="$newest"

  if tag_exists "$tag"; then
    echo "$tag is already tagged; nothing to do. Start the next release with: make release"
    exit 0
  fi

  sha="$(release_commit "$tag")"
  [ -n "$sha" ] || fail "no 'chore: release $tag' commit on $remote/$branch; is its pull request merged?"

  merged_changelog="$(mktemp)"
  git show "$sha:CHANGELOG.md" > "$merged_changelog"
  python3 "$changelog_tool" notes "$tag" --extract-existing --notes "$notes" --changelog "$merged_changelog"

  echo "Release commit: $(git log -1 --format='%h %s' "$sha")"
  echo
  cat "$notes"
  echo

  if [ "$dry_run" = "true" ]; then
    echo "Dry run: would tag $(git rev-parse --short "$sha") as $tag (nothing was changed)."
    exit 0
  fi

  git tag -a --cleanup=verbatim "$tag" -F "$notes" "$sha"

  if ! confirm "Push $tag to $remote?"; then
    echo "Not pushed. The tag is local. To publish: git push $remote $tag"
    echo "To undo: git tag -d $tag"
    exit 0
  fi

  git push --quiet "$remote" "refs/tags/$tag"
  echo "Tagged $tag. The full matrix runs now and publishes the GitHub Release when it passes."
  exit 0
fi

# --- Step 1: open the release pull request -----------------------------------

if [ "$dry_run" != "true" ]; then
  [ -z "$(git status --porcelain)" ] || { git status --short >&2; fail "the working tree must be clean"; }
  command -v gh >/dev/null 2>&1 || fail "the GitHub CLI (gh) is needed to open the pull request"
fi

git fetch --quiet --tags "$remote"
git rev-parse --verify --quiet "$remote/$branch" >/dev/null || fail "no $remote/$branch; push $branch first"

pending="$(git show "$remote/$branch:CHANGELOG.md" 2>/dev/null | newest_changelog_tag)"
if [ -n "$pending" ] && ! tag_exists "$pending"; then
  fail "$pending is merged but not tagged; tag it first with: make release-tag"
fi

if [ "$dry_run" != "true" ]; then
  open_prs="$(gh pr list --base "$branch" --state open --json number,headRefName --jq '.[] | select(.headRefName | startswith("release/")) | "#\(.number) \(.headRefName)"')"
  [ -z "$open_prs" ] || fail "a release pull request is already open: $open_prs"
fi

# Work from origin/main whatever is checked out, and come back afterwards.
if [ "$dry_run" != "true" ]; then
  start="$(git symbolic-ref --quiet --short HEAD || git rev-parse HEAD)"
  git switch --quiet --detach "$remote/$branch"
  revision="HEAD"
else
  revision="$remote/$branch"
fi

last_release="$(git tag --list 'v*' --merged "$revision" --sort=-v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | head -n 1 || true)"
if [ -n "$last_release" ] && [ -z "$(git rev-list "$last_release..$revision")" ]; then
  fail "no commits on $remote/$branch since $last_release; nothing to release"
fi

default_tag="$(python3 "$changelog_tool" next --bump "$bump" --rev "$revision")"

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

tag_exists "$tag" && fail "tag $tag already exists"
release_branch="release/$tag"

if [ "$dry_run" = "true" ]; then
  preview_changelog="$(mktemp)"
  git show "$remote/$branch:CHANGELOG.md" > "$preview_changelog" 2>/dev/null || true
  python3 "$changelog_tool" notes "$tag" --notes "$notes" --changelog "$preview_changelog" --rev "$revision"

  echo "Dry run: would open $release_branch -> $branch for $tag (nothing was changed)."
  echo
  cat "$notes"
  exit 0
fi

git rev-parse --verify --quiet "refs/heads/$release_branch" >/dev/null && fail "branch $release_branch already exists locally; delete it first: git branch -D $release_branch"
git switch --quiet --create "$release_branch"

echo "Preparing $tag on $release_branch: running make ci"
make ci

python3 "$changelog_tool" notes "$tag" --notes "$notes" --changelog CHANGELOG.md
[ -n "$(git status --porcelain -- CHANGELOG.md)" ] || fail "CHANGELOG.md did not change; nothing to release"

git add CHANGELOG.md
git commit --quiet -m "chore: release $tag"

echo
cat "$notes"
echo

if ! confirm "Push $release_branch and open the release pull request?"; then
  echo "Not pushed. The release commit is on the local branch $release_branch."
  echo "To publish: git push -u $remote $release_branch && gh pr create --base $branch --head $release_branch --title 'chore: release $tag' --body-file <notes>"
  echo "To undo: git branch -D $release_branch"
  exit 0
fi

git push --quiet -u "$remote" "$release_branch"
gh pr create --base "$branch" --head "$release_branch" --title "chore: release $tag" --body-file "$notes"

echo
echo "Opened the release pull request for $tag. Once it's merged (if you squash,"
echo "keep the title \"chore: release $tag\"), tag it with: make release-tag"
