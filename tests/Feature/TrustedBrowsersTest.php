<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
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

/** The remember-me cookie Laravel sets for $user ("id|token|password hash"). */
function rememberMeCookie($test, $user, string $guard = 'web'): void
{
    // withCredentials(): JSON test requests send no cookies without it.
    $test->withCredentials()->withCookie(Auth::guard($guard)->getRecallerName(), $user->getAuthIdentifier().'|'.str_repeat('t', 60).'|'.$user->getAuthPassword());
}

it('is on by default', function () {
    expect((require __DIR__.'/../../config/mfa.php')['trusted_browsers']['enabled'])->toBeTrue();
});

it('can be turned off: not offered, and "remember" does nothing', function () {
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
        $test->travel(1)->seconds();   // signing in again after the reset logged them out
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
    // An enforced profile without a window or idle timeout (one with either never gets trust).
    config(['mfa.enforcement.policy' => EnforceForEveryone::class, 'mfa.lifetime.profiles.enforced.absolute' => null, 'mfa.lifetime.profiles.enforced.idle' => null]);
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

describe('a browser that remembers the user (remember me)', function () {
    it('starts with "don\'t ask again" ticked, so a remembered sign-in is not challenged again the next day', function () {
        [$user] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', ['days' => 30, 'checked' => false]);

        rememberMeCookie($this, $user);
        $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', ['days' => 30, 'checked' => true]);
    });

    it('is not ticked for another user\'s remember-me cookie, a malformed one, or when turned off', function () {
        [$user] = $this->userWithFactor();
        $other = $this->makeUser();
        $this->loginWithSession($user);

        rememberMeCookie($this, $other);
        $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser.checked', false);

        $this->withCredentials()->withCookie(Auth::guard('web')->getRecallerName(), 'not-a-recaller');
        $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser.checked', false);

        config(['mfa.trusted_browsers.default_when_remembered' => false]);
        rememberMeCookie($this, $user);
        $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser.checked', false);
    });

    it('is still not offered where trust isn\'t (enforced users)', function () {
        config(['mfa.enforcement.policy' => EnforceForEveryone::class]);
        [$user] = $this->userWithFactor();
        $this->loginWithSession($user);
        rememberMeCookie($this, $user);

        $this->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', null);
    });

    it('ships on', function () {
        expect((require __DIR__.'/../../config/mfa.php')['trusted_browsers']['default_when_remembered'])->toBeTrue();
    });
});

it('still holds an enforced user without a required type on the settings page', function () {
    config(['mfa.enforcement.policy' => EnforceForEveryone::class, 'mfa.trusted_browsers.allow_enforced' => true, 'mfa.lifetime.profiles.enforced.absolute' => null, 'mfa.lifetime.profiles.enforced.idle' => null]);
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

describe('across real sign-ins: a password login, the session expiring, remember-me', function () {
    /**
     * A request from a browser holding $jar (name => [raw value as the server
     * set it, expiry timestamp or 0 for a session cookie]). Like a browser, it
     * drops cookies whose expiry has passed (on the travelled clock) before
     * sending; the cookies the response sets or deletes update $jar.
     */
    function fromBrowser($test, array &$jar, Closure $request): TestResponse
    {
        $jar = array_filter($jar, fn (array $cookie) => $cookie['expires'] === 0 || $cookie['expires'] > now()->getTimestamp());
        (fn () => $this->unencryptedCookies = [])->call($test);
        // A real request starts with nothing queued; the test app keeps one cookie jar across requests.
        app('cookie')->flushQueuedCookies();
        $response = $request($test->withCredentials()->withUnencryptedCookies(array_map(fn (array $cookie) => $cookie['value'], $jar)));

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($jar[$cookie->getName()]);
            } else {
                $jar[$cookie->getName()] = ['value' => $cookie->getValue(), 'expires' => $cookie->getExpiresTime()];
            }
        }

        return $response;
    }

    /** The app's login form: Auth::attempt() with or without "remember me". */
    function signIn($test, array &$jar, $user, bool $remember, string $password = 'password'): void
    {
        fromBrowser($test, $jar, fn ($t) => $t->post('/test-login', ['email' => $user->email, 'password' => $password, 'remember' => $remember]))->assertOk();
    }

    /** Pass the challenge, ticking "don't ask again" or not. */
    function passChallenge($test, array &$jar, $user, MfaFactor $factor, bool $trust): void
    {
        $test->travel(31)->seconds(); // a new TOTP step: each code works once
        fromBrowser($test, $jar, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
        fromBrowser($test, $jar, fn ($t) => $t->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $t->currentTotpCode($factor), 'remember' => $trust]))->assertOk();
        fromBrowser($test, $jar, fn ($t) => $t->get('/dashboard'))->assertOk();
    }

    /** Idle past session.lifetime: the server-side session is gone (the browser's other cookies stay). */
    function sessionExpires($test, array &$jar): void
    {
        $test->flushSession();
        Auth::forgetGuards();
        unset($jar[config('session.cookie')]);
    }

    beforeEach(function () {
        Route::middleware('web')->post('/test-login', function () {
            abort_unless(Auth::attempt(request()->only('email', 'password'), request()->boolean('remember')), 422);
            request()->session()->regenerate();

            return 'signed in';
        });
        // The profile page's "Sign out of other devices" (Laravel re-hashes the password for it).
        Route::middleware(['web', 'auth'])->post('/test-sign-out-other-devices', function () {
            Auth::guard('web')->logoutOtherDevices(request('password'));

            return 'signed out elsewhere';
        });
        // The profile page's password change.
        Route::middleware(['web', 'auth'])->post('/test-change-password', function () {
            Auth::user()->forceFill(['password' => request('password')])->save();

            return 'changed';
        });
    });

    it('skips the challenge when signing in again with the password on a trusted browser, without remember-me', function () {
        Event::fake([VerificationSucceeded::class]);
        [$user, $factor] = $this->userWithFactor();
        $jar = [];

        signIn($this, $jar, $user, remember: false);
        passChallenge($this, $jar, $user, $factor, trust: true);
        expect($jar)->not->toHaveKey(Auth::guard('web')->getRecallerName());

        // Two hours idle: logged out, so the password is asked again...
        $this->travel(3)->hours();
        sessionExpires($this, $jar);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('login'));

        // ...but not the code.
        signIn($this, $jar, $user, remember: false);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertOk();
        Event::assertDispatched(VerificationSucceeded::class, fn ($e) => ($e->context['via'] ?? null) === 'trusted_browser');

        // The next day too.
        $this->travel(1)->day();
        sessionExpires($this, $jar);
        signIn($this, $jar, $user, remember: false);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertOk();
    });

    it('skips the challenge when remember-me logs the user back in on a trusted browser', function () {
        [$user, $factor] = $this->userWithFactor();
        $jar = [];

        signIn($this, $jar, $user, remember: true);
        passChallenge($this, $jar, $user, $factor, trust: true);

        $this->travel(1)->day();
        sessionExpires($this, $jar);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertOk();
    });

    it('challenges each new remember-me session when "don\'t ask again" was unticked (why it starts ticked)', function () {
        [$user, $factor] = $this->userWithFactor();
        $jar = [];

        signIn($this, $jar, $user, remember: true);
        fromBrowser($this, $jar, fn ($t) => $t->getJson('/mfa/challenge'))->assertJsonPath('trustBrowser.checked', true);
        passChallenge($this, $jar, $user, $factor, trust: false);

        $this->travel(1)->day();
        sessionExpires($this, $jar);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
    });

    it('skips the challenge on every day of the trust, then asks again when it ends', function (int $days, bool $remember) {
        config(['mfa.trusted_browsers.days' => $days]);
        $this->freezeSecond();
        Event::fake([BrowserTrusted::class, VerificationSucceeded::class]);
        [$user, $factor] = $this->userWithFactor();
        $jar = [];

        signIn($this, $jar, $user, $remember);
        passChallenge($this, $jar, $user, $factor, trust: true);
        $trustedAt = now()->toImmutable();

        // The next session, $at after trusting: logged back in by remember-me, or with the password.
        $nextSession = function ($at) use (&$jar, $user, $remember): TestResponse {
            $this->travelTo($at);
            sessionExpires($this, $jar);
            if (! $remember) {
                signIn($this, $jar, $user, remember: false);
            }

            return fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'));
        };

        for ($day = 1; $day < $days; $day++) {
            $nextSession($trustedAt->addDays($day))->assertOk();
        }
        // The last minute of it still counts...
        $nextSession($trustedAt->addDays($days)->subMinute())->assertOk();
        Event::assertDispatchedTimes(VerificationSucceeded::class, 1 + $days);
        Event::assertDispatchedTimes(BrowserTrusted::class, 1); // never renewed behind the user's back

        // ...and when it ends, the code is asked again.
        $nextSession($trustedAt->addDays($days))->assertRedirect(route('mfa.challenge'));
    })->with([
        '30 days (the default), password each day' => [30, false],
        '30 days (the default), remember-me' => [30, true],
        'trusted_browsers.days = 7, password each day' => [7, false],
    ]);

    it('ends on the server too, if the browser keeps sending the cookie after it expired', function () {
        $this->freezeSecond();
        [$user, $factor] = $this->userWithFactor();
        $jar = [];

        signIn($this, $jar, $user, remember: false);
        passChallenge($this, $jar, $user, $factor, trust: true);
        $name = app(TrustedBrowsers::class)->cookieName('web', $user);
        $jar[$name]['expires'] = 0; // a browser (or a copied cookie) that ignores its expiry

        $this->travel(30)->days();
        sessionExpires($this, $jar);
        signIn($this, $jar, $user, remember: false);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
        expect($jar)->not->toHaveKey($name); // and the server deletes it
    });

    /** Two browsers the user trusted: $laptop, then $phone (the one they act from), on day 0. */
    function twoTrustedBrowsers($test, $user, MfaFactor $factor, ?array &$laptop, ?array &$phone, bool $trustPhone = true): void
    {
        $laptop = $phone = [];
        signIn($test, $laptop, $user, remember: false);
        passChallenge($test, $laptop, $user, $factor, trust: true);
        sessionExpires($test, $laptop);
        signIn($test, $phone, $user, remember: false);
        passChallenge($test, $phone, $user, $factor, trust: $trustPhone);
    }

    it('keeps trusting the browser that pressed "sign out of other devices", and only that one', function () {
        $this->freezeSecond();
        Event::fake([BrowserTrusted::class, TrustedBrowsersForgotten::class]);
        [$user, $factor] = $this->userWithFactor();
        twoTrustedBrowsers($this, $user, $factor, $laptop, $phone);
        $trustedAt = now()->toImmutable();

        fromBrowser($this, $phone, fn ($t) => $t->post('/test-sign-out-other-devices', ['password' => 'password']))->assertOk();
        Event::assertDispatched(TrustedBrowsersForgotten::class, fn ($e) => $e->context['count'] === 2 && $e->context['cause'] === 'password_changed');
        Event::assertDispatched(BrowserTrusted::class, fn ($e) => ($e->context['via'] ?? null) === 'other_devices_signed_out');
        expect(MfaTrustedBrowser::query()->count())->toBe(1);

        // The next day: the phone still skips the code, the laptop is asked.
        $this->travelTo($trustedAt->addDay());
        sessionExpires($this, $phone);
        signIn($this, $phone, $user, remember: false);
        fromBrowser($this, $phone, fn ($t) => $t->get('/dashboard'))->assertOk();

        sessionExpires($this, $laptop);
        signIn($this, $laptop, $user, remember: false);
        fromBrowser($this, $laptop, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));

        // Not extended: it ends when it would have.
        $this->travelTo($trustedAt->addDays(30)->subMinute());
        sessionExpires($this, $phone);
        signIn($this, $phone, $user, remember: false);
        fromBrowser($this, $phone, fn ($t) => $t->get('/dashboard'))->assertOk();
        $this->travelTo($trustedAt->addDays(30));
        sessionExpires($this, $phone);
        signIn($this, $phone, $user, remember: false);
        fromBrowser($this, $phone, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
    });

    it('trusts no browser after "sign out of other devices" when the one that pressed it was not trusted', function () {
        [$user, $factor] = $this->userWithFactor();
        twoTrustedBrowsers($this, $user, $factor, $laptop, $phone, trustPhone: false);

        fromBrowser($this, $phone, fn ($t) => $t->post('/test-sign-out-other-devices', ['password' => 'password']))->assertOk();

        expect(MfaTrustedBrowser::query()->count())->toBe(0);
        sessionExpires($this, $phone);
        signIn($this, $phone, $user, remember: false);
        fromBrowser($this, $phone, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
    });

    it('still ends every browser, the current one too, on a real password change (not "sign out of other devices")', function () {
        [$user, $factor] = $this->userWithFactor();
        twoTrustedBrowsers($this, $user, $factor, $laptop, $phone);

        fromBrowser($this, $phone, fn ($t) => $t->post('/test-change-password', ['password' => 'a-new-password']))->assertOk();

        expect(MfaTrustedBrowser::query()->count())->toBe(0);
        sessionExpires($this, $phone);
        signIn($this, $phone, $user, remember: false, password: 'a-new-password');
        fromBrowser($this, $phone, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
    });

    it('still challenges the same user in a browser without the cookie (cleared cookies, a private window)', function () {
        [$user, $factor] = $this->userWithFactor();
        $jar = [];

        signIn($this, $jar, $user, remember: false);
        passChallenge($this, $jar, $user, $factor, trust: true);

        sessionExpires($this, $jar);
        $jar = [];
        signIn($this, $jar, $user, remember: false);
        fromBrowser($this, $jar, fn ($t) => $t->get('/dashboard'))->assertRedirect(route('mfa.challenge'));
    });
});

describe('the reminder before a trusted browser expires', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->get('/app-page', fn () => Mfa::context()->reverifyReminder);
        Route::middleware(['web', 'auth'])->get('/app-page/form', fn () => 'form');
    });

    it('shows in the last 12 hours of the trust, on a session that runs on that browser', function () {
        $this->freezeSecond();
        [$user, $factor] = $this->userWithFactor();
        [$name, $token] = trustThisBrowser($this, $user, $factor);
        $expires = now()->addDays(30);

        // Not due: hidden, with its deadlines so an open page can show it later.
        $this->getJson('/app-page')->assertJson(['show' => false, 'expiresAt' => $expires->toIso8601String()]);

        $this->travelTo($expires->copy()->subHours(12)->subSecond());
        newSession($this, $user, [$name => $token]);
        // Not due yet, but an open page is told when it will be.
        $this->get('/app-page')->assertJson(['show' => false, 'reason' => 'trust', 'showAt' => $expires->copy()->subHours(12)->toIso8601String()]);

        $this->travelTo($expires->copy()->subHours(12));
        $this->get('/app-page')->assertJson(['show' => true, 'reason' => 'trust', 'expiresAt' => $expires->toIso8601String(), 'showAt' => $expires->copy()->subHours(12)->toIso8601String(), 'button' => 'Verify now']);

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

        $this->postJson('/mfa/reminder/dismiss')->assertOk()->assertExactJson(['status' => 'reminder-dismissed']);
        $this->get('/app-page')->assertJson(['show' => false]);

        session()->forget(StrontiumCorp\LaravelMfa\Mfa::REMINDER_DISMISSED);
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
    // Browsers built on Chrome that name themselves in the user agent.
    ['Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet on Android'],
    ['Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36 EdgA/129.0', 'Edge on Android'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/129.0 Mobile/15E148 Safari/605.1.15', 'Edge on iPhone'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0 Mobile/15E148 Safari/604.1', 'Chrome on iPhone'],
    ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15', 'Firefox on iPad'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 OPR/114.0', 'Opera on Windows'],
    ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Vivaldi/6.9', 'Vivaldi on Linux'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 YaBrowser/24.10 Safari/537.36', 'Yandex Browser on Windows'],
    ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 'Chrome on ChromeOS'],
    ['Mozilla/5.0 (X11; Ubuntu; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 'Chrome on Linux'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'Safari on Mac'],
]);

