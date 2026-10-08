# laravel-mfa

Multi-factor authentication for Laravel 11, 12 and 13: authenticator apps (TOTP), email and SMS codes, and recovery codes. It ships with role-based enforcement, React/Inertia pages you own, a JSON mode, and audit logging.

Owned by [Strontium Corp](https://github.com/strontiumcorp). Maintained by [Mojahidul Islam](https://github.com/itsemon245).

## Quickstart

Add the private repository to the app's `composer.json`, then install:

```json
"repositories": [
    { "type": "vcs", "url": "git@github.com:strontiumcorp/laravel-mfa.git" }
]
```

```bash
composer require strontiumcorp/laravel-mfa
php artisan mfa:install
php artisan migrate
```

Add the contract and trait to the User model:

```php
use StrontiumCorp\LaravelMfa\Concerns\HasMultiFactorAuthentication;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

class User extends Authenticatable implements MultiFactorAuthenticatable
{
    use HasMultiFactorAuthentication;
}
```

Link to `route('mfa.settings')` from the account page, then check the setup:

```bash
php artisan mfa:doctor
```

For local development against `../laravel-mfa`, configuration, and the app code to check (impersonation, API keys, social login), follow **[docs/integration.md](docs/integration.md)**.

## How it works

The middleware is added to the `web` group, so every web route is protected. It checks the user the login stored in the session, not `Auth::user()`:

| Case | Result |
|---|---|
| Password, social or remember-me login | Challenged until MFA passes |
| `Auth::setUser()` in webhooks, jobs or per-request impersonation | Not challenged |
| Login-swap impersonation (`Auth::loginUsingId()`) | Needs `Mfa::grantForImpersonation()`; see the integration guide |
| `actingAs()` in tests | Not challenged, so existing suites keep passing |
| JSON requests | `403 {"error": "mfa_required", "redirect": "..."}` |

Users without factors aren't challenged, unless `enforce` requires them to enroll.

## Documentation

- [docs/integration.md](docs/integration.md): adding the package to an app, step by step, with Laravel version notes.
- [docs/configuration.md](docs/configuration.md): enforcement, sending limits, SMS providers, observability, security model.
- [docs/json-mode.md](docs/json-mode.md): the endpoints for non-Inertia frontends.

## Testing your app

```php
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Testing\InteractsWithMfa;

uses(InteractsWithMfa::class);

it('challenges users with MFA', function () {
    $user = User::factory()->create();
    $this->createMfaFactor($user);

    $this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    $this->actingAsMfaVerified($user)->get('/dashboard')->assertOk();
});

it('sends SMS codes', function () {
    $sms = Mfa::fakeSms();       // or ->failWith('outage')
    Mfa::fakeCodes('123456');    // predictable codes
    // ...
    $sms->assertSentTo('+15555550100');
});
```

## Developing the package

`make` lists every task. The main ones:

```bash
make ci                    # Pint, PHPStan, the test suite
make coverage              # with coverage; fails under 85%
make test-matrix           # Laravel 11, 12 and 13, newest and lowest dependencies
make typecheck-stubs APPS="../artistly ../clone-voice ../podcast-flow"
make release               # tag a release; ARGS="--dry-run" to preview
```

`make release` infers the version from the commit messages, updates `CHANGELOG.md`, and pushes an annotated tag after you confirm. CI then runs the full matrix on the tag and publishes the GitHub Release.

CI runs static analysis and 7 key combinations on every push. The full matrix (22 jobs) runs on release tags, nightly when `main` changed, and on demand.

Conventions, testing rules and the design invariants are in [CLAUDE.md](CLAUDE.md).
