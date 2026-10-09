<?php

/*
 * Regression tests for the 2026-10-08 security review findings.
 */

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\FactorManager;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\RateLimits;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\RequestAwarePolicy;

it('[#9] lets enforced users through password confirmation while enrolling (no redirect loop)', function () {
    config(['mfa.enforcement.policy' => EnforceForAdmins::class, 'mfa.routes.confirm_middleware' => ['password.confirm']]);
    require __DIR__.'/../../routes/mfa.php';
    app('router')->getRoutes()->refreshNameLookups();
    $admin = $this->makeUser(['is_admin' => true]);

    $this->loginWithSession($admin)->post('/mfa/factors', ['type' => 'totp'])->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertOk();
});

it('[#2] shows a pending TOTP secret only to the session that started enrollment', function () {
    $user = $this->makeUser();
    $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
    expect($this->getJson('/mfa/settings')->json('pending.0.secret'))->toBeString();

    // A second session on the same account (attacker with the password).
    app('session')->driver()->flush();
    $this->freshGuards()->loginWithSession($user);

    expect($this->getJson('/mfa/settings')->json('pending'))->toBe([]);
});

it('[#2] refuses to confirm a pending factor from another session', function () {
    $user = $this->makeUser();
    $secret = $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'totp'])->json('setup.secret');
    $id = $user->mfaFactors()->sole()->id;

    app('session')->driver()->flush();
    $this->freshGuards()->loginWithSession($user);

    $code = (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    $this->postJson("/mfa/factors/{$id}/confirm", ['code' => $code])->assertUnprocessable();
    expect(Mfa::hasConfirmedFactors($user))->toBeFalse();
});

it('[#2] expires pending enrollments after 30 minutes', function () {
    $user = $this->makeUser();
    $secret = $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'totp'])->json('setup.secret');
    $id = $user->mfaFactors()->sole()->id;

    $this->travel(31)->minutes();

    $code = (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    $this->postJson("/mfa/factors/{$id}/confirm", ['code' => $code])->assertUnprocessable();
});

it('[#6] forgets MFA verification on logout even when the session is not invalidated', function () {
    [$user] = $this->userWithFactor();
    $this->actingAsMfaVerified($user);

    Auth::guard('web')->logout(); // artistly-style: no session()->invalidate()

    $this->freshGuards()->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
});

it('forgets a confirmed password on logout even when the session is not invalidated', function () {
    config(['mfa.routes.password_confirmation' => true]);
    [$user] = $this->userWithFactor();
    $this->actingAsMfaVerified($user)->withConfirmedPassword();
    $this->getJson('/mfa/settings')->assertOk()->assertJsonPath('passwordConfirmationRequired', false);

    Auth::guard('web')->logout(); // artistly-style: no session()->invalidate()

    // The next login in this browser (another user, or the same one) must
    // confirm their own password before changing factors.
    expect(session()->has(StrontiumCorp\LaravelMfa\Mfa::PASSWORD_CONFIRMED_AT))->toBeFalse();
    $this->freshGuards()->actingAsMfaVerified($user)->getJson('/mfa/settings')->assertOk()->assertJsonPath('passwordConfirmationRequired', true);
    $this->postJson('/mfa/factors', ['type' => 'email'])->assertStatus(423);
});

it('[#8] rejects non-scalar factor ids with a 422 instead of a 500', function () {
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->postJson('/mfa/challenge', ['factor_id' => [1], 'code' => '123456'])->assertUnprocessable();
    $this->postJson('/mfa/challenge/send', ['factor_id' => ['x']])->assertUnprocessable();
});

it('[#8] throttles the MFA routes themselves', function () {
    config(['mfa.routes.throttle' => '3,1']);
    require __DIR__.'/../../routes/mfa.php';
    app('router')->getRoutes()->refreshNameLookups();
    [$user] = $this->userWithFactor();
    $this->loginWithSession($user);

    foreach (range(1, 3) as $_) {
        $this->getJson('/mfa/challenge')->assertOk();
    }
    $this->getJson('/mfa/challenge')->assertStatus(429);
});

