# CLAUDE.md — strontiumcorp/laravel-mfa

Multi-factor authentication for Laravel 11, 12 and 13: TOTP, email and SMS codes, recovery codes, enforcement, React/Inertia pages, JSON mode, observability. Composer `strontiumcorp/laravel-mfa`, namespace `StrontiumCorp\LaravelMfa`, private repo `github.com/strontiumcorp/laravel-mfa`. Maintainer: Mojahidul Islam (`@itsemon245`).

## Goal

One package that three apps install, publish and configure, with no app-specific code:

| App (`~/Work/…`) | Laravel | Frontend | Auth quirks |
|---|---|---|---|
| artistly | 11 (EOL), Kernel-style | Inertia v2, React 18, `Pages/` + `Components/` | login-swap impersonation (`Auth::loginUsingId`), Socialite, `role` is a string, no trusted proxies |
| clone-voice | 12, Octane | Inertia v2, React 19, `pages/` | per-request `Auth::setUser()` impersonation, Google login (Socialite), `UserRole` enum, trusts `'*'` |
| podcast-flow | 13, Octane | Inertia v3, React 19, `pages/` | per-request impersonation, webhooks inside the `web` group, `UserRole` enum, trusts `'*'` |

It must be secure, cheap per request, Octane-safe, fully tested, and observable. Rollout order: **artistly → clone-voice → podcast-flow**.

**The plan is the source of truth for status, decisions and next steps:** [.codex/plans/mfa-integration-plan.md](.codex/plans/mfa-integration-plan.md). Read its Snapshot first. When you finish meaningful work, update its checklists and add a dated entry to its progress log.

## Commands

`php` here is a Docker wrapper, so always go through the Makefile or `vendor/bin/*`. Run `make` to list the targets.

