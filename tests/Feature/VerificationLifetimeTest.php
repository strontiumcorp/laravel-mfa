<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\TrustedBrowsersForgotten;
use StrontiumCorp\LaravelMfa\Events\VerificationExpired;
use StrontiumCorp\LaravelMfa\Events\VerificationsRevoked;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Exceptions\ImpersonationNotAllowed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaTrustedBrowser;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\ConfigMerge;
use StrontiumCorp\LaravelMfa\Support\Revocations;
use StrontiumCorp\LaravelMfa\Support\TrustedBrowsers;
use StrontiumCorp\LaravelMfa\Support\VerificationLifetime;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\CountingCacheStore;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\FailingCacheStore;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\SilentlyFailingCacheStore;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\SupportStaffLifetime;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\WriteFailingCacheStore;

beforeEach(function () {
    $this->freezeSecond();
    // Idle off unless a test is about it: most travel hours at a time.
    config(['mfa.enforcement.policy' => EnforceForAdmins::class, 'mfa.lifetime.profiles.enforced.idle' => null]);

    // An enforced admin who just passed the challenge (TOTP).
    $this->verifiedAdmin = function (): array {
        [$admin, $factor] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $this->loginWithSession($admin)
            ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertOk();

        return [$admin, $factor];
    };

    Route::middleware(['web', 'auth'])->group(function () {
        Route::post('/form', fn () => 'saved');
        Route::post('/export', fn () => 'exported')->name('export');
        Route::post('/autosave', fn () => 'autosaved')->name('autosave');
        Route::get('/context', fn () => Mfa::context());
    });
});

function inertiaVisit($test, string $uri, array $headers = [])
{
    $response = $test->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', ...$headers])->get($uri);
    $test->flushHeaders(); // withHeaders() would stick to every later request

    return $response;
}

describe('profiles', function () {
    it('gives enforced users a fixed window and everyone else none, by default', function () {
        $settings = app(VerificationLifetime::class);
        $defaults = (require __DIR__.'/../../config/mfa.php')['lifetime']['profiles'];

        expect($defaults['enforced'])->toMatchArray(['absolute' => 240, 'idle' => 25, 'reminder' => 30, 'grace' => 10, 'on_expiry' => 'challenge'])
            ->and($defaults['default'])->toMatchArray(['absolute' => null, 'idle' => null, 'grace' => null])
            ->and($settings->profileFor($this->makeUser(['is_admin' => true])))->toBe('enforced')
            ->and($settings->profileFor($this->makeUser()))->toBe('default');
    });

    it('reads env strings, and treats 0 or nonsense as off', function () {
        config(['mfa.lifetime.profiles.enforced' => ['absolute' => '240', 'idle' => '0', 'reminder' => 'x', 'grace' => '-5', 'on_expiry' => 'nonsense']]);

        expect(app(VerificationLifetime::class)->profile('enforced'))
            ->toBe(['absolute' => 240, 'idle' => null, 'reminder' => null, 'grace' => 0, 'on_expiry' => 'challenge']);
    });

    it('asks lifetime.policy, and falls back to default for an unknown profile', function () {
        config([
            'mfa.lifetime.policy' => SupportStaffLifetime::class,
            'mfa.lifetime.profiles.support' => ['absolute' => 60, 'idle' => null, 'reminder' => null, 'grace' => null, 'on_expiry' => 'challenge'],
        ]);
        $settings = app(VerificationLifetime::class);

        expect($settings->profileFor($this->makeUser(['is_admin' => true])))->toBe('support');

        config(['mfa.lifetime.profiles.support' => null]);
        expect($settings->profileFor($this->makeUser(['is_admin' => true])))->toBe('default');
    });

    it('leaves default users verified for the whole session, as before', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);

        $this->travel(30)->days();
        $this->get('/dashboard')->assertOk();
        $this->getJson('/context')->assertJsonPath('verification', null);
    });
});