it('[#1] counts an attempt before checking it, so concurrent requests cannot all pass the check', function () {
    [$user, $factor] = $this->userWithFactor();
    $limits = app(RateLimits::class);

    // Four requests "in flight" already incremented the counter.
    foreach (range(1, 4) as $_) {
        expect($limits->attemptVerify($user))->toBeTrue();
    }
    expect($limits->attemptVerify($user))->toBeTrue()   // 5th: at the limit
        ->and($limits->attemptVerify($user))->toBeFalse(); // 6th: over it
});

it('[#1] caps verification attempts per day, not just per minute', function () {
    config(['mfa.rate_limit.verify_per_minute' => 100, 'mfa.rate_limit.verify_per_day' => 3]);
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user);

    foreach (range(1, 3) as $_) {
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])->assertUnprocessable();
    }

    $this->travel(2)->hours();
    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertStatus(429);

    $this->travel(23)->hours();
    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();
});

it('[#5] a stale "no MFA" read cannot overwrite the answer written when a factor is confirmed', function () {
    $user = $this->makeUser();
    $key = 'mfa:factor-types:'.$user->id;

    // Request A starts its fill and reads "no factors"...
    $staleRead = Mfa::hasConfirmedFactors($user); // fills the cache with 0
    expect($staleRead)->toBeFalse();
    cache()->forget($key);                        // ...but has not written yet

    // ...meanwhile the user confirms a factor (model event writes through)...
    $this->createMfaFactor($user);
    expect(cache()->get($key))->toBe('|totp|');

    // ...then A's late write arrives. add() must not clobber the fresh value.
    cache()->add($key, '|', 3600);

    expect(Mfa::hasConfirmedFactors($user))->toBeTrue();
});

it('applies a factor type being turned off or on at once, without forgetting the cache', function () {
    $this->freshGuards();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    expect(Mfa::hasConfirmedFactors($user))->toBeTrue(); // cached

    // Off: an SMS-only user isn't challenged by it any more (D10), so they
    // must not be held at a challenge with nothing to choose from.
    config(['mfa.factors.sms.enabled' => false]);
    expect(Mfa::hasConfirmedFactors($user))->toBeFalse();
    $this->loginWithSession($user)->get('/dashboard')->assertOk();

    // On again: challenged at once, not after cache.ttl.
    config(['mfa.factors.sms.enabled' => true]);
    expect(Mfa::hasConfirmedFactors($user))->toBeTrue();
    $this->freshGuards()->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));

    // And a factor added while the type was off is seen when it comes back.
    config(['mfa.factors.sms.enabled' => false]);
    $other = $this->makeUser();
    expect(Mfa::hasConfirmedFactors($other))->toBeFalse();
    $this->createMfaFactor($other, FactorType::Sms);
    config(['mfa.factors.sms.enabled' => true]);
    expect(Mfa::hasConfirmedFactors($other))->toBeTrue();
});

it('[#9] keeps raw transport errors (which can contain the address) out of the MFA log and audit table', function () {
    config(['mfa.factors.email.notification' => ThrowingOtpNotification::class]);
    app(FactorManager::class)->forgetDrivers();
    [$user, $factor] = $this->userWithFactor(FactorType::Email);

    $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertUnprocessable();

    // Exactly one event per failure (the sync queue must not also fire failed()).
    $row = MfaAuditLog::where('event', 'challenge_delivery_failed')->sole();
    expect(json_encode($row->context))->not->toContain('secret.person@example.com')->toContain('RuntimeException')
        ->and($row->context)->not->toHaveKey('queued');
});

class ThrowingOtpNotification extends OtpCodeNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        throw new RuntimeException('Expected response code 250 but got 550 for <secret.person@example.com>');
    }
}

