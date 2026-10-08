# laravel-mfa

Drop-in multi-factor authentication for Laravel 11, 12 and 13: authenticator apps (TOTP), email codes and SMS codes, with recovery codes, enforcement policies, structured observability and React/Inertia pages you own.

Owned by [Strontium Corp](https://github.com/strontiumcorp). Maintained by [Mojahidul Islam](https://github.com/itsemon245).

## Installation

The package lives in a private repository. Add it to the app's `composer.json` once:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:strontiumcorp/laravel-mfa.git" }
]
```

Composer needs read access to the repo:
- **Locally:** your SSH key works.
- **CI or servers:** use a deploy key, or a GitHub token via `composer config --global github-oauth.github.com <token>`.

Then:

```bash
composer require strontiumcorp/laravel-mfa
php artisan mfa:install      # config + UI pages (detects resources/js/Pages vs pages; --js-path for other layouts)
php artisan migrate
php artisan mfa:doctor       # verifies the integration, exits non-zero on problems
```

Then add the contract and trait to your User model:

```php
use StrontiumCorp\LaravelMfa\Concerns\HasMultiFactorAuthentication;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

class User extends Authenticatable implements MultiFactorAuthenticatable
{
    use HasMultiFactorAuthentication;
}
```

Link to `route('mfa.settings')` from your account page. That's the whole integration.

MFA rows belong to one user model through `user_id` foreign keys. By default that's the model of the first MFA guard; set `mfa.user_model` to override it. The user's primary key must be a big integer (Laravel's default `$table->id()`). Deleting a user deletes their factors, codes and recovery codes in the database, even for deletes that skip model events. Their audit rows stay, with `user_id` set to null, until the retention prune, so incidents can still be investigated. Soft-deleted users keep everything until they are force-deleted.

Optionally, share MFA state with every page (see [Frontend context](#frontend-context)) and show the API key notice next to your API key settings (see [API keys](#api-keys)).

## How it protects your app

The `EnsureMfaVerified` middleware is appended to the `web` group, so **every web route is protected by default**. It checks the identity the login stored in the session, not `Auth::user()`. That means:

| Scenario | Behaviour |
|---|---|
| Password login, Socialite login, remember-me cookie | Challenged until MFA passes |
| Webhooks / jobs / commands calling `Auth::setUser()` | Never challenged (nothing is stored in the session) |
| `loginUsingId()` / `login()` in a request with no session cookie (a job, a stateless webhook) | Not challenged in that request; the login is never persisted |
| `loginUsingId()` / `login()` in a browser request | Persists a session login, so the **next** request is challenged (see impersonation below) |
| Impersonation via per-request `Auth::setUser()` (clone-voice, podcast-flow) | Works unchanged: the verified admin is still the session user |
| Impersonation via `Auth::loginUsingId($target)` (artistly) | Call `Mfa::grantForImpersonation($admin, $target)` right after |
| `actingAs()` in your tests | Not challenged, so existing suites keep passing |
| JSON / API requests | `403 {"error": "mfa_required", "redirect": ...}` |

Users with no factors are never challenged unless an enforcement policy says they must enroll.

### Login-swap impersonation

```php
$admin = auth()->user();
Auth::loginUsingId($request->user_id);
Mfa::grantForImpersonation($admin, auth()->user()); // throws if $admin hasn't passed MFA
```

If the target has MFA, the admin must have actually passed MFA in this session; an admin without factors can't impersonate them. Targets without MFA only need the admin to be MFA-satisfied.

### API keys

If your API-key middleware calls `auth()->login($user)`, change it to `auth()->setUser($user)`. `login()` writes a session cookie, which turns the API key into a browser session.

MFA is **not** enforced for API-key requests: a leaked key (or a leaked password, used to read the key) bypasses it. Show the published notice next to your API key settings so users know to regenerate the key after any leak:

```tsx
import MfaApiKeyNotice from '@/components/mfa/api-key-notice'; // Components/ in apps that use Pages/

