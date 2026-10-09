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
composer require strontiumcorp/laravel-mfa:^0.3
```

The repo is private. Locally your SSH key works. CI and servers need a deploy key, or a token: `composer config --global github-oauth.github.com <token>`.

## 2. Publish and migrate

```bash
php artisan mfa:install    # config/mfa.php, pages, components
php artisan migrate
```

`mfa:install` publishes into `resources/js/` (`--js-path` for another layout):

```
resources/js/
├── {Pages|pages}/mfa/                 matches the app's pages directory
│   ├── challenge.tsx                  Inertia pages: props in, components out
│   ├── settings.tsx
│   └── mfa-context.ts                 useMfa(), the shared MFA context (Inertia only)
└── components/vendor/laravel-mfa/     always this lowercase path
    ├── challenge-form.tsx             plain React components, see step 6
    └── ...
```

Existing files are skipped; `--force` overwrites them. The pages import the components through the `@/` alias for `resources/js/`, which the Laravel starter kits set up in `tsconfig.json` and `vite.config`.

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
| `enforcement.roles` | The roles that must use MFA, e.g. `['admin', 'super_admin', 'support']`. For logic in code, set `enforcement.policy` to a `Contracts\EnforcementPolicy` class instead (with `roles` empty, it decides alone). |
| `enforcement.required_types` | What those users must set up and sign in with. Default `['totp']`: an admin with only email is sent to add an authenticator app. `[]` accepts any factor. |
| `routes.home` | Where users land after the challenge when there's no intended page. Default `/dashboard`. |
| `routes.logout_route` | The app's named logout route. Default `logout`. |
| `routes.password_confirmation` | Keep `true`: the MFA settings page asks for the password before factor changes, with no confirm page needed in the app. Users with an empty password are never asked. If social-login users have a password they don't know, exempt them with `routes.password_confirmation_policy` (a `Contracts\PasswordConfirmationPolicy` class), or set `false` to ask nobody. `mfa:doctor` warns when Socialite is installed. See [configuration.md](configuration.md#password-confirmation). |
| `routes.confirm_middleware` | `[]` (default). `['password.confirm']` sends users to the app's own confirm-password page instead of MFA's prompt. |
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

It throws if the target has MFA and the admin hasn't passed MFA in this session. It also drops the admin's password confirmation, so the admin can't change the target's factors. Per-request impersonation through `Auth::setUser()` needs nothing.

**`login()` or `loginUsingId()` outside browser requests** (jobs, commands). Fine when queued, because nothing is persisted. Dispatched synchronously from a web request, it would change that request's logged-in user.

**Sanctum stateful API** (`statefulApi()` or `EnsureFrontendRequestsAreStateful`). Those `api` routes use the session but sit outside the `web` group. Add the `mfa` middleware to them. Token-based `auth:sanctum` is unaffected.

**Trusted proxies.** The per-IP send limits need the real client IP. Behind a load balancer, list the proxy IPs. Trust `'*'` only if the app is reachable solely through exactly one proxy.

## 6. Frontend

The published files are the app's own: restyle and rearrange them freely. `mfa:install` never overwrites them without `--force`.

- Wrap the published pages in the app's layouts. The pages only wire Inertia (forms, posting, `Head`, errors) to the components, so layout changes go there.
- Add the two-factor card to the account settings page. It says whether two-factor is on and links to the MFA settings page, and hides itself when MFA or its routes are off:

  ```tsx
  import MfaSettingsCard from '@/components/vendor/laravel-mfa/settings-card';
  import { mfaSettingsCardProps, useMfa } from '@/pages/mfa/mfa-context'; // or @/Pages/...
  import { Link } from '@inertiajs/react';

  <MfaSettingsCard {...mfaSettingsCardProps(useMfa())} renderLink={(link) => <Link {...link} />} />
  ```

  Without `renderLink` it renders a plain `<a>`. It needs the shared context below; without it, the card stays hidden.
- Share the MFA context in `HandleInertiaRequests::share()`:

  ```php
  'mfa' => fn () => \StrontiumCorp\LaravelMfa\Facades\Mfa::context($request),
  ```

  React code reads it with `useMfa()` from `@/{Pages|pages}/mfa/mfa-context`.
- Show the API-key notice next to API key management:

  ```tsx
  import MfaApiKeyNotice from '@/components/vendor/laravel-mfa/api-key-notice';
  import { mfaApiKeyNoticeProps, useMfa } from '@/pages/mfa/mfa-context'; // or @/Pages/...

  <MfaApiKeyNotice {...mfaApiKeyNoticeProps(useMfa())} />
  ```

**The components** in `components/vendor/laravel-mfa/` are plain React: no Inertia, no Ziggy, and none imports another, so each one can be copied into any React project. They take data and callbacks as props, plus `processing`, `error` and, for sends, `retryAfter`:

| Component | Props |
|---|---|
| `challenge-form` | `factors`, `selectedFactorId`, `onSelectFactor(id)`, `onSubmit(code)`, `processing`, `error`, `children` (shown above the code input), `onUseRecoveryCode?`, `onSignOut?` |
| `send-code-button` | `onSend()`, `processing`, `retryAfter`, `sent`, `error` |
| `recovery-code-form` | `onSubmit(code)`, `processing`, `error`, `onUseVerificationCode?`, `onSignOut?` |
| `factor-list` | `factors`, `onRemove(factor)`, `removingId`, `required`, `requiredLabels`, `confirmRemove?` |
| `add-factor-form` | `types` (`{ type, label, recommended? }`; recommended ones get a badge and go first), `onAdd(type, destination?)`, `processing`, `error` |
| `totp-setup` | `secret`, `qrSvg`, `onConfirm(code)`, `processing`, `error`, `label` |
| `destination-setup` | `label`, `destination`, `onConfirm(code)`, `onResend()`, `processing`, `resending`, `retryAfter`, `sent`, `error` |
| `recovery-codes-panel` | `remaining`, `codes`, `onRegenerate()`, `processing`, `confirmRegenerate?` |
| `api-key-notice` | `enabled`, `settingsUrl`, `className` |
| `settings-card` | `settingsUrl` (renders nothing when `null`), `enabled`, `hasMfa`, `mustEnroll`, `className`, `renderLink?({ href, className, children })` |
| `password-confirm-form` | `onConfirm(password)`, `onCancel?`, `processing`, `error`, `retryAfter` (the settings page's `passwordRetryAfter`), `className` |

The settings page shows `password-confirm-form` when a change answers "password confirmation required" (`routes.password_confirmation`), and retries the change once the password is confirmed. The code and password inputs clear themselves after a failed attempt (`processing` goes back to false with an `error`). `retryAfter` drives a countdown; a new value restarts it. `totp-setup` renders `qrSvg` as HTML, so pass only the server's `qr_svg`.

**Upgrading from v0.2.** Two new components (`settings-card`, `password-confirm-form`), a `mfaSettingsCardProps()` helper in `mfa-context.ts`, and a settings page that asks for the password inline. Run `php artisan mfa:install` to add the new components (existing files are skipped); to get the new settings page and helper, re-publish with `--force` (this overwrites customised pages) or copy the changes from `stubs/inertia-react/pages/` in the package. Then check `routes.password_confirmation` in step 4: the [config upgrade note](configuration.md#password-confirmation) says what changed.

**Upgrading from v0.1.** v0.1 published `api-key-notice.tsx` and `mfa-context.ts` to `{Components|components}/mfa/`, and self-contained pages. Run `php artisan mfa:install --force` (this overwrites customised pages), change the imports as above (`<MfaApiKeyNotice />` now takes its state as props), then delete `{Components|components}/mfa/`. `mfa:install` warns while those old files remain.

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