it('prefers the client hints Chrome-based browsers send over the user agent string', function (string $agent, array $hints, string $label) {
    expect(TrustedBrowsers::label($agent, $hints))->toBe($label);
})->with([
    // Brave and other Chromium browsers send Chrome's user agent; only the hints name them.
    'Brave' => [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        ['sec-ch-ua' => '"Brave";v="152", "Chromium";v="152", "Not)A;Brand";v="99"', 'sec-ch-ua-platform' => '"Linux"'],
        'Brave on Linux',
    ],
    'Chrome on a reduced user agent' => [
        'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36',
        ['sec-ch-ua' => '"Not)A;Brand";v="8", "Chromium";v="152", "Google Chrome";v="152"', 'sec-ch-ua-platform' => '"Android"'],
        'Chrome on Android',
    ],
    'Edge on a Mac' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 Edg/152.0',
        ['sec-ch-ua' => '"Microsoft Edge";v="152", "Chromium";v="152", "Not-A.Brand";v="24"', 'sec-ch-ua-platform' => '"macOS"'],
        'Edge on Mac',
    ],
    'ChromeOS' => [
        'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        ['sec-ch-ua' => '"Google Chrome";v="152", "Chromium";v="152"', 'sec-ch-ua-platform' => '"Chrome OS"'],
        'Chrome on ChromeOS',
    ],
    'Chromium alone' => [
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        ['sec-ch-ua' => '"Chromium";v="152", "Not A(Brand";v="24"', 'sec-ch-ua-platform' => '"Linux"'],
        'Chromium on Linux',
    ],
    'unknown platform: the user agent decides' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        ['sec-ch-ua' => '"Google Chrome";v="152"', 'sec-ch-ua-platform' => '"Unknown"'],
        'Chrome on Windows',
    ],
    'garbage hints are ignored' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        ['sec-ch-ua' => '<script>', 'sec-ch-ua-platform' => '"'.str_repeat('x', 200).'"'],
        'Chrome on Windows',
    ],
]);