describe('the absolute window', function () {
    it('lasts exactly the window, however active the user is (not sliding)', function () {
        Event::fake([VerificationExpired::class]);
        ($this->verifiedAdmin)();

        for ($minute = 0; $minute < 240; $minute += 20) {
            $this->get('/dashboard')->assertOk();
            $this->travel(20)->minutes();
        }

        // 4:00:00: the grace period begins, and a page visit is challenged.
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
        Event::assertDispatched(VerificationExpired::class, fn ($e) => $e->context['cause'] === 'absolute'
            && $e->context['profile'] === 'enforced' && $e->context['verified_for'] === 240 * 60 && $e->context['on_expiry'] === 'challenge');
    });

    it('lets what the user was doing finish during the grace period, and challenges every request after it', function () {
        ($this->verifiedAdmin)();
        $this->travel(240)->minutes();

        $this->post('/form')->assertOk()->assertSee('saved');
        $this->getJson('/api/me')->assertOk();                                            // a background fetch
        inertiaVisit($this, '/dashboard', ['X-Inertia-Partial-Data' => 'stats'])->assertOk(); // a partial reload / poll
        inertiaVisit($this, '/dashboard', ['Purpose' => 'prefetch'])->assertOk();

        $this->travel(10 * 60 - 1)->seconds();
        $this->post('/form')->assertOk();

        $this->travel(1)->seconds();
        $this->getJson('/api/me')->assertForbidden()->assertJson(['error' => 'mfa_required', 'reason' => 'absolute']);
        $this->post('/form')->assertRedirect(route('mfa.challenge'))->assertStatus(303);
    });

    it('challenges a page visit in the grace period, Inertia ones included, and returns there after the challenge', function () {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(241)->minutes();

        inertiaVisit($this, '/dashboard')->assertRedirect(route('mfa.challenge'));

        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();
        $this->post('/form')->assertOk(); // a new window
        $this->travel(239)->minutes();
        $this->get('/dashboard')->assertOk();
    });

    it('gives no grace to routes in lifetime.no_grace, nor when grace is off', function () {
        config(['mfa.lifetime.no_grace' => ['export']]);
        ($this->verifiedAdmin)();
        $this->travel(240)->minutes();

        $this->post('/export')->assertRedirect(route('mfa.challenge'));

        $this->flushSession();
        config(['mfa.lifetime.profiles.enforced.grace' => null]);
        ($this->verifiedAdmin)();
        $this->travel(240)->minutes();
        $this->post('/form')->assertRedirect(route('mfa.challenge'));
    });

    it("can't be brought back by a request that writes an older copy of the session", function () {
        ($this->verifiedAdmin)();
        $old = session()->all();
        $this->travel(251)->minutes();
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));

        session()->put($old); // a concurrent request saving the session it read earlier
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('counts a session verified before lifetimes existed from when it was verified', function () {
        $admin = $this->makeUser(['is_admin' => true]);
        $this->createMfaFactor($admin);
        $this->loginWithSession($admin);
        session()->put(Mfa::sessionKey('web', $admin->id), now()->subHours(5)->getTimestamp());

        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('gives an impersonated user their own profile, from the moment of the grant', function () {
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser(['is_admin' => true]);
        $this->createMfaFactor($target);

        $this->post("/admin/switch/{$target->id}", ['grant' => 1])->assertRedirect('/dashboard');
        expect(session(VerificationLifetime::SESSION_KEY.'.web.'.$target->id))->toMatchArray(['profile' => 'enforced', 'until' => now()->addHours(4)->getTimestamp()]);
    });
});

describe('the idle timeout', function () {
    beforeEach(fn () => config(['mfa.lifetime.profiles.enforced.idle' => 25]));

    it('ends the verification after 25 minutes without activity, with no grace', function () {
        ($this->verifiedAdmin)();

        $this->travel(24 * 60 + 59)->seconds();
        $this->get('/dashboard')->assertOk();

        $this->travel(25)->minutes();
        $this->post('/form')->assertRedirect(route('mfa.challenge'));
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('reason', null); // already ended above
    });

    it('answers JSON with the reason', function () {
        ($this->verifiedAdmin)();
        $this->travel(25)->minutes();

        $this->getJson('/api/me')->assertForbidden()->assertJson(['error' => 'mfa_required', 'reason' => 'idle']);
    });

    it("isn't kept alive by background requests or the deadline lookup, only by the user", function () {
        ($this->verifiedAdmin)();

        $this->travel(20)->minutes();
        $this->getJson('/api/me')->assertOk();
        inertiaVisit($this, '/dashboard', ['X-Inertia-Partial-Data' => 'stats'])->assertOk();
        $this->getJson('/mfa/session')->assertOk()->assertJsonPath('verification.idleSeconds', 1500);
        $this->travel(5)->minutes();
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('counts page visits, Inertia visits, form submits and "Stay signed in", but not lifetime.idle_ignore', function () {
        config(['mfa.lifetime.idle_ignore' => ['autosave']]);
        ($this->verifiedAdmin)();

        foreach ([fn () => $this->get('/dashboard'), fn () => inertiaVisit($this, '/dashboard'), fn () => $this->post('/form'), fn () => $this->postJson('/mfa/session/keep-alive')->assertNoContent()] as $activity) {
            $this->travel(20)->minutes();
            $activity()->assertSuccessful();
        }

        $this->travel(20)->minutes();
        $this->post('/autosave')->assertOk();
        $this->travel(5)->minutes();
        $this->post('/autosave')->assertRedirect(route('mfa.challenge'));
    });

    it('keeps the deadline lookup and keep-alive reachable for an enforced user still enrolling', function () {
        config(['mfa.enforcement.required_types' => ['sms']]);
        ($this->verifiedAdmin)();

        $this->get('/dashboard')->assertRedirect(route('mfa.settings'));
        $this->getJson('/mfa/session')->assertOk();
        $this->postJson('/mfa/session/keep-alive')->assertNoContent();
    });
});

describe('on_expiry', function () {
    beforeEach(fn () => config(['mfa.lifetime.profiles.enforced.idle' => 25]));

    it('logs the user out instead, remember-me included, when set to "logout"', function () {
        config(['mfa.lifetime.profiles.enforced.on_expiry' => 'logout']);
        [$admin] = ($this->verifiedAdmin)();
        $admin->forceFill(['remember_token' => 'remember-me-token'])->save();
        $this->travel(251)->minutes();

        $this->get('/dashboard')->assertRedirect(route('login'));

        expect(Auth::guard('web')->check())->toBeFalse()
            ->and(session()->has(Auth::guard('web')->getName()))->toBeFalse()
            ->and($admin->fresh()->remember_token)->not->toBe('remember-me-token'); // Laravel's logout cycles it
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it('answers JSON 401 when logging out', function () {
        config(['mfa.lifetime.profiles.enforced.on_expiry' => 'logout']);
        ($this->verifiedAdmin)();
        $this->travel(25)->minutes();

        $this->getJson('/api/me')->assertUnauthorized()->assertJson(['error' => 'mfa_session_ended', 'reason' => 'idle', 'redirect' => route('login')]);
    });
});

describe('the reminder and verifying early', function () {
    it('tells an open page when to remind, shows it in the last 30 minutes and through the grace period', function () {
        config(['mfa.lifetime.profiles.enforced.idle' => 25]);
        ($this->verifiedAdmin)();
        $until = now()->addHours(4);

        $this->getJson('/context')
            ->assertJsonPath('reverifyReminder.show', false)
            ->assertJsonPath('reverifyReminder.reason', 'lifetime')
            ->assertJsonPath('reverifyReminder.showAt', $until->copy()->subMinutes(30)->toIso8601String())
            ->assertJsonPath('reverifyReminder.title', 'Two-factor check coming up')
            ->assertJsonPath('verification', [
                'profile' => 'enforced',
                'now' => now()->toIso8601String(),
                'expiresAt' => $until->toIso8601String(),
                'remindAt' => $until->copy()->subMinutes(30)->toIso8601String(),
                'graceUntil' => $until->copy()->addMinutes(10)->toIso8601String(),
                'idleSeconds' => 1500,
                'idleExpiresAt' => now()->addMinutes(25)->toIso8601String(),
                'renewUrl' => route('mfa.challenge', ['renew' => 1]),
                'keepAliveUrl' => route('mfa.session.keep-alive'),
                'stateUrl' => route('mfa.session'),
            ]);

        // Active every 15 minutes up to 4:05 (in the grace period).
        for ($minute = 15; $minute <= 245; $minute += 15) {
            $this->travel(15)->minutes();
            $this->post('/form')->assertOk();
            if ($minute === 210) {
                $this->getJson('/context')->assertJsonPath('reverifyReminder.show', true);
            }
        }
        $this->getJson('/context')->assertJsonPath('reverifyReminder.show', true);

        $this->postJson('/mfa/reminder/dismiss')->assertOk();
        $this->getJson('/context')->assertJsonPath('reverifyReminder.show', false);
    });

    it('"Verify now" verifies early and starts a new window from then', function () {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(220)->minutes();
        $this->get('/dashboard')->assertOk();

        $this->withHeader('referer', url('/dashboard'))->getJson('/mfa/challenge?renew=1')->assertOk()
            ->assertJson(['renew' => true, 'trustBrowser' => null]);
        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertOk()->assertJson(['redirect' => url('/dashboard')]);

        $this->travel(239)->minutes();
        $this->get('/dashboard')->assertOk();
    });

    it('is no way around a challenge: ?renew does nothing for a session without a window', function () {
        [$user] = $this->userWithFactor();

        $this->actingAsMfaVerified($user)->get('/mfa/challenge?renew=1')->assertRedirect('/dashboard');
    });
});

describe('trusted browsers', function () {
    it('are offered to default users and never to users with a fixed window', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user)->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', ['days' => 30]);

        $this->flushSession();
        Auth::forgetGuards();
        config(['mfa.trusted_browsers.allow_enforced' => true]); // still not: the window wins
        [$admin, $adminFactor] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $this->loginWithSession($admin)->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', null);
        $this->postJson('/mfa/challenge', ['factor_id' => $adminFactor->id, 'code' => $this->currentTotpCode($adminFactor), 'remember' => true])->assertOk();

        expect(MfaTrustedBrowser::query()->count())->toBe(0);
    });

    it('are not honoured for a user whose profile has a fixed window', function () {
        config(['mfa.lifetime.profiles.enforced.absolute' => null, 'mfa.trusted_browsers.allow_enforced' => true]);
        [$admin, $factor] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $response = $this->loginWithSession($admin)
            ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();
        $name = app(TrustedBrowsers::class)->cookieName('web', $admin);
        $token = $response->getCookie($name, false)->getValue();

        config(['mfa.lifetime.profiles.enforced.absolute' => 240]);
        $this->flushSession();
        Auth::forgetGuards();
        $this->loginWithSession($admin)->withUnencryptedCookie($name, $token)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });
});

describe('revocation', function () {
    it('logs another session out on its very next request when an administrator resets MFA', function () {
        Event::fake([VerificationsRevoked::class, VerificationExpired::class, FactorDisabled::class]);
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);
        $this->get('/dashboard')->assertOk();

        $this->travel(1)->seconds();
        expect(Mfa::reset($user, 'admin:7'))->toBe(1);

        // Not enforced, no factor left: without the logout this session would walk on.
        $this->get('/dashboard')->assertRedirect(route('login'));
        expect(Auth::guard('web')->check())->toBeFalse()
            ->and(MfaFactor::query()->count())->toBe(0);
        Event::assertDispatched(VerificationExpired::class, fn ($e) => $e->context === ['cause' => 'revoked', 'on_expiry' => 'logout']);
        Event::assertDispatched(VerificationsRevoked::class, fn ($e) => $e->context === ['by' => 'admin:7', 'by_administrator' => true, 'logout' => true]);
        Event::assertDispatched(FactorDisabled::class, fn ($e) => $e->context['via'] === 'admin:7');

        Mfa::revokeVerifications($user, 'suspension', byAdministrator: false);
        Event::assertDispatched(VerificationsRevoked::class, fn ($e) => $e->context === ['by' => 'suspension']);
    });

    it('logs out sessions still waiting at the challenge too (a password-only attacker), and lets the owner sign in again', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
        $this->travel(1)->seconds();

        Mfa::reset($user);
        $this->getJson('/api/me')->assertUnauthorized()->assertJson(['error' => 'mfa_session_ended', 'reason' => 'revoked']);

        $this->travel(1)->seconds();
        Auth::forgetGuards();
        $this->loginWithSession($user)->get('/dashboard')->assertOk(); // no factor now, not enforced
    });

    it('logs out sessions that logged in before login times were recorded', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);
        session()->forget(StrontiumCorp\LaravelMfa\Mfa::LOGIN_AT_PREFIX);
        $this->travel(1)->seconds();

        Mfa::reset($user);
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it("doesn't log anyone out for revokeVerifications() alone", function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);
        $this->travel(1)->seconds();

        Mfa::revokeVerifications($user);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
        expect(Auth::guard('web')->check())->toBeTrue();
    });

    it('challenges at once, with no grace, a user who still has MFA (revokeVerifications)', function () {
        [$admin] = ($this->verifiedAdmin)();
        $this->travel(245)->minutes(); // in the grace period
        $this->post('/form')->assertOk();

        Mfa::revokeVerifications($admin);
        $this->getJson('/api/me')->assertForbidden()->assertJson(['reason' => 'revoked']);
        $this->post('/form')->assertRedirect(route('mfa.challenge'));
    });

    it('holds an enforced user who lost every factor on the enrollment page once they sign in again', function () {
        [$admin] = ($this->verifiedAdmin)();
        $this->travel(1)->seconds();
        Mfa::reset($admin);
        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->travel(1)->seconds();
        Auth::forgetGuards();
        $this->loginWithSession($admin)->get('/dashboard')->assertRedirect(route('mfa.settings'));
    });

    it('lets the next verification through', function () {
        [$admin, $factor] = ($this->verifiedAdmin)();
        Mfa::revokeVerifications($admin);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));

        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();
        $this->get('/dashboard')->assertOk();
    });

    it('forgets their trusted browsers, or one would verify them again at once', function () {
        Event::fake([TrustedBrowsersForgotten::class]);
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user)
            ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();
        expect(MfaTrustedBrowser::query()->count())->toBe(1);

        Mfa::revokeVerifications($user);

        expect(MfaTrustedBrowser::query()->count())->toBe(0);
        Event::assertDispatched(TrustedBrowsersForgotten::class, fn ($e) => $e->context['cause'] === 'verifications_revoked');
    });

    it('is done by mfa:reset', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);
        $this->travel(1)->seconds();

        $this->artisan('mfa:reset', ['user' => $user->id, '--force' => true])->assertSuccessful();

        expect((array) DB::table('mfa_revocations')->where('user_id', $user->id)->first(['revoked_at', 'logged_out_at']))
            ->toEqual(['revoked_at' => now()->getTimestamp(), 'logged_out_at' => now()->getTimestamp()]);
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it('survives a cleared cache', function () {
        [$admin] = ($this->verifiedAdmin)();
        Mfa::revokeVerifications($admin);
        Cache::flush();

        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('falls back to the table when the cache fails, and never lets a request through unchecked when both fail', function () {
        [$admin] = ($this->verifiedAdmin)();
        Mfa::revokeVerifications($admin);
        $this->travel(1)->seconds();
        [$other] = $this->userWithFactor();
        $this->flushSession();
        Auth::forgetGuards();
        $this->actingAsMfaVerified($other);

        Cache::extend('failing', fn () => Cache::repository(new FailingCacheStore));
        config(['cache.stores.failing' => ['driver' => 'failing'], 'mfa.cache.store' => 'failing']);

        $this->get('/dashboard')->assertOk(); // read from the table
        expect(app(Revocations::class)->stamps($admin->id)['revoked'])->toBeGreaterThan(0);

        Schema::drop('mfa_revocations');
        $this->get('/dashboard')->assertServerError();
    });

    it('costs one query for a cold cache and none after', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);
        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            $queries += str_contains($q->sql, 'mfa_') ? 1 : 0;
        });

        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();

        expect($queries)->toBe(1);
    });
});