<MfaApiKeyNotice />
```

It reads the [frontend context](#frontend-context) to hide itself when MFA is off, and links to the MFA settings for users who haven't enabled it.

**Sanctum SPA mode.** If you use Sanctum's stateful API (`statefulApi()` / `EnsureFrontendRequestsAreStateful`), `api` routes are authenticated by the session cookie but are outside the `web` group. Add the `mfa` middleware to them. `mfa:doctor` warns when this applies. Token-based `auth:sanctum` is unaffected.

### Frontend context

`Mfa::context($request)` returns a typed `MfaContext` with everything the UI needs: whether MFA is on, the enabled factors, whether factor changes ask for a password, the session user's `hasMfa` / `verified` / `mustEnroll`, and the MFA URLs. Share it once, under one key:

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'mfa' => fn () => \StrontiumCorp\LaravelMfa\Facades\Mfa::context($request),
    ];
}
```

`mfa:install` publishes the matching TypeScript type and a hook to `components/mfa/mfa-context.ts`:

```tsx
import { useMfa } from '@/components/mfa/mfa-context';

const mfa = useMfa(); // MfaContext | null
{mfa?.user && !mfa.user.hasMfa && <a href={mfa.urls.settings ?? '#'}>Turn on two-factor authentication</a>}
```

The PHP class and the TypeScript type mirror each other; a test pins the shape.

## Configuration

Everything lives in `config/mfa.php`. Common `.env` switches:

```dotenv
MFA_ENABLED=true                 # global kill switch
MFA_TOTP_ENABLED=true
MFA_EMAIL_ENABLED=true
MFA_SMS_ENABLED=false
MFA_SMS_DRIVER=twilio            # log | twilio | vonage | infobip | sns | failover | routing | your own
TWILIO_SID=...
TWILIO_TOKEN=...
TWILIO_FROM=+15550000000         # or TWILIO_MESSAGING_SERVICE_SID
MFA_DELIVERY_QUEUE=mfa           # queue name on the default connection; both empty = send inline
MFA_DELIVERY_QUEUE_CONNECTION=   # or a specific connection
MFA_LOG_CHANNEL=                 # dedicated log channel for MFA events
```

**Enforcement.** Users must enroll when `enforce` says so:

```php
'enforce' => ['admin', 'super_admin', 'support'],   // roles, matched against $user->getMfaRoles()
'enforce' => \App\Mfa\MyPolicy::class,             // or a Contracts\EnforcementPolicy class
```

`getMfaRoles()` comes from the trait and reads the `role` attribute (a string or an enum). Override it for other role systems, e.g. spatie/laravel-permission: `return $this->getRoleNames()->all();`.

For logic in code, register a callback in a service provider. It takes precedence over the config. (A closure can't go in `config/mfa.php`, because `config:cache` can't store it.)

```php
Mfa::enforceUsing(fn (User $user) => $user->isAdmin());
```

**Code delivery.** Set `delivery.queue` (a queue on the default connection) or `delivery.queue_connection` to send codes from a queued job. The payload is encrypted and retried with backoff. If every retry fails, the unsent code is discarded so the user can request another one right away. A connection that resolves to `sync` sends inline.

**Disabling a factor type** fails open: users whose only factors are of that type are no longer challenged. Disable a type only after those users enroll another factor; `mfa:doctor` counts the users affected.

**Logout link.** The challenge page's "Sign out" button posts to the route named in `routes.logout_route` (default `logout`), so an app whose logout is `POST /admin/logout` works as long as the route is named. That route is always exempt from the middleware, so unverified users can sign out. Set it to `null` to hide the button.

**Password users vs. social-login users.** Adding or removing a factor runs `password.confirm` by default (`routes.confirm_middleware`). If some users have no password, set it to `[]` or to your own middleware. `mfa:doctor` warns when Socialite is installed and password confirmation is on.

