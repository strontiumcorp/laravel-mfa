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

**Disabling a factor type fails open:** users whose only factors are of that type are no longer challenged. Disable a type only after they enroll another factor. `mfa:doctor` counts the users affected.

## Enforcement

```php
'enforce' => ['admin', 'support'],            // roles, from $user->getMfaRoles()
'enforce' => \App\Mfa\MyPolicy::class,        // or a Contracts\EnforcementPolicy
```

`getMfaRoles()` reads the `role` attribute. Override it for other role systems, e.g. spatie/laravel-permission: `return $this->getRoleNames()->all();`.

For a rule in code, `Mfa::enforceUsing(fn ($user) => $user->isAdmin())` in a service provider takes precedence over the config. (Closures can't go in the config file: `config:cache` can't store them.)

## Sending limits

Two separate budgets, so the protection against message bombing can't be used to lock an owner out:

| Sending to | Limits (defaults) |
|---|---|
| A confirmed destination (login codes) | Per-factor cooldown of 2 → 4 → 8 → 15 minutes, reset by a successful login or an hour of quiet. 10 sends per account per hour. No per-IP limit, so shared networks are fine. |
| A new destination (enrollment) | 2 messages per destination per day across all accounts. 3 new destinations per account per day. 10 new destinations per IP per hour (IPv6 grouped per /64). 500 per hour app-wide, then sending pauses. |

A refused request doesn't use up any quota. Responses include `retry_after`, and the pages show a countdown.

SMS numbers must also match `factors.sms.allowed_calling_codes` and not `factors.sms.blocked_prefixes` (by default, premium-rate ranges behind `+1`). Also turn on your provider's geo-permissions and fraud protection.

Events to act on:
- `SuspiciousCodeRequests`: repeated login codes without a successful login, which usually means the password leaked. Notify the owner.
- `SendingCircuitTripped` (critical): the app-wide limit was hit. Alert on it.

## SMS providers

`twilio`, `vonage`, `infobip` and `sns` call the providers' HTTP APIs directly, with no SDKs. Each attempt has a 3s connect and 5s total timeout (set `connect_timeout` / `timeout` on a driver). A request is retried only if it never reached the provider, so a retry can't send a duplicate.

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
- **Audit table** `mfa_audit_logs`: kept for `observability.audit.retention_days` (90).
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
- A user who has factors but hasn't verified can't open the MFA settings, so a stolen password can't add a factor.
- A pending enrollment belongs to the browser session that started it and expires after 30 minutes.
- Deleting a user deletes their factors and codes. Their audit rows stay, unlinked, until pruned.

## Performance

- A verified session costs no MFA queries. Whether a user has MFA is cached and refreshed when their factors change.
- Safe under Octane: no request state is kept between requests.
- On multiple servers, use a shared cache and session store (Redis or database). `mfa:doctor` warns otherwise.
