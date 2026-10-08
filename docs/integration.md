# Integrating into an app

The same steps for every app. Each step says when it applies.

## 1. Install

**Local development (symlinked).** Add a path repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "path", "url": "../laravel-mfa", "options": { "symlink": true } }
]
```

```bash
composer require strontiumcorp/laravel-mfa:@dev
```

Edits in `../laravel-mfa` apply to the app immediately.

If the app runs in Docker or Sail, the container only sees the app folder, so the symlink breaks there. Mount the package next to it, in a `docker-compose.override.yml` that you don't commit:

```yaml
services:
  laravel.test:                       # the app service's name
    volumes:
      - ../laravel-mfa:/var/www/laravel-mfa
```

`/var/www/html/../laravel-mfa` then resolves inside the container too.

**Released version.** Use a VCS repository and a version constraint instead:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:strontiumcorp/laravel-mfa.git" }
]
```

```bash
composer require strontiumcorp/laravel-mfa:^0.1
```

The repo is private. Locally your SSH key works. CI and servers need a deploy key, or a token: `composer config --global github-oauth.github.com <token>`.

## 2. Publish and migrate

```bash
php artisan mfa:install    # config/mfa.php, pages, components
php artisan migrate
```

`mfa:install` puts the pages in `resources/js/{Pages|pages}/mfa/` and the components in `{Components|components}/mfa/`, matching the app's casing. Use `--js-path` for another layout.

The migration adds `user_id` foreign keys to the users table, so `users.id` must be a big integer (Laravel's default `$table->id()`).

## 3. User model

```php
use StrontiumCorp\LaravelMfa\Concerns\HasMultiFactorAuthentication;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

class User extends Authenticatable implements MultiFactorAuthenticatable
{
    use HasMultiFactorAuthentication;
}
```

The trait reads the user's email from `email` and their role from `role` (a string or an enum). Override `getMfaEmail()` or `getMfaRoles()` if the columns differ.

## 4. Configure

`.env`:

```dotenv
MFA_TOTP_ENABLED=true
MFA_EMAIL_ENABLED=true
MFA_SMS_ENABLED=false            # SMS stays off for now; Twilio is ready when needed
MFA_SMS_DRIVER=twilio
MFA_DELIVERY_QUEUE=mfa           # send codes from a queued job; needs a worker on this queue
MFA_LOG_CHANNEL=mfa              # optional dedicated channel
```

`config/mfa.php`:

| Key | Set to |
|---|---|
| `enforce` | The roles that must use MFA, e.g. `['admin', 'super_admin', 'support']`. For logic in code, call `Mfa::enforceUsing(fn ($user) => ...)` in a service provider instead. |
| `routes.home` | Where users land after the challenge when there's no intended page. Default `/dashboard`. |
| `routes.logout_route` | The app's named logout route. Default `logout`. |
| `routes.confirm_middleware` | `[]` if some users have no password (social login), otherwise keep `password.confirm`. `mfa:doctor` warns when Socialite is installed. |
| `middleware.except` | Routes a logged-in user must reach before passing MFA (e.g. a language switcher). Requests without a session login, such as webhooks, already pass. |

## 5. Check the app's auth code

Search the app for these patterns. Each needs a change only when it's present.

**API-key auth calling `login()`.** Change `auth()->login($user)` to `auth()->setUser($user)`. `login()` writes a session cookie, which turns the key into a browser session. MFA isn't enforced for API keys, so show the notice next to API key settings (step 6).

**Login-swap impersonation** (`Auth::loginUsingId($target)` on a browser request). Grant MFA for the target right after the swap:

```php
$admin = auth()->user();
Auth::loginUsingId($request->user_id);
Mfa::grantForImpersonation($admin, auth()->user());
```

It throws if the target has MFA and the admin hasn't passed MFA in this session. Per-request impersonation through `Auth::setUser()` needs nothing.

**`login()` or `loginUsingId()` outside browser requests** (jobs, commands). Fine when queued, because nothing is persisted. Dispatched synchronously from a web request, it would change that request's logged-in user.

**Sanctum stateful API** (`statefulApi()` or `EnsureFrontendRequestsAreStateful`). Those `api` routes use the session but sit outside the `web` group. Add the `mfa` middleware to them. Token-based `auth:sanctum` is unaffected.

**Trusted proxies.** The per-IP send limits need the real client IP. Behind a load balancer, list the proxy IPs. Trust `'*'` only if the app is reachable solely through exactly one proxy.

## 6. Frontend

- Wrap the published pages in the app's layouts.
- Link to `route('mfa.settings')` from the account settings.
- Share the MFA context in `HandleInertiaRequests::share()`:

  ```php
  'mfa' => fn () => \StrontiumCorp\LaravelMfa\Facades\Mfa::context($request),
  ```

  React components read it with `useMfa()` from `components/mfa/mfa-context`.
- Show `<MfaApiKeyNotice />` from `components/mfa/api-key-notice` next to API key management.

## 7. Tests

Existing suites keep passing: `actingAs()` is never challenged. For MFA tests, use the helpers:

```php
uses(\StrontiumCorp\LaravelMfa\Testing\InteractsWithMfa::class);

$this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
$this->actingAsMfaVerified($user)->get('/dashboard')->assertOk();
```

Add tests for: an admin forced to enroll, impersonation, a webhook and an API key still working for a user with MFA.

## 8. Verify and deploy

```bash
php artisan mfa:doctor     # exits non-zero on problems
```

Manual check:
- Password login with MFA → challenge → the intended page.
- Wrong code → error; email code arrives; recovery code works once.
- "Sign out" on the challenge page works.
- An admin without MFA is sent to enroll.
- Impersonation, webhooks and API keys still work.
- `php artisan mfa:status <email>` shows the attempts.

Deploy:
- Run `mfa:doctor` in the pipeline.
- Run a queue worker for `MFA_DELIVERY_QUEUE`.
- Keep the scheduler running (it prunes old codes and audit rows daily).
- Use a shared cache and session store (Redis or database) on multiple servers.

To switch MFA off in an incident, set `MFA_ENABLED=false`.

## Laravel versions

| Version | Notes |
|---|---|
| 11 | End of life, and every 11.x release has open security advisories. Composer 2.9 blocks insecure versions by default, so `composer require` can fail in an app locked to Laravel 11. Resolve that in the app first (upgrade, or an explicit audit decision). Kernel-style apps (`app/Http/Kernel.php`) work as is; `mfa:doctor` confirms the middleware is in the `web` group and reads `App\Http\Middleware\TrustProxies`. |
| 12 | Nothing specific. |
| 13 | Nothing specific. |

Under Octane, run the test suite and one manual login with `octane:start` to confirm nothing leaks between requests.
