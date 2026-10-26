<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use StrontiumCorp\LaravelMfa\Console\InstallCommand;
use StrontiumCorp\LaravelMfa\Console\ReleasedStubs;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\VerificationLifetime;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\ExemptSocialLogins;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\PlainUser;

it('passes mfa:doctor on a correctly integrated app', function () {
    config(['session.driver' => 'database', 'mfa.factors.sms.enabled' => false]);

    $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('All checks passed');
});

it('fails mfa:doctor with actionable output on a broken setup', function () {
    config(['auth.providers.users.model' => User::class, 'mfa.sms.driver' => 'twilio']);

    $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('implements MultiFactorAuthenticatable');
});

it('shows a user\'s factors and recent activity with mfa:status', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $this->loginWithSession($user)->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000']);

    $this->artisan('mfa:status', ['user' => $user->email])
        ->assertSuccessful()
        ->expectsOutputToContain('+*******0100')
        ->expectsOutputToContain('verification_failed');
});

it('resets a locked-out user with mfa:reset', function () {
    [$user] = $this->userWithFactor();
    expect(Mfa::hasConfirmedFactors($user))->toBeTrue();

    $this->artisan('mfa:reset', ['user' => $user->id, '--force' => true])->assertSuccessful();

    expect($user->mfaFactors()->count())->toBe(0)
        ->and(Mfa::hasConfirmedFactors($user))->toBeFalse();
});

it('publishes the pages into the app\'s pages directory and the components into components/vendor/laravel-mfa', function (string $dir) {
    // Never write into the shared Testbench skeleton: use a private temp dir,
    // and record the config publish instead of performing it.
    $files = new Filesystem;
    $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
    $files->ensureDirectoryExists("{$js}/{$dir}");
    // artistly has both components/ and Components/; components always go to lowercase components/.
    $files->ensureDirectoryExists("{$js}/Components");
    $published = [];
    Artisan::command('vendor:publish {--tag=} {--force}', function () use (&$published) {
        $published[] = $this->option('tag');
    });
    $stubs = __DIR__.'/../../stubs/inertia-react';
    $names = fn (string $path) => collect($files->files($path))->map->getFilename()->sort()->values()->all();

    try {
        $this->artisan('mfa:install', ['--js-path' => $js, '--css-path' => "{$js}/css"])
            ->assertSuccessful()
            ->expectsOutputToContain("{$js}/{$dir}/mfa/challenge.tsx")
            ->expectsOutputToContain("{$js}/components/vendor/laravel-mfa/challenge-form.tsx")
            ->expectsOutputToContain("from '@/{$dir}/mfa/mfa-context'")
            ->expectsOutputToContain("from '@/components/vendor/laravel-mfa/api-key-notice'")
            ->expectsOutputToContain("from '@/components/vendor/laravel-mfa/settings-card'")
            ->expectsOutputToContain("from '@/components/vendor/laravel-mfa/enable-nudge'")
            ->expectsOutputToContain('<MfaEnableNudge {...useMfaNudge()} />')
            ->expectsOutputToContain('<MfaIdleWarning {...useMfaIdleWarning()} />')
            ->expectsOutputToContain('routes.password_confirmation_policy')
            ->doesntExpectOutputToContain('earlier version');

        expect($published)->toBe(['mfa-config'])
            ->and($names("{$js}/{$dir}/mfa"))->toBe(['challenge.tsx', 'mfa-context.ts', 'settings.tsx'])
            ->and(file_get_contents("{$js}/{$dir}/mfa/challenge.tsx"))->toBe(file_get_contents("{$stubs}/pages/challenge.tsx"))
            ->and($names("{$js}/components/vendor/laravel-mfa"))->toBe($names("{$stubs}/components"))
            ->and($names("{$js}/components/vendor/laravel-mfa"))->toContain('api-key-notice.tsx', 'challenge-form.tsx', 'totp-setup.tsx', 'password-confirm-form.tsx', 'settings-card.tsx', 'enable-nudge.tsx')
            ->and(file_get_contents("{$js}/components/vendor/laravel-mfa/totp-setup.tsx"))->toBe(file_get_contents("{$stubs}/components/totp-setup.tsx"))
            ->and("{$js}/Components/vendor")->not->toBeDirectory()
            ->and("{$js}/{$dir}/mfa/components")->not->toBeDirectory();
    } finally {
        $files->deleteDirectory($js);
    }
})->with([
    'Pages (artistly)' => ['Pages'],
    'pages' => ['pages'],
]);