**Sending codes: cooldown, caps and abuse protection.** Email and SMS codes are protected against bombing, toll fraud and lock-out attacks. There are two separate budgets, so the protection against bombing can't be used to lock an owner out:

| Sends to… | Limits (defaults, all in `config/mfa.php`) |
|---|---|
| **A confirmed destination** (login codes; only the owner can trigger these) | Cooldown `2 → 4 → 8 → 15 min` per factor (`factors.*.resend_cooldown`). It resets after a successful verification or an hour of quiet. A resend is allowed as soon as the current code expires or is burned. Plus `rate_limit.send_per_hour` (10) per account. **No per-IP cap**, so users behind a shared NAT are fine. |
| **An unconfirmed destination** (adding a phone/email; anyone can trigger these) | `unconfirmed_per_destination_per_day` (2) across **all** accounts, so a victim gets at most 2 unsolicited messages a day. `new_destinations_per_account_per_day` (3 *distinct* destinations). `new_destinations_per_ip_per_hour` (10 *distinct* destinations; IPv6 grouped per /64). `unconfirmed_global_per_hour` (500), an app-wide circuit breaker. Retrying the same destination is free. |

The cooldown is checked before any cap. Every counter a send touches is counted atomically, and all of them are rolled back if any limit refuses, so a refused request never uses up quota. SMS destinations also pass `factors.sms.allowed_calling_codes` and `factors.sms.blocked_prefixes`; the default list blocks the Caribbean ranges behind `+1` and US 900 numbers. These rules are re-checked at every send.

Responses include `retry_after`, and the React pages show a live countdown. Also turn on your provider's geo-permissions and fraud guard.

**Per-IP limits need the real client IP.**
- Behind a load balancer or CDN, configure Laravel's trusted proxies, or every client shares the balancer's IP.
- Trusting `'*'` is only safe if the app is reachable solely through exactly one proxy layer; otherwise clients can spoof `X-Forwarded-For`.
- `mfa:doctor` reports which of these applies.

**Warn the owner when someone else has the password.** The challenge only appears after the password step. So repeated login-code requests without a verification usually mean the password has leaked. The package fires `SuspiciousCodeRequests` (after `rate_limit.warn_after_unverified_sends` sends, or when the hourly cap is hit; at most once per factor per hour). Notify the owner on another channel:

```php
Event::listen(SuspiciousCodeRequests::class, fn ($e) => $e->user?->notify(new PasswordMayBeCompromised));
```

`SendingCircuitTripped` (critical) fires once an hour while the app-wide breaker is open. Alert on it.

### Non-Inertia frontends

Set `MFA_UI_DRIVER=json` and drive the same routes from any frontend. Endpoints, payloads and error shapes are documented in [docs/json-mode.md](docs/json-mode.md) and pinned by a contract test.

### SMS providers, failover and regional routing

Built-in drivers: `log` (development), `twilio`, `vonage`, `infobip`, and `sns` (Amazon SNS). None needs a vendor SDK; they call the providers' HTTP APIs, so `Http::fake()` works in tests.

**Amazon SNS works from any host** (self-hosted included). It needs only an IAM access key limited to `sns:Publish`, and a region. Requests are signed with AWS Signature V4 inside the package, verified against the official AWS SDK.

```dotenv
MFA_SMS_DRIVER=sns
AWS_SNS_KEY=AKIA...
AWS_SNS_SECRET=...
AWS_SNS_REGION=us-east-1
AWS_SNS_ORIGINATION_NUMBER=+18885550100   # your registered toll-free/10DLC number (US)
```

Two composite drivers combine the others:

```php
// config/mfa.php → 'sms'
'driver' => 'routing',
'drivers' => [
    // Pick a driver by number prefix (longest match wins), else the default.
    'routing' => [
        'routes'  => ['880' => 'bd'],     // Bangladesh → its own chain
        'default' => 'failover',
    ],
    // Try providers in order until one accepts the message.
    'failover' => ['drivers' => ['sns', 'twilio']],
    // Named drivers: any name + "transport" picks the kind.
    'bd' => ['transport' => 'failover', 'drivers' => ['infobip', 'twilio']],
    // ...plus the provider credentials (twilio, infobip, sns, ...)
],
```

