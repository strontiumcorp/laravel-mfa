<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\BrowserTrusted;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\TrustedBrowsersForgotten;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaTrustedBrowser;
use StrontiumCorp\LaravelMfa\Support\CodeHasher;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\EnforceForEveryone;

function macChromeAgent(): string
{
    return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
}

beforeEach(function () {
    config(['mfa.trusted_browsers.enabled' => true]);
});

/** Pass the challenge with "don't ask again" ticked; returns the cookie's name and token. */
function trustThisBrowser($test, $user, MfaFactor $factor): array
{
    $test->loginWithSession($user);
    $response = $test->withHeader('User-Agent', macChromeAgent())
        ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $test->currentTotpCode($factor), 'remember' => true])
        ->assertOk();

    $name = app(TrustedBrowsers::class)->cookieName('web', $user);
    $cookie = $response->getCookie($name, false);
    expect($cookie)->not->toBeNull();

    return [$name, $cookie->getValue()];
}

/** A new session for $user (session expired, logged back in), on a browser sending $cookies. */
function newSession($test, $user, array $cookies = []): void
{
    $test->flushSession();
    Auth::forgetGuards();
    $test->loginWithSession($user);
    foreach ($cookies as $name => $value) {
        $test->withUnencryptedCookie($name, $value);
    }
}

it('is off by default: not offered, and "remember" does nothing', function () {
    config(['mfa.trusted_browsers.enabled' => false]);
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', null);
    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();

    expect(MfaTrustedBrowser::query()->count())->toBe(0);
});

it('skips the challenge on a trusted browser after the session ends, until it expires', function () {
    Event::fake([BrowserTrusted::class, VerificationSucceeded::class]);
    $this->freezeSecond();
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);

    $browser = MfaTrustedBrowser::query()->sole();
    expect($browser->label)->toBe('Chrome on Mac')
        ->and($browser->expires_at->getTimestamp())->toBe(now()->addDays(30)->getTimestamp())
        ->and($browser->token_hash)->not->toBe($token);
    Event::assertDispatched(BrowserTrusted::class, fn ($e) => $e->context['trusted_browser_id'] === $browser->id && $e->context['label'] === 'Chrome on Mac');

    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertOk();
    expect($browser->fresh()->last_used_at)->not->toBeNull();
    Event::assertDispatched(VerificationSucceeded::class, fn ($e) => $e->context === ['via' => 'trusted_browser', 'trusted_browser_id' => $browser->id]);

    $this->travel(31)->days();
    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
});

it('sets an http-only cookie for the configured number of days', function () {
    config(['mfa.trusted_browsers.days' => 7]);
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user);

    $response = $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true]);
    $cookie = $response->getCookie(app(TrustedBrowsers::class)->cookieName('web', $user), false);

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(6)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(7)->getTimestamp() + 1);
});

it('still challenges a browser without the cookie, a forged cookie, and other users on a trusted browser', function () {
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);

    newSession($this, $user);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));

    // A forged cookie fails Laravel's decryption; one that decrypts but matches no row is removed.
    newSession($this, $user, [$name => 'forged']);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    MfaTrustedBrowser::query()->delete();
    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'))->assertCookieExpired($name);

    [$other] = $this->userWithFactor();
    newSession($this, $other, [app(TrustedBrowsers::class)->cookieName('web', $other) => $token, $name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
});

it('lets a trusted browser through the challenge page itself', function () {
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);

    newSession($this, $user, [$name => $token]);
    $this->get('/mfa/challenge')->assertRedirect('/dashboard');
});

it('ends when the password changes', function () {
    Event::fake([TrustedBrowsersForgotten::class]);
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);

    $user->forceFill(['password' => 'new-password'])->save();
    newSession($this, $user->fresh(), [$name => $token]);

    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'))->assertCookieExpired($name);
    expect(MfaTrustedBrowser::query()->count())->toBe(0);
    Event::assertDispatched(TrustedBrowsersForgotten::class, fn ($e) => $e->context['cause'] === 'password_changed');
});