it('points at the files left by an earlier version', function () {
    $files = new Filesystem;
    $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
    $files->ensureDirectoryExists("{$js}/Components/mfa");
    $files->put("{$js}/Components/mfa/mfa-context.ts", 'old');
    Artisan::command('vendor:publish {--tag=} {--force}', fn () => null);

    try {
        $this->artisan('mfa:install', ['--js-path' => $js, '--css-path' => "{$js}/css"])
            ->assertSuccessful()
            ->expectsOutputToContain("Found files from an earlier version in {$js}/Components/mfa/");

        // Left for the app to delete once its imports have moved.
        expect(file_get_contents("{$js}/Components/mfa/mfa-context.ts"))->toBe('old');
    } finally {
        $files->deleteDirectory($js);
    }
});

it('replaces published files the app never changed, keeps changed ones unless forced, and can skip the UI', function () {
    $files = new Filesystem;
    $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
    $files->ensureDirectoryExists("{$js}/pages/mfa");
    $files->ensureDirectoryExists("{$js}/components/vendor/laravel-mfa");
    // As an earlier release published them: untouched since.
    $files->put("{$js}/pages/mfa/settings.tsx", "old settings page\n");
    $files->put("{$js}/components/vendor/laravel-mfa/factor-cards.tsx", "old cards\r\n"); // a Windows checkout
    // Changed by the app.
    $files->put("{$js}/pages/mfa/challenge.tsx", 'customised');
    $files->put("{$js}/components/vendor/laravel-mfa/challenge-form.tsx", 'customised');
    $this->app->instance(ReleasedStubs::class, new ReleasedStubs(released([
        'pages/settings.tsx' => "old settings page\n",
        'components/factor-cards.tsx' => "old cards\n",
        'pages/challenge.tsx' => 'as released',
    ])));
    $stubs = __DIR__.'/../../stubs/inertia-react';
    Artisan::command('vendor:publish {--tag=} {--force}', fn () => null);

    try {
        $this->artisan('mfa:install', ['--js-path' => $js, '--css-path' => "{$js}/css"])
            ->assertSuccessful()
            ->expectsOutputToContain("Kept (changed in the app; --force replaces it): {$js}/pages/mfa/challenge.tsx")
            ->expectsOutputToContain("Kept (changed in the app; --force replaces it): {$js}/components/vendor/laravel-mfa/challenge-form.tsx");
        expect(file_get_contents("{$js}/pages/mfa/challenge.tsx"))->toBe('customised')
            ->and(file_get_contents("{$js}/components/vendor/laravel-mfa/challenge-form.tsx"))->toBe('customised')
            ->and(file_get_contents("{$js}/pages/mfa/settings.tsx"))->toBe(file_get_contents("{$stubs}/pages/settings.tsx"))
            ->and(file_get_contents("{$js}/components/vendor/laravel-mfa/factor-cards.tsx"))->toBe(file_get_contents("{$stubs}/components/factor-cards.tsx"))
            ->and("{$js}/components/vendor/laravel-mfa/totp-setup.tsx")->toBeFile();

        $this->artisan('mfa:install', ['--js-path' => $js, '--css-path' => "{$js}/css", '--force' => true])->assertSuccessful();
        expect(file_get_contents("{$js}/pages/mfa/challenge.tsx"))->toBe(file_get_contents("{$stubs}/pages/challenge.tsx"))
            ->and(file_get_contents("{$js}/components/vendor/laravel-mfa/challenge-form.tsx"))->toBe(file_get_contents("{$stubs}/components/challenge-form.tsx"));

        $files->deleteDirectory("{$js}/pages/mfa");
        $files->deleteDirectory("{$js}/components");
        $files->deleteDirectory("{$js}/css");
        $this->artisan('mfa:install', ['--js-path' => $js, '--css-path' => "{$js}/css", '--no-ui' => true])->assertSuccessful();
        expect("{$js}/pages/mfa")->not->toBeDirectory()
            ->and("{$js}/components")->not->toBeDirectory()
            ->and("{$js}/css")->not->toBeDirectory();
    } finally {
        $files->deleteDirectory($js);
    }
});