How these behave:
- **Timeouts:** 3s to connect and 5s in total per attempt (override with `connect_timeout` / `timeout` on a driver). A request is retried only if it never reached the provider, so a slow provider can't produce a duplicate SMS.
- **When a provider fails** in a failover chain, `SmsProviderFailed` fires. It's logged, audited and counted under `mfa.sms_provider_failed{provider=…}`, with the flow ID.
- **Only when every provider fails** does the user see a delivery error, and `ChallengeDeliveryFailed` fires.
- **Circular configurations** are rejected when the driver is built.
- **`mfa:doctor`** checks every driver in a chain and lists each missing setting.

### Custom SMS provider

```php
// AppServiceProvider::boot()
Mfa::extendSms('acme', fn ($app, array $config) => new AcmeSmsSender($config['token']));
// config/mfa.php → 'sms' => ['driver' => 'acme', 'drivers' => ['acme' => ['token' => env('ACME_TOKEN')]]]
```

### Replacing a factor's implementation

`Mfa::extend()` swaps the implementation of a built-in factor type (`totp`, `email` or `sms`), for example to deliver email codes through your own channel. Implement `Contracts\Factor` and register it in a service provider:

```php
Mfa::extend('email', fn ($app) => new MyEmailFactor(...));
```

The factor types themselves are fixed (`FactorType`). A new kind of factor, such as passkeys, needs package support; it's on the roadmap.

## Observability

Every action emits a domain event (`StrontiumCorp\LaravelMfa\Events\*`, all implementing `Contracts\MfaActivity`). The package fans each event out to three sinks; a failure in any sink is reported and never blocks a login.

- **Logs.** One structured line per event (`mfa.verification_failed`, …) with `user_id`, `factor`, `reason`, `flow_id` and `ip`. Codes, secrets and full phone numbers are never logged. Free text in event context (such as provider errors) has URLs, email addresses and phone numbers redacted before it reaches any sink.
- **Audit table.** `mfa_audit_logs` is queryable and pruned after `retention_days`.
- **Metrics.** Counters and delivery timings go through `Contracts\MetricsRecorder`. Bind your own for Prometheus, StatsD or Pulse; `log` and `null` ship built in.

Every event in one challenge shares a **flow id**. It is also pushed into Laravel's `Context`, so your own log lines in that request carry `mfa_flow_id` too, and it propagates into queued delivery jobs.

Failure reasons are a closed enum (`invalid_code`, `expired`, `replayed`, `rate_limited`, `delivery_failed`, …), so you can alert on them.

### Support tooling

```bash
php artisan mfa:status jane@example.com           # factors + recent events
php artisan mfa:status 42 --flow=3f2c...          # one login attempt end to end
php artisan mfa:reset jane@example.com            # locked-out user (verify identity first!)
php artisan about --only=mfa
```

## Security model

- TOTP secrets and phone/email destinations are encrypted at rest. OTPs and recovery codes are stored only as HMACs, keyed from `APP_KEY`; `APP_PREVIOUS_KEYS` keeps working after a key rotation.
- TOTP replay protection uses an atomic compare-and-set on the last accepted time step.
- OTPs are single use, expire, and are burned after N wrong attempts. They are issued and verified under a row lock, so concurrent requests on many servers can't double-spend a code.
- Verification attempts are rate limited per user, per minute and per day.
  - Each attempt is counted before it is checked, so a burst of parallel requests can't slip past the limit.
  - The daily cap stops slow brute force of 6-digit codes.