it('ends for every browser when a method is added or removed, or a recovery code is used', function (Closure $change, string $cause) {
    Event::fake([TrustedBrowsersForgotten::class]);
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);

    $change($this, $user, $factor);

    expect(MfaTrustedBrowser::query()->count())->toBe(0);
    Event::assertDispatched(TrustedBrowsersForgotten::class, fn ($e) => $e->context === ['count' => 1, 'cause' => $cause]);
    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
})->with([
    'a method added' => [function ($test, $user) {
        $secret = $test->postJson('/mfa/factors', ['type' => 'totp'])->json('setup.secret');
        $id = $user->mfaFactors()->whereNull('confirmed_at')->sole()->id;
        $test->postJson("/mfa/factors/{$id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + 1)])->assertOk();
    }, 'factor_enabled'],
    'a method removed' => [function ($test, $user) {
        $test->createMfaFactor($user, FactorType::Email);
        $test->deleteJson('/mfa/factors/'.$user->mfaFactors()->where('type', 'email')->sole()->id)->assertOk();
    }, 'factor_disabled'],
    'mfa:reset' => [function ($test, $user) {
        $test->artisan('mfa:reset', ['user' => $user->id, '--force' => true])->assertSuccessful();
        $test->createMfaFactor($user); // re-enrolled (no event), so there is something to challenge
    }, 'factor_disabled'],
    'a recovery code used' => [function ($test, $user) {
        $code = app(RecoveryCodes::class)->generate($user)[0];
        $test->post('/logout');
        Auth::forgetGuards();
        $test->loginWithSession($user)->postJson('/mfa/challenge/recover', ['code' => $code])->assertOk();
    }, 'recovery_code_used'],
]);

it('is never offered after a recovery code', function () {
    [$user] = $this->userWithFactor();
    $code = app(RecoveryCodes::class)->generate($user)[0];

    $this->loginWithSession($user)->postJson('/mfa/challenge/recover', ['code' => $code, 'remember' => true])->assertOk();

    expect(MfaTrustedBrowser::query()->count())->toBe(0);
});

it('is not offered to enforced users unless allow_enforced is on', function () {
    config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', null);
    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();
    expect(MfaTrustedBrowser::query()->count())->toBe(0);

    config(['mfa.trusted_browsers.allow_enforced' => true]);
    newSession($this, $user);
    $this->travel(31)->seconds();
    [$name, $token] = trustThisBrowser($this, $user, $factor);
    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertOk();

    // Turned off again: existing trust no longer counts.
    config(['mfa.trusted_browsers.allow_enforced' => false]);
    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
});

it('still holds an enforced user without a required type on the settings page', function () {
    config(['mfa.enforcement.policy' => EnforceForEveryone::class, 'mfa.trusted_browsers.allow_enforced' => true]);
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);
    config(['mfa.enforcement.required_types' => ['sms']]);

    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
});

it('ignores trusted browsers once the feature is turned off', function () {
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);
    config(['mfa.trusted_browsers.enabled' => false]);

    newSession($this, $user, [$name => $token]);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    $this->getJson('/mfa/settings'); // unreachable while unverified
    expect(MfaTrustedBrowser::query()->count())->toBe(1);
});

it('lists them in settings with the current one marked, and forgets one or all, only the user\'s own', function () {
    Event::fake([TrustedBrowsersForgotten::class]);
    [$user, $factor] = $this->userWithFactor();
    [$other, $otherFactor] = $this->userWithFactor();
    trustThisBrowser($this, $other, $otherFactor);
    $this->post('/logout');
    $this->freshGuards();
    $this->travel(31)->seconds();
    [$name, $token] = trustThisBrowser($this, $user, $factor);
    $theirs = MfaTrustedBrowser::query()->where('user_id', $other->id)->sole();

    $this->withCredentials()->withUnencryptedCookie($name, $token)->getJson('/mfa/settings')
        ->assertJsonCount(1, 'trustedBrowsers')
        ->assertJsonPath('trustedBrowsers.0.label', 'Chrome on Mac')
        ->assertJsonPath('trustedBrowsers.0.current', true);

    $this->deleteJson("/mfa/trusted-browsers/{$theirs->id}")->assertOk();
    expect($theirs->fresh())->not->toBeNull();

    $mine = MfaTrustedBrowser::query()->where('user_id', $user->id)->sole();
    $this->deleteJson("/mfa/trusted-browsers/{$mine->id}")->assertOk();
    Event::assertDispatched(TrustedBrowsersForgotten::class, fn ($e) => $e->user->is($user) && $e->context === ['count' => 1, 'cause' => 'settings', 'trusted_browser_id' => $mine->id]);

    $this->deleteJson('/mfa/trusted-browsers')->assertOk();
    expect(MfaTrustedBrowser::query()->where('user_id', $user->id)->count())->toBe(0);
    $this->getJson('/mfa/settings')->assertJsonPath('trustedBrowsers', []);
});

