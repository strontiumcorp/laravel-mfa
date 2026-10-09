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

Any protected `web` route that receives a JSON request from a user who hasn't verified yet returns:

```http
HTTP/1.1 403 Forbidden

{ "message": "Multi-factor authentication required.", "error": "mfa_required", "redirect": "https://app.test/mfa/challenge" }
```

Users who are required to enroll get `"error": "mfa_enrollment_required"`, with `redirect` pointing at `/mfa/settings`: enforced users with no factor, and enforced users who verified but still lack a required type (`enforcement.required_types`). The challenge lists, and accepts, only an enforced user's required types once they have one.

A global response interceptor is the simplest way to handle both:

```js
axios.interceptors.response.use(null, (error) => {
    const data = error.response?.data;
    if (error.response?.status === 403 && data?.error?.startsWith('mfa_')) {
        window.location.href = data.redirect; // or open your MFA modal
    }
    return Promise.reject(error);
});
```

## Errors

| Status | When | Body |
|---|---|---|
| `422` | Wrong, expired or reused code; invalid destination; unknown factor | `{ "message": "...", "errors": { "code": ["..."] } }`. Enrollment errors use the `destination` or `type` key instead of `code`. |
| `429` | Rate limited; resend during the cooldown; too many codes to one destination today (`destination_limit`) | Same shape, plus `"retry_after": <seconds>` when known |
| `503` | The app-wide send breaker is open (likely an attack). Logins with confirmed factors are unaffected. | Same shape |
| `403` | Not verified yet (see above), or settings opened while a challenge is pending | `{ "error": "mfa_required", ... }` |
| `423` | A factor change needs the password first (see [Password confirmation](#password-confirmation)) | `{ "message": "...", "error": "password_confirmation_required", "confirm_url": "…/mfa/confirm-password" }` |

The messages are written for end users and are safe to display as-is. For the machine-readable reason, check the audit log or the `VerificationFailed` event, not the message text.

## Challenge (during login)

### `GET /mfa/challenge`

```json
{
    "factors": [
        { "id": 7, "type": "sms", "type_label": "SMS", "label": "SMS", "destination": "+*******0100",
          "confirmed": true, "confirmed_at": "2026-10-08T10:00:00+00:00", "last_used_at": null,
          "code_sent": true, "retry_after": 90, "code_length": 6 }
    ],
    "defaultFactorId": 7,
    "hasRecoveryCodes": true,
    "urls": { "send": "…/mfa/challenge/send", "verify": "…/mfa/challenge", "recover": "…/mfa/challenge/recover", "logout": "…/logout" },
    "status": null,
    "recoveryCodes": null,
    "retryAfter": null
}
```

- `factors` is ordered by most recently used. Destinations are always masked.
- `code_sent` is `true` while an email/SMS factor has a usable code out (sent, not expired, not used or burned). `retry_after` is the whole seconds until a resend is allowed, or `null` when it is allowed now. Both survive a refresh, so use them to restore the countdown, and to skip sending a new code when one is already out. TOTP factors always have `false` and `null`. Opening the page never sends a code: call `POST /mfa/challenge/send` yourself.
- `code_length` is how many digits the factor's codes have: always `6` for TOTP, `factors.{type}.length` for email and SMS.
- `urls.logout` is `null` when `config('mfa.routes.logout_route')` doesn't name an existing route.
- If the session is already verified, or the user has no factors, the endpoint redirects to the intended page instead.

### `POST /mfa/challenge/send`: email/SMS factors only

```json
{ "factor_id": 7 }
```
→ `{ "status": "code-sent", "retry_after": 120 }`.

- `retry_after` is the number of seconds until the next resend unlocks. The cooldown grows 2 → 4 → 8 → 15 minutes, but is never longer than the code's lifetime, so use it to drive a countdown.
- A resend during the cooldown gets `429` with the remaining `retry_after`.
- TOTP factors return `{ "status": "code-sent" }`, and nothing is sent.

### `POST /mfa/challenge`

```json
{ "factor_id": 7, "code": "482913" }
```
→ `{ "status": "verified", "redirect": "https://app.test/dashboard" }`

The session ID is regenerated on success, so take the new cookie from the response. (Inertia requests get a full page visit to the intended page instead: `409` with `X-Inertia-Location`.) `redirect` is the page the user originally asked for, or `config('mfa.routes.home')` if there was none.

### `POST /mfa/challenge/recover`

```json
{ "code": "ab3de-fg7hk" }
```
→ `{ "status": "verified-with-recovery-code", "redirect": "…", "remaining": 9 }`

Codes are matched case-insensitively, ignoring spaces and dashes. When `remaining` gets low, prompt the user to regenerate their codes.

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

### `GET /mfa/settings`

```json
{
    "factors": [ /* confirmed factors, same shape as above */ ],
    "pending": [ /* unconfirmed factors from the last 30 minutes, same shape plus setup fields (below) */ ],
    "availableTypes": [ { "type": "totp", "label": "Authenticator app", "recommended": true }, { "type": "email", "label": "Email", "recommended": false } ],
    "recoveryCodesRemaining": 10,
    "recoveryCodesTotal": 10,
    "mustEnroll": false,
    "requiredTypes": [],
    "passwordConfirmationRequired": false,
    "passwordRetryAfter": null,
    "nudge": { "title": "Protect your account", "body": "Turn on two-factor sign-in now. It takes a minute and will soon be required." },
    "urls": { "store": "…/mfa/factors", "confirm": "…/mfa/factors/__ID__/confirm", "resend": "…/mfa/factors/__ID__/resend",
              "destroy": "…/mfa/factors/__ID__", "recoveryCodes": "…/mfa/recovery-codes", "confirmPassword": "…/mfa/confirm-password" },
    "status": null,
    "recoveryCodes": null,
    "retryAfter": null
}
```

`passwordConfirmationRequired` says whether adding or removing a method would answer `423` right now (see [Password confirmation](#password-confirmation)), so a client can ask for the password before starting; the routes still enforce it. `recoveryCodesTotal` is how many a fresh set has (`recovery_codes.count`), for an "8 of 10 left" display. `availableTypes` lists the recommended types first (`factors.{type}.recommended`, default `totp`). For an enforced user, `requiredTypes` lists what they must set up (`enforcement.required_types`, e.g. `[{ "type": "totp", "label": "Authenticator app" }]`); `mustEnroll` stays true until they have one. It's `[]` for other users.

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

### `POST /mfa/recovery-codes`

→ `{ "status": "recovery-codes-generated", "recovery_codes": [ … ] }`. This invalidates every previous code.

If the user has no confirmed factor yet, the response is `422 { "message": "Enable a verification method first." }`, without an `errors` key.

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
