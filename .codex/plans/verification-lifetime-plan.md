# Plan — verification lifetime for enforced users (absolute window, idle timeout, reminder, grace)

> **Status:** `[~]` built 2026-10-11 on branch `feat/verification-lifetime` (uncommitted), in review. §7 (`mfa.fresh` step-up) not built: `lifetime.no_grace` covers sensitive routes for now. Trusted browsers are now on by default (maintainer, 2026-10-11).
> **Parent plan:** [mfa-integration-plan.md](mfa-integration-plan.md) §1.11.

## Requirements (as received 2026-10-10)

1. Normal users: trusted browsers stay as they are (30 days).
2. Enforced users: **no** "trust this browser". Instead one MFA verification lasts a fixed window:
   - **absolute** 4 hours (configurable), **not sliding**: activity never extends it;
   - an **idle** timeout of 20–30 minutes (configurable);
   - a **reminder** 30 minutes before the window ends (configurable).
3. When the window ends, end the verification **within a 10-minute window, without breaking what the user is doing** (a form being submitted, a request in flight). Configurable, and switchable off per app.
4. All of it per app through config, no app code beyond publishing the stubs.
5. (added 2026-10-11) When an administrator resets a user's MFA, **every** session of that user loses its verification **immediately**, not after a delay (a delay would be a security gap). Applies to all users, not only enforced ones.

(3) means a **grace period** after the user's own deadline (maintainer, 2026-10-11): only "new task" requests (page navigations) are challenged, while writes and background requests still pass; at the end of the grace everything is challenged. It never applies to a revocation (5).

## 1. Model: lifetime profiles

One verification gets a **profile** at the moment it succeeds (`markVerified()`, `grantForImpersonation()`, a trusted-browser pass). The profile is copied into the session, so the gate keeps costing **zero database queries** on verified requests (plus the one cache read for revocation, §6).

```php
// config/mfa.php (new block; deep-merged, so old app configs get it)
'lifetime' => [
    // Picks the profile for a user, at verification time only. null = built-in rule:
    // "enforced" for users an enforcement rule applies to (Mfa::isEnforced()), else "default".
    // A class implementing Contracts\LifetimePolicy (profile(user): string) for other splits,
    // e.g. support staff 4h, admins 8h.
    'policy' => null,

    'profiles' => [
        'default' => [
            'absolute' => null,  // minutes; null = until the Laravel session ends (today's behaviour)
            'idle' => null,      // minutes without activity; null = off
            'reminder' => null,  // minutes before `absolute` ends; null = no reminder
            'grace' => null,     // minutes after `absolute` in which only navigations are challenged; null/0 = off
            'on_expiry' => 'challenge', // or 'logout' (end the login too)
        ],
        'enforced' => [
            'absolute' => env('MFA_ENFORCED_LIFETIME', 240),
            'idle' => env('MFA_ENFORCED_IDLE', 25), // maintainer decision: 25 by default
            'reminder' => 30,
            'grace' => env('MFA_ENFORCED_GRACE', 10),
            // What ends when the window runs out: 'challenge' = re-enter an MFA code, stay
            // logged in; 'logout' = end the login too (maintainer decision 2026-10-10:
            // challenge now, logout must stay one config change away).
            'on_expiry' => env('MFA_ENFORCED_ON_EXPIRY', 'challenge'),
        ],
    ],
],
```

- New contract `Contracts\LifetimePolicy` (class named in config, resolved per call, like `enforcement.policy`; no closures in config, nothing held on the `Mfa` singleton).
- Unknown profile name → `default`, and `mfa:doctor` fails on it.
- The `enforced` profile above ships **active** (Decision 2), because that is the requested behaviour and enforcement is opt-in anyway. Called out as a breaking change in the release notes (`feat!`), with "set `lifetime.profiles.enforced.absolute` to null for the old behaviour".

## 2. Session state (per guard and user, like `mfa.verified.*`)

| Key | Value | Written |
|---|---|---|
| `mfa.verified.{guard}.{id}` | verified-at timestamp (exists today) | on verification |
| `mfa.lifetime.{guard}.{id}` | `{profile, until, idle, grace, remind_at, on_expiry}` (seconds/timestamps) | on verification |
| `mfa.seen.{guard}.{id}` | last activity timestamp | on every request that counts as activity (Laravel saves the session every request anyway; a throttle would make the idle timeout up to a minute early) |

**Invariant: expiry is computed from timestamps on every request, never stored as a flag.** Forgetting the keys is clean-up only, so a concurrent request that writes an old copy of the session back can't resurrect an expired verification.

## 3. Gate algorithm (`EnsureMfaVerified`, verified branch)

For every verified session identity, whatever its profile:

0. verified before the user's revocation stamp (§6) → **expired (revoked)**, at once, no grace.

