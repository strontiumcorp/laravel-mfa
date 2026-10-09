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

**The name in authenticator apps.** `factors.totp.issuer` (`MFA_TOTP_ISSUER`, default `APP_NAME`) is what authenticator apps show for the account. Outside production the environment is added in brackets ("Acme (staging)", "Acme (local)"), so a test account never looks like the real one; set `factors.totp.issuer_environment` to `false` to turn that off. It applies to apps added from then on: an existing entry keeps the name it was added with.

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

The challenge page sends an email or SMS code by itself when it opens on that method, or when the user picks it, once per method per visit. It doesn't send when a usable code is already out (after a refresh, say): it shows "We sent a code to …" and the remaining countdown instead, from each factor's `code_sent` and `retry_after`. Opening the page (the `GET`) never sends anything, so prefetches and back/forward are safe; the send is the page's own `POST`, under the same cooldown and limits. Each method's countdown runs from when the page got it, so switching methods shows the time actually left. A send refused only because the cooldown runs (say, back/forward restored older props and the page sent again) shows the countdown, not an error; other refusals still show theirs.

SMS numbers must also match `factors.sms.allowed_calling_codes` and not `factors.sms.blocked_prefixes` (by default, premium-rate ranges behind `+1`). Also turn on your provider's geo-permissions and fraud protection.

Events to act on:
- `SuspiciousCodeRequests`: repeated login codes without a successful login, which usually means the password leaked. Notify the owner.
- `SendingCircuitTripped` (critical): an app-wide limit was hit, once per window (at most hourly) for each: `scope` is `unconfirmed` (enrollments paused) or `confirmed` (login codes paused; authenticator apps and recovery codes still work). Alert on it.

**Choosing `confirmed_global_per_hour`.** It stops SMS pumping through many accounts that each stay under the per-account cap (each account's number was confirmed once, so the enrollment limits no longer apply). It counts every email and SMS login code. The default, 1000 an hour (about 17 a minute, sustained, and twice the enrollment breaker), is far above what a login flow of a few thousand daily users sends, since a code goes out only for a new session of a user with an email or SMS method. Set it to about three times your busiest hour of login codes (the `challenge_sent` metric or audit rows); a refused send costs no quota.

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

## Security model

- TOTP secrets, phone numbers and email destinations are encrypted. Codes and recovery codes are stored only as keyed hashes (from `APP_KEY`; `APP_PREVIOUS_KEYS` keeps old ones valid after a rotation).
- Codes are single use, expire, and are burned after 5 wrong attempts. TOTP codes can't be replayed. Concurrent requests can't use a code twice.
- Verification attempts are limited per user per minute and per day; the daily cap stops slow brute force.
- The session ID changes after verification. Logging out clears verification even if the app doesn't invalidate the session.
- Each logged-in guard must pass MFA on its own.
- The gate runs before route model binding (it is placed ahead of `SubstituteBindings` in the middleware priority, after the session and auth middleware), so an unverified user gets the challenge for every URL, whether the record exists or not, and the app's binding code doesn't run for them. An app that replaces the whole priority list (`->priority([...])` in `bootstrap/app.php`, or `$middlewarePriority` in a Kernel) should list `EnsureMfaVerified` right before `SubstituteBindings` itself.
- A user who has factors but hasn't verified can't open the MFA settings, so a stolen password can't add a factor.
- Adding or removing a factor and regenerating recovery codes ask for the password again (see [Password confirmation](#password-confirmation)), so a stolen session alone can't change them (unless confirmation is off or the user is exempt).
- A pending enrollment belongs to the browser session that started it and expires after 30 minutes.
- Deleting a user deletes their factors and codes. Their audit rows stay, unlinked, until pruned.

## Performance

- A verified session costs no MFA queries. Whether a user has MFA is cached and refreshed when their factors change. The cache holds the user's factor types, and the enabled types are applied on each read, so turning a type off or on takes effect at once. Within one request the cache is read once per user (kept on the request, never on a singleton), however often the gate, the shared context and the nudge ask.
- The nudge adds nothing for users with MFA, and at most one cache read per page for users without it (none once the session knows it was dismissed).
- Safe under Octane: no request state is kept between requests.
- On multiple servers, use a shared cache and session store (Redis or database). `mfa:doctor` warns otherwise.