it('costs no query for browsers without the cookie, nor for verified sessions', function () {
    [$user, $factor] = $this->userWithFactor();
    [$name, $token] = trustThisBrowser($this, $user, $factor);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    $this->get('/dashboard')->assertOk(); // verified
    newSession($this, $user);
    $this->get('/dashboard')->assertRedirect(route('mfa.challenge')); // no cookie

    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'mfa_trusted_browsers')))->toBeEmpty();
});

it('is pruned after it expires, goes with the user, and shows in mfa:status', function () {
    [$user, $factor] = $this->userWithFactor();
    trustThisBrowser($this, $user, $factor);

    $this->artisan('mfa:status', ['user' => $user->id])->expectsOutputToContain('Trusted browsers')->assertSuccessful();

    $this->travel(31)->days();
    $this->artisan('model:prune', ['--model' => [MfaTrustedBrowser::class]])->assertSuccessful();
    expect(MfaTrustedBrowser::query()->count())->toBe(0);

    $this->travelBack();
    [$another, $anotherFactor] = $this->userWithFactor();
    $this->post('/logout');
    $this->freshGuards();
    trustThisBrowser($this, $another, $anotherFactor);
    $another->delete();
    expect(MfaTrustedBrowser::query()->count())->toBe(0);
});

describe('the reminder before a trusted browser expires', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->get('/app-page', fn () => Mfa::context()->trustReminder);
        Route::middleware(['web', 'auth'])->get('/app-page/form', fn () => 'form');
    });

    it('shows in the last 12 hours of the trust, on a session that runs on that browser', function () {
        $this->freezeSecond();
        [$user, $factor] = $this->userWithFactor();
        [$name, $token] = trustThisBrowser($this, $user, $factor);
        $expires = now()->addDays(30);

        $this->getJson('/app-page')->assertJson(['show' => false, 'expiresAt' => null]);

        $this->travelTo($expires->copy()->subHours(12)->subSecond());
        newSession($this, $user, [$name => $token]);
        $this->get('/app-page')->assertJson(['show' => false]);

        $this->travelTo($expires->copy()->subHours(12));
        $this->get('/app-page')->assertJson(['show' => true, 'expiresAt' => $expires->toIso8601String(), 'button' => 'Verify now']);

        // A session on another browser (no trusted cookie) has nothing to renew.
        $this->post('/logout');
        newSession($this, $user);
        $this->actingAsMfaVerified($user)->get('/app-page')->assertJson(['show' => false]);
    });

    it('is hidden on MFA pages, after "Later", when off, and once the browser is forgotten', function () {
        [$user, $factor] = $this->userWithFactor();
        trustThisBrowser($this, $user, $factor);
        $this->travel(29 * 24 + 13)->hours();
        $this->get('/app-page')->assertJson(['show' => true]);

        config(['mfa.trusted_browsers.reminder.hours' => 0]);
        $this->get('/app-page')->assertJson(['show' => false]);
        config(['mfa.trusted_browsers.reminder.hours' => 12]);

        $this->postJson('/mfa/trusted-browsers/reminder/dismiss')->assertOk()->assertExactJson(['status' => 'trust-reminder-dismissed']);
        $this->get('/app-page')->assertJson(['show' => false]);

        session()->forget(TrustedBrowsers::REMINDER_DISMISSED);
        $this->get('/app-page')->assertJson(['show' => true]);
        $this->deleteJson('/mfa/trusted-browsers')->assertOk();
        $this->get('/app-page')->assertJson(['show' => false]);
    });

    it('"Verify now" passes the challenge early, trusts the browser again and returns to the page', function () {
        $this->freezeSecond();
        [$user, $factor] = $this->userWithFactor();
        [$name, $token] = trustThisBrowser($this, $user, $factor);
        $this->travel(29 * 24 + 13)->hours();

        $this->withCredentials()->withUnencryptedCookie($name, $token)->withHeader('referer', url('/app-page/form'))
            ->getJson('/mfa/challenge?renew=1')->assertOk()->assertJson(['renew' => true, 'trustBrowser' => ['days' => 30]]);

        $this->travel(31)->seconds(); // a fresh TOTP step
        $response = $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])
            ->assertOk()->assertJson(['redirect' => url('/app-page/form')]);

        $browser = MfaTrustedBrowser::query()->sole(); // the old row was replaced
        expect($browser->expires_at->getTimestamp())->toBe(now()->addDays(30)->getTimestamp())
            ->and($response->getCookie($name, false))->not->toBeNull();
        $this->get('/app-page')->assertJson(['show' => false]);
    });

    it('is not a way around the challenge: ?renew does nothing for a session that is not on a trusted browser', function () {
        [$user] = $this->userWithFactor();

        $this->actingAsMfaVerified($user)->get('/mfa/challenge?renew=1')->assertRedirect('/dashboard');
    });
});