Then, only when it has a `mfa.lifetime` entry (no entry → today's behaviour, no extra work):

1. `now >= until + grace` → **expired (absolute)**.
2. `idle` set and `now >= seen + idle` → **expired (idle)** (no grace: the idle warning in §5 covers it).
3. `until <= now < until + grace` → **grace**: let through requests that don't start a new task (see below); challenge the rest.
4. Otherwise pass; record activity if this request counts (below).

On expiry: forget `mfa.verified/lifetime/seen` for that identity, emit `VerificationExpired` (`reason: absolute|idle|revoked`, profile, minutes verified), then either deny through the existing `deny()` path (`on_expiry: challenge`: JSON 403 `mfa_required`, Inertia/HTML redirect to the challenge with `url.intended`) or log that guard out and invalidate the session (`on_expiry: logout`, for apps whose staff have no MFA factor to re-enter, e.g. SSO).

**What counts as a new task** (challenged during grace) — one predicate, unit-tested:
- a top-level GET navigation (`isPageNavigation()` already exists), or
- an Inertia GET visit that is not a partial reload (`X-Inertia` without `X-Inertia-Partial-Data`).

Everything else passes in grace: POST/PUT/PATCH/DELETE (form submits, uploads), fetch/XHR, Inertia partial reloads and polls. Routes listed in `middleware.sensitive` (new, default `[]`) and routes using the new `mfa.fresh` middleware (§7) never get grace.

A request already running when the deadline passes is never touched: expiry is only checked at the gate.

**What counts as activity** (refreshes `seen`): navigations, Inertia visits and any non-GET request. Not activity: GET fetch/XHR and Inertia partial reloads (background polling must not keep an unattended screen alive), plus routes in `lifetime.idle_ignore` (new, default `[]`).

## 4. Trusted browsers vs the lifetime

A trusted-browser cookie would silently re-verify the moment the 4 hours end, which defeats the absolute window. Rule: **a user whose profile has `absolute` set is never offered trust and is never verified by a trust cookie** (`TrustedBrowsers::offeredTo()` and `attempt()` ask the lifetime policy; one policy call, only when an unverified user presents a cookie). No clean-up of existing rows: trusted browsers haven't reached production (Decision 6), and `attempt()` refusing them is enough for any local/staging rows, which expire and are pruned as usual.

`trusted_browsers.allow_enforced` keeps working for enforced users whose profile has no `absolute`. artistly sets it back to `false` (it was a workaround for the 2-hour re-challenge, which this replaces).

## 5. Reminder, early renewal, idle warning (frontend)

**Context** (`Support\MfaContext` ↔ `mfa-context.ts` ↔ `MfaContextTest`): new key `verification`, null unless the session identity has a lifetime:

```ts
verification: {
  profile: string;
  expiresAt: string;        // ISO, absolute deadline (without grace)
  remindAt: string | null;  // ISO
  idleSeconds: number | null;
  graceUntil: string | null;
  renewUrl: string;         // /mfa/challenge?renew=1
  keepAliveUrl: string | null;
  stateUrl: string;         // GET, not counted as activity
} | null
```

- **Reminder**: generalise the trust reminder into one `reverifyReminder` (breaking rename of `trustReminder`, pre-1.0, `reason: 'trust' | 'lifetime'`), with copy under `lifetime.reminder.*`, e.g. "Your secure session ends :when. Verify now so it doesn't interrupt you." The card is shown **by a client timer** at `remindAt`, so it appears on a page that was opened before the reminder window (today it only appears on the next request).
- **Verify now**: `/mfa/challenge?renew=1` accepts a lifetime session too (today only a trusted-browser one). A successful renewal is a fresh verification, so the 4-hour window restarts from now; it never extends the old one.
- **Idle warning**: new component `idle-warning` (props-only, in `components/`, icons via `icons.tsx`). Two minutes before the idle deadline it asks "Still there?" with **Stay signed in** (POST `keepAliveUrl`, counts as activity, can't move the absolute deadline) and **Sign out**. Before showing it, the tab asks `stateUrl` for the real deadline, because another tab may have been active (plus a `storage`/`BroadcastChannel` event between tabs to avoid the request in most cases).
- **After expiry with the page open**: the next navigation lands on the challenge and returns to `url.intended`; in grace, a pending form submit still goes through.
- Preview scenarios: reminder due, idle warning, grace, expired. `make typecheck-stubs` for all three apps.

## 6. Immediate revocation (requirement 5)

Today `mfa:reset` deletes the factors but every already-verified session of that user stays verified until it ends (the gate never looks at the factors once a session is verified). Other sessions can't be edited (file/database/redis session stores are keyed by session id, not user), so the check has to happen in the gate.

- **Stamp:** new table `mfa_revocations` (`user_id` primary key, cascades with the user, `revoked_at`). Cache-aside like "has MFA": the gate reads the cache key (HMAC via `CacheKey`); on a miss it queries the table once and fills the cache with `add()` (a stale read can't win), "none" included. Written to the table first, then the cache (write-through), so a `cache:clear` never brings a revoked session back.
- **Gate:** for each verified identity, verified-at (`mfa.verified.*` timestamp, whole seconds) `<=` stamp → expired with reason `revoked`, regardless of profile or grace. Revoking at second T also ends a session verified in second T; the admin's own fresh verification afterwards is in T+1 or later (test with a frozen clock).
- **Who revokes:** new public `Mfa::reset($user, ?string $by = null)` (factors, recovery codes, trusted browsers, cached state, **and** the stamp; `FactorDisabled` events as today, plus `VerificationsRevoked`), used by `mfa:reset` and by apps' admin panels; lower-level `Mfa::revokeVerifications($user)` for apps that end access for other reasons (role removed, account suspended).
- **Cost:** one cache read per verified request per identity, no DB query once the cache is warm. This changes the CLAUDE.md invariant from "a verified session costs zero MFA queries" to "zero MFA database queries, one cache read"; update it.
- **Failure:** cache **and** table unreachable → fail closed (challenge), reported. `mfa:doctor` fails when the cache store is `array` outside tests and warns on `file` (not shared between servers).
- **Not revoked:** the user removing a factor themselves in settings (their own action, other sessions keep running; unchanged), logout.

## 7. Step-up for sensitive routes (optional, small)

Middleware alias `mfa.fresh:{minutes}` (like `password.confirm`): passes only if this session verified within N minutes, else redirects to `?renew=1` (Inertia) / JSON 423 `mfa_fresh_required` + `renew_url`. Gives apps a cheap way to protect bulk exports and similar without shortening everyone's window. Never gets grace.

## 8. Events, logs, audit

- New `Events\VerificationExpired` (`reason`, `profile`, `verified_for` seconds) and `Events\VerificationRenewed`; `VerificationSucceeded` gains `profile` and `expires_at` in context.
- `MfaActivity` fan-out unchanged (log, audit table, metrics). No codes, secrets or destinations, as always.
- `mfa:status` shows the profile a user would get; `mfa:doctor`:
  - fails: unknown profile name, `LifetimePolicy` class missing / not implementing the contract, `grace` without `absolute`, `reminder >= absolute`;
  - warns: `session.lifetime` (minutes) shorter than a profile's `idle` (Laravel's session ends first, so the idle setting does nothing) or shorter than `absolute` with `expire_on_close=false` and no remember-me (users are challenged earlier than the profile says); `on_expiry: logout` while a remember-me guard would log them straight back in.

## 9. JSON mode

- 403 `mfa_required` gains `reason: "expired" | "idle" | "revoked"` when it comes from §3 (docs/json-mode.md ↔ `JsonContractTest`).
- `GET mfa/session` → `{ profile, expires_at, remind_at, idle_seconds, grace_until }`; `POST mfa/session/keep-alive` → 204.

## 10. Tests (Pest; write each failing first)

`freezeSecond()`/`travelTo()` everywhere a deadline is asserted.
- default profile: no lifetime keys, no change in queries (`DB::enableQueryLog()` = 0 on a verified request, as today).
- enforced: passes at 3:59:59, challenged at 4:00:00 on a navigation, POST passes in grace, everything challenged at 4:10:00; JSON 403 with `reason`.
- not sliding: requests every minute for 4 hours don't move the deadline.
- idle: 24:59 passes, 25:00 challenged; background GET fetch and Inertia partial reloads don't refresh activity; keep-alive does; activity write throttled to once a minute.
- renewal restarts the window from now and never extends a past deadline; `?renew=1` for a lifetime session.
- trusted browsers: never offered or honoured for a profile with `absolute`; still 30 days for default users; old rows handled.
- `sensitive` routes and `mfa.fresh` get no grace.
- revocation: `Mfa::reset()` / `mfa:reset` ends **another** session's verification on its very next request (two sessions, one reset), for default and enforced users, during grace too; survives `Cache::flush()` (table fallback); cache + table down → challenged; a fresh verification after the reset passes; zero DB queries on a verified request with a warm cache.
- `on_expiry: logout`.
- impersonation: `grantForImpersonation()` gives the **target's** profile; an impersonator's expiry ends the impersonation's verification too (decide: probably yes).
- multi-guard: each guard has its own window.
- concurrent stale session write can't resurrect an expired verification.
- Octane: nothing per-request on singletons (existing Octane tests extended).
- Vitest: reminder timer, idle warning (cross-tab), challenge renew mode copy, context types.
- Matrix: `make test-matrix` (Carbon 2 `diffInSeconds` truncation: compute from timestamps).

## 11. Docs

configuration.md (new "Verification lifetime" section, replacing the "re-challenges" advice for enforced users), integration.md (mount `idle-warning` in the layout; props table), json-mode.md, README feature line, CHANGELOG via release script, plan Phase 2 checklist item ("decide lifetime profiles"), `mfa:install` output hint.

## 12. artistly rollout (Phase 3)

1. Bump to the new minor; `mfa:install --force` the stubs (or republish only `mfa-context.ts`, the nudge and the new `idle-warning`).
2. `config/mfa.php`: `trusted_browsers.allow_enforced => false`; `lifetime.profiles.enforced` as above; `.env`: `MFA_TRUSTED_BROWSERS=true` (normal users, 30 days), optionally `MFA_ENFORCED_*`.
3. Mount the idle warning in the authenticated layout next to the nudge.
4. `SESSION_LIFETIME=120` is fine (longer than idle 25).
5. QA at all three widths in `make preview` and in the app: 4h deadline (shorten to 5 min locally), grace with an open form, idle, reminder, renew.

## Effort

Package: ~3 days with tests and docs (§1–6, 8–11). §7 step-up +0.5 day (optional). artistly: half a day.

## Decisions

All taken by the maintainer:

1. `[x]` "Revoke within 10 minutes" means the **grace period** (§3), 10 minutes by default, `grace => null` turns it off (2026-10-11). Delayed revocation of *other* sessions was rejected as a security gap: an administrator's MFA reset must end every session **immediately** (§6).
2. `[x]` The `enforced` profile ships **active by default** (`feat!`, opt-out in the release notes) (2026-10-11).
3. `[x]` On expiry: a config value per profile, `on_expiry` (`challenge` | `logout`, env `MFA_ENFORCED_ON_EXPIRY`), default **challenge** (2026-10-10). `logout` must be fully implemented and tested now, so switching later is a config change only.
4. `[x]` Idle timeout: **25 minutes** by default (2026-10-10).
5. `[x]` Rename `trustReminder` → `reverifyReminder` (breaking for published stubs, upgrade note in integration.md) (2026-10-11).
6. `[x]` Old trusted-browser rows of enforced users: nothing to clean up, trusted browsers haven't reached production (2026-10-11).

## As built (2026-10-11) — differences from the design above

- `middleware.sensitive` became `lifetime.no_grace` (plus `lifetime.idle_ignore`). §7 `mfa.fresh` not built. No `VerificationRenewed` event: a renewal is `VerificationSucceeded` with the new `profile`/`expires_at`.
- JSON `reason` values are `absolute` | `idle` | `revoked`; `on_expiry: logout` answers `401 mfa_session_ended`.
- Trusted browsers are refused for any profile with a window **or** an idle timeout (`VerificationLifetime::hasWindow()`), not only `absolute` (review finding: a trust cookie would undo an idle timeout).
- Revocation: plain cache key `{prefix}:revocations:{id}` (value `revoked:logged_out`) (like `factor-types`), table `mfa_revocations`. Trusted browsers are forgotten before the stamp is written. A cache that can neither store nor drop the stamp throws. A revoked session also loses `auth.password_confirmed_at` and `mfa.pending`. `revokeVerifications(..., byAdministrator: false)` for non-admin causes.
- Doctor fails on an `array` cache store outside tests; grace/reminder without `absolute` and `reminder >= absolute` only warn. No remember-me + `logout` warning (Laravel's logout already forgets the recaller).
- `verification` and `GET mfa/session` carry the server's `now`; `useMfaIdleWarning()` moves deadlines onto the browser's clock.
- `REMINDER_DISMISSED` moved to `Mfa` (`mfa.reminder_dismissed`).
- Review decisions (maintainer, 2026-10-11): impersonation is capped at the admin's own window/idle, ends when the admin is revoked or reset, is refused while the admin is in grace, and **logs out** when it ends; Inertia reloads of the current page (`router.reload()`, `usePoll()` without `only`; Referer path = request path) are background, not activity and not a new task; the first page visit in grace ends the verification for every tab (kept); **`Mfa::reset()` always logs the user out of every session** (`mfa_revocations.logged_out_at` vs. the session's `mfa.login_at`, from Laravel's `Login` event; `revokeVerifications(..., logout: true)` for the same without removing factors).
- Documented, not changed: grace is header-controlled (bounded by `grace`; use `grace => null` / `no_grace` where it matters); `on_expiry: logout` invalidates the whole session (other guards too); a revocation in the user's own request also revokes a re-verification in the same second.
- Accepted (maintainer, 2026-10-11): a browser trusted while an enforced user held fewer required types still skips the challenge after they add more. Only possible with `trusted_browsers.allow_enforced` and an enforced profile without window or idle; with the defaults enforced users never get trusted browsers.
- `enforcement.required_types` is "all of" for enforced users only; everyone else passes with any one enabled type.