- Code sends follow an exponential cooldown, with separate budgets for confirmed and unconfirmed destinations (see *Sending codes* above).
- The MFA routes carry their own request throttle (`routes.throttle`).
- The session is regenerated after verification. Verification is cleared on `Auth::logout()`, even if the app doesn't invalidate the session.
- Verification is tracked per guard. If several session guards are logged in, each one must pass MFA on its own.
- MFA settings are unreachable for a user who has factors but hasn't verified, so a stolen password can't be used to add a factor.
- A pending enrollment is bound to the browser session that started it, and expires after 30 minutes. Another session on the same account can't read its TOTP secret or confirm it.
- Transport error text, which can contain the recipient's address, never reaches the MFA log or audit table. The full exception goes to the app's normal error reporting.
- Queued delivery jobs are encrypted (`ShouldBeEncrypted`).
- Deleting a user removes their MFA secrets and destinations (foreign-key cascade); audit rows are kept, unlinked, until pruned.

## Scaling

- A verified session costs **zero** MFA queries per request.
- "Does this user have MFA?" is cached. Factor changes write the fresh answer through, and cache fills never overwrite it, so a concurrent stale read can't win.
- All services are stateless singletons. Request-scoped lookups (request, auth) go through the live container, which makes them safe under Octane.
- Expired codes and old audit rows are pruned daily (`model:prune`, scheduled with `onOneServer`).
- Behind a load balancer, use a shared cache (redis/database) for rate limits and a shared session driver. `mfa:doctor` warns if you don't.

## Testing your app

```php
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Testing\InteractsWithMfa;

uses(InteractsWithMfa::class);

it('challenges MFA users', function () {
    $user = User::factory()->create();
    $this->createMfaFactor($user);

    $this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    $this->actingAsMfaVerified($user)->get('/dashboard')->assertOk();
});

it('sends SMS codes', function () {
    $sms = Mfa::fakeSms();        // or ->failWith('outage')
    Mfa::fakeCodes('123456');     // deterministic OTPs
    // ...
    $sms->assertSentTo('+15555550100');
});
```

## Developing the package

`make` is the task runner (`make` on its own lists every target):

```bash
make test                              # Pest, in parallel
make coverage                          # parallel, with coverage; fails under 85%
make lint                              # Pint (check); `make format` fixes
make analyse                           # PHPStan (Larastan) level 6
make ci                                # lint + analyse + test
make test-laravel VERSION=11           # the suite against another Laravel major (scratch copy)
make test-matrix                       # Laravel 11, 12 and 13
make typecheck-stubs APPS="../podcast-flow ../artistly"   # React stubs vs real apps
```

`composer test` and `composer test:coverage` run the same parallel commands.

**Releasing.** `make release` tags a release from `main`. It infers the version from the commits since the last tag (Conventional Commits: a breaking change bumps major, or minor below 1.0; `feat` bumps minor; anything else bumps patch). It runs `make ci`, prepends grouped release notes to `CHANGELOG.md`, commits `chore: release vX.Y.Z`, creates an annotated tag carrying the notes, and pushes both atomically after you confirm. Composer reads the version from the tag; no file carries it.

```bash
make release ARGS="--dry-run"      # preview the tag and notes
make release                       # inferred version
make release ARGS="--minor"        # or --major / --patch / v1.2.3
```

CI runs PHP 8.2–8.5 × Laravel 11/12/13 × lowest/stable dependencies.

Notes for contributors:
- Tests must never write into Testbench's skeleton under `vendor/`; use a temp dir (see the `mfa:install` test). Parallel runs share it.
- `@pest-mutate-ignore` markers in `src/` record mutants proven equivalent during a one-off mutation-testing pass, each with its reason on the line above. Mutation testing isn't part of the regular workflow (too slow).

To work on it against a real app, add a path repository to the app's `composer.json`:

```json
"repositories": [{ "type": "path", "url": "../laravel-mfa", "options": { "symlink": true } }]
```

then run `composer require strontiumcorp/laravel-mfa:@dev`.