it('[#9] resolves the current request from the live container, not the one captured at boot (Octane)', function () {
    $mfa = app(StrontiumCorp\LaravelMfa\Mfa::class);   // resolved at boot, holds the base app
    $admin = $this->makeUser();
    $target = $this->makeUser(); // no MFA: an MFA target needs a verified impersonator (D9)

    // Octane: each request runs in a clone of the base app, set as the
    // global container instance, with its own request bound.
    $base = app();
    $sandbox = clone $base;
    Container::setInstance($sandbox);
    $request = Request::create('/x');
    $request->setLaravelSession($session = new Store('s', new ArraySessionHandler(10)));
    $sandbox->instance('request', $request);

    try {
        $mfa->grantForImpersonation($admin, $target);
    } finally {
        Container::setInstance($base);
    }

    expect($session->has($mfa->sessionKey('web', $target->id)))->toBeTrue();
});

it('resolves the enforcement and password confirmation policies from the live container (Octane)', function () {
    config([
        'mfa.enforcement.policy' => RequestAwarePolicy::class,
        'mfa.routes.password_confirmation' => true,
        'mfa.routes.password_confirmation_policy' => RequestAwarePolicy::class,
    ]);
    $mfa = app(StrontiumCorp\LaravelMfa\Mfa::class);   // resolved at boot, holds the base app
    $user = $this->makeUser();

    // Without Octane (artistly): the one container, the one request.
    expect($mfa->isEnforced($user))->toBeFalse()->and($mfa->requiresPasswordConfirmation($user))->toBeFalse();

    // Octane: this request runs in a clone of the base app with its own request.
    $base = app();
    $sandbox = clone $base;
    Container::setInstance($sandbox);
    $sandbox->instance('request', Request::create('/x', server: ['HTTP_X_STRICT' => '1']));

    try {
        $seen = [$mfa->isEnforced($user), $mfa->requiresPasswordConfirmation($user), $mfa->mustEnroll($user)];
    } finally {
        Container::setInstance($base);
    }

    expect($seen)->toBe([true, true, true])
        // And back on the base app's request, the policy sees that one again.
        ->and($mfa->isEnforced($user))->toBeFalse();
});

// Regression guard: on MySQL, whereKey('12abc') matches id 12. SQLite can't
// reproduce that coercion, so this documents the contract rather than the bug.
it('[#9] mfa:reset matches ids strictly', function () {
    [$user] = $this->userWithFactor();

    $this->artisan('mfa:reset', ['user' => $user->id.'abc', '--force' => true])->assertFailed();
    expect($user->mfaFactors()->count())->toBe(1);
});

describe('[#3] multiple session guards', function () {
    beforeEach(function () {
        config([
            'auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'],
            'mfa.guards' => ['web', 'admin'],
        ]);
    });

    it('challenges every logged-in guard, not just the first', function () {
        $plain = $this->makeUser();                 // no MFA, on "web"
        [$admin] = $this->userWithFactor();         // MFA, on "admin"

        $this->loginWithSession($plain, 'web')->loginWithSession($admin, 'admin');

        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('does not let a verification on one guard satisfy another guard with the same id', function () {
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user, 'web')->loginWithSession($user, 'admin');

        expect(Mfa::isVerified(session()->driver(), $user, 'admin'))->toBeFalse();
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('verifies the pending guard when the challenge is completed', function () {
        $plain = $this->makeUser();
        [$admin, $factor] = $this->userWithFactor();
        $this->loginWithSession($plain, 'web')->loginWithSession($admin, 'admin');

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();

        expect(Mfa::isVerified(session()->driver(), $admin, 'admin'))->toBeTrue();
        $this->get('/dashboard')->assertOk();
    });
});

describe('[#4] SMS pumping', function () {
    // The per-destination and per-IP caps were redesigned on 2026-10-08
    // (unconfirmed vs confirmed budgets) — see tests/Feature/SendLimitsTest.php.

    it('blocks premium-rate NANP prefixes even though +1 is allowed', function (string $phone) {
        $sms = Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());

        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => $phone])->assertUnprocessable();
        $sms->assertNothingSent();
    })->with(['+18765550100', '+18095550100', '+19005550100']);

    it('re-checks the blocklist at send time for factors enrolled before a prefix was blocked', function () {
        $sms = Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        config(['mfa.factors.sms.blocked_prefixes' => ['1555']]);

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertUnprocessable();
        $sms->assertNothingSent();
    });
});