it('names a trusted browser from the request, client hints included', function () {
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user)->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        'Sec-CH-UA' => '"Brave";v="152", "Chromium";v="152", "Not)A;Brand";v="99"',
        'Sec-CH-UA-Platform' => '"Linux"',
    ])->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();

    expect(MfaTrustedBrowser::query()->sole()->label)->toBe('Brave on Linux');
});

it('adds the user_agent column to a table made by the old create migration, and nothing when it is there', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_10_11_000001_add_user_agent_to_mfa_trusted_browsers_table.php';

    $migration->up(); // fresh install: the create migration made it already
    expect(Schema::hasColumn('mfa_trusted_browsers', 'user_agent'))->toBeTrue();

    Schema::table('mfa_trusted_browsers', fn ($table) => $table->dropColumn('user_agent')); // as before this release
    $migration->up();
    expect(Schema::hasColumn('mfa_trusted_browsers', 'user_agent'))->toBeTrue();
});

it('keeps the raw user agent with the browser, capped in length', function () {
    [$user, $factor] = $this->userWithFactor();
    $agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36 '.str_repeat('x', 2000);
    $this->loginWithSession($user)->withHeader('User-Agent', $agent)
        ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();

    $browser = MfaTrustedBrowser::query()->sole();
    expect($browser->user_agent)->toBe(mb_substr($agent, 0, 1024))
        ->and($browser->label)->toBe('Chrome on Linux');
});

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
        expect(app(TrustedBrowsers::class)->reminderWindow($request, $user, 'web'))->toBeNull()
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