| Command | What it does |
|---|---|
| `make ci` | Pint (check), PHPStan level 6, the parallel Pest suite, `make test-js`. Run before every commit. |
| `make test-js` | `tsc` and Vitest for `stubs/inertia-react` (needs `npm ci`; `make install` does both). |
| `make preview` | Live preview of the published pages and components (`preview/`: Vite, a fake backend with the package's endpoints, scenarios, light/dark, phone/tablet/desktop frames) at http://localhost:5180. Reloads on every edit under `stubs/`. Code `123456`, password `password`. Tailwind comes from its CDN, so it needs internet. Look at UI changes here at all three widths. |
| `make test PROCESSES=2` | The suite grouped the way CI groups it (CI has 2 workers; the local default is one per CPU). |
| `make test-filter FILTER="…"` | Run tests matching a name. |
| `make coverage` | Parallel coverage, fails under 85% (currently ~98%). |
| `make test-matrix` | Laravel 11, 12 and 13, each with newest **and** lowest dependencies, in scratch copies. Run before pushing anything non-trivial. |
| `make test-laravel VERSION=11 [LOWEST=1]` | One cell of the matrix. |
| `make typecheck-stubs APPS="../artistly ../clone-voice ../podcast-flow"` | Type-checks `stubs/inertia-react` (pages and components) against each app's real React/Inertia. Run after any stub change. |
| `make format` | Pint fix. |
| `make release ARGS="--dry-run"` | Preview the next release (see Releasing). |

**Never run mutation tests** (`pest --mutate`): about 36 minutes in this setup, and `--parallel` reports false kills. The `@pest-mutate-ignore: <Mutator>` markers in `src/` document proven-equivalent mutants. Keep them, and keep the reason comment on the line above when you touch those lines.

### Environment gotchas

- Perl can't open files in this repo; use `python3` for scripted edits.
- The shell is zsh: `$VAR` holding a command with arguments isn't word-split. Use env vars or write the command out.
- After `make format`, Pint may turn fully qualified names into `use` imports, so re-read a file before editing it again.

## Git and releases

- Commits: focused, Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`, scopes like `fix(sms):`, `!` for breaking). Short scannable subject; detail goes in extra `-m` paragraphs. **No `Co-Authored-By` or any other trailer.**
- Don't push unless asked. Pushing and tagging are the maintainer's call.
- Releasing: `make release` (`scripts/release.sh` + `scripts/update-changelog.py`). It runs only on a clean `main` that is up to date with `origin`, runs `make ci`, infers the version from the commits (breaking → major, or minor below 1.0; `feat` → minor; else patch), prepends grouped notes to `CHANGELOG.md`, commits `chore: release vX.Y.Z`, creates an annotated tag with the notes, and pushes both atomically after you confirm. Composer reads the version from the tag; no file holds a version. The tag push runs `full-matrix.yml`, whose last job publishes the GitHub Release from the tag's notes once all combinations pass (no Release for a red tag). Release only after CI on GitHub is green.
- CI layout: `.github/workflows/test-suite.yml` holds the test job once (reusable, takes a JSON matrix). `tests.yml` (every push/PR) runs static analysis plus 7 combinations, under the plan's 20-concurrent-job limit so nothing queues. `full-matrix.yml` runs all 22 on `v*` tags, on demand, and nightly if `main` changed. Private repo: each job bills a whole minute, so keep per-push jobs few. Runners are pinned to `ubuntu-24.04` (not `ubuntu-latest`, which moves to Ubuntu 26 from 2026-10-19); bump it deliberately, after a green run on the new image.

## Testing rules (each one learned the hard way)

- **Write the failing test first** for every bug, then fix it, then confirm the test fails without the fix (e.g. `git stash -- src/…`).
- **Never write into Testbench's `vendor/` skeleton.** Parallel workers share it; use a temp dir (see the `mfa:install` tests, `--js-path`).
- **No process-global state in tests.** A parallel worker runs many test files in one PHP process, so a declared class, `class_alias` or `require`d class leaks into later files. Put fixtures under the `StrontiumCorp\LaravelMfa\Tests\` namespace and use per-app state (e.g. set `app()->namespace` or `loadedProviders` by closure binding). Check with `make test PROCESSES=2`.
- **Freeze the clock** (`freezeSecond()`) in any test that asserts an exact time value (`retry_after`). Two requests can straddle a second boundary on a slow runner.
- **Must pass on PHP 8.2** (CI's oldest): e.g. `class_alias()` of an internal class fails there.
- **Must pass on the lowest dependencies:** Laravel 11.x with Carbon 2 truncates `diffInSeconds()`. Compute whole-second waits from timestamps.
- **Guzzle 7 (Laravel 11/12) and Guzzle 8 (Laravel 13) differ:** Guzzle 8 has typed exceptions (`ConnectException` means "never connected"), Guzzle 7 puts curl data in `getHandlerContext()`. Code and tests must handle both.
- SQLite foreign keys are on in `TestCase` (Testbench turns them off), because MFA rows rely on cascades.
- Boot-time config (read while providers boot, e.g. `middleware.append_to_web_group`) needs `$this->rebootWith([...])`.
- Never assert `null`/falsy on a response without asserting its status: a 500 can satisfy it.
- `actingAs()` is never challenged (it doesn't write the session). Use `loginWithSession()` / `actingAsMfaVerified()` from `Testing\InteractsWithMfa` to test MFA itself.

## Architecture and invariants

Don't break these. Each one is covered by tests; read them before changing the area.

**Who is checked**
- `EnsureMfaVerified` is appended to the `web` group (deny by default). It checks the **identity stored in the session** (`Support\SessionIdentity`), never `Auth::user()`. That's why `Auth::setUser()` in webhooks, jobs and per-request impersonation is never challenged, while password, Socialite and remember-me logins always are.
- Verification is **per guard** (`mfa.verified.{guard}.{id}` in the session). Every logged-in MFA guard must pass on its own.
- Users with no factors pass, unless enforcement (`mfa.enforcement.roles` / `.policy`; the v0.1 `mfa.enforce` key is still honoured) says they must enroll.
- Enforced users must hold a factor of `enforcement.required_types` (default totp). Without one they verify with what they have, then a session flag (`mfa.enroll.{guard}.{id}`, set in `markVerified()`, refreshed on factor confirm/remove) holds them on the enrollment routes, so verified requests still cost no query. With one, the challenge lists and accepts only required types (`Mfa::challengeTypes()`, checked server-side in `ChallengeService`); recovery codes still work.
- The configured `routes.logout_route` is always reachable; MFA's own challenge routes are always reachable while a challenge is pending.
- Settings routes are unreachable for an unverified user who has factors (a stolen password can't add a factor). Pending enrollments are bound to the session that started them (`PendingEnrollments`, 30 minutes).
- Adding/removing a factor and regenerating recovery codes need a password confirmed within `auth.password_timeout` (`routes.password_confirmation`, `Http\Middleware\RequirePasswordConfirmation`): JSON gets `423` + `confirm_url`, Inertia a `password_confirmation_required` validation error; the page asks inline (`POST mfa.password.confirm`, rate-limited, evented) and retries. Same session key as Laravel's `password.confirm` (`auth.password_confirmed_at`), so either satisfies the other. Users with an empty password, or exempted by `routes.password_confirmation_policy`, are never asked. `auth.password_timeout` is read as is (no fallback; `mfa:doctor` fails without it). A password lockout flashes `mfa.password_retry_after`, never `mfa.retry_after` (the code countdown). `grantForImpersonation()` forgets the confirmation (it was the impersonator's).
- `Mfa::grantForImpersonation()` (login-swap impersonation): if the target has MFA, the impersonator must have actually passed MFA in this session (D9).
- Disabling a factor type fails open (D10): those users aren't challenged by it any more; `mfa:doctor` counts them.

**Data**
- All MFA rows belong to **one user model** through `user_id` foreign keys (`Mfa::userModel()`: `mfa.user_model`, else the first guard's model; bigint key). User delete cascades to factors, OTP codes and recovery codes; audit rows are kept with `user_id = null` until pruned.
- TOTP secrets and destinations are encrypted casts. OTPs and recovery codes are stored only as HMACs (`CodeHasher`, keyed from `APP_KEY`, `APP_PREVIOUS_KEYS` honoured). Send-limit cache keys are HMACs too (`CacheKey`).
- `FactorType` is a closed enum (totp, email, sms). `Mfa::extend()` replaces a built-in implementation; it can't add types.

**Concurrency and cost**
- OTP issue/verify run under a row lock on the factor (`OtpStore`); only the latest code is valid, burned after `max_attempts`. TOTP replay protection is a compare-and-set on `last_totp_timestep`. Recovery codes are consumed with an atomic conditional update.
- Rate limits count **before** checking (atomic increment), so parallel bursts can't slip through.
- Sends (`SendGuard`) have two budgets: confirmed destinations (cooldown curve + per-account hourly cap + app-wide hourly cap, no per-IP cap) and unconfirmed ones (per-destination daily cap across all accounts, distinct new destinations per account and per IP with IPv6 grouped per /64, global circuit breaker). The cooldown is checked first; every counter is rolled back if any limit refuses.
- A verified session costs zero MFA queries. "Has MFA" is cached; factor model events write through, and fills use `add()` so a stale read can't win.
- Octane: singletons hold no per-request state. Resolve the request and auth from the live container (`Container::getInstance()`), never from the container captured at boot.

**Delivery and SMS**
- Delivery is a job (`DeliverOtp`, encrypted payload). It's queued when `delivery.queue` or `delivery.queue_connection` is set (a `sync` connection sends inline), otherwise it runs inline with immediate errors. A failed delivery discards its code, so no cooldown applies.
- SMS drivers (`twilio`, `vonage`, `infobip`, `sns` with in-package SigV4, `log`, plus the `failover` and `routing` composites) use Laravel's HTTP client through `Sms\Concerns\CallsProviderApi`: 3s connect / 5s total, and a retry **only** when the request never reached the provider (so no duplicate SMS). `SmsManager` builds drivers fresh per send (so `Http::fake()` and config changes apply) and rejects circular configs.
- `DeliveryFailed` messages must never contain the code, the recipient, or credentials (contract; `DeliveryFailed::provider()` also redacts).

**Observability**
- Every action is a domain event (`Events\*`, `MfaActivity`) fanned out to the log, the audit table and metrics. A failing sink is reported and never blocks a login. Failure reasons are the closed `FailureReason` enum.
- Event context is redacted (`Support\Redact`: URLs, emails, phone numbers) before any sink. Never put codes, secrets or full destinations in events.
- A flow ID ties one challenge together and is pushed into Laravel `Context`.

**Config**
- `config/mfa.php` is deep-merged with the app's published copy (`ConfigMerge`), so new nested keys reach apps with old configs. Every new key needs its default in `config/mfa.php`; don't add `config('…', fallback)` defaults in code.
- Closures can't go in config (`config:cache`). Code-level rules are classes named in config that implement a contract (`enforcement.policy`, `routes.password_confirmation_policy`), resolved per call; the `Mfa` singleton holds no closures for them.

## Contracts that must change together

- JSON responses ↔ [docs/json-mode.md](docs/json-mode.md) ↔ `tests/Feature/JsonContractTest.php`.
- `Support\MfaContext` (PHP) ↔ `stubs/inertia-react/pages/mfa-context.ts` (TypeScript) ↔ `tests/Feature/MfaContextTest.php`.
- Inertia page props ↔ `stubs/inertia-react/pages/*.tsx` → run `make typecheck-stubs` for all three apps.
- Component props ↔ `tests/js/*.test.tsx` ↔ the props table in [docs/integration.md](docs/integration.md) step 6.
- Behaviour visible to integrators ↔ the docs: [README.md](README.md) is only a quickstart; integration steps go in [docs/integration.md](docs/integration.md), settings and behaviour in [docs/configuration.md](docs/configuration.md). New integration steps also go in the plan's Phase 2 checklist and, where sensible, `mfa:install` output or an `mfa:doctor` check.

## Where things live

- `src/Mfa.php` (facade root): verification state, enforcement, context, impersonation, extension points.
- `src/Http/Middleware/EnsureMfaVerified.php`, `src/Support/SessionIdentity.php`: who gets challenged.
- `src/Support/`: `ChallengeService`, `EnrollmentService`, `OtpStore`, `RecoveryCodes`, `RateLimits`, `SendGuard`, `CodeHasher`, `Redact`, `MfaContext`.
- `src/Factors/`: TOTP, email, SMS. `src/Sms/`: drivers, composites, `Aws/SignatureV4`.
- `src/Console/`: `mfa:install`, `mfa:doctor` (deploy gate; exits non-zero on failure), `mfa:status`, `mfa:reset`.
- `stubs/inertia-react/pages/`: thin Inertia pages plus `mfa-context.ts` (`useMfa()`), published to `{Pages|pages}/mfa/`. `components/`: plain React components, published to `components/vendor/laravel-mfa/` (always lowercase). Components import only `react` and `./icons`, never Inertia, Ziggy or each other (`tests/js/standalone.test.tsx` enforces it). Every icon lives in `icons.tsx` (named `MfaIcon*` components, React-only; no `<svg>` in any other component), so the icon set is swapped in one file. Small helpers such as `useCountdown` are duplicated on purpose so each file stands alone. Tests in `tests/js/` (Vitest, jsdom, Testing Library): components through props and callbacks; pages with `@inertiajs/react` mocked (`tests/js/inertia-mock.ts`), asserting the URL and payload of each request.
- `tests/Feature/*Test.php` by area; `SecurityReviewTest.php` and `BehaviourGapsTest.php` hold regression tests from the reviews.
- `.gitattributes` keeps tests, tooling, `.codex/` and this file out of the Composer dist.
