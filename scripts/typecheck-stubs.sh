#!/usr/bin/env bash
# Type-check stubs/inertia-react (pages and components) against each host app's node_modules
# (React + @inertiajs/react versions vary between apps). Nothing is written
# into the apps.
set -euo pipefail

[ "$#" -gt 0 ] || { echo "Usage: scripts/typecheck-stubs.sh <app-dir>..." >&2; exit 1; }
root="$(cd "$(dirname "$0")/.." && pwd)"
status=0

for app in "$@"; do
  nm="$(cd "$app" && pwd)/node_modules"
  [ -x "$nm/.bin/tsc" ] || { echo "$app: no node_modules/.bin/tsc (run npm/pnpm install there)"; status=1; continue; }

  # Lay the files out as mfa:install publishes them: pages under pages/mfa/,
  # components under components/vendor/laravel-mfa/ (imported via "@/").
  work="$(mktemp -d)"
  mkdir -p "$work/pages/mfa" "$work/components/vendor/laravel-mfa"
  cp "$root"/stubs/inertia-react/pages/* "$work/pages/mfa/"
  cp "$root"/stubs/inertia-react/components/* "$work/components/vendor/laravel-mfa/"
  # The integration snippets from docs/integration.md step 6, as an app writes them.
  cat > "$work/pages/integration-examples.tsx" <<'TSX'
import MfaApiKeyNotice from '@/components/vendor/laravel-mfa/api-key-notice';
import MfaSettingsCard from '@/components/vendor/laravel-mfa/settings-card';
import { mfaApiKeyNoticeProps, mfaSettingsCardProps, useMfa } from '@/pages/mfa/mfa-context';
import { Link } from '@inertiajs/react';

export default function AccountSettings() {
    const mfa = useMfa();

    return (
        <>
            <MfaSettingsCard {...mfaSettingsCardProps(mfa)} renderLink={(link) => <Link {...link} />} />
            <MfaSettingsCard {...mfaSettingsCardProps(mfa)} />
            <MfaApiKeyNotice {...mfaApiKeyNoticeProps(mfa)} />
        </>
    );
}
TSX
  cat > "$work/tsconfig.json" <<JSON
{ "compilerOptions": { "target": "ES2022", "module": "ESNext", "moduleResolution": "bundler", "jsx": "react-jsx",
  "strict": true, "noEmit": true, "skipLibCheck": true, "lib": ["DOM", "ES2022"], "baseUrl": ".",
  "paths": { "@/*": ["./*"], "*": ["$nm/@types/*", "$nm/*"] }, "typeRoots": ["$nm/@types"] }, "include": ["pages/**/*", "components/**/*"] }
JSON
  if (cd "$work" && "$nm/.bin/tsc" -p .); then echo "$app: OK"; else echo "$app: FAILED"; status=1; fi
  rm -rf "$work"
done

exit $status