describe('--ui-only', function () {
    it('updates the pages, components and theme without touching the config', function () {
        $files = new Filesystem;
        $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
        $files->ensureDirectoryExists("{$js}/Pages/mfa");
        $files->put("{$js}/Pages/mfa/settings.tsx", 'as released');
        $files->put("{$js}/Pages/mfa/challenge.tsx", 'customised');
        $files->put("{$js}/Pages/mfa/mfa-context.ts", file_get_contents(__DIR__.'/../../stubs/inertia-react/pages/mfa-context.ts'));
        $this->app->instance(ReleasedStubs::class, new ReleasedStubs(released(['pages/settings.tsx' => 'as released'])));
        $published = [];
        Artisan::command('vendor:publish {--tag=} {--force}', function () use (&$published) {
            $published[] = $this->option('tag');
        });

        try {
            $this->artisan('mfa:install', ['--ui-only' => true, '--js-path' => $js, '--css-path' => "{$js}/css", '--tailwind' => '3'])
                ->assertSuccessful()
                ->expectsOutputToContain("{$js}/Pages/mfa/settings.tsx")
                ->expectsOutputToContain("Kept (changed in the app; --force replaces it): {$js}/Pages/mfa/challenge.tsx")
                ->expectsOutputToContain('1 file changed in the app was kept')
                // Only the files: no config, no first-install steps.
                ->doesntExpectOutputToContain('Next steps');

            expect($published)->toBe([])
                ->and(file_get_contents("{$js}/Pages/mfa/settings.tsx"))->not->toBe('as released')
                ->and(file_get_contents("{$js}/Pages/mfa/challenge.tsx"))->toBe('customised')
                ->and("{$js}/components/vendor/laravel-mfa/factor-setup-dialog.tsx")->toBeFile()
                ->and("{$js}/css/vendor/laravel-mfa/theme.css")->toBeFile();

            $this->artisan('mfa:install', ['--ui-only' => true, '--force' => true, '--js-path' => $js, '--css-path' => "{$js}/css"])
                ->assertSuccessful()
                ->doesntExpectOutputToContain('was kept');
            expect(file_get_contents("{$js}/Pages/mfa/challenge.tsx"))->not->toBe('customised')
                ->and($published)->toBe([]);
        } finally {
            $files->deleteDirectory($js);
        }
    });

    it('refuses --ui-only with --no-ui', function () {
        $this->artisan('mfa:install', ['--ui-only' => true, '--no-ui' => true])
            ->assertFailed()
            ->expectsOutputToContain('--ui-only and --no-ui');
    });
});

it('ships the hashes of every released page, component and theme', function () {
    $released = json_decode((string) file_get_contents(ReleasedStubs::path()), true, flags: JSON_THROW_ON_ERROR);

    expect($released)->toHaveKeys(['pages/settings.tsx', 'pages/challenge.tsx', 'components/factor-setup-dialog.tsx', 'css/theme.css'])
        ->and(collect($released)->flatten()->every(fn ($hash) => is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1))->toBeTrue()
        ->and((new ReleasedStubs)->contains('pages/settings.tsx', 'never released'))->toBeFalse()
        ->and((new ReleasedStubs)->contains('pages/unknown.tsx', 'anything'))->toBeFalse();
});

