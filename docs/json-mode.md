# JSON mode

By default the MFA routes render Inertia pages. Set the `json` UI driver to drive the same flow from any frontend: a Vue/Svelte SPA, Blade with fetch, or a mobile web view.

```dotenv
MFA_UI_DRIVER=json
```

Even with the `inertia` driver, any request that sends `Accept: application/json` (and not `X-Inertia`) gets JSON. You can therefore mix the two modes: Inertia pages for the browser, JSON for a widget.

Every response shape below is pinned by `tests/Feature/JsonContractTest.php`. If you change a response, update that test and this page together.

## Before you start

- **This is still session authentication.** Send the session cookie and the CSRF token, exactly as for any other `web` route. With axios and Laravel's default `XSRF-TOKEN` cookie this happens automatically. It is not a token API; for stateless API clients, issue tokens only after MFA.
- All routes live under `config('mfa.routes.prefix')`, which defaults to `/mfa`. They require an authenticated session.
- IDs in URLs and `factor_id` values are integer factor IDs from the `factors` / `pending` arrays. Non-integer values get a `422`.
- The routes are throttled per client (`routes.throttle`, default 60/min). Exceeding that returns Laravel's standard `429`, separate from the per-user code limits below.

## Detecting that MFA is needed

Any protected `web` route that receives a JSON request (or a script's `fetch()`, which browsers mark with `Sec-Fetch-Mode: cors`, `same-origin` or `no-cors`) from a user who hasn't verified yet returns:

```http
HTTP/1.1 403 Forbidden

{ "message": "Multi-factor authentication required.", "error": "mfa_required", "redirect": "https://app.test/mfa/challenge" }
```

When an earlier verification of this session has just ended, the body also has `"reason"`: `"absolute"` (the [lifetime](configuration.md#verification-lifetime) window and its grace ran out), `"idle"` (no activity for the profile's `idle` minutes) or `"revoked"` (an administrator's `Mfa::reset()` / `mfa:reset`, or `Mfa::revokeVerifications()`). Only the request that ends it carries it; the next ones are plain `mfa_required`.

A profile with `on_expiry: "logout"` logs the user out instead:

```http
HTTP/1.1 401 Unauthorized

{ "message": "Your session has ended. Please sign in again.", "error": "mfa_session_ended", "reason": "absolute", "redirect": "https://app.test/login" }
```

Users who are required to enroll get `"error": "mfa_enrollment_required"`, with `redirect` pointing at `/mfa/settings`: enforced users with no factor, and enforced users who verified but still lack a required type (`enforcement.required_types`). The challenge lists, and accepts, only an enforced user's required types once they have one, and asks for each of them (see `steps` below).

A global response interceptor is the simplest way to handle both:

```js
axios.interceptors.response.use(null, (error) => {
    const data = error.response?.data;
    if (error.response?.status === 403 && ['mfa_required', 'mfa_enrollment_required'].includes(data?.error)) {
        window.location.href = data.redirect; // or open your MFA modal
    }
    return Promise.reject(error);
});
```

For `fetch()`, see the wrapper in [integration step 6](integration.md#background-requests). Page navigations still get a redirect (`302`, or `303` after a blocked `POST`/`PUT`/`PATCH`/`DELETE`, so the browser follows it with a `GET`), and only they set the page to return to after verifying; [configuration.md](configuration.md#blocked-requests) has the full table.

## Errors

| Status | When | Body |
|---|---|---|
| `422` | Wrong, expired or reused code; invalid destination; unknown factor | `{ "message": "...", "errors": { "code": ["..."] } }`. Enrollment errors use the `destination` or `type` key instead of `code`. |
| `429` | Rate limited; resend during the cooldown; too many codes to one destination today (`destination_limit`); the account's daily cap for that method from this network (`daily_limit`, `factors.{type}.send_per_day`; authenticator apps and recovery codes still work) | Same shape, plus `"retry_after": <seconds>` when known |
| `503` | An app-wide send cap was hit (likely an attack): new destinations (`unconfirmed_global_per_hour`), or login codes (`confirmed_global_per_hour`; authenticator apps and recovery codes still work) | Same shape, plus `"retry_after": <seconds>` until sending resumes |
| `403` | Not verified yet (see above), or settings opened while a challenge is pending | `{ "error": "mfa_required", ... }`, plus `reason` when a verification just ended |
| `401` | The verification ended and its profile logs out (`on_expiry: "logout"`), an impersonation ended, or an administrator's reset logged the user out (`reason: "revoked"`) | `{ "error": "mfa_session_ended", "reason": "...", "redirect": "…/login" }` |
| `423` | A factor change needs the password first (see [Password confirmation](#password-confirmation)) | `{ "message": "...", "error": "password_confirmation_required", "confirm_url": "…/mfa/confirm-password" }` |
| `423` | A first factor needs proof of ownership first (see [Enrollment verification](#enrollment-verification)) | `{ "message": "...", "error": "enrollment_verification_required", "email": "j***@example.com", "send_url": "…", "verify_url": "…" }` |

The messages are written for end users and are safe to display as-is. For the machine-readable reason, check the audit log or the `VerificationFailed` event, not the message text.

## Challenge (during login)

### `GET /mfa/challenge`

```json
{
    "factors": [
        { "id": 7, "type": "sms", "type_label": "SMS", "label": "SMS", "destination": "+*******0100",
          "confirmed": true, "confirmed_at": "2026-10-08T10:00:00+00:00", "last_used_at": null,
          "code_sent": true, "retry_after": 90, "expires_in": 510, "code_length": 6 }
    ],
    "defaultFactorId": 7,
    "hasRecoveryCodes": true,
    "trustBrowser": null,
    "renew": false,
    "steps": null,
    "urls": { "send": "…/mfa/challenge/send", "verify": "…/mfa/challenge", "recover": "…/mfa/challenge/recover", "logout": "…/logout" },
    "status": null,
    "recoveryCodes": null,
    "retryAfter": null
}
```

- `factors` lists authenticator apps first, then the rest by most recently used, and `defaultFactorId` is the first of them: a user with an authenticator app always starts on it (nothing is sent), even after signing in with email last time. Destinations are always masked.
- `code_sent` is `true` while an email/SMS factor has a usable code out (sent, not expired, not used or burned). `retry_after` is the whole seconds until a resend is allowed, or `null` when it is allowed now. Both survive a refresh, so use them to restore the countdown, and to skip sending a new code when one is already out. `code_sent: false` with a `retry_after` means a code was just used to verify and the next one has to wait (the cooldown spans logins): show the countdown and send when it ends, rather than sending at once (that send would get `429`). `expires_in` is the whole seconds until that code expires, or `null` when none is out: once it runs out, treat the code as gone (a resend is allowed then). TOTP factors always have `false`, `null` and `null`. Opening the page never sends a code: call `POST /mfa/challenge/send` yourself.
- `code_length` is how many digits the factor's codes have: always `6` for TOTP, `factors.{type}.length` for email and SMS.
- `steps` is set when an enforced user must pass several required types (`enforcement.required_types`, every one they hold): `{ "total": 2, "passed": ["totp"] }`. `factors` then lists only the types still to pass. `null` means any one factor passes.
- `urls.logout` is `null` when `config('mfa.routes.logout_route')` doesn't name an existing route.
- If the session is already verified, or the user has no factors, the endpoint redirects to the intended page instead.

### `POST /mfa/challenge/send`: email/SMS factors only

```json
{ "factor_id": 7 }
```
→ `{ "status": "code-sent", "retry_after": 120 }`.

- `retry_after` is the number of seconds until the next resend unlocks. The cooldown grows 2 → 4 → 8 → 15 minutes over the codes sent in the last hour, and a successful verification doesn't reset it (the next login's code waits one step lower, from the used code's send). It is never longer than the code's lifetime, so use it to drive a countdown.
- A resend during the cooldown gets `429` with the remaining `retry_after`, which also never runs past the current code's expiry (a resend is allowed once it has expired), so it always matches the challenge page's `retry_after`.
- TOTP factors return `{ "status": "code-sent" }`, and nothing is sent.

### `POST /mfa/challenge`

```json
{ "factor_id": 7, "code": "482913", "remember": true }
```
→ `{ "status": "verified", "redirect": "https://app.test/dashboard" }`

With `steps`, every code but the last answers `{ "status": "factor-verified", "remaining": ["email"] }` (`200`, the session is not verified yet): fetch `GET /mfa/challenge` again and verify the next type. A passed step counts for 10 minutes. Inertia and form posts are redirected back to the challenge with that status flashed.

`remember` (optional) trusts this browser: the next sessions of this user on it skip the challenge for `trusted_browsers.days` (see [configuration.md](configuration.md#trusted-browsers)). It is ignored unless `GET /mfa/challenge` returned `trustBrowser` (`{ "days": 30 }`; `null` when it isn't offered). `GET /mfa/challenge?renew=1` from a verified session that has a fixed [lifetime](configuration.md#verification-lifetime) window, or runs on a trusted browser, returns the challenge with `"renew": true` instead of redirecting (the shared context's `reverifyReminder.verifyUrl`). Verifying then starts a new window (with `remember: true`, also trusts the browser again), and `redirect` is the page the user came from. `renew` is `false` otherwise. `POST /mfa/reminder/dismiss` hides the reminder until the next verification → `{ "status": "reminder-dismissed" }`. The response sets the trusted-browser cookie, so keep the cookies. `POST /mfa/challenge/recover` never trusts the browser.

The session ID is regenerated on success, so take the new cookie from the response. (Inertia requests get a full page visit to the intended page instead: `409` with `X-Inertia-Location`.) `redirect` is the page the user originally asked for, or `config('mfa.routes.home')` if there was none.

### `POST /mfa/challenge/recover`

```json
{ "code": "ab3de-fg7hk" }
```
→ `{ "status": "verified-with-recovery-code", "redirect": "…", "remaining": 9 }`

Codes are matched case-insensitively, ignoring spaces and dashes. Send one code: input holding several (e.g. lines pasted from the saved list) or longer than 32 characters gets `422` with "Enter one recovery code. Each code works once." under `code`, and uses up no attempt. When `remaining` gets low, prompt the user to regenerate their codes.

## Settings (managing factors)

These routes are reachable when the session is verified, or when the user has no factors yet (first enrollment).

### Password confirmation

Adding or removing a factor and regenerating recovery codes need a recently confirmed password (`config('mfa.routes.password_confirmation')`, on by default; see [configuration.md](configuration.md#password-confirmation)). Until then they answer:

```http
HTTP/1.1 423 Locked

{ "message": "Please confirm your password to continue.", "error": "password_confirmation_required", "confirm_url": "https://app.test/mfa/confirm-password" }
```

Ask for the password, post it to `confirm_url`, then retry the same request. A confirmation lasts `auth.password_timeout` (3 hours in Laravel's default config). Users with an empty password, and users exempted by `routes.password_confirmation_policy`, never get the `423`.

If the app sets `routes.confirm_middleware` to `['password.confirm']`, Laravel's middleware runs first and answers `423 { "message": "Password confirmation required." }` without `error`; send the user to the app's confirm-password page, then retry.

### `POST /mfa/confirm-password`

```json
{ "password": "…" }
```
→ `{ "status": "password-confirmed" }`

- A wrong password gets `422` on `password`: `{ "message": "The provided password is incorrect.", "errors": { "password": ["…"] } }`.
- Attempts are limited per account (5 per minute, 20 per day); over the limit gets `429` on `password` with `retry_after`, even for the right password.
- The URL is also in the settings response, as `urls.confirmPassword`. After an Inertia request is refused by the limit, the settings page's `passwordRetryAfter` holds the wait instead of `retryAfter`, which stays for code sends.

### Enrollment verification

Before an account's first factor is added, an enforced user (or every user, with `enrollment_verification.required_for` set to `everyone`) proves they own the account beyond the password: with a code emailed to the account's address, or an administrator's one-time link (see [configuration.md](configuration.md#enrollment-verification)). It comes after the password confirmation. Until then `POST /mfa/factors` and `POST /mfa/factors/{id}/confirm` answer:

```http
HTTP/1.1 423 Locked

{
    "message": "Enter the code we email you to confirm it is you.",
    "error": "enrollment_verification_required",
    "email": "j***@example.com",
    "send_url": "https://app.test/mfa/enrollment-verification/send",
    "verify_url": "https://app.test/mfa/enrollment-verification"
}
```

When email codes can't be used (`enrollment_verification.email` is `false`, or the account has no email address), `email`, `send_url` and `verify_url` are `null` and the message is "Ask an administrator for a setup link to add your first sign-in method.": the user needs a link from `php artisan mfa:enrollment-link` or `Mfa::enrollmentLink()`, opened while signed in. The link is a page visit that redirects to `/mfa/settings`.

`GET /mfa/settings` says the same in advance: `enrollmentVerification` is `{ "email": "j***@example.com" }` (or `{ "email": null }`) while it is needed, `null` otherwise.

#### `POST /mfa/enrollment-verification/send`

No body. → `{ "status": "enrollment-code-sent", "retry_after": 120, "email": "j***@example.com" }`

- The code goes to the account's email, works only in this session, and expires after `factors.email.ttl`.
- Resending waits like a login code (`429` with `retry_after` during the cooldown) and counts toward the account's send caps (`429` `daily_limit`/`rate_limited`, `503` when an app-wide cap is hit).
- `422` on `code` when only a link works, or when the email couldn't be sent.
- When no proof is needed (any more), it sends nothing: `{ "status": "enrollment-verified" }`.

#### `POST /mfa/enrollment-verification`

```json
{ "code": "123456" }
```
→ `{ "status": "enrollment-verified" }`. Then retry the factor request.

- A wrong code gets `422` on `code`; it is burned after `factors.email.max_attempts` wrong guesses (send a new one). Attempts count toward the verify rate limits (`429` with `retry_after`).
- The proof lasts until logout.

### `GET /mfa/settings`

```json
{
    "factors": [ /* confirmed factors, same shape as above */ ],
    "pending": [ /* unconfirmed factors from the last 30 minutes, same shape plus setup fields (below) */ ],
    "availableTypes": [ { "type": "totp", "label": "Authenticator app", "recommended": true }, { "type": "email", "label": "Email", "recommended": false } ],
    "recoveryCodesRemaining": 10,
    "recoveryCodesTotal": 10,
    "recoveryCodesFile": { "app": "Acme (staging)", "slug": "acme-staging", "account": "jane@example.com" },
    "mustEnroll": false,
    "requiredTypes": [],
    "passwordConfirmationRequired": false,
    "passwordRetryAfter": null,
    "nudge": { "title": "Protect your account", "body": "Turn on two-factor sign-in now. It takes a minute and will soon be required." },
    "urls": { "store": "…/mfa/factors", "confirm": "…/mfa/factors/__ID__/confirm", "resend": "…/mfa/factors/__ID__/resend",
              "destroy": "…/mfa/factors/__ID__", "recoveryCodes": "…/mfa/recovery-codes", "confirmPassword": "…/mfa/confirm-password",
              "sendEnrollmentCode": "…/mfa/enrollment-verification/send", "verifyEnrollmentCode": "…/mfa/enrollment-verification",
              "forgetTrustedBrowser": "…/mfa/trusted-browsers/__ID__", "forgetTrustedBrowsers": "…/mfa/trusted-browsers" },
    "enrollmentVerification": null,
    "trustedBrowsers": null,
    "status": null,
    "recoveryCodes": null,
    "retryAfter": null
}
```

`passwordConfirmationRequired` says whether adding or removing a method would answer `423` right now (see [Password confirmation](#password-confirmation)), so a client can ask for the password before starting; the routes still enforce it. `enrollmentVerification` does the same for the proof of ownership before a first factor (see [Enrollment verification](#enrollment-verification)). `recoveryCodesTotal` is how many a fresh set has (`recovery_codes.count`), for an "8 of 10 left" display. `recoveryCodesFile` is what a downloaded codes file is named after: `app` is the name authenticator apps show (`factors.totp.issuer`, with the environment in brackets outside production unless `factors.totp.issuer_environment` is off), `slug` the same for a file name, and `account` the user's authenticator label (`getMfaLabel()`: the email, else the auth identifier). The bundled dialog names the file `{slug}-recovery-codes-{account}-{YYYY-MM-DD}.txt` with the browser's date. `availableTypes` lists the recommended types first (`factors.{type}.recommended`, default `totp`). For an enforced user, `requiredTypes` lists what they must set up (`enforcement.required_types`, e.g. `[{ "type": "totp", "label": "Authenticator app" }]`); `mustEnroll` stays true until they have every one, and `availableTypes` lists only those (`POST /mfa/factors` refuses other types with `422` on `type`). It's `[]` for other users.

`nudge` holds the [nudge](configuration.md#nudge)'s title and body, to show as a notice, for a user with no method who isn't enforced (and while `nudge.enabled` is on); it's `null` otherwise.

Replace `__ID__` in the URLs with a factor ID.

A pending TOTP factor also includes `secret`, `otpauth_url` and `qr_svg`, so you can show the QR code again after a page reload. The secret is never kept in the session.

Pending factors are bound to the browser session that started the enrollment, and expire after 30 minutes:
- Another session on the same account sees an empty `pending` list.
- Another session gets `422` ("not available") from `confirm` and `resend`.
- If the user switches device mid-enrollment, they simply start again.

### `POST /mfa/factors`: start enrollment

| `type` | Body | `setup` in the response |
|---|---|---|
| `totp` | `{ "type": "totp" }` | `{ "secret", "otpauth_url", "qr_svg" }` |
| `email` | `{ "type": "email", "destination": "optional@example.com" }` (defaults to the account email) | `{ "destination": "o***@example.com", "sent": true, "reason": null, "retry_after": 120 }` |
| `sms` | `{ "type": "sms", "destination": "+1 555 555 0142" }` (E.164 with country code) | `{ "destination": "+*******0142", "sent": true, "reason": null, "retry_after": 120 }` |

Response: `{ "status": "enrollment-started", "factor": { … }, "setup": { … } }`. Email/SMS enrollments also include a top-level `retry_after`.

- Render `qr_svg` as markup. It's server-generated, not user input.
- If `sent` is `false`, `reason` is a failure code such as `delivery_failed`.
- When a send limit refuses the message, no pending factor is created, and the response is `429`/`503` on `destination`.
- Starting a new enrollment of the same type replaces any earlier unconfirmed factor of that type.

### `POST /mfa/factors/{id}/confirm`

```json
{ "code": "123456" }
```
→ `{ "status": "factor-enabled", "recovery_codes": ["ab3de-fg7hk", …] }`

- `recovery_codes` appears only when this is the user's first factor (or they had no codes left). Show the codes once; they can't be retrieved again.
- Confirming also marks the current session as verified.

### `POST /mfa/factors/{id}/resend`

Resends the code for a pending email/SMS factor. Returns `{ "status": "code-sent", "retry_after": … }`.

Unconfirmed destinations have tight limits, because anyone can trigger them:
- at most 2 messages per destination per day across all accounts (`429`, "use an authenticator app")
- 3 new destinations per account per day
- 10 distinct new destinations per IP per hour

### `DELETE /mfa/factors/{id}`

→ `{ "status": "factor-disabled" }`. Removing the last factor also deletes the user's recovery codes. IDs belonging to other users are ignored.

### `DELETE /mfa/trusted-browsers/{id}` and `DELETE /mfa/trusted-browsers`

Stop trusting one browser, or all of the user's browsers. → `{ "status": "trusted-browsers-forgotten" }`. The URLs are in the settings response as `urls.forgetTrustedBrowser` (with `__ID__`) and `urls.forgetTrustedBrowsers`. An ID that isn't the user's changes nothing.

`GET /mfa/settings` lists them as `trustedBrowsers` (`null` when the feature is off), newest first:

```json
[ { "id": 3, "label": "Chrome on Mac", "created_at": "2026-10-10T08:30:00+00:00", "last_used_at": null, "expires_at": "2026-11-09T08:30:00+00:00", "current": true } ]
```

`current` marks the browser making the request; `label` is `null` when the user agent was missing.

### `POST /mfa/recovery-codes`

→ `{ "status": "recovery-codes-generated", "recovery_codes": [ … ] }`. This invalidates every previous code.

If the user has no confirmed factor yet, the response is `422 { "message": "Enable a verification method first." }`, without an `errors` key.

## Verification lifetime

For pages that show the idle warning (see [configuration.md](configuration.md#verification-lifetime)). Both run behind the gate: once the verification has ended they answer `403` like any other request.

### `GET /mfa/session`

The verified session's deadlines as they are now (another tab may have been active). It never counts as activity.

```json
{ "verification": { "profile": "enforced", "now": "2026-10-11T12:00:00+00:00", "expiresAt": "2026-10-11T16:00:00+00:00", "remindAt": "2026-10-11T15:30:00+00:00",
  "graceUntil": "2026-10-11T16:10:00+00:00", "idleSeconds": 1500, "idleExpiresAt": "2026-10-11T12:25:00+00:00" } }
```

`verification` is `null` for a session whose profile has no window and no idle timeout. Times are ISO 8601; `null` fields are off. `now` is the server's clock, to correct for a client clock that is off.

### `POST /mfa/session/keep-alive`

"Stay signed in": counts as activity for the idle timeout → `204 No Content`. It never moves the absolute window.

## Nudge

### `POST /mfa/nudge/dismiss`

"Not today" on the [nudge](configuration.md#nudge) to turn two-factor on. The only input is the browser's timezone (optional; `Intl.DateTimeFormat().resolvedOptions().timeZone`):

```json
{ "timezone": "Asia/Dhaka" }
```
→ `{ "status": "nudge-dismissed", "until": "2026-10-10T18:00:00+00:00" }`

- `until` is the user's next local midnight, in the app timezone, at most 26 hours away. A missing or unknown timezone counts as `app.timezone`; nothing else in the request is read.
- It applies to the user on every device, until `until`. Whether to show the nudge is in the shared context (`Mfa::context()`, `nudge.show`).
- Sent again while it is already hidden (a double click, another tab or device), it changes nothing and fires no event: the answer is the same `200` with the existing `until`.
- Inertia and plain form posts get a `303` back to the page they came from, with no status flash.
- Like the other MFA routes, it needs a logged-in session that has passed the challenge (a user without methods isn't challenged).