describe('events', function () {
    it('puts the profile and deadline on VerificationSucceeded for a verification with a window', function () {
        Event::fake([VerificationSucceeded::class]);
        ($this->verifiedAdmin)();

        Event::assertDispatched(VerificationSucceeded::class, fn ($e) => $e->context['profile'] === 'enforced'
            && $e->context['expires_at'] === now()->addHours(4)->toIso8601String());
    });

    it('forgets the lifetime on logout', function () {
        ($this->verifiedAdmin)();
        $this->post('/logout');

        expect(session()->has(VerificationLifetime::SESSION_KEY))->toBeFalse()
            ->and(session()->has(VerificationLifetime::SEEN_KEY))->toBeFalse();
    });
});

describe('review fixes', function () {
    it('never lets a trusted browser undo an idle timeout, even without a fixed window', function () {
        config(['mfa.lifetime.profiles.enforced.absolute' => null, 'mfa.lifetime.profiles.enforced.idle' => 25, 'mfa.trusted_browsers.allow_enforced' => true]);
        [$admin, $factor] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $this->loginWithSession($admin)->getJson('/mfa/challenge')->assertJsonPath('trustBrowser', null);

        // A cookie trusted while the profile had no window at all.
        config(['mfa.lifetime.profiles.enforced.idle' => null]);
        $response = $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();
        $name = app(TrustedBrowsers::class)->cookieName('web', $admin);
        $token = $response->getCookie($name, false)->getValue();
        config(['mfa.lifetime.profiles.enforced.idle' => 25]);

        // The cookie no longer verifies anyone whose profile has an idle timeout.
        $this->flushSession();
        Auth::forgetGuards();
        $this->loginWithSession($admin)->withUnencryptedCookie($name, $token)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('forgets trusted browsers before revoking, so one can\'t verify in between', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user)
            ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();
        $order = [];
        Event::listen(TrustedBrowsersForgotten::class, function () use (&$order) {
            $order[] = 'forgotten:'.DB::table('mfa_revocations')->count();
        });

        Mfa::revokeVerifications($user);

        expect($order)->toBe(['forgotten:0']);
    });

    it('fails the reset visibly when the cache can neither store nor drop the revocation', function () {
        [$user] = $this->userWithFactor();
        Cache::extend('read-only', fn () => Cache::repository(new WriteFailingCacheStore));
        config(['cache.stores.read-only' => ['driver' => 'read-only'], 'mfa.cache.store' => 'read-only']);

        expect(fn () => Mfa::revokeVerifications($user))->toThrow(RuntimeException::class);
        expect(DB::table('mfa_revocations')->where('user_id', $user->id)->exists())->toBeTrue(); // written first
    });

    it('drops the revoked session\'s password confirmation and pending setups', function () {
        config(['mfa.routes.password_confirmation' => true]);
        [$admin] = ($this->verifiedAdmin)();
        $this->withConfirmedPassword();
        session()->put('mfa.pending', ['x' => 1]);
        $this->travel(1)->seconds();

        Mfa::revokeVerifications($admin);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));

        expect(session()->has(StrontiumCorp\LaravelMfa\Mfa::PASSWORD_CONFIRMED_AT))->toBeFalse()
            ->and(session()->has('mfa.pending'))->toBeFalse();
    });

    it("doesn't count a background GET to a no_grace route as activity", function () {
        Route::middleware(['web', 'auth'])->get('/export-status', fn () => 'ok')->name('export.status');
        config(['mfa.lifetime.profiles.enforced.idle' => 25, 'mfa.lifetime.no_grace' => ['export.status']]);
        ($this->verifiedAdmin)();

        $this->travel(20)->minutes();
        $this->getJson('/export-status')->assertOk();
        $this->travel(5)->minutes();
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('treats Sec-Purpose prefetches and HEAD requests as background in the grace period (they render nothing)', function () {
        ($this->verifiedAdmin)();
        $this->travel(240)->minutes();

        $this->withHeaders(['Sec-Purpose' => 'prefetch'])->get('/dashboard')->assertOk();
        $this->flushHeaders();
        $this->call('HEAD', '/dashboard')->assertOk();
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('starts a lifetime for a session whose verified value is not a timestamp, and ends it', function () {
        $admin = $this->makeUser(['is_admin' => true]);
        $this->createMfaFactor($admin);
        $this->loginWithSession($admin);
        session()->put(Mfa::sessionKey('web', $admin->id), true);

        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('gives each guard its own window', function () {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'mfa.guards' => ['web', 'admin']]);
        [$admin, $factor] = ($this->verifiedAdmin)();
        $this->travel(2)->hours();
        Auth::guard('admin')->login($admin);
        session()->put(Mfa::sessionKey('admin', $admin->id), now()->getTimestamp());

        $this->travel(2 * 60 + 11)->minutes(); // web: 4:11, admin: 2:11
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
        expect(Mfa::isVerified(session()->driver(), $admin, 'admin'))->toBeTrue()
            ->and(Mfa::isVerified(session()->driver(), $admin, 'web'))->toBeFalse();
    });
});

describe('impersonation', function () {
    it("caps the impersonation at the admin's own window, and logs out when it ends", function () {
        config(['mfa.lifetime.profiles.enforced.idle' => 25]);
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser(); // default profile: no window of its own
        for ($minute = 0; $minute < 180; $minute += 20) { // active for 3 hours
            $this->travel(20)->minutes();
            $this->get('/dashboard')->assertOk();
        }

        $this->post("/admin/switch/{$target->id}", ['grant' => 1])->assertRedirect('/dashboard');
        expect(session(VerificationLifetime::SESSION_KEY.'.web.'.$target->id))
            ->toMatchArray(['until' => now()->subHours(3)->addHours(4)->getTimestamp(), 'idle' => 1500, 'on_expiry' => 'logout']);

        foreach ([20, 20] as $minutes) { // 3:20, 3:40
            $this->travel($minutes)->minutes();
            $this->get('/dashboard')->assertOk();
        }
        $this->travel(20)->minutes(); // 4:00, the admin's window ends: a page visit ends the impersonation
        $this->get('/dashboard')->assertRedirect(route('login'));
        expect(Auth::guard('web')->check())->toBeFalse();
    });

    it('ends the impersonation when the admin is revoked or reset', function () {
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser();
        $this->post("/admin/switch/{$target->id}", ['grant' => 1]);
        $this->get('/dashboard')->assertOk();

        Mfa::revokeVerifications($admin);
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it("refuses a grant in the admin's grace period", function () {
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser();
        $this->travel(241)->minutes();

        $this->withoutExceptionHandling();
        expect(fn () => $this->post("/admin/switch/{$target->id}", ['grant' => 1]))
            ->toThrow(ImpersonationNotAllowed::class);
    });
});

describe('Inertia reloads', function () {
    it('treats a reload of the page the user is on as background: no activity, and no challenge in grace', function () {
        config(['mfa.lifetime.profiles.enforced.idle' => 25]);
        ($this->verifiedAdmin)();

        // A poll (usePoll / router.reload() without `only`) from the dashboard.
        $this->travel(20)->minutes();
        inertiaVisit($this, '/dashboard', ['Referer' => url('/dashboard')])->assertOk();
        $this->travel(5)->minutes();
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('still challenges a visit to another page in grace', function () {
        ($this->verifiedAdmin)();
        $this->travel(240)->minutes();

        inertiaVisit($this, '/dashboard', ['Referer' => url('/dashboard')])->assertOk();
        inertiaVisit($this, '/dashboard', ['Referer' => url('/settings')])->assertRedirect(route('mfa.challenge'));
    });
});

it('records when each guard logged in, and forgets it on logout', function () {
    $user = $this->makeUser();
    Route::middleware('web')->post('/test-login', fn () => tap('in', fn () => Auth::login($user)));
    $this->post('/test-login')->assertOk();
    expect(session(StrontiumCorp\LaravelMfa\Mfa::LOGIN_AT_PREFIX.'.web.'.$user->id))->toBe(now()->getTimestamp());

    $this->post('/logout');
    expect(session()->has(StrontiumCorp\LaravelMfa\Mfa::LOGIN_AT_PREFIX.'.web.'.$user->id))->toBeFalse();
});

describe('third review fixes', function () {
    it('never sends the user to another site after "Verify now" (the Referer is not trusted)', function () {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(220)->minutes();

        $this->withHeader('referer', 'https://evil.example/phish')->getJson('/mfa/challenge?renew=1')->assertOk();
        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertOk()->assertJsonPath('redirect', '/dashboard');
    });

    it('keeps a same-site Referer as the page to return to', function () {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(220)->minutes();

        $this->withHeader('referer', url('/dashboard?tab=2'))->getJson('/mfa/challenge?renew=1')->assertOk();
        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertJsonPath('redirect', url('/dashboard?tab=2'));
    });

    it('throws when the cache silently refuses to store or drop a revocation', function () {
        [$user] = $this->userWithFactor();
        Cache::extend('silent', fn () => Cache::repository(new SilentlyFailingCacheStore));
        config(['cache.stores.silent' => ['driver' => 'silent'], 'mfa.cache.store' => 'silent']);
        app(Revocations::class)->stamps($user->id); // a cached "never"
        SilentlyFailingCacheStore::$failing = true;

        try {
            expect(fn () => Mfa::revokeVerifications($user))->toThrow(RuntimeException::class);
        } finally {
            SilentlyFailingCacheStore::$failing = false;
        }
    });

    it('reads the revocation stamp and the factor types in one cache round trip', function () {
        $store = new CountingCacheStore;
        Cache::extend('counting', fn () => Cache::repository($store));
        config(['cache.stores.counting' => ['driver' => 'counting'], 'mfa.cache.store' => 'counting']);
        $user = $this->makeUser();
        $this->loginWithSession($user);
        $this->get('/dashboard')->assertOk(); // warm both entries
        $store->gets = $store->manys = 0;

        $this->get('/dashboard')->assertOk();

        expect([$store->gets, $store->manys])->toBe([0, 1]);
    });

    it('stores only the profile for a verification with no window, and no activity time', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user);
        $this->get('/dashboard')->assertOk();

        expect(session(VerificationLifetime::SESSION_KEY.'.web.'.$user->id))->toBe(['profile' => 'default', 'started' => now()->getTimestamp()])
            ->and(session()->has(VerificationLifetime::SEEN_KEY.'.web.'.$user->id))->toBeFalse();
    });

    it('records no login time outside a request with a session', function () {
        $user = $this->makeUser();
        $store = app('session')->driver();
        $this->app->instance('request', Request::create('/'));

        Auth::guard('web')->login($user);

        expect($store->has(StrontiumCorp\LaravelMfa\Mfa::LOGIN_AT_PREFIX))->toBeFalse();
    });
});

describe('regression review', function () {
    it('records the login time of a remember-me login, so a reset made before it does not log it out', function () {
        $user = $this->makeUser();
        Mfa::reset($user);
        expect($user->fresh()->remember_token)->toBeNull(); // no token to replace, none written
        $this->travel(1)->seconds();
        $user->forceFill(['remember_token' => 'tok'])->save();
        $this->travel(1)->seconds();

        $this->withCookie(Auth::guard('web')->getRecallerName(), $user->id.'|tok|'.$user->getAuthPassword())->get('/dashboard')->assertOk();
        expect(session(StrontiumCorp\LaravelMfa\Mfa::LOGIN_AT_PREFIX.'.web.'.$user->id))->toBe(now()->getTimestamp());
    });

    it('keeps a same-host Referer whatever its scheme or spelled-out default port (TLS ended at a proxy)', function (string $referer) {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(220)->minutes();

        $this->withHeader('referer', $referer)->getJson('/mfa/challenge?renew=1')->assertOk();
        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertJsonPath('redirect', $referer);
    })->with(['https://localhost/dashboard?tab=2', 'http://localhost:80/dashboard']);

    it('rejects a Referer on another port or a look-alike host', function (string $referer) {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(220)->minutes();

        $this->withHeader('referer', $referer)->getJson('/mfa/challenge?renew=1')->assertOk();
        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertJsonPath('redirect', '/dashboard');
    })->with(['http://localhost:8080/dashboard', 'http://localhost.evil.example/dashboard', '//evil.example/x']);

    it('shows a dismissed reminder again after the next verification', function () {
        [, $factor] = ($this->verifiedAdmin)();
        $this->travel(215)->minutes();
        $this->getJson('/context')->assertJsonPath('reverifyReminder.show', true);
        $this->postJson('/mfa/reminder/dismiss')->assertOk();

        $this->getJson('/mfa/challenge?renew=1')->assertOk();
        $this->travel(31)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();
        $this->travel(215)->minutes();

        $this->getJson('/context')->assertJsonPath('reverifyReminder.show', true);
    });

    it("keeps the admin's own idle timeout alive while they impersonate", function () {
        config(['mfa.lifetime.profiles.enforced.idle' => 25]);
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser();
        $this->post("/admin/switch/{$target->id}", ['grant' => 1])->assertRedirect('/dashboard');

        foreach ([20, 20, 20] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->get('/dashboard')->assertOk();
        }

        $this->post('/admin/exit')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('dashboard:'.$admin->id);
    });

    it('ends an impersonation, with a logout, when the admin is reset', function () {
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser();
        $this->post("/admin/switch/{$target->id}", ['grant' => 1]);
        $this->get('/dashboard')->assertOk();

        Mfa::reset($admin);
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it('drops the impersonation grant once the target passes MFA as themselves', function () {
        [$admin] = ($this->verifiedAdmin)();
        $target = $this->makeUser();
        $this->post("/admin/switch/{$target->id}", ['grant' => 1]);
        $key = StrontiumCorp\LaravelMfa\Mfa::IMPERSONATOR_PREFIX.'.web.'.$target->id;
        expect(session()->has($key))->toBeTrue();

        $request = request();
        $request->setLaravelSession(session()->driver());
        Mfa::markVerified($request, $target);

        expect(session()->has($key))->toBeFalse();
        Mfa::revokeVerifications($admin);
        $this->get('/dashboard')->assertOk(); // no longer tied to the admin
    });

    it("caps an impersonation at the admin's window when the admin signed in on another guard", function () {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'mfa.guards' => ['web', 'admin']]);
        [$admin] = $this->userWithFactor(FactorType::Totp, ['is_admin' => true]);
        $this->actingAsMfaVerified($admin, 'admin');
        $this->get('/public')->assertOk(); // the gate starts the admin's lifetime
        $target = $this->makeUser();
        Auth::guard('web')->login($target);

        $request = request();
        $request->setLaravelSession(session()->driver());
        Mfa::grantForImpersonation($admin, $target, $request);

        expect(session(VerificationLifetime::SESSION_KEY.'.web.'.$target->id)['until'])
            ->toBe(session(VerificationLifetime::SESSION_KEY.'.admin.'.$admin->id)['until']);
        Mfa::revokeVerifications($admin);
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it('forgets trusted browsers on revocation even while the feature is off', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user)
            ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor), 'remember' => true])->assertOk();
        config(['mfa.trusted_browsers.enabled' => false]);

        Mfa::revokeVerifications($user);

        expect(MfaTrustedBrowser::query()->count())->toBe(0);
    });

    it('drops the revocation row with the user', function () {
        [$user] = $this->userWithFactor();
        Mfa::revokeVerifications($user);

        $user->delete();

        expect(DB::table('mfa_revocations')->count())->toBe(0);
    });

    it('keeps the idle timeout when an app config only turns the window off', function () {
        $merged = ConfigMerge::merge(
            require __DIR__.'/../../config/mfa.php',
            ['lifetime' => ['profiles' => ['enforced' => ['absolute' => null]]]],
        );

        expect($merged['lifetime']['profiles']['enforced'])->toMatchArray(['absolute' => null, 'idle' => 25, 'grace' => 10]);
    });
});
