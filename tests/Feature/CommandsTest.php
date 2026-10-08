<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;

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

it('publishes the UI into whichever pages directory the app uses', function (string $dir) {
    // Never write into the shared Testbench skeleton: use a private temp dir,
    // and record the config publish instead of performing it.
    $files = new Filesystem;
    $js = sys_get_temp_dir().'/mfa-install-'.uniqid();
    $files->ensureDirectoryExists("{$js}/{$dir}");
    $published = [];
    Artisan::command('vendor:publish {--tag=} {--force}', function () use (&$published) {
        $published[] = $this->option('tag');
    });

    try {
        $this->artisan('mfa:install', ['--js-path' => $js])->assertSuccessful();

        expect($published)->toBe(['mfa-config'])
            ->and("{$js}/{$dir}/mfa/challenge.tsx")->toBeFile()
            ->and("{$js}/{$dir}/mfa/settings.tsx")->toBeFile()
            ->and(file_get_contents("{$js}/{$dir}/mfa/challenge.tsx"))->toBe(file_get_contents(__DIR__.'/../../stubs/inertia-react/challenge.tsx'));
    } finally {
        $files->deleteDirectory($js);
    }
})->with(['Pages', 'pages']);

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