/** A released-stubs file holding these contents (relative stub path => contents); returns its path. */
function released(array $contents): string
{
    $path = sys_get_temp_dir().'/mfa-released-'.uniqid().'.json';
    file_put_contents($path, json_encode(array_map(fn (string $c) => [hash('sha256', $c)], $contents)));

    return $path;
}

describe('the theme', function () {
    it('publishes the theme for the app\'s Tailwind version and says how to import it', function (?string $tailwind, string $stub, string $where) {
        $files = new Filesystem;
        $tmp = sys_get_temp_dir().'/mfa-install-'.uniqid();
        Artisan::command('vendor:publish {--tag=} {--force}', fn () => null);

        try {
            $this->artisan('mfa:install', array_filter(['--js-path' => "{$tmp}/js", '--css-path' => "{$tmp}/css", '--tailwind' => $tailwind]))
                ->assertSuccessful()
                ->expectsOutputToContain("{$tmp}/css/vendor/laravel-mfa/theme.css")
                ->expectsOutputToContain("@import './vendor/laravel-mfa/theme.css';")
                ->expectsOutputToContain($where);

            expect(file_get_contents("{$tmp}/css/vendor/laravel-mfa/theme.css"))
                ->toBe(file_get_contents(__DIR__."/../../stubs/inertia-react/css/{$stub}"));
        } finally {
            $files->deleteDirectory($tmp);
        }
    })->with([
        'no Tailwind found: 4' => [null, 'theme.css', 'resources/css/app.css'],
        'Tailwind 4' => ['4', 'theme.css', 'resources/css/app.css'],
        'Tailwind 3 (artistly)' => ['3', 'theme.tailwind-3.css', 'before the @tailwind directives'],
    ]);

    it('keeps a customised theme unless forced', function () {
        $files = new Filesystem;
        $tmp = sys_get_temp_dir().'/mfa-install-'.uniqid();
        $files->ensureDirectoryExists("{$tmp}/css/vendor/laravel-mfa");
        $files->put("{$tmp}/css/vendor/laravel-mfa/theme.css", ':root { --mfa-primary: #7042f3; }');
        Artisan::command('vendor:publish {--tag=} {--force}', fn () => null);

        try {
            $this->artisan('mfa:install', ['--js-path' => "{$tmp}/js", '--css-path' => "{$tmp}/css"])
                ->assertSuccessful()
                ->expectsOutputToContain("Kept (changed in the app; --force replaces it): {$tmp}/css/vendor/laravel-mfa/theme.css");
            expect(file_get_contents("{$tmp}/css/vendor/laravel-mfa/theme.css"))->toBe(':root { --mfa-primary: #7042f3; }');

            $this->artisan('mfa:install', ['--js-path' => "{$tmp}/js", '--css-path' => "{$tmp}/css", '--force' => true])->assertSuccessful();
            expect(file_get_contents("{$tmp}/css/vendor/laravel-mfa/theme.css"))->toContain('--mfa-primary: var(--primary);');
        } finally {
            $files->deleteDirectory($tmp);
        }
    });

    it('refuses an unknown --tailwind', function () {
        Artisan::command('vendor:publish {--tag=} {--force}', fn () => null);

        $this->artisan('mfa:install', ['--no-ui' => true, '--tailwind' => '2'])
            ->assertFailed()
            ->expectsOutputToContain('--tailwind must be 3 or 4');
    });

    it('reads the Tailwind major from package.json', function (?string $json, ?int $major) {
        expect(InstallCommand::tailwindMajor($json))->toBe($major);
    })->with([
        'artistly' => ['{"devDependencies": {"tailwindcss": "^3.2.1"}}', 3],
        'clone-voice' => ['{"devDependencies": {"tailwindcss": "^4.0.0"}}', 4],
        'in dependencies' => ['{"dependencies": {"tailwindcss": "~4.1.16"}}', 4],
        'no Tailwind' => ['{"devDependencies": {"react": "^19.0.0"}}', null],
        'not a version' => ['{"devDependencies": {"tailwindcss": "latest"}}', null],
        'not JSON' => ['{', null],
        'no package.json' => [null, null],
    ]);
});

