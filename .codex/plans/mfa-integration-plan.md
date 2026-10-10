# MFA integration plan — `strontiumcorp/laravel-mfa` → artistly, clone-voice, podcast-flow

> **Status:** Phase 1 (package) **released and in use**: v0.5.2 is the latest tag. On branch `claude/quizzical-wright-00a388`, uncommitted and waiting for the maintainer's review (suggested v0.6.0): the artistly-audit fixes (§1.7: enrollment verification, owner notifications, 303/fetch handling in the gate, session docs), trusted browsers with the reminder before trust ends (§1.8), and the fixes from the review of both. Phase 3 (artistly) is **in progress** on branch `SDAP-786`; clone-voice and podcast-flow (Phases 4–5) and the rollout (Phase 6) haven't started.
> **Last updated:** 2026-10-10
> **Legend:** `[x]` done · `[~]` in progress / partially done · `[ ]` not started · `[!]` blocked or needs a decision

## Snapshot (2026-10-10) — start here in a new session

**Done**
- **The package is released:** public repo `github.com/strontiumcorp/laravel-mfa`, installed as a VCS repository (not on Packagist); tags v0.1.1 … v0.4.2, each with a green full matrix (PHP 8.2–8.5 × Laravel 11/12/13, lowest and newest deps). `make release` releases straight from `main` (no branch protection since 2026-10-09); `make release-pr` / `make release-tag` stay for a protected `main`.
- **Features:** TOTP, email and SMS codes; recovery codes; enforcement (roles, policy, required types); impersonation helper; deny-by-default middleware; React/Inertia pages and JSON mode; the "turn on two-factor" nudge; observability (events, logs, audit table, metrics, flow IDs); `mfa:install`, `mfa:doctor`, `mfa:status`, `mfa:reset`.
- **UI:** the challenge page shows one method at a time (6-box input, auto-send with a server-side countdown); the settings page is one card per method with a setup dialog; the settings card is its own page section; every component sets its own control styles (safe under `@tailwindcss/forms` and global input CSS) and has dark variants, both enforced by `tests/js/host-styles.test.tsx`.
- **Security and cost:** two independent reviews plus a full audit (2026-10-09), all findings fixed or decided (see §1.4c and the log). Send limits: exponential cooldown that spans logins, per-account hourly cap, per-type daily caps (email 15, SMS 5), separate budgets for unconfirmed destinations, app-wide caps for both, rollback on refusal; no resend or failover after a "maybe delivered" SMS.

