<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
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

it('publishes the UI into whichever pages and components directories the app uses', function (string $dir, string $components) {
    // Never write into the shared Testbench skeleton: use a private temp dir,
    // and record the config publish instead of performing it.
    $files = new Filesystem;
    $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
    $files->ensureDirectoryExists("{$js}/{$dir}");
    // artistly has both components/ and Components/; the pages casing wins.
    $files->ensureDirectoryExists("{$js}/components");
    $files->ensureDirectoryExists("{$js}/Components");
    $published = [];
    Artisan::command('vendor:publish {--tag=} {--force}', function () use (&$published) {
        $published[] = $this->option('tag');
    });

    try {
        $this->artisan('mfa:install', ['--js-path' => $js])->assertSuccessful();

        expect($published)->toBe(['mfa-config'])
            ->and("{$js}/{$dir}/mfa/challenge.tsx")->toBeFile()
            ->and("{$js}/{$dir}/mfa/settings.tsx")->toBeFile()
            ->and(file_get_contents("{$js}/{$dir}/mfa/challenge.tsx"))->toBe(file_get_contents(__DIR__.'/../../stubs/inertia-react/challenge.tsx'))
            ->and("{$js}/{$components}/mfa/mfa-context.ts")->toBeFile()
            ->and("{$js}/{$components}/mfa/api-key-notice.tsx")->toBeFile()
            ->and("{$js}/{$dir}/mfa/components")->not->toBeDirectory();
    } finally {
        $files->deleteDirectory($js);
    }
})->with([
    'Pages + Components (artistly)' => ['Pages', 'Components'],
    'pages + components' => ['pages', 'components'],
]);

it('does not overwrite customised pages unless forced, and can skip the UI', function () {
    $files = new Filesystem;
    $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
    $files->ensureDirectoryExists("{$js}/pages/mfa");
    $files->put("{$js}/pages/mfa/challenge.tsx", 'customised');
    Artisan::command('vendor:publish {--tag=} {--force}', fn () => null);

    try {
        $this->artisan('mfa:install', ['--js-path' => $js])->assertSuccessful()->expectsOutputToContain('Skipped (exists)');
        expect(file_get_contents("{$js}/pages/mfa/challenge.tsx"))->toBe('customised');

        $this->artisan('mfa:install', ['--js-path' => $js, '--force' => true])->assertSuccessful();
        expect(file_get_contents("{$js}/pages/mfa/challenge.tsx"))->not->toBe('customised');

        $files->deleteDirectory("{$js}/pages/mfa");
        $this->artisan('mfa:install', ['--js-path' => $js, '--no-ui' => true])->assertSuccessful();
        expect("{$js}/pages/mfa")->not->toBeDirectory();
    } finally {
        $files->deleteDirectory($js);
    }
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
        if (! class_exists('Laravel\Socialite\SocialiteServiceProvider')) {
            // PHP 8.2 can't alias internal classes, so alias a fixture class.
            class_alias(PlainUser::class, 'Laravel\Socialite\SocialiteServiceProvider');
        }

        $this->artisan('mfa:doctor')->assertSuccessful()
            ->expectsOutputToContain('password.confirm middleware and route exist')
            ->expectsOutputToContain('Socialite is installed');
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