it('registers in php artisan about', function () {
    $this->artisan('about', ['--only' => 'mfa'])->assertSuccessful()->expectsOutputToContain('Factors');
});

describe('mfa:doctor trusted proxies (per-IP limits need the real client IP)', function () {
    afterEach(fn () => TrustProxies::flushState());

    beforeEach(fn () => config(['session.driver' => 'database', 'mfa.factors.sms.enabled' => false]));

    it('warns when no proxies are trusted', function () {
        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('No trusted proxies configured');
    });

    it('warns that trusting every proxy only works behind exactly one proxy layer', function () {
        TrustProxies::at('*');

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('Trusts all proxies');
    });

    it('is satisfied with an explicit proxy list', function () {
        TrustProxies::at(['10.0.0.0/8']);

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('Trusted proxies configured')
            ->doesntExpectOutputToContain('No trusted proxies configured');
    });
});

describe('mfa:doctor integration checks', function () {
    beforeEach(fn () => config(['session.driver' => 'database']));

    it('warns when the logout route is missing (the Sign out button is hidden)', function () {
        config(['mfa.routes.logout_route' => 'admin.logout']);

        $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('Logout route [admin.logout] does not exist');
    });

    it('fails when routes.home does not resolve', function () {
        config(['mfa.routes.home' => '/nowhere']);

        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('Home [/nowhere] resolves to a route');
    });

    it('checks password.confirm, and warns social-login apps about it (D6)', function () {
        config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
        // As if Socialite's (auto-discovered) provider were loaded. Per app, so
        // nothing leaks into other tests in the same process.
        (fn () => $this->loadedProviders['Laravel\Socialite\SocialiteServiceProvider'] = true)->call(app());

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('password.confirm middleware and route exist')
            ->expectsOutputToContain("can't pass password.confirm (routes.confirm_middleware)");
    });

    it('fails when confirm_middleware names password.confirm but the app has no such route', function () {
        config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
        app('router')->getRoutes()->getByName('password.confirm')->name('renamed');
        app('router')->getRoutes()->refreshNameLookups();

        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('or set routes.confirm_middleware to []');
    });

    it('reports the settings page\'s own password prompt, and asks social-login apps to exempt password-less users', function () {
        config(['mfa.routes.password_confirmation' => true]);
        (fn () => $this->loadedProviders['Laravel\Socialite\SocialiteServiceProvider'] = true)->call(app());

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('Password asked on the MFA settings page (routes.password_confirmation)')
            ->expectsOutputToContain('exempt the others with routes.password_confirmation_policy')
            ->doesntExpectOutputToContain('Password confirmation is off');
    });

    it('trusts a password confirmation policy to exempt social-login users, and checks the class', function () {
        config(['mfa.routes.password_confirmation' => true, 'mfa.routes.password_confirmation_policy' => ExemptSocialLogins::class]);
        (fn () => $this->loadedProviders['Laravel\Socialite\SocialiteServiceProvider'] = true)->call(app());

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('Password confirmation policy ['.ExemptSocialLogins::class.'] implements PasswordConfirmationPolicy')
            ->doesntExpectOutputToContain('Socialite is installed');

        config(['mfa.routes.password_confirmation_policy' => PlainUser::class]);
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('implements PasswordConfirmationPolicy');
    });

    it('fails when auth.password_timeout is not set (MFA reads it as is)', function () {
        config(['mfa.routes.password_confirmation' => true, 'auth.password_timeout' => null]);

        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('auth.password_timeout is set');
    });

    it('warns when password confirmation is off entirely', function () {
        config(['mfa.routes.password_confirmation' => false, 'mfa.routes.confirm_middleware' => []]);

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('Password confirmation is off (routes.password_confirmation)')
            ->doesntExpectOutputToContain('Password asked on the MFA settings page');
    });

    it('warns when Sanctum gives api routes a session that MFA does not cover', function () {
        app(Kernel::class)->prependMiddlewareToGroup('api', 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful');

        $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('Sanctum stateful API is on');
    });

    it('fails when an MFA guard uses another model than the one MFA rows belong to', function () {
        config([
            'auth.providers.plain' => ['driver' => 'eloquent', 'model' => PlainUser::class],
            'auth.guards.plain' => ['driver' => 'session', 'provider' => 'plain'],
            'mfa.guards' => ['web', 'plain'],
        ]);

        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('Guard [plain] uses the MFA user model');
    });

    it('warns about users whose factor type was disabled (fail-open, D10)', function () {
        $this->userWithFactor(FactorType::Sms);
        config(['mfa.factors.sms.enabled' => false]);

        $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('1 user(s) have a confirmed [sms] factor');
    });
});

it('warns about a log mailer in production when only security emails go out by mail', function () {
    $this->app['env'] = 'production';
    config(['mail.default' => 'log', 'mfa.factors.email.enabled' => false]);

    $this->artisan('mfa:doctor')->expectsOutputToContain('email codes and security notifications will not be delivered');

    config(['mfa.notifications.enabled' => false, 'mfa.enrollment_verification.email' => false]);
    $this->artisan('mfa:doctor')->doesntExpectOutputToContain('Mailer is "log"');
});

describe('mfa:doctor verification lifetime', function () {
    it('fails on a policy class that is not a LifetimePolicy and a bad on_expiry', function () {
        config(['session.driver' => 'database', 'mfa.factors.sms.enabled' => false, 'mfa.lifetime.policy' => ExemptSocialLogins::class]);
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('implements LifetimePolicy');

        config(['mfa.lifetime.policy' => null, 'mfa.lifetime.profiles.enforced.on_expiry' => 'signout']);
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('on_expiry is "challenge" or "logout"');
    });

    it('warns about settings that do nothing', function () {
        config(['session.driver' => 'database', 'mfa.factors.sms.enabled' => false, 'session.lifetime' => 20,
            'mfa.lifetime.profiles.default' => ['absolute' => null, 'idle' => null, 'reminder' => null, 'grace' => 10, 'on_expiry' => 'challenge']]);

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('[default] sets grace or reminder without absolute')
            ->expectsOutputToContain('[enforced] idle (25 min) is longer than session.lifetime (20 min)');
    });
});

it('shows the lifetime profile and the last revocation in mfa:status', function () {
    config(['mfa.enforcement.policy' => EnforceForAdmins::class]);
    [$admin] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);

    // As configured: the summary follows the enforced profile.
    $profile = app(VerificationLifetime::class)->profile('enforced');
    $summary = "enforced ({$profile['absolute']} min window, {$profile['idle']} min idle, {$profile['grace']} min grace"
        .($profile['on_expiry'] === 'logout' ? ', logs out' : '').')';

    $this->artisan('mfa:status', ['user' => $admin->email])->assertSuccessful()
        ->expectsOutputToContain($summary)
        ->expectsOutputToContain('never');

    Mfa::revokeVerifications($admin);
    $this->artisan('mfa:status', ['user' => $admin->email])->assertSuccessful()->expectsOutputToContain(date('Y-m-d H:i'));
});

it('fails mfa:doctor on an array cache store outside tests, and warns about a reminder longer than the window', function () {
    config(['session.driver' => 'database', 'mfa.factors.sms.enabled' => false, 'mfa.lifetime.profiles.enforced.reminder' => 300]);
    $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('reminds 300 minutes before a 240-minute window ends');

    $env = $this->app['env'];
    $this->app['env'] = 'production';
    try {
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('keeps entries between requests');
    } finally {
        $this->app['env'] = $env;
    }
});