it('names browsers from their user agent', function (?string $agent, ?string $label) {
    expect(TrustedBrowsers::label($agent))->toBe($label);
})->with([
    [macChromeAgent(), 'Chrome on Mac'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1', 'Safari on iPhone'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0', 'Edge on Windows'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Linux'],
    ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36', 'Chrome on Android'],
    ['curl/8.0', 'A browser'],
    [null, null],
]);

describe('review fixes', function () {
    it('keeps trusting browsers after an APP_KEY rotation that lists the old key in APP_PREVIOUS_KEYS', function () {
        [$user, $factor] = $this->userWithFactor();
        [$name, $token] = trustThisBrowser($this, $user, $factor);

        $old = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32)), 'app.previous_keys' => [$old]]);
        app()->forgetInstance(CodeHasher::class);

        newSession($this, $user, [$name => $token]);
        $this->get('/dashboard')->assertOk();
    });

    it('ends as soon as the password changes, not on the next use', function () {
        Event::fake([TrustedBrowsersForgotten::class]);
        [$user, $factor] = $this->userWithFactor();
        trustThisBrowser($this, $user, $factor);

        $user->forceFill(['password' => 'changed'])->save();

        expect(MfaTrustedBrowser::query()->count())->toBe(0);
        Event::assertDispatched(TrustedBrowsersForgotten::class, fn ($e) => $e->context === ['count' => 1, 'cause' => 'password_changed']);
    });

    it('ends when Laravel resets the password, and other saves leave it alone', function () {
        [$user, $factor] = $this->userWithFactor();
        trustThisBrowser($this, $user, $factor);

        $user->forceFill(['name' => 'Renamed'])->save();
        expect(MfaTrustedBrowser::query()->count())->toBe(1);

        event(new PasswordReset($user));
        expect(MfaTrustedBrowser::query()->count())->toBe(0);
    });

    it("doesn't list browsers trusted under an old password", function () {
        [$user, $factor] = $this->userWithFactor();
        trustThisBrowser($this, $user, $factor);
        // Changed without model events (e.g. a query builder update).
        DB::table('users')->where('id', $user->id)->update(['password' => bcrypt('changed')]);

        $this->freshGuards()->getJson('/mfa/settings')->assertJsonPath('trustedBrowsers', []);
    });

    it('reads the reminder from the session without asking the enforcement policy on every page', function () {
        $calls = new ArrayObject;
        app()->instance(EnforceForEveryone::class, new class($calls) implements EnforcementPolicy
        {
            public function __construct(private ArrayObject $calls) {}

            public function mustEnroll(MultiFactorAuthenticatable $user): bool
            {
                $this->calls->append(1);

                return false;
            }
        });
        config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
        $user = $this->makeUser();
        $this->actingAsMfaVerified($user); // no trusted browser in this session

        $request = request();
        $request->setLaravelSession(session()->driver());

        $before = count($calls);
        expect(app(TrustedBrowsers::class)->reminderDue($request, $user, 'web'))->toBeNull()
            ->and(count($calls))->toBe($before);
    });
});

describe('verification review fixes', function () {
    it('leaves trusted browsers alone, without a query, while the feature is off', function () {
        [$user, $factor] = $this->userWithFactor();
        trustThisBrowser($this, $user, $factor);
        config(['mfa.trusted_browsers.enabled' => false]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $user->forceFill(['password' => 'changed'])->save();
        event(new FactorDisabled($user, FactorType::Email));

        expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'mfa_trusted_browsers')))->toBeEmpty();
    });

    it("never fails the app's own save when trusted browsers can't be cleared", function () {
        Exceptions::fake();
        [$user] = $this->userWithFactor();
        Schema::drop('mfa_trusted_browsers'); // e.g. the migration hasn't run yet

        $user->forceFill(['password' => 'changed'])->save();
        event(new PasswordReset($user));

        expect($user->fresh()->password)->not->toBeNull();
        Exceptions::assertReported(QueryException::class);
    });
});
