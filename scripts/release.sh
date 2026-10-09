#!/usr/bin/env bash
# Cut a release. Composer reads the version from the tag, so no file carries a
# version number.
#
#   make release      (default) on an up-to-date main: changelog commit plus an
#                     annotated tag, pushed to origin together.
#   make release-pr   for a protected main: opens a "chore: release vX.Y.Z"
#                     pull request with the CHANGELOG.md update instead...
#   make release-tag  ...and, once it's merged, tags the merged release commit
#                     and pushes only the tag.
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/release.sh [--pr] [--major|--minor|--patch] [vX.Y.Z] [--dry-run] [--yes]
       scripts/release.sh --tag [--dry-run] [--yes]
       make release ARGS="..."  |  make release-pr ARGS="..."  |  make release-tag ARGS="..."

Both ways of releasing pick the tag the same way: inferred from the commits
since the last release (breaking -> major, feat -> minor, else patch; below
1.0.0 a breaking change bumps minor), overridden by a flag or an explicit
vX.Y.Z. Both refuse while a release merged through a pull request is untagged.

Direct release (default):
  1. Checks: on main, clean tree, up to date with origin/main.
  2. Runs `make ci`, prepends the release notes to CHANGELOG.md and commits it
     ("chore: release vX.Y.Z").
  3. Creates an annotated tag carrying the release notes.
  4. Asks for confirmation, then pushes main and the tag atomically.

Through a pull request (--pr), when main only accepts reviewed changes:
  1. Checks: clean tree, no open release pull request. Works from origin/main
     whatever is checked out.
  2. Creates branch release/vX.Y.Z, runs `make ci`, and commits the
     CHANGELOG.md update.
  3. Asks for confirmation, then pushes the branch and opens the pull request.
  Then, once it's merged (any merge method; keep the title if you squash):
  --tag finds the merged "chore: release vX.Y.Z" commit on origin/main,
  creates an annotated tag on it carrying its changelog section, and pushes
  only the tag.

Every tag push runs the full matrix, which publishes the GitHub Release when
it passes.

  --dry-run  Preview; changes nothing (a release preview works on a dirty tree).
  --yes      Accept the inferred tag and skip the confirmations.
USAGE
}

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"
changelog_tool="scripts/update-changelog.py"
branch="main"
remote="origin"

mode="direct"
bump="auto"
assume_yes="false"
dry_run="false"
tag=""

while [ "$#" -gt 0 ]; do
  case "$1" in
    --tag) mode="tag" ;;
    --pr) mode="pr" ;;
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

# --- Release: direct (default) or through a pull request (--pr) --------------

if [ "$dry_run" != "true" ]; then
  [ -z "$(git status --porcelain)" ] || { git status --short >&2; fail "the working tree must be clean"; }
  if [ "$mode" = "pr" ]; then
    command -v gh >/dev/null 2>&1 || fail "the GitHub CLI (gh) is needed to open the pull request"
  fi
fi

git fetch --quiet --tags "$remote"
git rev-parse --verify --quiet "$remote/$branch" >/dev/null || fail "no $remote/$branch; push $branch first"

pending="$(git show "$remote/$branch:CHANGELOG.md" 2>/dev/null | newest_changelog_tag)"
if [ -n "$pending" ] && ! tag_exists "$pending"; then
  fail "$pending is merged but not tagged; tag it first with: make release-tag"
fi

if [ "$mode" = "pr" ] && [ "$dry_run" != "true" ]; then
  open_prs="$(gh pr list --base "$branch" --state open --json number,headRefName --jq '.[] | select(.headRefName | startswith("release/")) | "#\(.number) \(.headRefName)"')"
  [ -z "$open_prs" ] || fail "a release pull request is already open: $open_prs"
fi

if [ "$mode" = "pr" ]; then
  # Work from origin/main whatever is checked out, and come back afterwards.
  if [ "$dry_run" != "true" ]; then
    start="$(git symbolic-ref --quiet --short HEAD || git rev-parse HEAD)"
    git switch --quiet --detach "$remote/$branch"
    revision="HEAD"
  else
    revision="$remote/$branch"
  fi
else
  # Release what's checked out: main, with everything origin/main has.
  if [ "$dry_run" != "true" ]; then
    [ "$(git rev-parse --abbrev-ref HEAD)" = "$branch" ] || fail "releases are cut from $branch (on $(git rev-parse --abbrev-ref HEAD)); or use make release-pr"
    [ -z "$(git rev-list "HEAD..$remote/$branch")" ] || fail "$branch is behind $remote/$branch; pull first"
  fi
  revision="HEAD"
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
  git show "$revision:CHANGELOG.md" > "$preview_changelog" 2>/dev/null || true
  python3 "$changelog_tool" notes "$tag" --notes "$notes" --changelog "$preview_changelog" --rev "$revision"

  if [ "$mode" = "pr" ]; then
    echo "Dry run: would open $release_branch -> $branch for $tag (nothing was changed)."
  else
    echo "Dry run: would release $tag (nothing was changed)."
  fi
  echo
  cat "$notes"
  exit 0
fi

if [ "$mode" = "pr" ]; then
  git rev-parse --verify --quiet "refs/heads/$release_branch" >/dev/null && fail "branch $release_branch already exists locally; delete it first: git branch -D $release_branch"
  git switch --quiet --create "$release_branch"
  echo "Preparing $tag on $release_branch: running make ci"
else
  echo "Preparing $tag: running make ci"
fi
make ci

python3 "$changelog_tool" notes "$tag" --notes "$notes" --changelog CHANGELOG.md
[ -n "$(git status --porcelain -- CHANGELOG.md)" ] || fail "CHANGELOG.md did not change; nothing to release"

git add CHANGELOG.md
git commit --quiet -m "chore: release $tag"

if [ "$mode" = "direct" ]; then
  git tag -a --cleanup=verbatim "$tag" -F "$notes"

  echo
  cat "$notes"
  echo

  if ! confirm "Push $branch and $tag to $remote?"; then
    echo "Not pushed. The release commit and tag are local. To publish: git push --atomic $remote $branch $tag"
    echo "To undo: git tag -d $tag && git reset --hard HEAD~1"
    exit 0
  fi

  git push --atomic "$remote" "$branch" "refs/tags/$tag"
  echo "Released $tag. The full matrix runs now and publishes the GitHub Release when it passes."
  exit 0
fi

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