**Next steps, in order**
1. **Review and release the artistly-audit fixes** (§1.7, this branch): the maintainer reviews the uncommitted diff, then focused commits, `make ci`, `make test-matrix`, `make typecheck-stubs`, and a v0.6.0 release (minor: on-by-default behaviour changes, see §1.7's upgrade notes).
2. **Finish artistly (Phase 3):** move to `^0.6`, run `migrate`, and republish the challenge and settings pages with their components (`mfa:install --force`; layouts live in `app.jsx`, so nothing to restore except `config/mfa.php`); add the fetch/axios interceptor (integration step 6, "Background requests"); set `enforcement.roles` (D5); confirm the mailer works for the new security emails; commit the integration on a dedicated MFA branch; run the manual QA checklist; staging.
3. **clone-voice (Phase 4), then podcast-flow (Phase 5).**
4. **Rollout (Phase 6)**, then the [Roadmap](#roadmap): per-network `verify_per_day`.

**Notes for picking up**
- Run `make` to list tasks. Verify with `make ci`, `make test PROCESSES=2`, `make test-laravel VERSION=11 LOWEST=1` and `make typecheck-stubs`. **Do not run mutation tests** (user decision).
- The `@pest-mutate-ignore` markers in `src/` document proven-equivalent mutants. Leave them in place.
- Tests must never write into Testbench's `vendor/` skeleton (parallel runs share it); freeze the clock for exact times.
- Per-app proxy issues: artistly trusts no proxies; clone-voice and podcast-flow trust `'*'`. See Phases 3–5.

## Goal

One installable package that adds MFA (authenticator app, email OTP, SMS OTP, plus recovery codes) to three Laravel apps on different framework versions. Each app should need only install, publish, and configure. The package must be secure, scalable, fully tested and observable.

## Definition of done

- [x] The package is versioned in a public repo, and CI is green on PHP 8.2–8.5 × Laravel 11/12/13 (full matrix on every tag).
- [ ] All three apps run the package in production, with no regressions in their existing test suites.
- [ ] Admins are required to use MFA in every app. Regular users can opt in.
- [ ] Every app passes `php artisan mfa:doctor` in its deploy pipeline.
- [ ] MFA failures are visible in logs and dashboards, and alerts are set on `delivery_failed` and `rate_limited` spikes.
- [ ] Support has a runbook for locked-out users (`mfa:status` / `mfa:reset`).

---

## Phase 0 — Decisions

| # | Decision | Status | Recommendation / notes |
|---|---|---|---|
| D1 | Package name / namespace | `[x]` | **`strontiumcorp/laravel-mfa`**, namespace **`StrontiumCorp\LaravelMfa`** (company-owned). Mojahidul Islam (`@itsemon245`) is the listed author/maintainer and the `CODEOWNERS` reviewer. Renamed 2026-10-08. |
| D2 | Where the package is hosted | `[x]` | **https://github.com/strontiumcorp/laravel-mfa**, public since 2026-10-09 (was private). Apps install it via a `vcs` repository entry with the HTTPS URL and no credentials; it isn't on Packagist. |
| D3 | SMS provider | `[x]` | **Twilio** when SMS is turned on; **SMS stays disabled** at launch (`MFA_SMS_ENABLED=false`, the default). Set `MFA_SMS_DRIVER=twilio` + credentials in each app's env so enabling it later is one switch. Pricing notes (Oct 2026 list): US ~$0.012/SMS everywhere; UK $0.044–0.057; Bangladesh $0.33–0.60 (Infobip cheapest, a `routing` candidate later); India $0.004 on AWS with DLT. |
| D4 | Which factors to launch with | `[x]` | **TOTP and email.** SMS later (cost, toll-fraud risk). |
| D5 | Enforcement | `[x]` | Enforce for admins in all three apps via config roles: `'enforcement' => ['roles' => ['admin', 'super_admin', 'support']]` (was `'enforce'` until D12) (matches each app's `isAdmin()`; the trait's `getMfaRoles()` reads the `role` attribute, string in artistly, `UserRole` enum in clone-voice/podcast-flow). Opt-in for everyone else. Code-level rules go through an `enforcement.policy` class (`Mfa::enforceUsing()` was removed in v0.3, user decision 2026-10-09: the policy class covers it and keeps closures off the singleton). Implemented 2026-10-09. |
| D6 | Password confirmation before factor changes | `[x]` | **Superseded 2026-10-09 (v0.3):** the package asks inline on the settings page (`routes.password_confirmation`, default `true`), so apps need no confirm page. Password-less users: empty stored passwords are never asked; social-login users with an unknown password are exempted per app with a `routes.password_confirmation_policy` class (`Contracts\PasswordConfirmationPolicy`), or `password_confirmation => false`. `routes.confirm_middleware` stays as "extra middleware" (default `[]`; `['password.confirm']` = the app's own page). Documented break: a v0.2 config that set `confirm_middleware => []` to turn confirmation off now asks. Earlier: per app: `mfa:doctor` warns when Socialite is installed and `password.confirm` is on. Apps with Google/social login (artistly and clone-voice both have Socialite) set `routes.confirm_middleware` to `[]` unless they ship a set-password flow. Client-side toggles come from one shared, typed `MfaContext` (`'mfa' => Mfa::context($request)` in `HandleInertiaRequests`, `useMfa()` in React). Implemented 2026-10-09. |
| D7 | Rollout order | `[x]` | **artistly first** (L11, most quirks), then clone-voice (L12), then podcast-flow (L13), so the newer apps get the least friction. Phases 3–5 reordered accordingly. |
| D8 | Code delivery mode | `[x]` | **Queued from day one.** `delivery.queue` alone queues on the default connection; `delivery.queue_connection` picks one. A `sync` connection falls back to inline sending. Failed queued deliveries discard the unsent code. Implemented 2026-10-09. Note: artistly's `.env.example` has `QUEUE_CONNECTION=sync`; confirm production runs a real queue and worker. |
| D9 | Impersonating with an admin who has no MFA (security review #7) | `[x]` | **Option A**, implemented 2026-10-09: if the target has MFA, `grantForImpersonation()` requires the impersonator to have actually passed MFA in the session. Free with D5, since admins must enroll. |
| D10 | Disabling a factor type in config (security review #9) | `[x]` | **Option A: keep fail-open** and document "disable a type only after users migrate" (docs/configuration.md). `mfa:doctor` now counts users who still have factors of a disabled type. |
| D11 | Published UI layout | `[x]` | User decision, 2026-10-09. **Pages** (`challenge.tsx`, `settings.tsx`) stay in `{Pages\|pages}/mfa/` as thin Inertia wrappers: props in, `useForm`/`router`/`Head` wiring, components out. **Components** go to `resources/js/components/vendor/laravel-mfa/<name>.tsx` (always lowercase), one per file, React-only: no Inertia, no Ziggy, no imports between them, so each works in any React app. Small helpers (`useCountdown`, the clear-after-failure effect, factor types) are **duplicated** per file rather than shared, so a copied or customised file never depends on a sibling (a shared helper would be one more published file whose edits silently change every component). **Amended 2026-10-09 (v0.3, user decision):** one shared file, `icons.tsx`, holds every icon (named `MfaIcon*` components), and components may import it as `./icons`, so the icon set is swapped in one place; the standalone test allows exactly that import and no `<svg>` elsewhere. `useMfa()` moved to `{Pages\|pages}/mfa/mfa-context.ts` (Inertia layer; `.ts` so the `**/*.tsx` page globs skip it); `MfaApiKeyNotice` takes `enabled`/`settingsUrl` props, fed by `mfaApiKeyNoticeProps(useMfa())`. Backend props unchanged. |
| D12 | What enforced users must use | `[x]` | User decision, 2026-10-09. Config regrouped: `enforcement.roles`, `enforcement.policy`, `enforcement.required_types` (default `['totp']`); the v0.1 `enforce` key is still honoured and `mfa:doctor` warns. Enforced users must hold a required type: without one they verify with the factors they have (never enroll unverified: a stolen password can't add a factor) and are then held on settings; with one, the challenge offers and the server accepts only required types (user chose "required types only" over "any factor"). Recovery codes still work. Impersonation grants don't set the hold. |

---

## Phase 1 — Package (`~/Work/laravel-mfa`)

### 1.1 Core — `[x]` done
- [x] Composer package for `illuminate/* ^11|^12|^13`, PHP ^8.2, auto-discovered provider and `Mfa` facade
- [x] Config (`config/mfa.php`): factors, SMS drivers, delivery, rate limits, cache, middleware, routes, UI, observability, pruning, tables
- [x] Migration: `mfa_factors`, `mfa_otp_codes`, `mfa_recovery_codes`, `mfa_audit_logs` (loaded automatically; publishing is optional)
- [x] Factors: TOTP (google2fa, with replay protection by compare-and-set), email OTP, SMS OTP. All extensible through `Mfa::extend()`.
- [x] SMS drivers: `log`, `twilio`, `vonage` (Laravel HTTP client, no vendor SDK), custom drivers through `Mfa::extendSms()`
- [x] Security: encrypted secrets and destinations; HMAC-hashed OTPs and recovery codes that still verify after an `APP_KEY` rotation; row-locked OTP store; attempt limits; resend cooldown; per-user verify and send rate limits; calling-code allowlist; session regeneration
- [x] Deny-by-default middleware on the `web` group. It checks the session's login ID, not `Auth::user()`, so `setUser()` webhooks, jobs and per-request impersonation are never blocked. Remember-me logins are challenged on their first request.
- [x] Impersonation helper `Mfa::grantForImpersonation()` for login-swap impersonation (artistly)
- [x] Enforcement: the `EnforcementPolicy` contract plus an `EnforceForAdmins` example
- [x] Performance: verified sessions cost 0 MFA queries; "has MFA" is cached and invalidated by model events; services are Octane-safe

### 1.2 Observability — `[x]` done
- [x] Domain events for every action (`MfaActivity` interface), feeding a structured log, the audit table and a metrics hook
- [x] Flow ID per challenge, pushed into Laravel `Context` (so it appears in app logs and queued jobs)
- [x] Failure reasons as a fixed enum; logs never contain codes, secrets or full destinations
- [x] A failing sink never breaks authentication
- [x] Commands: `mfa:doctor`, `mfa:status {user} [--flow]`, `mfa:reset {user}`, `mfa:install`, plus an `about` section
- [x] Daily `model:prune` scheduled with `onOneServer`

### 1.3 UI — `[x]` done
- [x] React/Inertia stubs `challenge.tsx` and `settings.tsx`, with all URLs passed in as props (no Ziggy dependency)
- [x] `mfa:install` detects `Pages/` vs `pages/`
- [x] Type-checked against all three apps' real dependencies (React 18/19, Inertia 2.2/2.3/3.0)
- [x] **Logout URL is configurable** (`routes.logout_route`, default `logout`). The challenge page posts to `urls.logout`, so artistly's `POST /admin/logout` works, and the button is hidden if the route doesn't exist. Tested, and the stubs re-type-check in all three apps.
- [x] **UI restructure (D11, 2026-10-09).** Nine components in `stubs/inertia-react/components/` (`challenge-form`, `send-code-button`, `recovery-code-form`, `factor-list`, `add-factor-form`, `totp-setup`, `destination-setup`, `recovery-codes-panel`, `api-key-notice`); pages and `mfa-context.ts` in `stubs/inertia-react/pages/`. `mfa:install` publishes to the new paths, keeps skip-if-exists and `--js-path`, prints the new import paths, and warns about v0.1 files left in `{Components|components}/mfa/`. Vitest + Testing Library (jsdom) at the repo root: 71 tests through props and callbacks, plus a guard that components import only `react`. `tsc` over components, pages (against `@inertiajs/react` 3) and tests. CI: `ui-suite.yml`, called from `tests.yml` and gating the Release in `full-matrix.yml`. `make test-js`, part of `make ci`. Re-type-checked in all three apps.
- [x] **Password confirmation inside the settings flow (v0.3, 2026-10-09).** `RequirePasswordConfirmation` on add/remove factor and regenerate codes; `POST mfa/confirm-password` (`mfa.password.confirm`) checks the session guard's provider, sets `auth.password_confirmed_at`, is rate-limited (`password_per_minute`/`_per_day`) and fires `PasswordConfirmed`/`PasswordConfirmationFailed` (`invalid_password`). New `password-confirm-form` component (with its own lockout countdown); the settings page asks inline and retries. `routes.password_confirmation_policy`, `InteractsWithMfa::withConfirmedPassword()`, `PasswordConfirmationRequired` event. Doctor reworked (policy class, `auth.password_timeout` set).
- [x] **Account-settings entry point (v0.3, 2026-10-09).** `settings-card` component (`enabled`, `settingsUrl`, `hasMfa`, `mustEnroll`, `className`, `renderLink`) and `mfaSettingsCardProps(useMfa())`. `scripts/typecheck-stubs.sh` also type-checks the documented snippets (card with Inertia `<Link>`, API-key notice) in each app.
- [x] **Challenge send state and auto-send (2026-10-09).** Each email/SMS factor in the challenge props has `code_sent` and `retry_after` (`OtpStore::status()`), so a refresh keeps the countdown; the page sends the code on arrival or when a method is picked, once per method per visit, unless one is already out.
- [x] **Challenge page redesign (2026-10-09, direction A).** One method at a time in a `rounded-2xl` card, mirroring the setup dialog: icon tile, per-type title, a segmented code input (one real input, `one-time-code`), "Didn't get it? Resend in 0:58", full-width Verify, and "Try another way" (methods list plus recovery code) / "Sign out" in the footer. Challenge factors gain `code_length`.
- [x] **"Turn on two-factor" nudge (2026-10-09).** For users with no method who aren't enforced: `enable-nudge` component (a floating card, `position`/`offset`, full width on phones), `useMfaNudge()` and `mfaNudgeProps()` in `mfa-context.ts`, `nudge` in `MfaContext`, `mfa.nudge` config (`enabled`, `title`, `body`, `button`, `dismiss_label`), `POST mfa/nudge/dismiss` (`mfa.nudge.dismiss`): hidden until the user's next local midnight (browser timezone, server-computed, capped at 26h), per user in the cache with a session mirror, `NudgeDismissed` event. The settings page shows the same copy as a notice (`nudge` prop, `factor-cards` `notice`).
- [x] **JSON mode docs**: [docs/json-mode.md](../../docs/json-mode.md) covers every endpoint, payload and error shape. A contract test (`tests/Feature/JsonContractTest.php`) pins each response so the docs can't drift.

### 1.4 Quality — `[x]` done except the GitHub CI run (waits on 1.5)
- [x] Pest suite: **290 tests**, running in parallel, all passing on **Laravel 11, 12 and 13**. Line coverage **95.5%**.
- [x] PHPStan (larastan) level 6 clean; Pint clean
- [x] CI workflow: PHP 8.2–8.5 × L11/12/13 × lowest/stable; static analysis job; coverage gate of 85%
- [x] **Mutation testing** (`pest --mutate`): one-off pass, now **retired** (see below)
  - **Correction:** the first run's 94.5% was invalid. Partway through, one mutant published `src/` into Testbench's `vendor/` skeleton. Every later test run then crashed ("Cannot redeclare class"), and Pest counted each crash as a kill. The earlier claim of "0 survivors in the security code" was wrong.
  - Guard added: the publish tests now remove a directory too, and each run checks `vendor/` for pollution afterwards.
  - Real baselines: **58.7%** for the whole package; **67.0%** for the security code (`Support`, `Factors`, `Http`, `Mfa`), with 352 survivors.
  - Many survivors were in-code `config()` fallbacks that could never run. That exposed a real bug: Laravel's `mergeConfigFrom` merges only top-level keys, so apps with an older published config lost newer nested keys. Fixed with a deep merge (`ConfigMerge`); the duplicated fallbacks were removed.
  - Gaps already closed with tests: the `destination` encrypted cast, cache invalidation on factor delete, SMS driver edge cases, event context, the log metrics driver, config publishing, the email template.
  - **Don't use `--mutate --parallel`.** In this setup it reports surviving mutants as killed: a known survivor showed as killed in parallel mode and survived sequentially, and a full parallel run claimed 100% in 10 seconds. Run sequentially; independent class groups can run side by side. (Parallel *coverage* runs are fine.)
  - [x] **Triage of the security code** (`Support`, `Factors`, `Http`, `Mfa`)
    - Real gaps closed with tests (`tests/Unit/MutationGapsUnitTest.php`, `tests/Feature/MutationGapsTest.php`, 81 tests). The important ones:
      - **Recovery-code login was untested.** Nothing checked that the user could use the app afterwards.
      - **A cold "has MFA" cache was never tested.** A miss could have skipped the challenge.
      - **Morph-mapped user models** could have been treated as having no MFA.
      - **The session-fixation test was ineffective.** The test client issues a new session ID per request anyway; it now checks `markVerified()` directly.
      - **A test passed on a 500.** It asserted `null` on an error response; the suite was swept for that pattern.
      - Rate-limit races and the IP marker scope.
      - Validation rules and event contexts.
    - Bugs found while triaging:
      - The per-account new-destination cap counted refused attempts. It's now counted as distinct destinations, on real sends only.
      - Rejected attempts could leak counts. All counters for an attempt are now rolled back when any limit refuses.
    - Code simplified where mutants showed redundancy: an always-true check, unused return values, an unused flag, and key building centralised in `CacheKey`.
    - Proven-equivalent mutants are marked `@pest-mutate-ignore: <Mutator>` per mutator, with the reason on the line above. Nothing is ignored wholesale.
    - Concurrency safety: no test writes into the shared Testbench skeleton any more (the config publish is asserted via its mapping; `mfa:install` gained `--js-path`).
  - **Final clean sequential run** (no concurrency, no edits during the run, no skeleton writes):
    - security code **99.4%** (968 killed, 6 survived); whole run 95.75%, which includes the untriaged service provider and models
    - the last 6 survivors were then resolved without another run: 2 closed with tests, 1 dead branch removed, 3 equivalent
    - one survivor exposed a subtle bug class: a `foreach` destructuring that inherits `$decay` from the previous loop (1-hour instead of 24-hour window); now tested
  - **Retired on 2026-10-08 (user decision):** too slow (about 36 minutes sequentially in this Docker setup), and `--parallel` is unreliable. Use `make test`, `make coverage`, `make analyse` and `make test-matrix`. The `@pest-mutate-ignore` markers stay in `src/` as documentation of proven-equivalent mutants.
- [x] **Security review** (independent adversarial review; findings verified with failing tests first, then fixed; see `tests/Feature/SecurityReviewTest.php`)
  - [x] #1 HIGH: TOTP brute force through parallel bursts (check-then-act limiter). Attempts are now counted before they're checked, plus a per-day cap (`verify_per_day`).
  - [x] #2 Pending TOTP secret readable or confirmable from another session on the account. Pending enrollment is now bound to the creating session, with a 30-minute expiry.
  - [x] #3 Only the first logged-in guard was checked, and verification counted across guards. Every session guard is now checked, and verification is per guard.
  - [x] #4 SMS pumping: blocked premium NANP prefixes behind `+1` (checked at enrollment and at every send). The caps were **redesigned**; see "Send limits" below.
  - [x] #5 A cache race could leave "has MFA = no" for up to an hour. Factor changes now write through, and fills use `add()`.
  - [x] #6 Verification survived `Auth::logout()` without session invalidation (artistly's admin logout). The `Logout` listener now clears MFA state.
  - [x] #8 Audit/log flooding and a 500 on array `factor_id`: added a route throttle (`routes.throttle`) and integer validation.
  - [x] #9 Enforced users were stuck in a redirect loop at `password.confirm`. Added `middleware.allow_while_enrolling`.
  - [x] #9 Transport error text (with the recipient address) reached the MFA log and audit table. Only the class name is kept now.
  - [x] #9 Octane: request and auth were resolved from the container captured at boot. They now use the live container.
  - [x] #9 `mfa:reset 12abc` would match user 12 on MySQL. IDs are now parsed strictly.
  - [x] Found while fixing: a delivery failure emitted **two** events in sync mode, one wrongly marked `queued`. Fixed in a way that also works on Laravel 11.
  - [!] #7 and the "fail-open on disabled factor type" finding are policy choices; see decisions D9 and D10.
- [x] **Send limits redesign** (agreed 2026-10-08; tests in `tests/Feature/SendLimitsTest.php`, `tests/Unit/OtpCooldownTest.php`)
  - [x] **Exponential cooldown** per factor: 2 → 4 → 8 → 15 min, configurable (`factors.*.resend_cooldown`). It resets after a successful verification or an hour of quiet. A resend is allowed as soon as the current code expires or is burned.
  - [x] The cooldown is checked under the factor lock *before* any cap, so rejected requests use no quota.
  - [x] **Separate budgets**, so bombing protection can't be used to lock an owner out:
    - **Unconfirmed destinations:** 2 per destination per day across all accounts; 3 *distinct* new destinations per account per day; 10 distinct new destinations per IP per hour (IPv6 per /64); an app-wide breaker of 500 per hour.
    - **Confirmed destinations:** the cooldown plus 10 per hour per account, with no per-IP cap (NAT-safe).
    - No pending factor is created when a send is refused.
    - Every counter a send touches is counted atomically and rolled back together if any limit refuses, so refused requests cost nothing.
  - [x] SMS code lifetime raised from 5 to 10 minutes.
  - [x] Events: `SuspiciousCodeRequests` (repeated unverified login sends, or the hourly cap hit; used to warn the owner) and `SendingCircuitTripped` (critical, once per hour).
  - [x] `retry_after` in JSON responses (success and errors); a `retryAfter` prop and live countdown in both React pages (type-checked in all three apps).
  - [x] `mfa:doctor` checks trusted proxies, warning on none configured (all clients share the load balancer's IP) and on `'*'` (spoofable unless there is exactly one proxy layer).
- [x] **Task runner:** a `Makefile`, with `make` listing targets:
  - `test` (parallel), `test-filter`, `coverage` (parallel, minimum 85%)
  - `lint`, `format`, `analyse`, `ci`
  - `test-laravel VERSION=…`, `test-matrix`
  - `typecheck-stubs APPS=…`
  - `clean`
  - `composer test` and the CI workflow also run Pest with `--parallel`.
- [!] First green CI run on GitHub: blocked on 1.5 (repo not pushed yet)

### 1.4c Second independent review — `[x]` (2026-10-09)

Scope: security core, `src/Sms/`, integration ergonomics, docs. `make ci`, `make test-matrix` (L11/12/13) and `make typecheck-stubs` (all three apps) pass.

**Fixed** (regression tests first, then the fix; 325 tests):
- [x] R1 MEDIUM: a custom `routes.logout_route` (e.g. `admin.logout`) was blocked by the middleware for unverified users, so the challenge page's "Sign out" bounced back to the challenge. `middleware.except` only listed `logout`. The configured logout route is now always exempt (`EnsureMfaVerified::isExcluded`).
- [x] R2 MEDIUM: `DeliverOtp::handle()` injected `SmsSender` for every delivery. A broken SMS config (e.g. `MFA_SMS_DRIVER=failover` with no chain) made **email** codes 500, even with SMS disabled. SMS build errors also escaped as a 500 and left the unsent code behind, so the cooldown blocked a retry. The sender is now resolved inside the delivery `try` for SMS only, so errors become `DeliveryFailed` and the code is discarded.

**Open** (not fixed; need a decision or are low impact):
- [x] R3 MEDIUM (fixed): sync SMS worst case is too slow. Each driver uses `timeout(10)` with 2 attempts (~20s per provider), so a 2–3 provider failover chain can take 40–60s. That exceeds PHP/nginx timeouts; a fatal mid-chain skips `DeliveryFailed`, leaves the code (cooldown), and shows the user a 500/504. Suggested: `connectTimeout(3)->timeout(5)`, one attempt per provider inside a failover chain (failover is the retry), or a total deadline in `FailoverSmsSender`.
- [x] R4 MEDIUM (fixed; matters now that D8 queues from day one): when a queued `DeliverOtp` finally fails, `failed()` doesn't discard the code, so the user waits out a cooldown for a code that never arrived. Suggested: pass the OTP id into the job and discard it in `failed()`.
- [x] R5 MEDIUM (privacy; fixed with a `user_id` foreign key, see below): deleting a user leaves `mfa_factors` (encrypted phone/email), recovery codes and audit rows (IP, user agent) behind; morph relations have no cascade. Suggested: a `deleted` / `forceDeleted` hook in `HasMultiFactorAuthentication`, or document the cleanup. Behaviour change, so ask first.
- [x] R6 LOW (won't fix, user decision 2026-10-09: inherent to per-account limits; affected users go to support / `mfa:reset`): anyone with the password can burn `verify_per_day` (50) and lock the owner out of the challenge, recovery codes included, for 24h. Known trade-off of per-account limits. Mitigate with the 1.6 "notify on daily-cap lockout" follow-up; support can `mfa:reset`.
- [x] R7 LOW (fixed: `Redact` strips URLs, emails and phone numbers from `DeliveryFailed` and from every event's context before any sink): `DeliveryFailed` for connection errors includes Guzzle's message, which ends with the request URL (Twilio Account SID, Infobip personal base URL). Not end-user PII, but it lands in logs and the audit table. Keep only the cURL error number.
- [x] R8 LOW (fixed: retry only when the request never reached the provider; Guzzle 7 and 8 aware): retrying on any `ConnectionException` includes read timeouts after the provider accepted the message, so the user can get two SMS (double cost). Same when failover moves on after a timeout. Accept, or retry only connect errors (cURL 6/7).
- [x] R9 LOW (fixed: `+880` and `880` both work): `routing.routes` keys written as `'+880'` never match (numbers are compared without `+`), silently sending that traffic to the default. Strip `+` from keys; have `mfa:doctor` reject non-digit prefixes.
- [x] R10 LOW (fixed: HMAC with `APP_KEY`): send-limit cache keys use unsalted `sha256(destination)`. Phone numbers are brute-forceable from a cache dump. Use an HMAC keyed like `CodeHasher`.
- [x] R11 LOW (fixed: README note, `mfa:doctor` warning, and the `MfaApiKeyNotice` component for API key settings): Sanctum stateful SPA routes (`statefulApi()` / `EnsureFrontendRequestsAreStateful`) in the `api` group carry a session but aren't covered by the web-group middleware. None of the three apps enables it today (artistly has it commented out). Add a README note and a `mfa:doctor` warning.
- [x] R12 LOW (fixed): `mfa:doctor` could also check that `routes.logout_route` and `routes.home` resolve, and that `confirm_middleware`'s alias and `password.confirm` route exist (relevant to D6).
- [x] R13 LOW (fixed: `Inertia::location()`): after a successful Inertia challenge the redirect goes to the intended URL via XHR; a non-Inertia page (Blade, download) then opens in Inertia's error modal. Use `Inertia::location()` for X-Inertia requests.
- [x] R14 LOW (fixed): README's "`loginUsingId()` is never challenged" holds only without a session cookie (a web request that calls it persists the login). `POST /mfa/recovery-codes` with no factor returns `422 {message}` without `errors`, which json-mode.md doesn't mention.
- R5 resolution (user decision 2026-10-09: FK, and keep audit rows until the prune). Implemented: replace the polymorphic `authenticatable_*` columns with a constrained `user_id` foreign key. All three apps authenticate only `App\Models\User` (bigint `id`). `cascadeOnDelete()` on factors, OTP codes (via factor) and recovery codes covers every hard delete, including query-builder deletes that skip model events, which a trait hook wouldn't. Soft deletes (artistly) keep the rows until a force delete, so a restored user keeps MFA. Audit rows use `nullOnDelete()`, so the security history survives until the 90-day prune. One user model only (`mfa.user_model`, default: the first guard's model); `mfa:doctor` fails when an MFA guard uses another model. The test suite now runs with SQLite foreign keys on.
- Accepted as-is: logout on any guard clears verification for all guards (only matters for multi-guard apps; none of the three).

**Checked and sound:** deny-by-default middleware and session-identity resolution (remember-me challenged, `setUser()` not); per-guard verification; enrollment allow-list; intended URL stored for plain GETs only; session regenerated on verify; pending enrollments bound to the session; settings unreachable while unverified; `OtpStore` (row lock, latest-code-only, burn after N, discard on failure, cooldown before caps); TOTP compare-and-set replay protection; atomic recovery-code consume; HMAC with key rotation and `hash_equals` over every candidate; count-before-check verify limits; `SendGuard` rollback and IPv6 /64 grouping; SigV4 (verified vectors; region regex blocks host injection); provider errors carry only status/codes, never the recipient; failover summarises non-`DeliveryFailed` exceptions by class; `SmsManager` cycle detection is Octane-safe (`finally`); the three apps only use token-based `auth:sanctum` in `api` (artistly's one `auth:sanctum` web route is in the `web` group, so covered).

### 1.4b SMS providers — `[x]` done (2026-10-08)
- [x] Native **Infobip** driver (SMS API v3, `POST /sms/3/messages`; personal base URL supported; allow-list on the status group)
- [x] Native **Amazon SNS** driver: Query API `Publish`, Transactional SMS type, sender ID and origination number. Requests are signed with SigV4 **in-package**, verified against signatures from the official `aws/aws-sdk-php` (4 reference vectors). Works from any host with an IAM key limited to `sns:Publish`.
- [x] **`failover`** driver: tries providers in order. Each failure emits `SmsProviderFailed`, which is logged, audited and counted as `mfa.sms_provider_failed{provider}`. `ChallengeDeliveryFailed` fires only when every provider fails. Exceptions from buggy custom drivers are reported and summarised by class, so their messages can't leak PII.
- [x] **`routing`** driver: longest-prefix match on the number (country or area code) with a default. Routes can point at named failover chains.
- [x] Named drivers via a `transport` key (several chains of the same kind); circular configs are rejected when built
- [x] `mfa:doctor` validates every driver in a chain and lists each problem on its own line
- [x] 32 new tests; 322 total, passing on Laravel 11/12/13

### 1.5 Release — `[x]`
- [x] Settle D1 (name): `strontiumcorp/laravel-mfa`, `StrontiumCorp\LaravelMfa`
- [x] Push to `github.com/strontiumcorp/laravel-mfa` (public since 2026-10-09)
- [x] Tags v0.1.1 … v0.4.2 with `make release`; the full matrix publishes each GitHub Release once every combination passes
- [x] `CHANGELOG.md` tooling: `make release` generates it from the commits (`scripts/release.sh`, `scripts/update-changelog.py`); `make release-pr` / `make release-tag` for a protected `main`

### 1.6 Package follow-ups (after launch)
- [x] Trusted devices ("remember this device for 30 days": hashed token, revocable): built as trusted browsers, §1.8.
- [ ] Grace period for enforced users (`enforce_grace_days`)
- [ ] Passkeys factor (WebAuthn) through `Mfa::extend()`
- [ ] Laravel Pulse card for MFA metrics
- [~] Email the user when a factor is added or removed, or a recovery code is used: done in §1.7 (plus new recovery codes and `SuspiciousCodeRequests`, which covers the daily/hourly send caps). Still open: an email after repeated failed verification attempts (`verify_per_day` lockout, security review #1).
- [ ] Region-accurate SMS allowlist using libphonenumber, instead of calling-code prefixes (security review #4)
- [ ] **India DLT support in the SNS driver** (only if Indian traffic matters): optional `entity_id` / `template_id` config sent as `AWS.MM.SMS.EntityId` / `AWS.MM.SMS.TemplateId`. `factors.sms.message` must then match the DLT-registered template exactly. This unlocks AWS's $0.004/SMS domestic route (vs $0.071).
- [x] (built 2026-10-11 on `feat/verification-lifetime`: every listed type is required and passed one after the other, recovery codes still pass alone; settings offer and accept only required types, other methods grayed out as "Not used for sign-in") **Required types: what enforced users see, and "all of" (maintainer note, 2026-10-11; after the verification lifetime).** Checked: `enforcement.required_types` means **any one of** the listed types (`['totp', 'email']` = either is enough; one code at the challenge, never both). Once an enforced user holds a required type, the challenge already lists and accepts only required types (`Mfa::challengeTypes()`, `ChallengeController::show()`, `ChallengeService`); before that, it shows every enabled type so they can verify with what they have and then enroll. To decide: (1) where non-required factors still show for an enforced user (settings page, "Try another way" before enrolling a required type) and whether to hide or mark them; (2) whether "all of" is wanted (two codes in one challenge: needs a multi-step challenge UI, `ChallengeService` tracking which types passed, JSON contract changes).
- [ ] **Per-rule `required_types`** (planned, not part of the nudge change): e.g. admins must use an authenticator app while other enforced users may use any method. Today `enforcement.required_types` applies to every enforced user.
- [ ] Before enabling SMS in any app, do a real send test per provider and region (`mfa:doctor` only validates config, not provider acceptance), and complete provider-side setup: US 10DLC or toll-free verification, leaving the SNS sandbox, sender-ID registration where required, and extending `allowed_calling_codes`.

### 1.7 artistly audit fixes — `[x]` (2026-10-10, uncommitted on `claude/quizzical-wright-00a388`, awaiting review)
An audit of the artistly integration found five gaps. Decisions and status:
- [x] **Enrollment verification (security).** A user who must enroll and had no factor could reach `/mfa/settings` with only the password and add the attacker's authenticator (artistly had just had a support account's password leak). Chosen: before an account's **first** factor, the session proves ownership beyond the password — **a code emailed to the account address** (default, cheap, self-service), or **an administrator's one-time link** (`mfa:enrollment-link`, `Mfa::enrollmentLink()`) for users without email or for apps whose mailboxes can't be trusted (`email => false`). Config `enrollment_verification` (`required_for`: `enforced` default | `everyone` | null; `email`; `link_ttl` 1440; `notification`). Asked after the password prompt, on `factors.store` and `factors.confirm` (`RequireEnrollmentVerification`); JSON `423 enrollment_verification_required` with `email`/`send_url`/`verify_url`, Inertia a validation error of that name; routes `mfa.enrollment-verification.{send,verify,link}` (reachable while enrolling). The code reuses `factors.email` settings, is bound to the session (HMAC in the session, attempts counted atomically in the cache), waits on the email cooldown curve across sessions, and counts toward the account's send caps (`SendGuard::attemptAccount()`). Links: signed, one use, only in the user's own session, revoked by a password change. Proof lasts until logout. Events `EnrollmentVerificationRequired/Sent`, `EnrollmentVerified` (method), `EnrollmentLinkIssued`; failures `VerificationFailed` (`enrollment_verification`, `enrollment_link` with new reason `invalid_link`); new reason `enrollment_link_required`. `mfa:doctor` warns when enforcement is on and this is off. Rejected: admin links only (support load for every admin onboarding); doing nothing for opt-in users is the default (`everyone` is one switch). Known limit: password + mailbox stolen together still enroll (docs "Known limitations"; mitigation `email => false`).
- [x] **Owner notifications.** `notifications` config (on by default; per-event switches; `notification` class, default `SecurityAlertNotification`, queued on the delivery queue): `factor_enabled`, `factor_disabled` (says "an administrator" after `mfa:reset`), `recovery_codes_generated` (not the first set: `RecoveryCodesGenerated` context gains `initial`), `recovery_code_used` (with remaining), `suspicious_code_requests`. No codes or secrets; what, when, IP. The first-enrollment proof isn't a separate alert: its code email already warns the owner. Listener `NotifyAccountOwner`; a failing send is reported, never blocks.
- [x] **303 for blocked non-GET requests** in `EnsureMfaVerified::deny()` (inertia-laravel 2.x has no global `EnsureGetOnRedirect`; v3 does, which is why only artistly saw the 405).
- [x] **Intended URL only for real navigations**: `Sec-Fetch-Mode: navigate` to a document (not prefetch/prerender/iframe), else a `GET` whose Accept names `text/html`. A script's `fetch()` (`Sec-Fetch-Mode` `cors`/`same-origin`/`no-cors`) now gets the JSON 403 instead of the challenge HTML. Interceptor for axios and `fetch` documented (integration step 6).
- [x] **Re-challenge after the session expires**: documented (configuration.md "Sessions, remember-me and re-challenges") as intended; recommendation then: accept it for enforced admins or raise `SESSION_LIFETIME`; the maintainer then asked for trusted browsers, built in §1.8.
- [x] Frontend for enrollment verification: a "Confirm it's you" step in `factor-setup-dialog` (`verifyEmail`, `onSendEmailCode`, `emailCodeSending`, `emailCodeSent`, `emailCodeRetryAfter`, `onVerifyEmailCode`, `emailCodeProcessing`, `emailCodeError`), wired in `settings.tsx` (423 `enrollment_verification_required` from store/confirm brings it back; "code sent" is kept while the page is open), 16 new Vitest tests, two preview scenarios. Type-checked against all three apps.

### 1.9 Review fixes — `[x]` (2026-10-10, same branch)
A two-axis review (standards, spec) of §1.7–1.8 found no security bug; the maintainer asked for every finding to be fixed, extras kept. Tests first (each checked to fail without its fix):
- [x] Security emails follow the code delivery rule (`DeliveryQueue::send()`: delivery queue when set, else inline), keeping a replacement class's own connection/queue; at most one `suspicious_code_requests` email per account per hour; a new `enrollment_link_issued` email (spec: "possibly the first-enrollment event").
- [x] Trusted browsers end at once on a password change (`PasswordReset`, and `eloquent.updated` of the MFA user model with a changed password); the settings list hides browsers trusted under an older password; cookie names keyed through `CodeHasher` so `APP_PREVIOUS_KEYS` keeps them working; the reminder reads the session before asking the enforcement rules (no per-page policy call).
- [x] "initial" recovery codes mean the account's first method, so a new set after all codes were used is emailed.
- [x] Refactors: `EnrollmentLinks` split from `EnrollmentVerification`; enums `TrustedBrowserRevocation` and `EnrollmentLinkProblem` (link failures carry `problem`); `by_administrator` in event context instead of matching `console:`; `via` default `app`; `Cooldown::capped()` shared with `OtpStore`; `Mfa::guardFor()` made public; phpstan type aliases for the context shapes; the stale plan lines.

### 1.10 Verification review fixes — `[x]` (2026-10-10, same branch)
A second review confirmed the §1.9 fixes (11/13 standards, 5/5 spec) and found small new issues, all fixed, tests first:
- [x] `ForgetTrustedBrowsers` does nothing (no query) while trusted browsers are off, and reports instead of throwing, since it runs inside the app's own save, enrollment or reset.
- [x] The hourly limit on the suspicious-requests email is claimed inside the error handling (a cache outage can't break the request) and released when the email fails.
- [x] Stale text (§1.7 line, a test name, a comment); `Mfa::guardFor()` public instead of the `guardOf()` wrapper; the dead cookie-name fallback (`CodeHasher` keys are typed non-empty).
- [x] Docs: Laravel's rehash-on-login also ends trusted browsers; the password-change listener needs the exact `user_model` class.

### 1.8 Trusted browsers — `[x]` (2026-10-10, uncommitted on the same branch, awaiting review)
Maintainer asked for "don't ask for verification for N days" (the MFA step only; the password login stays). Defaults chosen as proposed (the maintainer didn't pick otherwise): off (`MFA_TRUSTED_BROWSERS`), 30 days, not for enforced users unless `allow_enforced`, never after a recovery code.
- [x] Table `mfa_trusted_browsers` (new migration; keyed hashes of the cookie token and of the password hash, label from the user agent, expires/last used; pruned daily; cascades with the user). `Support\TrustedBrowsers` (issue, check, list, forget), `Models\MfaTrustedBrowser`.
- [x] Gate: only for an unverified user with factors whose browser sends that user's cookie (one query), it marks the session verified (`VerificationSucceeded`, `via: trusted_browser`); an enforced user still missing a required type is still held on settings. Cookie per guard and user (HMAC name), http-only, session cookie settings.
- [x] Ends on: expiry, password change, a factor added/removed (`mfa:reset` too), a recovery code used (`ForgetTrustedBrowsers` listener), settings (one or all: `DELETE mfa/trusted-browsers[/{id}]`), feature off. Not on logout.
- [x] Props: challenge `trustBrowser` (`{days}` or null), verify accepts `remember`; settings `trustedBrowsers` and two URLs. Events `BrowserTrusted`, `TrustedBrowsersForgotten`. `mfa:status` shows the count. 24 Pest tests + JSON contract.
- [x] Frontend: challenge checkbox (`challenge-form` `trustBrowserDays`, `onSubmit(code, { trustBrowser })`), `trusted-browsers-panel` component (+ `MfaIconBrowser`), page wiring, Vitest, preview scenarios. Type-checked in all three apps.
- [x] Reminder before trust ends (maintainer request: no unannounced challenge mid-task): in the last `trusted_browsers.reminder.hours` (12) the nudge card shows "Two-factor check coming up … again in 5 hours" with **Verify now** / **Later** (the maintainer rejected "Renew now": it reads like a payment). Context key `trustReminder` (from the session, no query); `/mfa/challenge?renew=1` (page prop `renew`) verifies early, re-trusts the browser (the old row is replaced) and returns to the page; `POST mfa/trusted-browsers/reminder/dismiss` hides it for the session. `?renew=1` does nothing for a session not on a trusted browser.
- [x] Frontend for the reminder: `trustReminder` in `mfa-context.ts` (`mfaTrustReminderWhen()`, rounded down so it never promises more time than is left), `mfaNudgeProps()`/`useMfaNudge()` show it in the nudge card (`kind`, `closeLabel`; `enable-nudge` gains `closeLabel`), the challenge's verify-early mode (box ticked, "Not now" goes back, or to `/` in a new tab). 420 Vitest; type-checked in all three apps.

### 1.11 Verification lifetime for enforced users — `[~]` (built 2026-10-11 on `feat/verification-lifetime`, uncommitted, in review)
New requirement: enforced users get no trusted browser; one verification lasts an absolute, non-sliding 4 hours (configurable) with a 20–30 minute idle timeout, a reminder 30 minutes before the end, and a grace window (≤ 10 minutes, switchable off) so the end never breaks a request in progress. Normal users keep 30-day trusted browsers. Full design, tests and open decisions: [verification-lifetime-plan.md](verification-lifetime-plan.md).
- [x] Maintainer decisions: 10-minute grace (not delayed revocation); an admin MFA reset ends every session immediately (new requirement); enforced profile on by default; `on_expiry` configurable, default `challenge`; idle 25 minutes; `trustReminder` → `reverifyReminder`; no trust-row clean-up (not in production yet).
- [x] Package: lifetime profiles + `LifetimePolicy`, gate expiry/grace/idle, immediate revocation (`Mfa::reset()`, `mfa_revocations`), reminder + renew (`reverifyReminder`, client timer), `idle-warning` + `useMfaIdleWarning()`, `GET mfa/session` / `POST mfa/session/keep-alive`, events, doctor/status, docs, tests (`VerificationLifetimeTest`, JSON contract, Vitest). Trusted browsers on by default. Not built: §7 `mfa.fresh`.
- [ ] artistly: `allow_enforced => false`, enforced profile, mount the idle warning, QA

**Phase 1 exit criteria:** met: released and tagged, CI green on GitHub.

---

## Phase 2 — Integration steps (the same checklist in every app)

The step-by-step guide for developers is [docs/integration.md](../../docs/integration.md); this checklist tracks it per app.

Each app gets these steps on its own feature branch. App-specific deviations are in Phases 3–5.

1. **Install**
   - Add the Composer repository: `vcs` with the public HTTPS URL (`https://github.com/strontiumcorp/laravel-mfa`), no credentials; require the current minor, e.g. `^0.5` (below 1.0 a caret only allows patches, so raise it for each new minor).
   - `composer require strontiumcorp/laravel-mfa`
   - `php artisan mfa:install` (publishes `config/mfa.php`, the pages and `mfa-context.ts` into `resources/js/{Pages|pages}/mfa/`, and the components into `resources/js/components/vendor/laravel-mfa/`)
   - `php artisan migrate`
2. **Model:** add `implements MultiFactorAuthenticatable` and `use HasMultiFactorAuthentication` to `App\Models\User`.
3. **Config / env:**
   - factors: TOTP + email (D4); `MFA_SMS_ENABLED=false`, `MFA_SMS_DRIVER=twilio` + Twilio credentials ready (D3)
   - `'enforcement' => ['roles' => ['admin', 'super_admin', 'support'], 'required_types' => ['totp']]` (D5, D12)
   - `routes.home`; password confirmation (D6): keep `routes.password_confirmation` on; for social-login users with an unknown password set `routes.password_confirmation_policy` to a class (clone-voice: ask only when `google_id === null`?) or set it to `false`
   - `MFA_DELIVERY_QUEUE=mfa` (or the app's queue) and a worker that serves it (D8)
   - `MFA_LOG_CHANNEL`
   - keep `enrollment_verification` at its default (email code before an enforced user's first method); decide whether any role needs `email => false` (admin links only)
   - security notifications are on by default: check the mailer in every environment, and turn off any the app already sends
   - verification lifetime: keep the enforced profile (4 h window, 25 min idle, 10 min grace) or set `MFA_ENFORCED_*`; `session.lifetime` longer than the idle timeout; trusted browsers are on by default for everyone else (republished config: `env('MFA_TRUSTED_BROWSERS', true)`)
4. **API-key auth:** in `ApiKeyAuth` / `MultiAuth`, change `auth()->login($user)` to `auth()->setUser($user)` (stateless; stops API keys from minting browser sessions).
5. **Impersonation:** confirm it still works. Login-swap impersonation needs `Mfa::grantForImpersonation()` on **every** swap (users without MFA too), so resetting the admin ends it. artistly: `Admin/UserController.php` already calls it on every swap.
6. **UI**
   - Give the published pages the app's layouts as persistent layouts in the Inertia `resolve` callback (`mfa/settings` → the authenticated layout; `mfa/challenge` needs none, only whatever keeps the app's dark-mode class in sync), so `mfa:install --force` can republish the pages untouched. artistly does this in `app.jsx`.
   - Add `<MfaSettingsCard {...mfaSettingsCardProps(useMfa())} renderLink={(link) => <Link {...link} />} />` (from `@/components/vendor/laravel-mfa/settings-card`) to the account settings page as its own section (no wrapper); `className` adds to its classes, `!` overrides them.
   - Check that flash/status display works with the app's `HandleInertiaRequests`.
   - Share the context: `'mfa' => fn () => Mfa::context($request)` in `HandleInertiaRequests::share()`.
   - Show `<MfaApiKeyNotice {...mfaApiKeyNoticeProps(useMfa())} />` (from `@/components/vendor/laravel-mfa/api-key-notice` and `@/{Pages|pages}/mfa/mfa-context`) next to the API key settings.
   - Add the axios/`fetch` interceptor from integration step 6 ("Background requests") so the app's own background requests send the browser to the challenge on `mfa_required` / `mfa_enrollment_required`.
   - Mount `<MfaIdleWarning {...useMfaIdleWarning()} />` (from `@/components/vendor/laravel-mfa/idle-warning`) next to the nudge, for the idle warning before an enforced user's idle timeout.
   - Mount `<MfaEnableNudge {...useMfaNudge()} />` (from `@/components/vendor/laravel-mfa/enable-nudge` and `@/{Pages|pages}/mfa/mfa-context`) once in the global authenticated layout; pick `position`/`offset` so it clears the app's own fixed UI (toasts, chat widgets). Pass the app's "is impersonating" state as `disabled` so an impersonating admin can't dismiss the user's nudge. Decide the copy (`mfa.nudge.*`) or turn it off (`MFA_NUDGE_ENABLED=false`).
7. **Tests**
   - Run the existing suite. It should pass unchanged, because `actingAs()` doesn't trigger MFA.
   - Add app-level tests with `InteractsWithMfa`: challenge after login, verified access, an admin forced to enroll, impersonation, a webhook still working, and an API key still working.
8. **Verify:** `php artisan mfa:doctor` passes; then manual QA (checklist below).
9. **Deploy pipeline:** add `php artisan mfa:doctor` as a deploy step; confirm the scheduler runs (for `mfa:prune`).

### Manual QA checklist (run per app)
- [ ] Password login with MFA → challenged → TOTP → land on the intended page
- [ ] Wrong code → error; 5 wrong codes → rate-limited message
- [ ] Email code: sent on its own when the challenge opens on email, receive, verify; a refresh keeps the countdown and sends nothing; resend cooldown message
- [ ] Recovery code works once, and `remaining` decreases
- [ ] "Sign out" on the challenge page works
- [ ] Remember-me: close the browser, reopen → challenged
- [ ] Admin: "Two-factor check coming up" 30 min before the window ends; "Still there?" before the idle timeout; a form submitted just after the window ends still saves; "Verify now" returns to the page (shorten `MFA_ENFORCED_*` locally)
- [ ] `mfa:reset <email>` signs the user out of every open session
- [ ] Google login (clone-voice) → challenged
- [ ] Enroll TOTP from settings; recovery codes shown once; remove the factor
- [ ] Admin without MFA → forced to the settings page; other pages blocked until enrolled; adding the first method asks for an emailed code first; a "method added" email arrives
- [ ] `mfa:enrollment-link <email>` link, opened by that user while signed in, lets them add a method without the email code; a second use is refused
- [ ] A stale tab that polls (`fetch`) after the session ends lands on the challenge; after verifying, the user returns to the page, not the polled URL
- [ ] A stale tab's `PUT`/`DELETE` after the session ends lands on the challenge (no 405)
- [ ] Admin impersonates a user who has MFA → no challenge; exit impersonation → back as admin
- [ ] A webhook endpoint and an API-key endpoint keep working for a user with MFA
- [ ] `mfa:status <email>` shows the events above with one flow ID per attempt

---

## Phase 3 — artistly (Laravel 11, Inertia v2, React 18 / JSX, Kernel-style app) — `[~]`

Integration is in progress on branch `SDAP-786` (uncommitted as of 2026-10-09); move it to a dedicated MFA branch before committing.

- [x] **Prerequisite:** package fix 1.3 (configurable logout URL). artistly's logout is the standard named `logout` route.
- [ ] Laravel 11 is end-of-life and every 11.x has open advisories, so Composer may block installs. Use the separate advisory-triage task, and consider upgrading to Laravel 12 before or after MFA.
- [x] Steps 1–3 of the Phase 2 checklist: `^0.4.2` installed from the public repo, pages in `resources/js/Pages/mfa/`, components in `resources/js/components/vendor/laravel-mfa/`, `User` implements `MultiFactorAuthenticatable`, `config/mfa.php` published, delivery queued under Horizon (email tested locally through Mailtrap).
- [ ] Move to `^0.5` after v0.5.0 and republish with `mfa:install --force` (back up `config/mfa.php` first).
- [ ] **Set `enforcement.roles`** (D5): still `[]` in artistly's `config/mfa.php`.
- [x] **Login-swap impersonation:** `Admin/UserController::switch_user` calls `Mfa::grantForImpersonation($impersonator, $target)` after the swap.
- [ ] `exit-impersonate`: no change expected (the admin's verified flag survives); verify in QA.
- [x] `ApiKeyAuth` already uses `Auth::setUser()`, so no change was needed.
- [ ] `App\Jobs\ProcessBedtimeFlipbookJob` calls `Auth::guard('web')->loginUsingId()` inside a job. Harmless when queued (no session is persisted); confirm it is never dispatched synchronously from a web request.
- [ ] `App\Http\Middleware\TrustProxies` has `$proxies` unset. If production runs behind a load balancer/CDN, configure the proxy IPs, or every user shares one IP for the per-IP limits (`mfa:doctor` warns).
- [ ] Kernel-style app: verify the middleware lands in the `web` group (`mfa:doctor`) and that `mfa:prune` appears in `schedule:list`.
- [x] Context shared in `HandleInertiaRequests` (`'mfa' => fn () => Mfa::context($request)`).
- [x] `MfaSettingsCard` on `Pages/Profile/Edit.jsx`; after v0.4.2 pass `className="mx-auto w-[90%] !border-0 !shadow sm:w-full dark:!bg-[#1E1F24]"` (or similar) so it matches the page's other sections.
- [x] Page layouts set in `app.jsx`'s `resolve`: `mfa/settings` → `AuthenticatedLayout`, `mfa/challenge` → `SyncDarkModeLayout` (calls `useDarkMode()` so the `dark` class follows the cookie).
- [x] `MfaEnableNudge` mounted in `Layouts/AuthenticatedLayout.jsx` with `disabled={!!original_user}`; check it against the app's toasts.
- [ ] After v0.3: keep `password_confirmation => true` and drop any `confirm_middleware => []` override; add `withConfirmedPassword()` to MFA tests that add/remove factors.
- [ ] After v0.6: republish `settings.tsx` and `factor-setup-dialog.tsx` (the enrollment verification step); add the `fetch`/axios interceptor (integration step 6, "Background requests"): `Components/Design/FilterByTags.tsx:28` and `Components/Design/AiBulkActionProgress.tsx:41` poll with plain `fetch()`; MFA tests that enroll an enforced admin need `withEnrollmentVerified($admin)`.
- [ ] Decide on re-challenges: artistly forces remember-me with `SESSION_LIFETIME=120`, so a user idle for 2 hours is challenged again (intended; docs/configuration.md "Sessions, remember-me and re-challenges"). Accept it, raise `SESSION_LIFETIME`, or turn on trusted browsers (§1.8: `MFA_TRUSTED_BROWSERS=true` and `trusted_browsers.allow_enforced` for admins).
- [~] Steps 7–9 of the checklist: `tests/Feature/MfaIntegrationTest.php` exists; `mfa:doctor`, manual QA and the deploy step are still to do.

## Phase 4 — clone-voice (Laravel 12, Inertia v2, React 19, Octane, Google OAuth) — `[ ]`

Working branch is currently `production`; create a dedicated MFA branch, not `production`.

- [ ] Steps 1–3 of the Phase 2 checklist (`resources/js/pages/mfa/`)
- [ ] `app/Http/Middleware/ApiKeyAuth.php:71` and `app/Http/Middleware/MultiAuth.php:35`: change `login()` to `setUser()`. `auth.multi` guards `routes/app.php`, which is in the `web` group, so this matters here.
- [ ] Google login (`Auth/GoogleController.php`, `Auth::login($user, remember: true)`) is challenged automatically. Apply decision D6 for password-less users.
- [ ] Admin login redirects to `admin.dashboard`. Confirm the intended URL survives the challenge for admins.
- [ ] Per-request impersonation (`HandleImpersonation`): verify only
- [ ] Trusted proxies are `'*'`. Same check as podcast-flow (exactly one proxy layer, or explicit proxy IPs).
- [ ] Settings link in `routes/settings.php` pages
- [ ] Mount `MfaEnableNudge` in the global authenticated layout (`layouts/app-layout.tsx` or `base-layout.tsx`)
- [ ] Steps 6–9 of the checklist; manual QA

## Phase 5 — podcast-flow (Laravel 13, Inertia v3, React 19, Octane) — `[ ]`

Working branch is currently `PODCAST-144`; create a dedicated MFA branch.

- [ ] Steps 1–3 of the Phase 2 checklist (pages go to `resources/js/pages/mfa/`)
- [ ] `app/Http/Middleware/ApiKeyAuth.php:61` and `app/Http/Middleware/MultiAuth.php:28`: change `login()` to `setUser()`
- [ ] Impersonation uses per-request `HandleImpersonation` (`Auth::setUser`). **No change expected**; verify only.
- [ ] Webhooks in `routes/webhooks.php` are in the `web` group but carry no session, so they pass. Verify `webhook/*` is in `middleware.except` anyway.
- [ ] Add the settings link next to `settings/profile` / `settings/password` (`routes/settings.php`)
- [ ] Octane: run the suite and a manual flow under `octane:start` (no state leaks between requests)
- [ ] Trusted proxies are `'*'`. Confirm the app is reachable **only** through exactly one proxy layer; otherwise list the proxy IPs explicitly, so per-IP send limits can't be bypassed by spoofing (`mfa:doctor` warns).
- [ ] Mount `MfaEnableNudge` in the global authenticated layout (`layouts/app-layout.tsx` or `base-layout.tsx`)
- [ ] Steps 6–9 of the checklist; manual QA

---

## Phase 6 — Rollout & operations — `[ ]`

### Staging (per app)
- [ ] Shared cache and session in staging/prod (redis/database). `mfa:doctor` warns on `file`.
- [ ] A dedicated `mfa` log channel, shipped to the log platform
- [ ] Dashboards: verification success/failure by `reason`, delivery failures, delivery latency (`mfa.delivery_duration_ms`)
- [ ] Alerts: a `delivery_failed` spike, a `rate_limited` spike, a `replayed` spike (possible attack)
- [ ] SMS: provider credentials, calling-code allowlist, and a monthly spend cap set at the provider

### Production (per app, after staging QA)
1. [ ] Deploy with `MFA_ENABLED=true`, opt-in only (`enforcement.roles` = `[]`). Watch for a week.
2. [ ] Turn on `EnforceForAdmins`. Admins enroll on next login.
3. [ ] Announce opt-in MFA to users (email / in-app).
4. [ ] Review metrics and audit logs. Decide whether to enforce for more user groups.

The kill switch for any incident is `MFA_ENABLED=false`. It takes effect on the next config reload / deploy.

### Support runbook
- [ ] Document the steps for a locked-out user:
  1. Verify identity out-of-band.
  2. Run `php artisan mfa:status <email>`.
  3. Run `php artisan mfa:reset <email>`.
  4. Ask the user to re-enroll. An enforced user gets an email code before their first method; for a user without a usable mailbox, run `php artisan mfa:enrollment-link <email>` and send the link on a trusted channel.
- [ ] Document how to trace a reported failure: `mfa:status <email> --flow=<id>`, then grep the logs for `mfa_flow_id`.

---

## Roadmap

- [ ] **Per-network `verify_per_day`** (R6, accepted 2026-10-09 as a known limitation, docs/configuration.md "Known limitations"): count the daily verify cap per user and per network, like the daily send caps (`SendGuard::ipBucket`), so someone with only the password can't block the owner's code entry for a day. Decide whether the per-minute limit stays per account.
- [~] **Trusted browsers**: built on 2026-10-10 at the maintainer's request (§1.8), off by default. Follow-ups if wanted: a shorter `days` for enforced users, and an email to the owner when a browser is trusted.

---

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| A route outside the `web` group serves session-authenticated pages | Audit each app's route groups during integration; add the `mfa` alias wherever needed |
| SMS toll fraud | Calling-code allowlist; cooldown that spans logins; per-account hourly cap and per-type daily caps (SMS 5/day); app-wide caps for confirmed and unconfirmed sends; no resend after a "maybe delivered" timeout; provider spend cap; launching without SMS |
| Users locked out | Recovery codes, multiple factors per user, `mfa:reset` runbook |
| Laravel 11 EOL advisories (artistly) | Separate triage task; plan the L12 upgrade |
| A boot-order difference drops the middleware | The package appends through the HTTP kernel; `mfa:doctor` checks it in every deploy |
| Breaking the existing test suites | `actingAs()` is not challenged by design; run each app's suite before merging |

---

## Progress log

- **2026-10-07**
  - Package scaffolded: core, factors, observability, commands, UI stubs, CI.
  - 121 tests passing on Laravel 11/12/13; PHPStan level 6 clean.
  - Bugs found and fixed while testing:
    - the middleware could be dropped from the `web` group depending on boot order
    - SMS drivers ignored `Http::fake()`
    - the intended URL was read twice after verification
    - a typing error in the React challenge page
  - Found that artistly's lockfile has 71 security advisories; a separate triage task was started.
- **2026-10-08**
  - Re-verified: 121 tests pass on Laravel 13, including the pruning/email/log-driver tests added at the end of the previous session.
  - Confirmed **no app has the package installed yet**, and the package repo has no commits.
  - Found that artistly's logout route is `POST /admin/logout`, so the configurable logout URL fix was added to Phase 1.
  - Found that clone-voice Google-login users affect password confirmation; added as decision D6.
  - This plan was created.
  - Completed 1.3 and 1.4:
    - configurable logout URL
    - JSON-mode docs with a contract test
    - mutation testing
    - security review: 11 findings fixed with regression tests; 2 turned into decisions D9 and D10
  - 161 tests pass on Laravel 11/12/13; PHPStan and Pint clean.
  - Found the first mutation score (94.5%) was invalid (vendor pollution); real security-code baseline is 67.0%.
  - Added a deep config merge (apps with older published configs were losing nested keys).
  - Send-limits redesign, agreed with the user:
    - exponential cooldown (2-minute base)
    - unconfirmed and confirmed budgets
    - per-IP distinct-destination cap with IPv6 /64 grouping
    - circuit breaker
    - suspicious-activity event
    - `retry_after` and a UI countdown
    - trusted-proxy doctor check
  - Proxy findings for the apps: artistly trusts no proxies (shared IP behind a load balancer); clone-voice and podcast-flow trust `'*'` (safe only behind exactly one proxy layer). Added to Phases 3–5.
  - 192 tests pass on Laravel 11/12/13.
  - Mutation triage of the security code:
    - parallel mutation found to be unreliable
    - concurrent groups found to collide through the shared skeleton; fixed
    - real gaps closed (recovery-code login, cold cache, morph maps, fixation test)
    - two counting bugs fixed
    - equivalents marked with reasons
  - 274 tests pass on Laravel 11/12/13; PHPStan and Pint clean.
  - Final mutation run: security code 99.4%; the last survivors were resolved by hand. Mutation testing is retired (user decision: too slow).
  - Added the `Makefile` task runner and parallel test/coverage runs.
  - Fixed: invalid email destinations got a "phone number" error message (now neutral).
  - 290 tests pass; coverage 95.5%; the stubs type-check in all three apps.
  - SMS providers:
    - Infobip and Amazon SNS drivers (SigV4 verified against the AWS SDK)
    - failover and routing composites, named drivers
    - doctor checks for chains
    - 322 tests pass on Laravel 11/12/13
  - Researched current list prices (Twilio, Vonage, Infobip, AWS). The result corrected an earlier assumption: AWS is only marginally cheaper outside India-with-DLT.
  - Clarified that all four providers are config-switchable at any time, and what provider-side setup they still need. India DLT support was noted as a follow-up.
  - Plan snapshot written for picking up in a new session.
  - D1/D2 settled:
    - renamed to `strontiumcorp/laravel-mfa` / `StrontiumCorp\LaravelMfa`: 465 replacements, including the HMAC derivation label (safe, since nothing is deployed)
    - added the author/maintainer entry, `CODEOWNERS` and private-repo install docs
    - all checks pass on Laravel 11/12/13
- **2026-10-09**
  - Second independent review of the package (security core, SMS drivers, integration, docs); see §1.4c.
  - Fixed: a custom logout route was blocked for unverified users (R1); a broken SMS config broke email delivery and 500'd SMS sends (R2). Regression tests added.
  - README: SMS driver list and logout-exemption wording updated.
  - 12 open items triaged (R3–R14); R3 (SMS timeouts) and R5 (user-deletion cleanup) recommended before v0.1.0.
  - Plan corrections for artistly: branch is now `SDAP-786`, the impersonation swap is at `UserController.php:265`, logout is the named `logout` route.
  - 325 tests pass on Laravel 11/12/13; PHPStan and Pint clean; the stubs type-check in all three apps.
  - Follow-up the same day, after the user's answers:
    - decisions D3–D10 closed (Twilio with SMS off; TOTP + email; role-based enforcement; Socialite doctor warning + shared `MfaContext`; artistly first; queued delivery; D9 option A; D10 fail-open documented)
    - implemented: R3/R7/R8/R9 (SMS timeouts, no duplicate retries, redaction, `+` prefixes), R10 (HMAC cache keys), R4 + D8 (queue name, discard failed codes), R13 (full page visit after the challenge), D9, D5 (`enforce` roles list, `Mfa::enforceUsing()`), doctor checks (R11, R12, D6, D10), `Mfa::context()` + TypeScript type + `MfaApiKeyNotice`, docs
    - R6 closed as won't-fix (user decision); R5 waits on the FK-vs-morph decision
    - 347 tests pass; PHPStan and Pint clean; the stubs (pages and components) type-check in all three apps
  - R5 done (user decision): MFA rows moved from a polymorphic relation to a `user_id` foreign key. Factors, OTP codes and recovery codes cascade on user delete (query-builder deletes included); audit rows are kept with `user_id` null until the prune. New `mfa.user_model` setting and a doctor check that every MFA guard uses it. R8 confirmed as implemented. 348 tests pass on Laravel 11/12/13.
  - Release tooling: `scripts/release.sh` (`make release`) adapted from the clonevoice script. It infers the bump from Conventional Commits, groups the changelog by type, checks branch/clean tree/up to date/`make ci`, makes an annotated tag with the notes, and pushes atomically after confirmation. Tested end to end against a scratch bare remote. The clonevoice-only helpers (plugin manifest versions, skill packaging) were dropped; Composer versions come from tags.
  - Coverage-gap pass (from the CI coverage report): 22 tests for behaviour nothing exercised. Covered:
    - resend refused for another session's pending factor
    - recovery codes shown to Inertia exactly once
    - recovery-code attempts share the verify rate limit
    - malformed codes don't spend an attempt
    - critical log level for the send circuit breaker
    - failing log/metrics sinks never block a login
    - `mfa:reset` decline, mfa:doctor D5/D8/production/Kernel-style TrustProxies checks
    - FK relations, `append_to_web_group = false`
  - Bugs found: a late `Mfa::extend()` was ignored once a factor driver was built; `mfa:doctor` counted failures across runs in one process. README's "custom factor" section promised new factor types (e.g. passkeys) that the closed `FactorType` enum can't store; reworded to "replace a built-in factor". 370 tests; coverage 98.4%.
  - CI speed: the per-push run was ~1m30s because 24 jobs exceed the plan's 20 concurrent jobs (the last 4 queued ~40s) and the quality job re-ran the suite for coverage. Now: coverage in one matrix cell, parallel Pint, Composer and PHPStan caches, Node 24 actions (checkout@v7, cache@v6). Per push: static analysis + 7 combinations (user decision); the full 22-job matrix runs on tags, on demand and nightly when `main` changed.
  - UI restructure (D11, user request): published pages are now thin Inertia wrappers; the UI lives in nine standalone React components published to `resources/js/components/vendor/laravel-mfa/`. `useMfa()` moved to `{Pages|pages}/mfa/mfa-context.ts`; `MfaApiKeyNotice` takes props. Backend contract unchanged. Vitest + Testing Library added (71 tests, `make test-js`, CI `ui-suite.yml`). `mfa:install`, its tests, `docs/integration.md` (steps 2 and 6, props table, upgrade note), README, CLAUDE.md and `scripts/typecheck-stubs.sh` updated. Pages and components type-check in artistly (Inertia 2, React 18), clone-voice (Inertia 2, React 19) and podcast-flow (Inertia 3, React 19). Not committed yet; release as v0.2.0 after review.
  - Recommended factor types (user request): `factors.{type}.recommended` in config (default: `totp` only), `Mfa::isTypeRecommended()`. Settings' `availableTypes` gains `recommended` and lists recommended types first (JSON contract, docs/json-mode.md and docs/configuration.md updated); `MfaAddFactorForm` badges them and orders them first too, so it works with any data. The challenge's factor order (most recently used first, which picks `defaultFactorId`) is unchanged.
  - Enforcement regrouped and required types (D12, user request): `config('mfa.enforcement')` with `roles`, `policy`, `required_types` (default `['totp']`). New `Mfa::isEnforced()`, `enforcesAnyone()`, `enforcementRules()`, `requiredTypes()`, `challengeTypes()`, `confirmedTypes()`, `refreshEnrollmentRequirement()`, `mustEnrollAfterVerification()`; `mustEnroll()` now also covers an enforced user without a required type. Middleware holds verified-but-incomplete sessions on the enrollment routes via a session flag (no per-request query). Challenge page and `ChallengeService` restrict enforced users to their required types. Settings props gain `requiredTypes`; `MfaFactorList` takes `requiredLabels`. `mfa:doctor` validates `required_types`, warns on the old `enforce` key; `about` shows the required types. 10 new Pest tests (`RequiredFactorTypesTest`), 2 Vitest tests; the key tests were checked to fail with the hold or the server-side restriction removed. Docs: configuration.md (table of cases, upgrade note), integration.md, json-mode.md, README, CLAUDE.md.
  - Review pass over the D11/D12/recommended-types work. Fixed: the challenge page showed "Code sent" and the resend countdown on every factor after a send, not just the one the code went to; `mfa:doctor` said nothing when the old `enforce` key sat next to the new keys (it's ignored then; now a warning); stale `enforce` references in docblocks; the settings page shadowed `window.confirm` with a local name. Added page tests (Vitest, `@inertiajs/react` mocked) for both pages' requests, a zero-query test for a verified enforced user, a doctor test and an `about` test. Checked, no change needed: Inertia `post`/`delete` default to `preserveState: true`, so page state survives the redirects; `ConfigMerge` takes list values (`required_types`, `roles`) from the app whole, so `[]` empties them.
  - v0.3.0 candidate (user request): password confirmation inside the MFA settings flow, and an account-settings entry-point component.
    - Decision: `routes.confirm_middleware` stays (extra middleware, now default `[]`); new boolean `routes.password_confirmation` (default `true`) runs MFA's own check after it. Both use `auth.password_confirmed_at`, so the app's `password.confirm` page and MFA's prompt satisfy each other. Old configs with `['password.confirm']` behave as before; old configs with `[]` (meaning "off") now ask: documented in the configuration.md upgrade note.
    - Backend: `Http\Middleware\RequirePasswordConfirmation` (JSON `423 {message, error: password_confirmation_required, confirm_url}`; Inertia a validation error under `password_confirmation_required`), `SettingsController::confirmPassword` (`POST /mfa/confirm-password`, validated by the session guard's user provider, rate limits counted before checking, cleared on success), `FailureReason::InvalidPassword`, events `PasswordConfirmed` / `PasswordConfirmationFailed`, `Mfa::confirmPasswordUsing()` / `asksForPassword()` / `needsPasswordConfirmation()` / `markPasswordConfirmed()`, `SessionIdentity::validatePassword()`, settings `urls.confirmPassword`, enrollment allow-list includes the confirm route. Users with an empty stored password are never asked. `mfa:doctor`: app-page check kept, warns when confirmation is off entirely and when Socialite is installed without `confirmPasswordUsing()`. `withConfirmedPassword()` test helper.
    - Found in review: with login-swap impersonation the admin's own fresh confirmation carried into the target's session, so the admin could remove the target's factors. `grantForImpersonation()` now forgets `auth.password_confirmed_at` (regression test in `ImpersonationTest`, failed before the fix).
    - Frontend: `password-confirm-form` and `settings-card` components; the settings page asks inline and retries the change from `onFinish` (a visit started in `onSuccess` would interrupt the confirm request); `mfaSettingsCardProps()`. The Inertia test mock now runs `onStart/onSuccess/onError/onFinish` and can queue errors.
    - Tests: 26 new Pest tests (`PasswordConfirmationTest`, JSON contract, doctor, install output); key ones checked to fail with the empty-password skip, the timeout boundary, the hook, count-before-check and clear-on-success removed. 21 new Vitest tests (two components, `mfa-context` helpers, the page's ask-and-retry flow, checked to fail without the retry).
    - Docs: configuration.md (new "Password confirmation" section and upgrade note), integration.md (steps 4 and 6, props table, v0.2 upgrade note), json-mode.md (423 contract, `POST /mfa/confirm-password`), README, `mfa:install` next steps, CLAUDE.md invariant.
  - Two-axis review of the v0.3 work (standards + spec sub-agents), then fixes, user decisions on each finding:
    - `Mfa::enforceUsing()` removed (an `enforcement.policy` class covers it), and the new `confirmPasswordUsing()` closure replaced by `routes.password_confirmation_policy` + `Contracts\PasswordConfirmationPolicy`: no closures on the singleton. Documented break (configuration.md upgrade note).
    - `auth.password_timeout` read as is, no `10800` in code; `mfa:doctor` fails when it isn't set.
    - New `PasswordConfirmationRequired` event on the refusal, like `ChallengeRequired` / `EnrollmentRequired` (kept in the audit table: low volume).
    - Bug: a password lockout flashed `mfa.retry_after`, which started the code-resend countdown on pending email/SMS setups. It now flashes `mfa.password_retry_after`, exposed as the settings prop `passwordRetryAfter`, and `password-confirm-form` counts down itself (`retryAfter` prop; hours for the daily cap).
    - `Mfa::PASSWORD_CONFIRMED_AT` constant; `withConfirmedPassword()` calls `markPasswordConfirmed()`. Renamed `asksForPassword()` / `needsPasswordConfirmation()` to `requiresPasswordConfirmation($user)` (policy) and `passwordRecentlyConfirmed($session)` (timestamp). `Mfa::validatePassword()` replaces `SessionIdentity::validatePassword()`, so the controller resolves the user once.
    - `MfaContext.passwordConfirmation` is per user when someone is logged in (would they be asked), global for guests.
    - Tests: the 429 shape pinned in `JsonContractTest`; regression for the session-user check under `Auth::setUser()` impersonation (fails if the middleware or the check use `Auth::user()`); lockout flash key, event, per-user context, doctor checks. Each new regression test was checked to fail without its fix.
    - Not changed (user decisions): old v0.2 pages are re-published on upgrade; `Mfa.php` refactor later if needed; the second round trip after a confirm stays unless it becomes a problem.
  - Settings page redesign (user request, design picked on the canvas "MFA settings redesign": direction A, one card per method, after GitHub's and Google Account's 2FA pages). New `factor-cards` component: a card per method with a 56px icon tile, status badge (Active / Not set up / Setting up / Required / Recommended), details (masked destination, Added from `confirmed_at`, Last used) and one action; "Set up" opens the email/phone entry inside the card with a two-step indicator, and the page passes each pending setup into its card (`setups`). Enforced users get a banner naming what they need, the required card is highlighted, and other methods say they don't count on their own. `recovery-codes-panel` restyled as a matching card with an "8 of 10 left" meter (`total` prop; settings prop `recoveryCodesTotal` = `recovery_codes.count`, JSON contract and docs updated) and a warning at 2 or fewer. `totp-setup` / `destination-setup` gained `framed` (false inside a card). The page header shows Required / On / Off. `factor-list` and `add-factor-form` stay published for v0.2 pages. Icons: `icons.tsx` (decision D11 amended, see Phase 0). Buttons use the package's near-black instead of the canvas's blue, so the cards match the setup forms inside them.
  - UI preview (user request): `make preview` serves `preview/` (Vite, in the repo but export-ignored) at http://localhost:5180: the real pages against a fake backend with the package's endpoints and rules (`preview/backend.ts`), 11 scenarios, light/dark/system, phone 390 / tablet 768 / desktop 1280 frames side by side; Inertia is aliased to a fake that calls the same callbacks in the same order. `vite` is now an explicit dev dependency. `tests/js/preview.test.tsx` renders every scenario and walks the backend's TOTP and password flows. Checked through it at all three widths: the TOTP setup stays inline in its card (no modal: on phones a modal fights the keyboard and the three-part step), and gained an "Open in authenticator app" link on phones (`otpauthUrl`, from `otpauth_url`), a Copy button and a key grouped in fours. Card actions now drop to a full-width row under the text below `sm`, and the "Save your recovery codes" box matches the new cards.
  - Authenticator setup as a dialog (user decision, replacing the inline card setup for TOTP): new `totp-setup-dialog` component, three steps (scan / enter code / save recovery codes); Complete unlocks only after Copy or Download, and the codes step can't be closed or escaped. Bottom sheet on phones, centred from `sm`; focus moves in, Tab is trapped, Esc closes (steps 1–2), page scroll locked, focus restored; a parent's `space-y-*` can't offset the fixed overlay (inline margin 0). Copy falls back to a selected textarea when the Clipboard API is blocked, then says to download instead. The page opens it for a pending TOTP factor (also after a reload), keeps a copy of the pending setup while confirming, leaves "Continue setup" in the card when closed early, never reopens a completed one, and hides those codes from `recovery-codes-panel`. Icons `MfaIconClose`, `MfaIconCopy`, `MfaIconDownload` added to `icons.tsx`. Walked through in `make preview` on phone and desktop (preview iframes now `allow="clipboard-write"`). Email/SMS setups stay inline.
  - Phone pass (user request, from a 390px screenshot): cards no longer wrap their badges or grow tall on phones. Body text stays 14px (smaller hurts readability); instead, below `sm`: short descriptions ("Codes from an authenticator app." etc., the full copy from `sm` up), no "Not set up" badge (the dashed card and its button say it), no "Recommended" next to "Required", a 44px icon tile and tighter padding. The authenticator card went from about 215px to about 100px tall at 390px; tablet and desktop unchanged.
  - Fix (user report): after a wrong code, the code inputs (challenge, setup dialog, inline authenticator setup, email/SMS setup) cleared themselves, so users couldn't see what they typed or fix one digit. They now keep the code, focus the field and select it (typing replaces it). The password prompt still clears. Tests changed first, and failed before the fix.
  - Password prompt placement (user report: the prompt appeared at the top of the page, away from the action, which moves the user's focus): it now shows inside the card where the change started: the method's card for Remove (`factor-cards` `passwordPrompt` at `{ factor }`), the type's card for Set up (`{ type }`), the recovery card for New codes (`recovery-codes-panel` `passwordPrompt`). In a Set up card it's the only next step (Send code disabled, the card's Cancel hidden), and the email/SMS form now closes only once its code went out, so the typed number survives the prompt. `password-confirm-form` gained `framed`; on phones it's the field, then Cancel and Confirm side by side (Confirm on the right). Walked through in `make preview` on a phone: number, password, code sent to that number, without leaving the card. Asking before Set up (instead of at Send code) is not done; the user chose placement first.
  - One setup dialog for every method (user decision): `factor-setup-dialog` replaces `totp-setup-dialog` (unreleased, so no break). Steps follow the props: password first when the new settings prop `passwordConfirmationRequired` says so (`requiresPasswordConfirmation() && ! passwordRecentlyConfirmed()`; Pest test, JSON contract, json-mode.md), again if the server asks mid-way (then the same address/number is retried); the QR code and key (authenticator app; its key is fetched right away, or after the password) or the address/number; the code (resend countdown, "Use another number/address"); the recovery codes for a first method of any type (they used to show in the page's panel for email/SMS), or "<method> added". `factor-cards` gains `onStart`, so Set up hands every type to the page. Remove and New codes keep the in-card password prompt. Walked through in `make preview` on a phone: SMS with the password (password → number → code → "SMS added", 4 steps) and email as a first method (address → code → recovery codes). docs/integration.md's upgrade notes split into v0.2 → v0.3 (what v0.3.0 shipped) and v0.3 → next (the redesign).
  - Review of the dialog work, four fixes (tests first; the Inertia test mock's forms now keep errors like Inertia's, which the stale-error test needed): a setup no longer shows the previous attempt's error; re-sending to the same number after "Use another number" moves on to the code; only a setup pending at page load reopens by itself, once (closing it no longer opens the next); a password confirmed in an earlier setup no longer skips the next one's password step.
  - Authenticator issuer names the environment (user report: a staging or local account looked the same as production in the app): outside production, `factors.totp.issuer` gets the environment in brackets ("Acme (staging)"), from `app.env`; new `factors.totp.issuer_environment` (default `true`) turns it off. `TotpFactor::issuer()`; tests for production, staging, local and off. New enrollments only; existing app entries keep their name.
  - Challenge page send state (user reports #2 and #3). Bug #3: a refresh lost the resend countdown, so "Send code" looked enabled and the server answered "Please wait before requesting another code." Each email/SMS factor in the challenge props (and JSON) now carries `code_sent` and `retry_after`, from a read-only `OtpStore::status()` (no lock, no writes) that shares the cooldown maths with `issue()`: streak, curve, and the code's expiry when sooner. TOTP factors get `false`/`null`. Feature #2: the page sends the code itself when it opens on an email/SMS method or the user picks one, once per method per visit (a ref, so StrictMode and re-renders don't double it), and not when a code is already out: then it says "We sent a code to …" with the countdown. A failed send shows its error and doesn't retry. Never sent from the GET (prefetch, back/forward, JSON clients decide). Pest tests first (refresh countdown with a frozen clock, expired, burned, cooldown curve, ttl cap, TOTP, no codes or send budget used by the GET), the regression checked to fail without the fix; Vitest for arrival, StrictMode, TOTP default, switching, failed send. JSON contract, json-mode.md, configuration.md and the preview backend updated.
  - Challenge page redesign, direction A "focused method" (maintainer's pick), mirroring the setup dialog. `challenge-form` now draws its own card: the method's icon tile, a title per type ("Open your authenticator app", "Check your email", "Check your phone"), a description that says "We're sending a code to …" until a code is out, then "Enter the 6-digit code we sent to …" (or "We couldn't send a code to …" after a failed send; `aria-live`), a code input with one box per digit (one real input behind them, `autocomplete="one-time-code"`, numeric, `pattern`, `maxLength`; a paste replaces the whole code, the caret stays at the end, the current box gets a strong border, a wrong code stays selected and its boxes turn red), a full-width Verify (needs every digit), and a footer with "Try another way" and "Sign out". "Try another way" swaps the card to "Choose how to verify": a row per method (icon tile, name, masked destination or "Code from your app", chevron), plus "Recovery code"; picking one returns to it (and the page's existing once-per-method auto-send runs), Back returns without choosing, focus moves to the list heading and back to the code input. `send-code-button` is now the "Didn't get it? Resend in 0:58 / Send a new code" line under the input; `recovery-code-form` is the same card ("Use a recovery code", "Try another way" back to the list). New optional props only: `challenge-form` `sent`, `sendFailed`, `initialView`; `recovery-code-form` `onTryAnotherWay`; icons `MfaIconChevronLeft`/`Right`. `children` moved under the code input. PHP: challenge factors gain `code_length` (6 for TOTP, `factors.{type}.length` for email/SMS; `OtpFactor::codeLength()`), Pest test first, JSON contract and json-mode.md updated. Vitest rewritten for the component and the page (titles, sending/sent/failed copy, boxes, paste, Backspace, autocomplete, the list, recovery path, logout, code length). Preview: new scenarios (email + authenticator app + SMS + recovery codes, a code already sent with the countdown, authenticator app only), checked at phone/tablet/desktop in light and dark. Docs: integration.md step 6 props table and an upgrade note. Checked: Inertia v2 and v3 `post` default to `preserveState: true`, so the chosen method and the list/recovery view survive the send's redirect.
  - "Turn on two-factor" nudge (agreed with the maintainer). Who: MFA and its routes on, a logged-in user on an MFA guard with no confirmed method, not enforced (enforced users are sent to enroll anyway), not on an `mfa.*` page, not dismissed. Server: `mfa.nudge` config block (every default in `config/mfa.php`; copy through `__()`), `Support\Nudge` (cache per user under a `CacheKey` HMAC, expiring at the instant; session mirror `mfa.nudge.{id}`, cleared on logout so a new login asks the cache), `POST mfa/nudge/dismiss` in the MFA route group (same middleware and throttle; JSON `{ status: "nudge-dismissed", until }`, otherwise 303 back without a status flash, `UiResponse::back()`), `NudgeDismissed` event. The dismissal lasts until the next midnight in the browser's timezone (old names such as `Asia/Calcutta` accepted; unknown → `app.timezone`), computed server-side from timestamps (a skipped midnight becomes 01:00), capped at 26h, never taken from the client. Cost: nothing for users with MFA (checked after `hasMfa`); otherwise one cache read per page until the session knows. Context gains `nudge: { show, title, body, button, dismissLabel, dismissUrl }`; the settings page gains `nudge` (`{ title, body }` or null) shown as an indigo notice in `factor-cards` (`notice` prop). Component `enable-nudge` (`MfaEnableNudge`): floating card, `position` (six), `offset` (number/string/pair, inline style plus `--mfa-nudge-x` with static `sm:` classes, so Tailwind 3 and 4 both generate them), full width with a 16px gutter on phones, optimistic hide, non-modal `region`, `MfaIconShieldLock`. `useMfaNudge()` in `mfa-context.ts` makes mounting one line. `mfa:install` prints the snippet. Tests: `tests/Feature/NudgeTest.php` (written first, failing), JSON contract, context contract, `CommandsTest`; Vitest for the component, the hook, the settings notice and the preview backend. Preview scenario `nudge` (app page, position switch) checked at phone/tablet/desktop in light and dark. Not done: a per-rule `required_types` (see 1.6).
  - Audit follow-ups, tests first, one commit each (server side; challenge props keep their shape plus a new `expires_in`). Refactors: the challenge send state is a `Support\ChallengeState` value object (`none()`, `sent()`, `toArray()`) through `OtpStore::status()` → `OtpFactor::challengeState()` → `ChallengeController`, TOTP's length from `TotpFactor::CODE_LENGTH`; one nudge rule, `Mfa::nudgeEligible()`, used by the settings notice and (with the `mfa.*` route check and the dismissal) the floating card; `UiResponse::back()` is `backQuietly()`. Fixes: enforcement and password confirmation policies and `Nudge` resolve from the live container (Octane); logout forgets `auth.password_confirmed_at`; a repeat nudge dismissal is a no-op (no event); refusals by a limit write one audit row per user, event, stage and scope per window (log and metrics still see every one; `PasswordConfirmationFailed` carries `retry_after`); the "has MFA" cache holds the user's confirmed types (`mfa:factor-types:{id}`) and applies the enabled types per read, so turning a type off or on applies at once; the gate is in the middleware priority before `SubstituteBindings` (no 404/302 existence leak); a send that may have been delivered (read timeout, `DeliveryFailed::$maybeDelivered`) ends a failover chain, isn't retried by the queue, keeps its code and is answered as sent (`maybe_delivered` in the events); a cooldown refusal's `retry_after` is capped at the code's expiry, like `status()`. Features: `rate_limit.confirmed_global_per_hour` (default 1000, `null`/`0` off) caps login codes app-wide against SMS pumping, rolled back like the others, `SendingPaused` (503) with `retry_after`; `SendingPaused` now says "Too many codes are being sent right now. Try again in N minutes, or use an authenticator app." for both app-wide caps; `SendingCircuitTripped` carries `scope` and fires once per window; challenge factors gain `expires_in`. Perf: the cached types are kept on the live request (`attributes`), so a user without factors costs one cache read per request instead of ~4. Docs: configuration.md (limits, choosing the cap, trusted proxies for the per-IP limits, observability, password confirmation on logout, Octane-resolved policies, maybe-delivered), json-mode.md (503, `expires_in`, cooldown cap, repeat dismiss), CLAUDE.md invariants.
  - Challenge page and nudge fixes (frontend only, test-first): each factor's `retry_after` (and the last send's flashed `retryAfter`) is pinned to an absolute deadline when props arrive, so switching methods shows the time actually left (was: counted from when the method was shown). A send refused while the factor's own fresh state says a code is out with a cooldown running is treated as that cooldown: "We sent a code to …" and the countdown, no red error (regression: back/forward restored stale props, the page auto-sent, the server refused). One `delivered` check. Challenge factors may carry an optional `expires_in`; the page then treats the code as gone when it passes and sends a new one, at most once per method per visit (a manual send counts too), and `challenge-form` gains `expired` ("The code we sent to … has expired"). **Server side still to do:** add `expires_in` (seconds until the usable code expires, `null` without one) to the per-factor challenge state next to `code_sent`/`retry_after`, plus `docs/json-mode.md` and `JsonContractTest`; until then expiry is inert. `MfaEnableNudge` gains `disabled` (impersonation: × and "Not today" hide it for the page view only, no `onDismiss`), documented in integration step 6.
  - Releases through a pull request (the maintainer protected `main`: pull request required, no force push or deletion; tags aren't covered): `make release` now opens a `chore: release vX.Y.Z` pull request from `release/vX.Y.Z` (based on `origin/main`, `make ci` run on it), and the new `make release-tag` tags the merged release commit (found by subject, so merge, squash and rebase all work) with its changelog section and pushes only the tag. It refuses a new release while the last one is untagged or a release pull request is open. `update-changelog.py` gained `--rev` (dry runs read `origin/main` without switching branches) and skips squashed release commits (`chore: release vX.Y.Z (#N)`). Tested end to end against a scratch bare remote with `gh`/`make` stubbed, for all three merge methods.
  - Host form styles (maintainer report from artistly, which uses `@tailwindcss/forms`): the challenge's invisible code input had no `type` and no border/padding/ring reset, so the plugin drew a rectangle around the six boxes. The overlay input now has `type="text"` and resets border, padding, shadow, ring and focus styles; every other text field (setup dialog, password prompt, inline setups, factor cards, add-factor form, recovery code) has a `type` and sets its own border, radius, padding, colours, placeholder and neutral focus ring (`focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10`, as the code boxes). Regression guard `tests/js/host-styles.test.tsx` scans every `<input>`/`<button>` in the stubs and renders the overlay. `make preview` gains a "Host forms plugin" toggle (`?forms=1`, Tailwind CDN `?plugins=forms`). Also (coordinator request): error and hint text without a dark variant (`text-red-600`, `text-gray-500` in factor-list, factor-cards, destination-setup, factor-setup-dialog, add-factor-form, totp-setup, password-confirm-form) gained `dark:text-red-400` / `dark:text-gray-400`; the same test fails on any colour class string without a `dark:` class (backdrop `bg-gray-950/50` allowed; api-key-notice and settings-card excluded until their branch merges). Docs: integration.md step 6 "Host styles".
  - Account settings card redesign (maintainer's pick, direction A of three shown in the design canvas; user report: artistly wrapped the card in its own profile-section card, so it showed a box in a box): `settings-card` is now a complete section in light and dark (`rounded-2xl` surface, `text-lg sm:text-xl` heading with the On / Off / Required badge, the text, then Manage with a shield icon as a secondary button or Set up as the primary one), so it needs no wrapper. `className` now replaces the surface rather than adding to it (Tailwind can't reliably override by appending); upgrade note in integration.md. `api-key-notice` had no dark-mode colours: it now uses the settings page's amber note style. Tests first; checked in `make preview` at phone and desktop, light and dark.
  - Direct releases again (the maintainer removed the rulesets on `main`): `make release` is back to the original flow (on an up-to-date `main`: `make ci`, changelog commit, annotated tag, `git push --atomic` of both after confirming). The pull-request flow stays as `make release-pr` plus `make release-tag` for a protected `main`; both refuse while a pull-request release is untagged. Tested end to end against a scratch bare remote (direct, off-main and behind guards, PR then tag).
  - Settings card `className` adds again (user report from artistly: `className="max-w-xl"` left the card with no surface at all, since v0.4.1 made `className` replace it). The maintainer chose extending over replacing: overrides use Tailwind's important modifier. Test first; docs and the component comment updated.
  - The repository is public now: the install docs (README quickstart, integration.md step 1) use the HTTPS VCS URL with no credentials and `^0.4`, and the local symlinked path-repository instructions are gone.
  - Resend loop closed (maintainer report: with email or SMS, log in → code → verify → log out → log in sent a new code every round, bounded only by `send_per_hour`, about 240 a day; a cost/spam problem, not a bypass, since it needs the password and the inbox/phone). Three guardrails, tests first: (1) the cooldown curve spans logins: it counts every code sent for the factor in the last hour, and after a code used by a successful verification the next send waits one step lower, from that code's send (`Cooldown::after($streak - 1)`): first re-login free, then 2, 4, 8, 15 minutes; expired and burned codes still allow a send at once; a used code is told from a burned or expired one without a migration (`MfaOtpCode::wasVerified()`: fewer than `max_attempts` wrong guesses, consumed by `expires_at`); the challenge state reports `code_sent: false` with `retry_after`, and the page waits, says "You recently used a code sent to …", and sends once the wait ends (`challenge-form` `waiting`, `send-code-button` "You can get a new code in m:ss", `h:mm:ss` from an hour); the suspicious-requests warning still counts only unverified sends. (2)+(3) per-account daily caps per method, `factors.email.send_per_day` 15 and `factors.sms.send_per_day` 5 (`null`/`0` off and uncounted), login and enrollment, counted apart per type, rolled back with the other counters, new reason `daily_limit` (429) "You've had too many codes today. Try again in 5 hours, or use an authenticator app.", `FailureReason::isLimit()`. Docs: configuration.md, json-mode.md, integration.md (props and an upgrade note), CLAUDE.md; preview scenario "code used moments ago". 516 Pest and 328 Vitest tests; green on Laravel 11 lowest and type-checked in all three apps. Roadmap section added (remember-me).
  - Plan refreshed (it still described 2026-10-08): Status and Snapshot now reflect the released package (v0.4.2, public repo, green full matrix on every tag, direct releases), D2 (public, no credentials), §1.5 done, Phase 1 exit criteria met, Phase 2's layout step (persistent layouts in `resolve`) and settings-card step (own section, `className` adds), Phase 3 marked in progress with artistly's actual state, and the SMS toll-fraud risk row with today's guardrails.
  - Two fixes from the review of the resend-loop branch, tests first. (1) Lockout confirmed: with only the password, logging in and burning each code with 5 wrong guesses (a burned code allows a new one at once) used up the owner's daily SMS cap in about 5 minutes (email in 1–2 h). The daily caps now count per user, per method and per network (`SendGuard::ipBucket()`: IPv4 per address, IPv6 per /64; no client IP = one shared `no-ip` bucket per account and type, still capped); same HMAC key, atomic count, order and rollback; message and `retry_after` unchanged. Shared IPs share a budget; with trusted proxies `'*'` the IP can be spoofed and the worst case is the hourly cap (configuration.md, config comments, CLAUDE.md). (2) A superseded code read as "used to verify": `issue()` marked the code out consumed, and after a failed delivery discarded the new code, one superseded in the second it expired (the page's expiry timer) passed `wasVerified()`, so the next send waited and the page said "You recently used a code". Superseded codes now also get `expires_at` a second before `consumed_at`, so they always read as expired (no migration). Found in passing, not changed: `rate_limit.verify_per_day` (50) is per user only, so wrong guesses with the password alone can also block the owner's verification for the day. 526 Pest and 328 Vitest tests; green on Laravel 11 lowest and type-checked in all three apps.
  - Known limitations documented (user decision): docs/configuration.md gains a "Known limitations" section: the password-only lockout of code entry through `verify_per_day` (R6, with the support steps and the possible per-network fix), shared IPs sharing the daily send budget, and per-network limits trusting the client IP. The per-network verify cap is on the Roadmap.
  - Challenge and recovery-code fixes (branch `fix/challenge-qol`, from v0.5.0), tests first. (1) Pasting several recovery codes (the saved list is one per line) got Laravel's raw "must not be greater than 32 characters": `recovery-code-form` now keeps the first code, read from the clipboard before the text field drops the line breaks (a single code typed in groups stays as typed), and the server answers 422 "Enter one recovery code. Each code works once." under `code` for several codes or over 32 characters, before any attempt counts (`RecoveryCodes::looksLikeSeveral()`; json-mode.md, JsonContractTest). (2) The challenge started on the method used last, so one "Try another way" to email made email (and an auto-sent code) the default from then on: authenticator apps now come first and are the default when the challenge accepts them (enforcement's required types still apply), the rest by last use (json-mode.md, configuration.md). (3) The recovery codes download is named `{app}-recovery-codes-{account}-{YYYY-MM-DD}.txt` (browser's date; unsafe characters → `-`) and its text names the app, account and download time with the time zone: new settings prop `recoveryCodesFile { app, slug, account }` (the TOTP issuer rule, now `TotpFactor::issuerFor()`, and `getMfaLabel()`), new dialog prop `recoveryCodesFile`, `downloadName` still overrides (integration.md props and upgrade note). 535 Pest and 338 Vitest tests; green on Laravel 11 lowest and type-checked in all three apps.
- **2026-10-10**
  - Recovery-code wording (user report: a list of codes read as one long secret, so the whole list was pasted): the challenge's recovery field says "Enter one code from your saved list, like k7m2p-x1q0t. Each code works once." (a made-up example in the real format that can never be a real code: it contains `1` and `0`, which the generator never uses; a unit test reads the example from the component and checks it against `RandomCodeGenerator`'s alphabet), the setup dialog and the settings panel say each of the shown codes is a separate code, and the downloaded file says "Each line below is one code." Tests first.
  - artistly audit fixes (§1.7; uncommitted on `claude/quizzical-wright-00a388` for review). Tests first for each; every regression test was checked to fail without its fix. (1) Enrollment verification before a first factor: `Support\EnrollmentVerification`, `RequireEnrollmentVerification`, `EnrollmentVerificationController`, `mfa:enrollment-link`, `Mfa::enrollmentLink()`, `InteractsWithMfa::withEnrollmentVerified()`, `SendGuard::attemptAccount()` (the account-cap counting is shared with `attempt()`), `Support\DeliveryQueue` (the queued-or-inline rule, shared with `OtpFactor`). (2) `NotifyAccountOwner` + `SecurityAlertNotification`, `notifications` config. (3) 303 for blocked non-GET/HEAD. (4) Intended URL only for navigations; Fetch Metadata non-navigations get the JSON 403. (5) Sessions/remember-me documented; trusted browsers designed in the Roadmap. (6) Frontend: the dialog's "Confirm it's you" step and its page wiring, preview scenarios. Results: 607 Pest (Laravel 11.22 lowest too), 364 Vitest, coverage 98.8%, typecheck-stubs OK in all three apps. Docs: configuration.md (Enrollment verification, Security notifications, Sessions, Blocked requests, Known limitations, Switches), json-mode.md (the 423, the two endpoints, the settings props), integration.md (config table, Background requests interceptor, tests, manual checks), README. `mfa:doctor` warns when enforcement is on without enrollment verification, and about a log/array mailer when only security emails use mail.
  - Trusted browsers (§1.8), at the maintainer's request after the session-expiry discussion: server side test-first (the gate test fails without the hook), docs in configuration.md (Trusted browsers; Sessions table; Switches; Security model), json-mode.md (`remember`, `trustBrowser`, `trustedBrowsers`, the DELETE endpoints) and integration.md (config row, migrate after upgrades).
  - Trusted-browser reminder (§1.8): server side and tests (MfaContext shape, the 12-hour window, Later, forgetting, verify-early returning to the page, `?renew=1` not a bypass); copy changed from "Renew now" to "Verify now" at the maintainer's request.
  - Trusted browsers and the reminder complete: 636 Pest, 420 Vitest, `make ci` green, typecheck-stubs OK in artistly, clone-voice and podcast-flow. Everything since v0.5.2 is still uncommitted for the maintainer's review; suggested release v0.6.0.
  - Review of §1.7–1.8 (standards + spec sub-agents) and every finding fixed (§1.9), tests first.
  - Verification review (second round): earlier fixes confirmed; new small findings fixed (§1.10), tests first.
  - Settings QoL (user report from artistly/clone-voice), tests first. (1) The first method's recovery codes step vanished: the apps' Inertia asset version includes the per-viewer Ziggy route list, which grows once the session is verified, so confirming the first method came back as a full page load and lost the dialog (the flashed codes then showed in the panel). The settings page now reopens the dialog on the codes when it loads with `factor-enabled` + codes (the method confirmed last), and likewise the new-codes dialog on `recovery-codes-generated`. (2) The QR SVG is a fixed 192px but its wrapper's padding left less room, so it spilled out in clone-voice: the SVG now scales to the box (`factor-setup-dialog`, `totp-setup`). (3) No more `window.confirm()` (user picked designs from a canvas of four): Remove asks in a strip inside the method's card (the password prompt takes its place when the server asks), "Forget all" trusted browsers asks the same way (it had no confirmation), `factor-list` asks in its row, and new recovery codes run in the new `recovery-codes-dialog` (confirm, password when asked, then the codes with Copy/Download and Complete gated, like setup); `recovery-codes-panel` no longer lists codes. Breaking for published components (`confirmRemove`, `confirmRegenerate`, panel `codes`/`onRegenerate`/`passwordPrompt` removed; `onNewCodes` added); integration.md upgrade note added.
- **2026-10-10 (verification lifetime)**
  - Planned §1.11 (absolute 4h window, idle timeout, reminder and grace for enforced users) in `verification-lifetime-plan.md`; waiting for decisions.
  - Assessed the Support SSO guide (Google OIDC + Workspace group check): not for this package; a separate package that marks SSO logins MFA-verified through `Mfa::markVerified()` and reuses the lifetime profiles (`on_expiry: logout`). Maintainer: SSO comes later as its own package, built from a shared skeleton (`~/Work/template-laravel-package`, derived from this repo's tooling).
  - Decisions folded into §1.11: `on_expiry` configurable (default challenge), idle 25 minutes.
- **2026-10-11**
  - §1.11 decisions taken: grace period (not delayed revocation); new requirement that an admin MFA reset ends all of the user's sessions immediately (today `mfa:reset` leaves verified sessions running); enforced profile on by default; `reverifyReminder` rename; no trust-row clean-up. Plan ready to build.
  - Built §1.11 on `feat/verification-lifetime`: `make ci` green (693 Pest, 460 Vitest), stubs type-check in all three apps. Also fixed a flaky `OtpCooldownTest` (no `freezeSecond()`). Noted the required-types follow-up in §1.6.
  - Maintainer decisions on the first review: impersonation capped at the admin's window and logs out when it ends; Inertia reloads of the current page are background; grace kept per session; `Mfa::reset()` always logs out every session. `enforcement.required_types` is now "all of" (multi-step challenge, `ChallengeSteps`), settings restricted to required types. `make ci` green (716 Pest, 467 Vitest).
  - Second review pass: fixed "Verify now" across several steps, a remember-me cookie surviving `Mfa::reset()` (new remember token), passed steps surviving a revocation or a new login, and one step's re-pass keeping older steps alive (per-type 10-minute timestamps). Open for the maintainer: a browser trusted before a second required type was added still skips the challenge (only with `allow_enforced` and an enforced profile without window/idle). 721 Pest, 467 Vitest, Laravel 11 lowest green.
  - Full security + scalability review (two reviewers): fixed an open redirect after "Verify now" (cross-site Referer as url.intended), a passed step resetting the verify limits, a silently failing cache write keeping an old revocation readable, the admin-side guard in impersonation grants, the idle warning restarting on every re-render, two cache round trips per request (now one `many()`), exception-report floods on a cache outage (`RevocationCacheUnavailable`), session size (compact lifetime entry, no activity time without idle), a duplicate enforcement-policy call, the reminder clock never stopping, and the login listener outside requests. 728 Pest, 468 Vitest, full matrix green.
