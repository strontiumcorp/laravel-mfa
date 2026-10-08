<?php

namespace StrontiumCorp\LaravelMfa\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\MfaServiceProvider;
use StrontiumCorp\LaravelMfa\Testing\InteractsWithMfa;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\HandleImpersonation;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    use InteractsWithMfa;

    /**
     * Config applied before the providers boot, for settings read at boot
     * (e.g. middleware.append_to_web_group). Set, then rebootWith().
     *
     * @var array<string, mixed>
     */
    public static array $bootConfig = [];

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class, MfaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config) {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $config->set('database.default', 'testing');
            // Testbench turns SQLite foreign keys off; MFA rows rely on them.
            $config->set('database.connections.testing.foreign_key_constraints', true);
            $config->set('auth.providers.users.model', User::class);
            $config->set('cache.default', 'array');
            $config->set('session.driver', 'array');
            $config->set('mfa.ui.driver', 'json');
            $config->set('mfa.routes.confirm_middleware', []);
            $config->set('mfa.factors.sms.enabled', true);
            $config->set('mfa.prune.schedule', false);
            $config->set(static::$bootConfig);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/login', fn () => 'login')->name('login');
            Route::post('/logout', fn () => tap('bye', fn () => Auth::logout()))->name('logout');
            Route::get('/confirm-password', fn () => 'confirm')->name('password.confirm');
            Route::get('/public', fn () => 'public');
            // A page without `auth` that still reads the user (e.g. a header with the name).
            Route::get('/public-user', fn () => 'user:'.(Auth::id() ?? 'guest'));

            Route::middleware('auth')->group(function () {
                Route::get('/dashboard', fn () => 'dashboard:'.Auth::id())->name('dashboard');
                Route::get('/api/me', fn () => ['id' => Auth::id()]);
            });

            // Webhook pattern used in all three apps: no session login, the
            // controller sets the user for the rest of the request.
            Route::post('/hooks/test', function () {
                Auth::guard('web')->setUser(User::firstOrFail());

                return 'hook:'.Auth::id();
            });

            // artistly-style: real login swap.
            Route::post('/admin/switch/{user}', function (User $user) {
                $admin = Auth::user();
                session(['impersonate' => $admin->id]);
                Auth::loginUsingId($user->id);

                if (request()->boolean('grant')) {
                    Mfa::grantForImpersonation($admin, $user);
                }

                return redirect('/dashboard');
            });
            Route::post('/admin/exit', function () {
                Auth::loginUsingId(session()->pull('impersonate'));

                return redirect('/dashboard');
            });

            // clone-voice / podcast-flow style: per-request setUser swap.
            Route::middleware(['auth', HandleImpersonation::class])
                ->get('/impersonating', fn () => 'as:'.Auth::id());
        });
    }

    protected function makeUser(array $attributes = []): User
    {
        static $n = 0;
        $n++;

        return User::create($attributes + [
            'name' => "User {$n}",
            'email' => "user{$n}@example.com",
            'password' => 'password',
        ]);
    }

    /** A user with one confirmed factor of the given type. */
    protected function userWithFactor(FactorType $type = FactorType::Totp, array $attributes = []): array
    {
        $user = $this->makeUser($attributes);

        return [$user, $this->createMfaFactor($user, $type)];
    }

    /**
     * Simulate a fresh request cycle's guard state (guards are cached on the
     * app instance in tests but are rebuilt per request in production).
     */
    protected function freshGuards(): static
    {
        Auth::forgetGuards();

        return $this;
    }

    /** Rebuild the app with boot-time config, with a migrated database. */
    protected function rebootWith(array $config): static
    {
        static::$bootConfig = $config;

        try {
            $this->refreshApplication();
        } finally {
            static::$bootConfig = [];
        }

        $this->defineDatabaseMigrations();
        $this->artisan('migrate');

        return $this;
    }
}
