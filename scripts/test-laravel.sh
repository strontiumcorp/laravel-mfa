#!/usr/bin/env bash
# Run the test suite against a given Laravel major (11, 12 or 13) in a scratch
# copy, so the main vendor/ is left alone. The copy is reused between runs.
# A second argument "lowest" installs the lowest allowed dependencies, like
# CI's prefer-lowest jobs.
set -euo pipefail

version="${1:?Usage: scripts/test-laravel.sh 11|12|13 [lowest]}"
stability="${2:-stable}"
case "$stability" in
  stable) lowest='' ;;
  lowest) lowest='--prefer-lowest --prefer-stable' ;;
  *) echo "Unknown stability: $stability (use lowest)" >&2; exit 1 ;;
esac
case "$version" in
  11) testbench='^9.0' ;;
  12) testbench='^10.0' ;;
  13) testbench='^11.0' ;;
  *) echo "Unsupported Laravel version: $version" >&2; exit 1 ;;
esac

root="$(cd "$(dirname "$0")/.." && pwd)"
work="${TMPDIR:-/tmp}/laravel-mfa-l${version}-${stability}"
mkdir -p "$work"

rsync -a --delete --exclude vendor --exclude composer.lock --exclude .git \
  --exclude build --exclude .phpunit.cache "$root/" "$work/"

cd "$work"
# Every Laravel 11 release has unpatched advisories (EOL); Composer 2.9 blocks
# them by default. Allowed here only to verify compatibility.
[ "$version" = "11" ] && composer config audit.block-insecure false

if [ ! -f "vendor/.laravel-$version" ]; then
  # shellcheck disable=SC2086 # $lowest is a list of flags
  composer update --no-interaction --no-progress -q -W $lowest \
    --with "laravel/framework:^${version}.0" --with "orchestra/testbench:${testbench}"
  touch "vendor/.laravel-$version"
fi

# Always refresh the autoloader: sources (and namespaces) change between runs.
composer dump-autoload -q

echo "Laravel $(composer show laravel/framework 2>/dev/null | awk '/^versions/ {print $NF}') ($stability dependencies)"
vendor/bin/pest --parallel
