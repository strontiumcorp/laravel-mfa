# Configuration reference

Everything is in `config/mfa.php`, with comments. This page covers the parts that need more than a comment. For setting up an app, see [integration.md](integration.md).

## Switches

| `.env` | Default | |
|---|---|---|
| `MFA_ENABLED` | `true` | Kill switch. `false` lets every request through and removes the MFA routes. |
| `MFA_TOTP_ENABLED`, `MFA_EMAIL_ENABLED`, `MFA_SMS_ENABLED` | on, on, off | Factor types users can enroll. |
| `MFA_SMS_DRIVER` | `log` | `twilio`, `vonage`, `infobip`, `sns`, `failover`, `routing`, or a custom driver. |
| `MFA_DELIVERY_QUEUE`, `MFA_DELIVERY_QUEUE_CONNECTION` | empty | Either one queues code delivery. Both empty sends inline. |
| `MFA_UI_DRIVER` | `inertia` | `json` for other frontends; see [json-mode.md](json-mode.md). |
| `MFA_LOG_CHANNEL`, `MFA_LOG_LEVEL` | default channel, `info` | Where MFA events are logged, and the lowest level logged. |
| `MFA_NUDGE_ENABLED` | `true` | The "turn on two-factor" nudge for users without a method; see [Nudge](#nudge). |
| `MFA_ENROLLMENT_VERIFICATION` | `enforced` | Who must prove ownership (an email code or an admin link) before their first factor: `enforced`, `everyone` or `null`; see [Enrollment verification](#enrollment-verification). |
| `MFA_TRUSTED_BROWSERS` | `false` | Offer "Don't ask again on this browser for N days" on the challenge; see [Trusted browsers](#trusted-browsers). |
| `MFA_NOTIFICATIONS_ENABLED` | `true` | Security emails to the account owner; see [Security notifications](#security-notifications). |

**The name in authenticator apps.** `factors.totp.issuer` (`MFA_TOTP_ISSUER`, default `APP_NAME`) is what authenticator apps show for the account. Outside production the environment is added in brackets ("Acme (staging)", "Acme (local)"), so a test account never looks like the real one; set `factors.totp.issuer_environment` to `false` to turn that off. It applies to apps added from then on: an existing entry keeps the name it was added with. The downloaded recovery codes file is named after the same name (`acme-staging-recovery-codes-…`).

**Recommended types.** `factors.{type}.recommended` in `config/mfa.php` (default: `totp` only) lists that type first with a "Recommended" badge when users add a method. It's a hint only; nothing is enforced.

**Disabling a factor type fails open:** users whose only factors are of that type are no longer challenged. Disable a type only after they enroll another factor. `mfa:doctor` counts the users affected.

## Enforcement

```php
'enforcement' => [
    'roles' => ['admin', 'support'],          // from $user->getMfaRoles()
    'policy' => \App\Mfa\MyPolicy::class,     // a Contracts\EnforcementPolicy (optional)
    'required_types' => ['totp'],             // what enforced users must use; [] = any
],
```

A user is enforced when their role is listed **or** the policy says so. With neither, MFA is opt-in. `getMfaRoles()` reads the `role` attribute. Override it for other role systems, e.g. spatie/laravel-permission: `return $this->getRoleNames()->all();`.

For a rule in code, write a policy class and leave `roles` empty, so it decides alone. It is resolved from the container on every check, so it can inject anything, the current `Request` included: under Octane it comes from the container of the request being handled, never the one the app booted with. For example:

```php
class EnforceForStaff implements \StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy
{
    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        return $user->isAdmin() || $user->team?->requires_mfa;
    }
}
```

`Policies\EnforceForAdmins` (users whose `isAdmin()` is true) ships as an example.

**Required types.** Enforced users must have a factor of a type in `required_types` (default: an authenticator app). Their other factors don't count:

| Enforced user has | At the challenge | After passing it |
|---|---|---|
| No factor | (not challenged) | Held on the settings page until they add a required type |
| Only other types (e.g. email) | Verifies with what they have | Held on the settings page until they add a required type |
| A required type (and maybe others) | Offered only the required types, plus recovery codes | Through |

The server enforces this, not just the pages: a non-required factor is refused at `send` and `verify` (`FactorNotFound`). Removing their last required factor sends the user back to enroll. Users who aren't enforced can use any enabled type. Types that are disabled are ignored; if none of `required_types` is enabled, any factor satisfies enforcement and `mfa:doctor` warns.

**Upgrading from v0.2:** `Mfa::enforceUsing()` is removed. Move its closure into an `enforcement.policy` class as above, with `roles` empty if the closure decided alone.

**Upgrading from v0.1:** the top-level `'enforce'` key is now `enforcement.roles` (a list) or `enforcement.policy` (a class). The old key is still honoured when neither is set, and `mfa:doctor` warns until you move it.

## Password confirmation

Adding or removing a factor and regenerating recovery codes ask for the account password first, at most once per `auth.password_timeout`, Laravel's own setting (3 hours in Laravel's `config/auth.php`). MFA reads it as is, with no default of its own, and `mfa:doctor` fails if it isn't set. The MFA settings page asks itself: it shows a password prompt, then retries the change. The app needs no confirm-password page. Confirming a pending enrollment, resending its code and viewing the page never ask.

```php
'routes' => [
    'password_confirmation' => true,             // MFA's own prompt; false = never ask
    'password_confirmation_policy' => null,      // a Contracts\PasswordConfirmationPolicy class; null = everyone with a password
    'confirm_middleware' => [],                  // extra middleware, e.g. ['password.confirm'] for the app's own page
],
```

Who is asked:

| Setting | Users asked |
|---|---|
| `password_confirmation => true` (default) | Everyone with a password, except users the `password_confirmation_policy` exempts. Users whose stored password is empty are never asked. |
| `password_confirmation => false` | Nobody. Passing MFA in the session is enough to change factors. |
| `confirm_middleware => ['password.confirm']` | Runs before MFA's own check, so the app's confirm page asks first. Both use the session key `auth.password_confirmed_at`, so whichever confirmed the password satisfies the other. |

**Users without a password** (social login). Users whose stored password is empty (a nullable `password` column) are never asked. If social-login users have a password they don't know (e.g. a random one set at sign-up), exempt them with a policy class:

```php
class AskPasswordUsers implements \StrontiumCorp\LaravelMfa\Contracts\PasswordConfirmationPolicy
{
    public function mustConfirmPassword(MultiFactorAuthenticatable $user): bool
    {
        return $user->google_id === null;
    }
}

// config/mfa.php
'password_confirmation_policy' => \App\Mfa\AskPasswordUsers::class,
```

Like the enforcement policy, it is resolved from the current request's container on every check, so it may inject the `Request` (Octane included). Or set `password_confirmation` to `false` to ask nobody. `mfa:doctor` checks that the policy class implements the contract, and warns when Socialite is installed with no policy, and when confirmation is off entirely.

The password is checked by the session guard's user provider, as `Auth::validate()` would. Attempts are limited per account (`rate_limit.password_per_minute`, 5, and `rate_limit.password_per_day`, 20), counted before checking and cleared on success. While locked, the prompt counts down to the next allowed attempt.

Events, which reach the log, the audit table and metrics like every MFA event (the password itself is never logged):
- `PasswordConfirmationRequired`: a factor change was refused until the password is confirmed (context: `path`).
- `PasswordConfirmed`: the password was right.
- `PasswordConfirmationFailed`: the password was wrong (`invalid_password`), or the account is over its attempt limit (`rate_limited`).

A request with no password, or one over 1000 characters, gets a validation error before any of this: it isn't counted and fires no event.

`Mfa::grantForImpersonation()` drops any password confirmation in the session, because it was the impersonator's: an admin impersonating a user can't add or remove that user's factors without the user's password.

Logging out drops it too, even when the app's logout keeps the session (no `session()->invalidate()`, like artistly's admin logout), so the next login in that browser confirms its own password. It is Laravel's own `auth.password_confirmed_at` key, so this also resets the app's `password.confirm` for that session.

In host-app tests, `$this->actingAsMfaVerified($user)->withConfirmedPassword()` (from `InteractsWithMfa`) skips the prompt.

**Upgrading from v0.2:** `confirm_middleware` now defaults to `[]`, and the new `password_confirmation` (default `true`) asks on the MFA settings page instead.
- A published config that still has `'confirm_middleware' => ['password.confirm']` keeps sending users to the app's confirm page, and works as before. Set it to `[]` to use MFA's prompt.
- A published config with `'confirm_middleware' => []` to turn confirmation **off** (social login) now asks for the password. Add `'password_confirmation' => false`, or exempt password-less users with `password_confirmation_policy`.

## Enrollment verification

Before an account's **first** factor is added, the session must prove it owns the account beyond the password. Without this, someone holding only a leaked password could sign in as a user who must enroll (`enforcement.roles` / `.policy`), add their own authenticator app on the settings page, and own the account: the real owner would then be challenged for a factor they don't have. The password prompt doesn't stop them, because they have the password.

```php
'enrollment_verification' => [
    'required_for' => env('MFA_ENROLLMENT_VERIFICATION', 'enforced'), // 'enforced' | 'everyone' | null
    'email' => true,          // accept a code emailed to the account's address; false = admin links only
    'link_ttl' => 1440,       // minutes an administrator's link stays valid
    'notification' => \StrontiumCorp\LaravelMfa\Notifications\EnrollmentCodeNotification::class,
],
```

| `required_for` | Who proves ownership before their first factor |
|---|---|
| `'enforced'` (default) | Users an enforcement rule applies to. They can reach nothing but the MFA settings page until they enroll, which is exactly what a password-only attacker would use. |
| `'everyone'` | Every user adding a first factor. Also stops someone with the password from locking an opt-in owner out by adding a factor the owner doesn't have. Costs every user one email code on first setup. |
| `null` / `false` | Nobody (v0.5 behaviour). `mfa:doctor` warns when enforcement is configured. |

Users who already have a factor never need it: they passed MFA to reach the settings page. It is asked after the password confirmation, and only on the routes that add a factor (`POST mfa/factors`, and again at `POST mfa/factors/{id}/confirm`).

**Two proofs are accepted:**

1. **An email code** (`email => true`): the settings page says "we'll email a code to j\*\*\*@example.com" and the user enters it. The code goes to `getMfaEmail()` (the account's `email` by default), is bound to the browser session that asked for it, reuses the email factor's settings (`factors.email.length`, `ttl`, `max_attempts`, `resend_cooldown`), counts toward the verify rate limits, and toward the account's send caps (`rate_limit.send_per_hour`, `factors.email.send_per_day`, `rate_limit.confirmed_global_per_hour`), so it can't be used to flood the owner's inbox. The email also warns the owner: "Someone signed in to your account and is setting up two-factor sign-in… If this was not you, change your password now." So a password-only attempt is noticed even if nobody acts on the code. It is queued on the delivery queue (encrypted) when one is set, sent inline otherwise.
2. **An administrator's link**, for users without an email address, or for everyone when the mailbox can't be trusted (`email => false`):

   ```bash
   php artisan mfa:enrollment-link jane@example.com --minutes=60
   ```

   or `Mfa::enrollmentLink($user, minutes: 60)` from your own admin screen. The link is signed, works once, only in that user's own signed-in session (a signed-out user is sent to log in first), and stops working when their password changes or after `link_ttl` minutes. Verify the person out of band and send it on a channel you trust: with the password, it is enough to set up two-factor. Issuing one fires `EnrollmentLinkIssued`.

**If the email account is the thing that's compromised** (same password reused for the mailbox, say), an email code proves nothing: whoever has both can enroll. The owner still gets the code email and the "method added" notification (see [Security notifications](#security-notifications)), but in the same inbox. For roles where that matters, set `email => false` so only an administrator's link works, and treat that link like a password reset.

The proof lasts for the session (logging out clears it). `EnrollmentVerificationRequired` (context `path`), `EnrollmentVerificationSent`, `EnrollmentVerified` (context `method`: `email` or `link`) and `EnrollmentLinkIssued` reach the log, the audit table and metrics; wrong codes and refused links are `VerificationFailed` with `stage` `enrollment_verification` / `enrollment_link` (reason `invalid_link` for links, with `problem`: `other_account`, `revoked` or `used`). `EnrollmentLinkIssued` carries `via` (`app` from `Mfa::enrollmentLink()`, `console:mfa:enrollment-link` from the command, which also sets `by_administrator`) and emails the owner.

In host-app tests, `$this->loginWithSession($user)->withEnrollmentVerified($user)` (from `InteractsWithMfa`) skips it.

**Upgrading from v0.5:** republish the settings page and `factor-setup-dialog` ([integration step 6](integration.md#6-frontend)); an older page can't show the new step. This is on by default for enforced users. An enforced user who hasn't enrolled yet now gets one email code before adding their first method. Users with a factor see no change. If your app's tests enroll an enforced user, add `withEnrollmentVerified($user)` (or fake notifications and enter the emailed code). To keep the old behaviour, set `MFA_ENROLLMENT_VERIFICATION=null`.

## Nudge

Users who aren't required to use MFA can be asked to turn it on: a small floating card in the app's layout (`MfaEnableNudge`, [integration step 6](integration.md#6-frontend)), and the same title and body as a notice on the MFA settings page.

```php
'nudge' => [
    'enabled' => env('MFA_NUDGE_ENABLED', true),
    'title' => 'Protect your account',
    'body' => 'Turn on two-factor sign-in now. It takes a minute and will soon be required.',
    'button' => 'Turn on',
    'dismiss_label' => 'Not today',
],
```

- **Who sees it.** A logged-in user (on an MFA guard) with no confirmed method who isn't enforced, while MFA and its routes are on. Enforced users never see it: they are sent to enroll anyway. The shared context says `nudge.show = false` on MFA's own pages (routes named `mfa.*`), so the card hides itself there.
- **"Not today"** (or ×) hides it until the user's next local midnight. The browser sends its timezone (an unknown or missing one means `app.timezone`); the server works out that midnight, never taking a time from the client, and stores it as an instant, at most 26 hours away. A midnight that summer time skips becomes the day's first real minute.
- **Per user, not per browser.** The dismissal is kept in the cache (`cache.store`, a keyed hash of the user id, expiring at that instant), so it holds on every device and after signing in again. The session keeps a copy, so a page view reads nothing once it knows; otherwise a user without MFA costs one cache read per page. Users with MFA cost nothing extra.
- **Copy.** Plain strings, safe with `config:cache`. Each one goes through `__()`, so a `lang/{locale}.json` entry with the English text as its key translates it.
- **Event.** `NudgeDismissed`, with `until` (ISO 8601, app timezone), reaches the log, the audit table and metrics like every MFA event. Only a dismissal that hides it fires: a repeat while it is hidden (another tab or device, a double click) keeps the existing time and records nothing.

## Sending limits

Two separate budgets, so the protection against message bombing can't be used to lock an owner out:

| Sending to | Limits (defaults) |
|---|---|
| A confirmed destination (login codes) | Per-factor cooldown of 2 → 4 → 8 → 15 minutes over the codes sent in the last hour, across logins (see below). 10 sends per account per hour. 15 email and 5 SMS codes per account per network per day (see below). 1000 per hour app-wide (`rate_limit.confirmed_global_per_hour`; `null` or `0` turns it off), then login codes pause for everyone until the hour's window frees up. No per-IP limit, so shared networks are fine. |
| A new destination (enrollment) | 2 messages per destination per day across all accounts. 3 new destinations per account per day. 10 new destinations per IP per hour (IPv6 grouped per /64). 500 per hour app-wide, then sending pauses. These sends count toward the per-account hourly and daily caps too. |

A refused request doesn't use up any quota. Responses include `retry_after`, and the pages show a countdown. When an app-wide cap pauses sending, the message says when to try again: "Too many codes are being sent right now. Try again in 18 minutes, or use an authenticator app." (minutes rounded up).

**The cooldown spans logins.** A successful verification doesn't reset the curve, so someone who can read the inbox or phone can't log in, verify, log out and log in again to get a code every time (each costs you an SMS). The curve counts every code sent for the method in the last hour. While a code is out, a resend waits the curve's step. Once a code was used to verify, the next one (the next login) waits one step lower, measured from that code's send: with the defaults, the first re-login gets its code at once, the next waits 2 minutes from the previous send, then 4, 8, and 15 at most. An hour without sends starts over. A code that expired, or was burned by wrong guesses, still allows a new one at once. The challenge page gets `code_sent: false` with the remaining `retry_after` then, says "You recently used a code sent to …", counts down, and sends by itself when the wait ends.

**Daily caps per method and network.** `factors.email.send_per_day` (15) and `factors.sms.send_per_day` (5) cap the codes one account gets for that method from one network in 24 hours: login and enrollment, every address or number, counted apart for email and SMS. The window opens with the first send, like the hourly cap (the RateLimiter's fixed window). They bound what someone with the password and the inbox or phone can cost you by logging in again and again (the curve alone still allows one every 15 minutes, about 100 a day). `null` or `0` turns a cap off, and then nothing is counted. When one is reached the send gets `429` with reason `daily_limit` and the wait: "You've had too many codes today. Try again in 5 hours, or use an authenticator app." (hours rounded up; minutes below an hour). The challenge still offers an authenticator app and recovery codes, so the owner isn't locked out.

The daily caps count per user, per method and per network: the client IP, with IPv6 grouped per /64 like the per-IP enrollment limits. So someone with only the password can't use them up for the owner: logging in from elsewhere and burning each code with wrong guesses (which allows a new code at once) spends that network's budget, not the owner's. People behind one shared IP (an office, a carrier NAT) share a budget for their account. A send with no client IP (a challenge started outside an HTTP request) counts in one shared bucket per account and method, so it is still capped. This makes correct trusted proxies matter (see below): in an app that trusts `'*'` a client can spoof its IP and get a fresh daily budget for each one, and the worst case is then the per-account hourly cap (`rate_limit.send_per_hour`, 10) along the cooldown curve.

Like the hourly cap, it raises `SuspiciousCodeRequests` (`send_cap_reached`) once for a confirmed method, and writes one audit row per window.

The per-IP limits and the per-network daily caps are only as good as the client IP Laravel sees, so configure trusted proxies correctly. Behind a load balancer or CDN, trust only that layer (its addresses, or Cloudflare's published ranges), not `'*'`: trusting every proxy lets a client set its own `X-Forwarded-For` and pick a fresh IP for each request. `mfa:doctor` warns about both no trusted proxies and `'*'`.

The challenge opens on the user's authenticator app when they have one (it is one of the methods the challenge accepts), otherwise on the method they used last; "Try another way" lists the rest. The page sends an email or SMS code by itself when it opens on that method, or when the user picks it, once per method per visit. It doesn't send when a usable code is already out (after a refresh, say): it shows "We sent a code to …" and the remaining countdown instead, from each factor's `code_sent` and `retry_after`. Opening the page (the `GET`) never sends anything, so prefetches and back/forward are safe; the send is the page's own `POST`, under the same cooldown and limits. Each method's countdown runs from when the page got it, so switching methods shows the time actually left. A send refused only because the cooldown runs (say, back/forward restored older props and the page sent again) shows the countdown, not an error; other refusals still show theirs.

SMS numbers must also match `factors.sms.allowed_calling_codes` and not `factors.sms.blocked_prefixes` (by default, premium-rate ranges behind `+1`). Also turn on your provider's geo-permissions and fraud protection.

Events to act on:
- `SuspiciousCodeRequests`: repeated login codes without a successful login, which usually means the password leaked. The owner is emailed (see [Security notifications](#security-notifications)).
- `SendingCircuitTripped` (critical): an app-wide limit was hit, once per window (at most hourly) for each: `scope` is `unconfirmed` (enrollments paused) or `confirmed` (login codes paused; authenticator apps and recovery codes still work). Alert on it.

**Choosing `confirmed_global_per_hour`.** It stops SMS pumping through many accounts that each stay under the per-account cap (each account's number was confirmed once, so the enrollment limits no longer apply). It counts every email and SMS login code. The default, 1000 an hour (about 17 a minute, sustained, and twice the enrollment breaker), is far above what a login flow of a few thousand daily users sends, since a code goes out only for a new session of a user with an email or SMS method. Set it to about three times your busiest hour of login codes (the `challenge_sent` metric or audit rows); a refused send costs no quota.

## Security notifications

The account owner is emailed (at `getMfaEmail()`) when their two-factor setup changes, or when it looks like someone else has their password, so they notice an attack they didn't make:

| Event | Email |
|---|---|
| `FactorEnabled` | "A sign-in method was added to your account" |
| `FactorDisabled` | "A sign-in method was removed", or "An administrator removed …" after `mfa:reset` |
| `RecoveryCodesGenerated` | "New recovery codes … your previous ones no longer work" (not the first set, which comes with the first method) |
| `RecoveryCodeUsed` | "Someone signed in with one of your recovery codes", with how many are left |
| `SuspiciousCodeRequests` | "Someone signed in with your password and keeps asking for sign-in codes" (at most one an hour, however many methods or caps raise it) |
| `EnrollmentLinkIssued` | "A two-factor setup link was created for your account" ([enrollment verification](#enrollment-verification); "An administrator created…" from `mfa:enrollment-link`) |

Each says what happened, when (in `app.timezone`) and from which IP address, and never contains a code, a secret or the destination. They are on by default:

```php
'notifications' => [
    'enabled' => env('MFA_NOTIFICATIONS_ENABLED', true),
    'events' => [
        'factor_enabled' => true,
        'factor_disabled' => true,
        'recovery_codes_generated' => true,
        'recovery_code_used' => true,
        'suspicious_code_requests' => true,
        'enrollment_link_issued' => true,
    ],
    'notification' => \StrontiumCorp\LaravelMfa\Notifications\SecurityAlertNotification::class,
],
```

They go out like codes: queued on the delivery queue (`delivery.queue` / `delivery.queue_connection`) when one is set, inline otherwise, so they never wait on a queue nobody works. A failing send is reported and never blocks the request. To change the email, point `notification` at your own class: it receives the event's name (the keys above) and an array of details (`factor`, the method's label; `ip`; `occurred_at`, ISO 8601; `by_administrator`; `remaining`, for `recovery_code_used`). Extend `SecurityAlertNotification` to keep the queueing, or implement `ShouldQueue` yourself; a connection or queue your class sets itself is kept. To send through another channel as well, listen to the events directly.

Users without an email address get none. Changes made while impersonating are reported to the impersonated user, like any other.

**Upgrading from v0.5:** owners now get these emails. Check that the mailer works in each environment (`mfa:doctor` warns about the `log` and `array` mailers in production), and turn off any you don't want. If you already notify on `SuspiciousCodeRequests` yourself, set `suspicious_code_requests` to `false` to avoid sending two.

## SMS providers

`twilio`, `vonage`, `infobip` and `sns` call the providers' HTTP APIs directly, with no SDKs. Each attempt has a 3s connect and 5s total timeout (set `connect_timeout` / `timeout` on a driver). A request is retried only if it never reached the provider, so a retry can't send a duplicate.

When the outcome is unknown (the request went out but no answer came back, e.g. a read timeout), the message may still arrive, so nothing sends it again: a `failover` chain stops there instead of trying the next provider, a queued delivery fails at once instead of using its retries (`delivery.tries`), and the code stays valid. The user is told it was sent, with the usual resend cooldown, and can ask for a new code after it if none arrives. `ChallengeDeliveryFailed` and `SmsProviderFailed` carry `maybe_delivered: true` then. A delivery that certainly failed drops its code, so the user can resend at once.

Amazon SNS works from any host and needs an IAM key limited to `sns:Publish`:

```dotenv
MFA_SMS_DRIVER=sns
AWS_SNS_KEY=AKIA...
AWS_SNS_SECRET=...
AWS_SNS_REGION=us-east-1
AWS_SNS_ORIGINATION_NUMBER=+18885550100
```

Two drivers combine others:

```php
'driver' => 'routing',
'drivers' => [
    'routing'  => ['routes' => ['880' => 'bd'], 'default' => 'failover'], // by number prefix, longest match
    'failover' => ['drivers' => ['twilio', 'sns']],                        // in order, until one accepts
    'bd'       => ['transport' => 'failover', 'drivers' => ['infobip', 'twilio']], // a named chain
    // ...plus each provider's credentials
],
```

The user sees a delivery error only when every provider in a chain failed. Each failed provider fires `SmsProviderFailed`.

A custom provider:

```php
Mfa::extendSms('acme', fn ($app, array $config) => new AcmeSmsSender($config['token']));
```

`Mfa::extend('email', ...)` similarly replaces a built-in factor's implementation. The factor types themselves (TOTP, email, SMS) are fixed.

## Observability

Every action fires an event (`StrontiumCorp\LaravelMfa\Events\*`), which goes to three places. A failure in any of them never blocks a login.

- **Log:** one line per event, with `user_id`, `factor`, `reason`, `flow_id` and `ip`. Codes, secrets, URLs, emails and phone numbers are never logged.
- **Audit table** `mfa_audit_logs`: kept for `observability.audit.retention_days` (90). A refusal by a limit (`rate_limited`, `destination_limit`, `daily_limit`, `sending_paused`) is written once per user, event, stage and scope per limit window (until its `retry_after`), so hammering a limit can't flood the table. The log and metrics still record every refusal, so alert on those for volume.
- **Metrics:** through `Contracts\MetricsRecorder`. Bind your own (Prometheus, StatsD, Pulse); `log` and `null` are built in.

All events of one login attempt share a `flow_id`, which also appears in the app's own log lines for that request.

Support commands:

```bash
php artisan mfa:status jane@example.com            # factors and recent events
php artisan mfa:status 42 --flow=3f2c...           # one login attempt
php artisan mfa:reset jane@example.com             # locked-out user; verify their identity first
```

## Trusted browsers

"Don't ask again on this browser for 30 days": an opt-in checkbox on the challenge. The user still signs in with their password (or is logged back in by remember-me); only the MFA step is skipped, on that browser, for that user, until it expires. Anyone else signing in on the same browser is still asked.

```php
'trusted_browsers' => [
    'enabled' => env('MFA_TRUSTED_BROWSERS', false),
    'days' => 30,
    'allow_enforced' => false,   // offer it to users enforcement applies to (admins)?
    'cookie' => 'mfa_trusted',   // cookie name prefix
],
```

- **What is stored.** The browser gets an http-only cookie (per guard and user, using the session cookie's path, domain, `secure` and `same_site`) holding a random token. The `mfa_trusted_browsers` table keeps only a keyed hash of it, a keyed hash of the user's password hash, a label from the user agent ("Chrome on Mac"), and when it was added, last used and expires. Rows go with the user and are pruned daily after they expire.
- **When it ends**, besides expiry: the password changes (at once: on Laravel's `PasswordReset` event and whenever the user model, exactly `Mfa::userModel()`, not a subclass, is saved with a new password; a password changed some other way stops counting on the browser's next use). Laravel's rehash on login (`hash.rehash_on_login`, on by default since Laravel 11) counts too: after you change the hashing cost, each user's trusted browsers end at their next sign-in; a sign-in method is added or removed (`mfa:reset` included); a recovery code is used (often a lost device); the user forgets it on the settings page (one browser, or all of them); or the feature is turned off. Logging out does **not** end it: that's the point.
- **Key rotation.** The cookie's name and token hashes are keyed from `APP_KEY`, with `APP_PREVIOUS_KEYS` honoured, so rotating the key with the old one listed keeps browsers trusted.
- **Never offered** after signing in with a recovery code, or to enforced users unless `allow_enforced` is on. Turning `allow_enforced` off later makes their existing trust stop counting.
- **The trade-off.** A trusted browser plus the password is enough to sign in, so stealing the cookie from that browser skips the second factor. That is why it's off by default and opt-in per sign-in. For admins, weigh a shorter `days` before turning on `allow_enforced`.
- **A reminder before it ends.** Trust is checked when a new session starts, so when it has run out, the next sign-in (say, after lunch, once remember-me logs the user back in) asks for the code again. To avoid that surprise mid-task, in the last `reminder.hours` (12) of a browser's trust the nudge card (`MfaEnableNudge`, [integration step 6](integration.md#6-frontend)) says "Two-factor check coming up … This browser will ask for your sign-in code again in 5 hours." **Verify now** opens the challenge early (`/mfa/challenge?renew=1`), with "don't ask again" already ticked, and returns to the page the user was on; the browser is then trusted for another `days`. **Later** hides it for the rest of the session. It shows only on a verified session running on that browser, never on MFA's own pages, and costs no query (the expiry is kept in the session). `reminder.hours => 0` turns it off; the copy (`reminder.title`, `body` with `:when`, `button`, `dismiss_label`) goes through `__()`. Opening `?renew=1` on a session that isn't on a trusted browser just redirects as before, so it can't be used to re-trust without the code.
- **Cost.** Nothing for verified sessions and for browsers without the cookie. A browser that sends one costs one indexed query, once, when its new session starts.
- **Events.** `BrowserTrusted` (context `trusted_browser_id`, `label`, `expires_at`); signing in on one is `VerificationSucceeded` with `via: trusted_browser`; `TrustedBrowsersForgotten` (context `count`, `cause`: `settings`, `factor_enabled`, `factor_disabled`, `recovery_code_used` or `password_changed`). `mfa:status` shows how many a user has.

**Upgrading:** it adds a migration (`mfa_trusted_browsers`); run `php artisan migrate`. Apps that published the migrations (`--tag=mfa-migrations`, or `Mfa::ignoreMigrations()`) publish the new one too. To offer it, set `MFA_TRUSTED_BROWSERS=true` and republish the challenge and settings pages with their components ([integration step 6](integration.md#6-frontend)).

## Sessions, remember-me and re-challenges

Verification is stored in the session, so it lasts exactly as long as the session does. Then the user is asked again:

| What happened | Challenged again? |
|---|---|
| A new request in the same session | No (and it costs no MFA queries). |
| Logged out, then logged in again | Yes. Logging out clears the verification even if the app keeps the session. |
| Idle longer than `session.lifetime`, logged back in by the remember-me cookie | **Yes.** Laravel restores the login from the recaller cookie into a fresh session, which has no verification in it. (Not on a [trusted browser](#trusted-browsers).) |
| Browser closed, with `session.expire_on_close` | Yes, on the next visit (remember-me or not). |

The remember-me case is deliberate: a remember-me cookie is a long-lived password substitute, so treating it as "already passed MFA" would let a stolen cookie skip the second factor. It does mean an app that forces remember-me with a short session (artistly: `SESSION_LIFETIME=120`) challenges its users after every two hours idle. Options, from least to most change:

- Accept it. For admins (who are enforced) this is a reasonable cadence; an authenticator code takes seconds.
- Raise `SESSION_LIFETIME` (e.g. to a working day). The session cookie is then the long-lived credential instead, with the same trade-off as above.
- Turn on [trusted browsers](#trusted-browsers): after a challenge the user may tick "Don't ask again on this browser for N days". For admins this also needs `allow_enforced`.

## Blocked requests

How the gate answers a request from a user who hasn't passed MFA yet (or must enroll):

| Request | Answer | Intended URL remembered? |
|---|---|---|
| A page the user opens (`Sec-Fetch-Mode: navigate` to a document; or, from browsers without Fetch Metadata, a `GET` that accepts `text/html`) | `302` to the challenge (or settings) | Yes: they land there after verifying |
| An Inertia visit | `302` for `GET`, `303` otherwise, to the challenge | No (the page they were on reloads) |
| A blocked `POST`, `PUT`, `PATCH` or `DELETE` (a stale tab, a form) | `303`, so the browser follows it with a `GET` (inertia-laravel 2.x doesn't turn the gate's 302 into a 303 itself, and a re-sent `PUT` to the challenge would be a `405`) | No |
| A JSON request (`Accept: application/json`, or axios' `X-Requested-With`), or a script's `fetch()` (`Sec-Fetch-Mode` `cors`, `same-origin` or `no-cors`) | `403` JSON `{ "error": "mfa_required" | "mfa_enrollment_required", "redirect": "…" }` | No |
| A prefetch, prerender or iframe load | `302` | No |

So a background poll in a stale tab gets a JSON error it can handle, and never replaces the page the user was going to. Apps whose own scripts call the backend should send the user to the challenge on that error: see the interceptor in [integration step 6](integration.md#6-frontend).

## Security model

- TOTP secrets, phone numbers and email destinations are encrypted. Codes and recovery codes are stored only as keyed hashes (from `APP_KEY`; `APP_PREVIOUS_KEYS` keeps old ones valid after a rotation).
- With [trusted browsers](#trusted-browsers) on, a browser the user trusted skips the challenge until it expires, the password or the methods change, or a recovery code is used; only a keyed hash of its token is stored.
- Codes are single use, expire, and are burned after 5 wrong attempts. TOTP codes can't be replayed. Concurrent requests can't use a code twice.
- Verification attempts are limited per user per minute and per day; the daily cap stops slow brute force.
- The session ID changes after verification. Logging out clears verification even if the app doesn't invalidate the session.
- Each logged-in guard must pass MFA on its own.
- The gate runs before route model binding (it is placed ahead of `SubstituteBindings` in the middleware priority, after the session and auth middleware), so an unverified user gets the challenge for every URL, whether the record exists or not, and the app's binding code doesn't run for them. An app that replaces the whole priority list (`->priority([...])` in `bootstrap/app.php`, or `$middlewarePriority` in a Kernel) should list `EnsureMfaVerified` right before `SubstituteBindings` itself.
- A user who has factors but hasn't verified can't open the MFA settings, so a stolen password can't add a factor.
- A user who must enroll (and, with `required_for => 'everyone'`, any user) proves ownership with an email code or an administrator's link before their first factor, so a stolen password alone can't enroll the attacker's authenticator (see [Enrollment verification](#enrollment-verification)).
- The owner is emailed when a method is added or removed, recovery codes are created or used, and when codes keep being requested (see [Security notifications](#security-notifications)).
- Adding or removing a factor and regenerating recovery codes ask for the password again (see [Password confirmation](#password-confirmation)), so a stolen session alone can't change them (unless confirmation is off or the user is exempt).
- A pending enrollment belongs to the browser session that started it and expires after 30 minutes.
- Deleting a user deletes their factors and codes. Their audit rows stay, unlinked, until pruned.

## Known limitations

Trade-offs we know about and have accepted for now. Each says who it affects, what to do when it happens, and what could change.

- **Someone with only the password can block code entry for a day.** Wrong codes count against `rate_limit.verify_per_day` (default 50) per user, not per network, so whoever has the password can use all 50 and the owner can't enter any code until the next day, authenticator-app codes and recovery codes included. Sending isn't affected: the daily send caps count per network, so the owner still gets codes; they just can't enter them.
  - *When it happens:* the owner sees the rate-limit message on the challenge. Verify their identity out of band, then `php artisan mfa:reset <email>` (or wait a day), and have them change their password: the attacker has it.
  - *What could change:* count the daily verify cap per user and per network, as the daily send caps already are (a per-network bucket keeps the owner's own network usable). Tracked on the roadmap.
- **A password and a mailbox stolen together can enroll.** The email code before a first factor proves inbox access, so someone who has both (a reused password, say) can still add their authenticator to an account that hasn't enrolled yet. The owner's warning emails go to that same inbox.
  - *What to do:* for roles that matter, set `enrollment_verification.email` to `false` so only an administrator's link works ([Enrollment verification](#enrollment-verification)).
- **People behind one IP share the daily send budget.** The per-method daily caps (`factors.email.send_per_day`, `factors.sms.send_per_day`) count per user and per network, so two devices of the same user on one office network or carrier-grade NAT share one budget. At 15 email / 5 SMS a day per user this rarely matters.
- **The per-network limits trust the client IP.** An app that trusts every proxy (`'*'`) can be sent a spoofed `X-Forwarded-For`, which dodges the per-IP and per-network limits; the per-account hourly cap and the app-wide caps still hold. Trust only your load balancer or CDN ranges (`mfa:doctor` warns).

## Performance

- A verified session costs no MFA queries. Whether a user has MFA is cached and refreshed when their factors change. The cache holds the user's factor types, and the enabled types are applied on each read, so turning a type off or on takes effect at once. Within one request the cache is read once per user (kept on the request, never on a singleton), however often the gate, the shared context and the nudge ask.
- The nudge adds nothing for users with MFA, and at most one cache read per page for users without it (none once the session knows it was dismissed).
- Safe under Octane: no request state is kept between requests.
- On multiple servers, use a shared cache and session store (Redis or database). `mfa:doctor` warns otherwise.
