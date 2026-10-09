<?php

/*
 * Pins behaviour that surviving mutants showed was untested (mutation triage
 * 2026-10-08). Each describe block names the class whose gaps it closes.
 */

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\FactorManager;
use StrontiumCorp\LaravelMfa\Http\Middleware\EnsureMfaVerified;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\Mask;
use StrontiumCorp\LaravelMfa\Support\OtpStore;
use StrontiumCorp\LaravelMfa\Support\PendingEnrollments;
use StrontiumCorp\LaravelMfa\Support\RandomCodeGenerator;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\HandleImpersonation;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\PlainUser;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\User;

function addSms(string $phone, string $ip = '203.0.113.7')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/mfa/factors', ['type' => 'sms', 'destination' => $phone]);
}

describe('SendGuard', function () {
    it('does not charge other counters when one limit refuses', function () {
        config(['mfa.rate_limit.send_per_hour' => 3]);
        Mfa::fakeSms();
        foreach (range(1, 2) as $_) {                       // others exhaust the destination
            $this->freshGuards()->loginWithSession($this->makeUser());
            addSms('+15555550188')->assertOk();
        }

        $user = $this->makeUser();
        $this->freshGuards()->loginWithSession($user);
        foreach (range(1, 4) as $_) {
            addSms('+15555550188')->assertStatus(429);      // refused by destination, not by account
        }

        addSms('+15555550189')->assertOk();                 // account budget untouched
    });

    it('still refuses when a concurrent request takes the last slot between check and count', function () {
        // Simulate the race: the pre-check always says "room left".
        app()->instance(RateLimiter::class, new class(app('cache')->store()) extends RateLimiter
        {
            public function tooManyAttempts($key, $maxAttempts)
            {
                return false;
            }
        });
        config(['mfa.rate_limit.unconfirmed_per_destination_per_day' => 1]);
        Mfa::fakeSms();

        $this->loginWithSession($this->makeUser());
        addSms('+15555550177')->assertOk();
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550177')->assertStatus(429);
    });

    it('counts a destination per IP, so another IP cannot ride on it', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 1]);
        Mfa::fakeSms();

        $this->loginWithSession($this->makeUser());
        addSms('+15555550101', '198.51.100.1')->assertOk();
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550101', '198.51.100.2')->assertOk();   // counted for .2
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550102', '198.51.100.2')->assertStatus(429);
    });

    it('keeps refusing a destination that was refused (no free retry)', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 1]);
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());

        addSms('+15555550101')->assertOk();
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550102')->assertStatus(429);
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550102')->assertStatus(429);
    });

    it('keeps the send and new-destination budgets apart', function () {
        config(['mfa.rate_limit.new_destinations_per_account_per_day' => 1, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->actingAsMfaVerified($user);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);
        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

        addSms('+15555550144')->assertOk();
    });

    it('raises the breaker event on the first refusal, with the limit, and only for the breaker', function () {
        Event::fake([Events\SendingCircuitTripped::class]);
        config(['mfa.rate_limit.unconfirmed_global_per_hour' => 2, 'mfa.rate_limit.new_destinations_per_ip_per_hour' => 1]);
        Mfa::fakeSms();

        $this->loginWithSession($this->makeUser());
        addSms('+15555550101', '198.51.100.1')->assertOk();
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550102', '198.51.100.1')->assertStatus(429);   // IP refusal (rolled back): no breaker event
        Event::assertNotDispatched(Events\SendingCircuitTripped::class);

        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550103', '198.51.100.2')->assertOk();          // global 2 of 2
        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550104', '198.51.100.3')->assertStatus(503);   // first breaker refusal
        Event::assertDispatched(Events\SendingCircuitTripped::class, fn ($e) => $e->context === ['limit' => 2]);
    });

    it('reports retry_after on refusals, and the scope in the audit log', function () {
        config(['mfa.rate_limit.send_per_hour' => 1, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        $retry = $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertStatus(429)->json('retry_after');

        expect($retry)->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600)
            ->and(MfaAuditLog::where('reason', 'rate_limited')->sole()->context)->toMatchArray(['scope' => 'account', 'stage' => 'send']);
    });
});

describe('suspicious-activity warnings', function () {
    beforeEach(fn () => Event::fake([Events\SuspiciousCodeRequests::class]));

    it('fires exactly at the threshold, with context', function () {
        config(['mfa.rate_limit.warn_after_unverified_sends' => 2, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);
        Event::assertNotDispatched(Events\SuspiciousCodeRequests::class);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);
        Event::assertDispatched(Events\SuspiciousCodeRequests::class, fn ($e) => $e->context === ['reason' => 'repeated_unverified_sends', 'factor_id' => $factor->id, 'sends' => 2]);
    });

    it('can be switched off with 0', function () {
        config(['mfa.rate_limit.warn_after_unverified_sends' => 0, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        foreach (range(1, 5) as $_) {
            $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);
        }

        Event::assertNotDispatched(Events\SuspiciousCodeRequests::class);
    });

    it('never fires for enrollment sends or enrollment cap refusals', function () {
        config(['mfa.rate_limit.warn_after_unverified_sends' => 1, 'mfa.rate_limit.send_per_hour' => 1]);
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());

        addSms('+15555550101')->assertOk();
        addSms('+15555550102')->assertStatus(429);

        Event::assertNotDispatched(Events\SuspiciousCodeRequests::class);
    });

    it('is deduplicated per factor, not globally', function () {
        config(['mfa.rate_limit.warn_after_unverified_sends' => 1]);
        Mfa::fakeSms();
        foreach (range(1, 2) as $_) {
            [$user, $factor] = $this->userWithFactor(FactorType::Sms);
            $this->freshGuards()->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        }

        Event::assertDispatchedTimes(Events\SuspiciousCodeRequests::class, 2);
    });
});

describe('OtpStore', function () {
    beforeEach(function () {
        [, $this->factor] = $this->userWithFactor(FactorType::Email);
        $this->store = app(OtpStore::class);
        $this->opts = ['length' => 6, 'ttl' => 60, 'max_attempts' => 2, 'resend_cooldown' => 0];
    });

    it('consumes older codes when issuing a new one', function () {
        $this->store->issue($this->factor, $this->opts);
        $this->store->issue($this->factor, $this->opts);

        expect($this->factor->otpCodes()->whereNull('consumed_at')->count())->toBe(1);
    });

    it('consumes an expired code, so the next attempt reports no active code', function () {
        $code = $this->store->issue($this->factor, $this->opts)['code'];
        $this->travel(61)->seconds();

        expect($this->store->verify($this->factor, $code, $this->opts)->reason)->toBe(FailureReason::Expired)
            ->and($this->store->verify($this->factor, $code, $this->opts)->reason)->toBe(FailureReason::NoActiveCode);
    });

    it('reports zero attempts remaining when a code is burned', function () {
        $code = $this->store->issue($this->factor, $this->opts)['code'];
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->store->verify($this->factor, $wrong, $this->opts);

        expect($this->store->verify($this->factor, $wrong, $this->opts)->context)->toBe(['attempts_remaining' => 0]);
    });

    it('reports the cooldown wait as a whole number of seconds', function () {
        $this->freezeSecond(); // exact waits: a second boundary mid-test would give 89
        $opts = [...$this->opts, 'ttl' => 600, 'resend_cooldown' => 120];
        $this->store->issue($this->factor, $opts);
        $this->travel(30)->seconds();

        expect($this->store->issue($this->factor, $opts)['result']->context['retry_after'])->toBe(90);
    });
});

describe('ChallengeService', function () {
    it('does not also emit VerificationFailed for a delivery failure', function () {
        Event::fake([Events\VerificationFailed::class]);
        Mfa::fakeSms()->failWith('down');
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

        Event::assertNotDispatched(Events\VerificationFailed::class);
    });

    it('records stage and factor in failure events', function () {
        Event::fake([Events\VerificationFailed::class]);
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge', ['factor_id' => 999, 'code' => '123456']);
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000']);
        $this->postJson('/mfa/challenge/recover', ['code' => 'nope']);

        Event::assertDispatched(Events\VerificationFailed::class, fn ($e) => $e->reason === FailureReason::FactorNotFound && $e->context === ['factor_id' => 999]);
        Event::assertDispatched(Events\VerificationFailed::class, fn ($e) => $e->reason === FailureReason::InvalidCode && $e->context === ['stage' => 'challenge', 'factor_id' => $factor->id]);
        Event::assertDispatched(Events\VerificationFailed::class, fn ($e) => $e->reason === FailureReason::InvalidRecoveryCode && $e->context === ['stage' => 'recovery']);
    });

    it('records stage and retry_after when rate limited', function () {
        $this->freezeSecond(); // exact waits: a second boundary between the requests would give 59
        Event::fake([Events\VerificationFailed::class]);
        config(['mfa.rate_limit.verify_per_minute' => 1]);
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000']);
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])->assertJsonPath('retry_after', 60);

        Event::assertDispatched(Events\VerificationFailed::class, fn ($e) => $e->reason === FailureReason::RateLimited && $e->context['stage'] === 'challenge' && $e->context['retry_after'] === 60);
    });

    it('resets the per-minute and per-day counters after a success', function (string $limit) {
        config(["mfa.rate_limit.{$limit}" => 3, 'mfa.rate_limit.verify_per_minute' => $limit === 'verify_per_day' ? 100 : 3]);
        [$user, $factor] = $this->userWithFactor();
        $fail = fn () => $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000']);

        $this->loginWithSession($user);
        $fail();
        $fail();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();

        $this->freshGuards()->post('/logout');
        $this->loginWithSession($user);
        $fail()->assertUnprocessable();
        $fail()->assertUnprocessable();   // would be 429 without the reset
    })->with(['verify_per_minute', 'verify_per_day']);

    it('resets the counters after a recovery code too', function () {
        config(['mfa.rate_limit.verify_per_minute' => 2]);
        [$user, $factor] = $this->userWithFactor();
        $code = app(RecoveryCodes::class)->generate($user)[0];
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000']);
        $this->postJson('/mfa/challenge/recover', ['code' => $code])->assertOk();
        $this->freshGuards()->post('/logout');

        $this->loginWithSession($user)->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])->assertUnprocessable();
    });

    it('passes the factor to the success event', function () {
        Event::fake([Events\VerificationSucceeded::class]);
        [$user, $factor] = $this->userWithFactor();

        $this->loginWithSession($user)->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)]);

        Event::assertDispatched(Events\VerificationSucceeded::class, fn ($e) => $e->factorType === FactorType::Totp && $e->context === ['factor_id' => $factor->id]);
    });

    it('refuses to verify against a factor whose type is disabled', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->createMfaFactor($user, FactorType::Email);   // keeps the user enrolled
        config(['mfa.factors.totp.enabled' => false]);
        Mfa::forgetCachedState($user);

        $this->loginWithSession($user)
            ->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertJsonPath('errors.code.0', 'This verification method is not available.');
    });
});

describe('EnrollmentService', function () {
    it('does not count TOTP enrollments against the new-destination budget', function () {
        config(['mfa.rate_limit.new_destinations_per_account_per_day' => 1]);
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
        addSms('+15555550101')->assertOk();
    });

    it('puts the factor id / count in its events', function () {
        Event::fake([Events\FactorEnrollmentStarted::class, Events\FactorEnabled::class, Events\FactorDisabled::class, Events\RecoveryCodesGenerated::class]);
        $user = $this->makeUser();
        $this->loginWithSession($user);

        $secret = $this->postJson('/mfa/factors', ['type' => 'totp'])->json('setup.secret');
        $id = $user->mfaFactors()->sole()->id;
        $this->postJson("/mfa/factors/{$id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))]);
        $this->deleteJson("/mfa/factors/{$id}");

        foreach ([Events\FactorEnrollmentStarted::class, Events\FactorEnabled::class, Events\FactorDisabled::class] as $event) {
            Event::assertDispatched($event, fn ($e) => $e->context === ['factor_id' => $id]);
        }
        Event::assertDispatched(Events\RecoveryCodesGenerated::class, fn ($e) => $e->context === ['count' => 10]);
    });

    it('shows a pending SMS enrollment in settings without TOTP fields', function () {
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());
        addSms('+15555550101')->assertOk();

        expect($this->getJson('/mfa/settings')->json('pending.0'))
            ->toMatchArray(['type' => 'sms', 'destination' => '+*******0101'])
            ->not->toHaveKey('secret');
    });

    it('keeps several pending enrollments from the same session', function () {
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
        addSms('+15555550101')->assertOk();

        expect(collect($this->getJson('/mfa/settings')->json('pending'))->pluck('type')->sort()->values()->all())->toBe(['sms', 'totp']);
    });
});

describe('factors', function () {
    it('replaces an earlier pending enrollment of the same type', function (string $type) {
        Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user);
        $payload = $type === 'sms' ? ['type' => 'sms', 'destination' => '+15555550101'] : ['type' => $type];

        $this->postJson('/mfa/factors', $payload)->assertOk();
        $this->travel(15)->minutes();
        $this->postJson('/mfa/factors', $type === 'sms' ? [...$payload, 'destination' => '+15555550102'] : $payload)->assertOk();

        expect($user->mfaFactors()->whereNull('confirmed_at')->count())->toBe(1);
    })->with(['totp', 'sms', 'email']);

    it('stores a default or custom label', function () {
        Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user);

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => '+15555550101', 'label' => 'Work phone'])->assertOk();

        expect($user->mfaFactors()->pluck('label', 'type')->all())->toBe(['totp' => 'Authenticator app', 'sms' => 'Work phone']);
    });

    it('puts the configured issuer in the authenticator URL', function () {
        config(['mfa.factors.totp.issuer' => 'Podcast Flow']);
        $this->loginWithSession($this->makeUser());

        expect($this->postJson('/mfa/factors', ['type' => 'totp'])->json('setup.otpauth_url'))->toStartWith('otpauth://totp/Podcast%20Flow:');
    });

    it('honours a zero clock-drift window', function () {
        config(['mfa.factors.totp.window' => 0]);
        app(FactorManager::class)->forgetDrivers();
        [, $factor] = $this->userWithFactor();
        $previous = (new Google2FA)->oathTotp($factor->secret, intdiv(now()->getTimestamp(), 30) - 1);

        expect(Mfa::factor(FactorType::Totp)->verify($factor, $previous)->reason)->toBe(FailureReason::InvalidCode);
    });

    it('rejects codes for a TOTP factor without a secret instead of crashing', function () {
        [, $factor] = $this->userWithFactor();
        $factor->forceFill(['secret' => null])->save();

        expect(Mfa::factor(FactorType::Totp)->verify($factor->fresh(), '123456')->reason)->toBe(FailureReason::InvalidCode);
    });

    it('accepts emailed/SMS codes typed with spaces', function () {
        Mfa::fakeCodes('482913');
        $sms = Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '482 913'])->assertOk();
    });

    it('reports delivery failures to the app and puts the factor in the event', function () {
        Exceptions::fake();
        Event::fake([Events\ChallengeDeliveryFailed::class, Events\ChallengeSent::class]);
        Mfa::fakeSms()->failWith('down');
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

        Exceptions::assertReported(DeliveryFailed::class);
        Event::assertDispatched(Events\ChallengeDeliveryFailed::class, fn ($e) => $e->context['factor_id'] === $factor->id);
        Event::assertNotDispatched(Events\ChallengeSent::class);
    });

    it('announces sends with the factor and delivery mode', function () {
        Event::fake([Events\ChallengeSent::class]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

        Event::assertDispatched(Events\ChallengeSent::class, fn ($e) => $e->context === ['factor_id' => $factor->id, 'queued' => false]);
    });

    it('normalises email destinations', function () {
        Notification::fake();
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'email', 'destination' => '  Jane@Example.COM '])->assertOk();

        expect($user->mfaFactors()->sole()->destination)->toBe('jane@example.com');
    });
});

describe('SessionIdentity', function () {
    it('ignores non-session guards in the configured list', function () {
        config(['auth.guards.token' => ['driver' => 'token', 'provider' => 'users'], 'mfa.guards' => ['token', 'web']]);
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('treats an empty session id as a guest', function () {
        $this->userWithFactor();
        session()->put(Auth::guard('web')->getName(), '');
        $request = Request::create('/');
        $request->setLaravelSession(session()->driver());

        expect(Mfa::sessionIdentities($request))->toBe([]);
    });

    it('challenges the real (session) user even when impersonation swapped Auth::user() first', function () {
        [$admin] = $this->userWithFactor(attributes: ['is_admin' => true]);
        $target = $this->makeUser();                          // no MFA
        $kernel = app(Kernel::class);
        $groups = $kernel->getMiddlewareGroups();
        array_splice($groups['web'], array_search(EnsureMfaVerified::class, $groups['web'], true), 0, [HandleImpersonation::class]);
        $kernel->setMiddlewareGroups($groups);

        // Admin logged in but NOT verified; impersonation runs before MFA.
        $this->loginWithSession($admin)->withSession(['impersonated_id' => $target->id])
            ->get('/public');
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });
});

describe('Mfa core', function () {
    it('still challenges when the "has MFA" cache is cold (flushed or evicted)', function () {
        [$user] = $this->userWithFactor();
        cache()->flush();

        expect(Mfa::hasConfirmedFactors($user))->toBeTrue();
        cache()->flush();
        $this->loginWithSession($user)->get('/dashboard')->assertRedirect(route('mfa.challenge'));
    });

    it('lists enabled types as a JSON list even when one is disabled', function () {
        config(['mfa.factors.totp.enabled' => false]);
        $this->loginWithSession($this->makeUser());

        expect($this->getJson('/mfa/settings')->assertOk()->json('availableTypes'))->toBe([
            ['type' => 'email', 'label' => 'Email', 'recommended' => false], ['type' => 'sms', 'label' => 'SMS', 'recommended' => false],
        ]);
    });

    it('lists recommended types first, flagged, keeping the order otherwise', function () {
        $this->loginWithSession($this->makeUser());

        // Default: only TOTP is recommended.
        expect($this->getJson('/mfa/settings')->assertOk()->json('availableTypes'))->toBe([
            ['type' => 'totp', 'label' => 'Authenticator app', 'recommended' => true],
            ['type' => 'email', 'label' => 'Email', 'recommended' => false],
            ['type' => 'sms', 'label' => 'SMS', 'recommended' => false],
        ]);

        config(['mfa.factors.totp.recommended' => false, 'mfa.factors.sms.recommended' => true]);

        expect($this->getJson('/mfa/settings')->json('availableTypes.*.type'))->toBe(['sms', 'totp', 'email'])
            ->and(Mfa::isTypeRecommended(FactorType::Sms))->toBeTrue()
            ->and(Mfa::isTypeRecommended(FactorType::Totp))->toBeFalse();
    });

    it('regenerates the session id when marking a session verified (fixation)', function () {
        [$user] = $this->userWithFactor();
        $request = Request::create('/');
        $request->setLaravelSession($session = app('session')->driver());
        $before = $session->getId();

        Mfa::markVerified($request, $user);

        expect($session->getId())->not->toBe($before)
            ->and(Mfa::isVerified($session, $user))->toBeTrue();
    });

    it('checks the first configured guard when none is given', function () {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'mfa.guards' => ['admin', 'web']]);
        $user = $this->makeUser();
        session()->put(Mfa::sessionKey('admin', $user->id), 1);

        expect(Mfa::isVerified(session()->driver(), $user))->toBeTrue();
        session()->forget('mfa');
        session()->put(Mfa::sessionKey('web', $user->id), 1);
        expect(Mfa::isVerified(session()->driver(), $user))->toBeFalse();
    });

    it('uses the explicitly passed request for the impersonation grant', function () {
        $target = $this->makeUser(); // no MFA: an MFA target needs a verified impersonator (D9)
        $request = Request::create('/');
        $request->setLaravelSession($session = new Store('other', new ArraySessionHandler(10)));

        Mfa::grantForImpersonation($this->makeUser(), $target, $request);

        expect($session->has(Mfa::sessionKey('web', $target->id)))->toBeTrue()
            ->and(session()->has(Mfa::sessionKey('web', $target->id)))->toBeFalse();
    });

    it('lets an impersonator without MFA support grant, and records who it was', function () {
        Event::fake([Events\ImpersonationGranted::class]);
        $target = $this->makeUser(); // no MFA: an MFA target needs a verified impersonator (D9)
        $impersonator = new GenericUser(['id' => 77]);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());

        Mfa::grantForImpersonation($impersonator, $target, $request);

        Event::assertDispatched(Events\ImpersonationGranted::class, fn ($e) => $e->context === ['impersonator_type' => GenericUser::class, 'impersonator_id' => 77]);
    });

    it('grants under the first configured guard when the target is not logged in', function () {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'mfa.guards' => ['admin', 'web']]);
        $target = $this->makeUser(); // no MFA: an MFA target needs a verified impersonator (D9)
        $request = Request::create('/');
        $request->setLaravelSession($session = app('session')->driver());

        Mfa::grantForImpersonation($this->makeUser(), $target, $request);

        expect($session->has(Mfa::sessionKey('admin', $target->id)))->toBeTrue();
    });

    it('applies fakeCodes even after the factor driver was already built', function () {
        Mfa::factor(FactorType::Sms);                // driver cached with the real generator
        Mfa::fakeCodes('246810');
        $sms = Mfa::fakeSms();
        [, $factor] = $this->userWithFactor(FactorType::Sms);

        Mfa::factor(FactorType::Sms)->challenge($factor);

        expect($sms->lastCodeFor('+15555550100'))->toBe('246810');
    });

    it('acts on the pending guard even when an earlier guard is already verified, with the same user id', function () {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'mfa.guards' => ['web', 'admin']]);
        [$user, $factor] = $this->userWithFactor();
        $this->actingAsMfaVerified($user, 'web')->loginWithSession($user, 'admin');

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])->assertOk();

        expect(Mfa::isVerified(session()->driver(), $user, 'admin'))->toBeTrue();
        $this->get('/dashboard')->assertOk();
    });

    it('lets users of a model without MFA support through, but refuses them the MFA pages', function () {
        config(['auth.providers.plain' => ['driver' => 'eloquent', 'model' => PlainUser::class], 'auth.guards.web.provider' => 'plain']);
        $plain = PlainUser::create(['name' => 'p', 'email' => 'p@example.com', 'password' => 'x']);

        $this->loginWithSession($plain)->get('/dashboard')->assertOk();
        $this->getJson('/mfa/challenge')->assertForbidden();
    });
});

describe('middleware details', function () {
    it('records the path in ChallengeRequired / EnrollmentRequired, with a flow id', function () {
        Event::fake([Events\ChallengeRequired::class, Events\EnrollmentRequired::class]);
        config(['mfa.enforcement.policy' => EnforceForAdmins::class]);
        [$user] = $this->userWithFactor();
        $admin = $this->makeUser(['is_admin' => true]);

        $this->loginWithSession($user)->get('/dashboard?x=1');
        $this->freshGuards()->post('/logout');
        $this->loginWithSession($admin)->get('/dashboard');

        Event::assertDispatched(Events\ChallengeRequired::class, fn ($e) => $e->context === ['path' => '/dashboard']);
        Event::assertDispatched(Events\EnrollmentRequired::class, fn ($e) => $e->context === ['path' => '/dashboard'] && $e->flowId !== null);
    });

    it('only remembers the intended URL for full-page GET requests', function () {
        [$user] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->post('/dashboard');
        expect(session('url.intended'))->toBeNull();

        $this->get('/dashboard', ['X-Requested-With' => 'XMLHttpRequest']);
        expect(session('url.intended'))->toBeNull();
    });

    it('accepts except patterns written with a leading slash', function () {
        config(['mfa.middleware.except' => ['/dashboard']]);
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)->get('/dashboard')->assertOk();
    });
});

describe('responses and controllers', function () {
    it('answers Inertia failures with a redirect and session errors, not JSON', function () {
        config(['mfa.ui.driver' => 'inertia']);
        [$user, $factor] = $this->userWithFactor();

        $this->loginWithSession($user)
            ->post('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'], ['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect()
            ->assertSessionHasErrors(['code' => 'The provided code is invalid.']);
    });

    it('refuses the MFA pages to a user who is not logged in through the session', function () {
        [$user] = $this->userWithFactor();

        $this->actingAs($user)->getJson('/mfa/challenge')->assertForbidden();
    });

    it('sends verified users and users without factors away from the challenge', function () {
        config(['mfa.ui.driver' => 'inertia']);
        [$user] = $this->userWithFactor();
        $this->actingAsMfaVerified($user)->get('/mfa/challenge')->assertRedirect('/dashboard');

        $this->freshGuards()->post('/logout');
        $this->loginWithSession($this->makeUser())->get('/mfa/challenge')->assertRedirect('/dashboard');
    });

    it('reports hasRecoveryCodes and the default factor', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->getJson('/mfa/challenge')->assertJsonPath('hasRecoveryCodes', false)->assertJsonPath('defaultFactorId', $factor->id);
        app(RecoveryCodes::class)->generate($user);
        $this->getJson('/mfa/challenge')->assertJsonPath('hasRecoveryCodes', true);
    });

    it('validates challenge input', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge/send', [])->assertJsonValidationErrors('factor_id');
        $this->postJson('/mfa/challenge', ['code' => '123456'])->assertJsonValidationErrors('factor_id');
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id])->assertJsonValidationErrors('code');
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => ['1']])->assertJsonValidationErrors('code');
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => str_repeat('1', 17)])->assertJsonValidationErrors('code');
        $this->postJson('/mfa/challenge/recover', [])->assertJsonValidationErrors('code');
        $this->postJson('/mfa/challenge/recover', ['code' => ['x']])->assertJsonValidationErrors('code');
        $this->postJson('/mfa/challenge/recover', ['code' => str_repeat('a', 33)])->assertJsonValidationErrors('code');
    });

    it('answers a TOTP "send" with no retry_after', function () {
        [$user, $factor] = $this->userWithFactor();

        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertExactJson(['status' => 'code-sent']);
    });

    it('logs the user in with a recovery code and records how', function () {
        Event::fake([Events\VerificationSucceeded::class]);
        [$user] = $this->userWithFactor();
        $code = app(RecoveryCodes::class)->generate($user)[0];

        $this->loginWithSession($user)->postJson('/mfa/challenge/recover', ['code' => $code])->assertOk();

        $this->get('/dashboard')->assertOk();
        Event::assertDispatched(Events\VerificationSucceeded::class, fn ($e) => $e->context === ['via' => 'recovery_code', 'remaining' => 9]);
    });

    it('hides pending enrollments of disabled types or older than 30 minutes', function () {
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());
        addSms('+15555550101')->assertOk();
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();

        config(['mfa.factors.sms.enabled' => false]);
        expect(collect($this->getJson('/mfa/settings')->json('pending'))->pluck('type')->all())->toBe(['totp']);

        $this->travel(31)->minutes();
        expect($this->getJson('/mfa/settings')->json('pending'))->toBe([]);
    });

    it('validates enrollment input', function () {
        $this->loginWithSession($this->makeUser());

        $this->postJson('/mfa/factors', [])->assertJsonValidationErrors('type');
        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => ['x']])->assertJsonValidationErrors('destination');
        $this->postJson('/mfa/factors', ['type' => 'email', 'destination' => str_repeat('a', 251).'@x.io'])->assertJsonValidationErrors('destination');
        $this->postJson('/mfa/factors', ['type' => 'totp', 'label' => ['x']])->assertJsonValidationErrors('label');
        $this->postJson('/mfa/factors', ['type' => 'totp', 'label' => str_repeat('a', 101)])->assertJsonValidationErrors('label');

        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
        $id = MfaFactor::sole()->id;
        $this->postJson("/mfa/factors/{$id}/confirm", [])->assertJsonValidationErrors('code');
        $this->postJson("/mfa/factors/{$id}/confirm", ['code' => ['1']])->assertJsonValidationErrors('code');
        $this->postJson("/mfa/factors/{$id}/confirm", ['code' => str_repeat('1', 17)])->assertJsonValidationErrors('code');
    });

    it('returns no recovery codes when confirming a second factor', function () {
        $user = $this->makeUser();
        $this->createMfaFactor($user, FactorType::Email);
        app(RecoveryCodes::class)->generate($user);
        $this->actingAsMfaVerified($user);

        // A TOTP enrollment started earlier in this (verified) session.
        $secret = (new Google2FA)->generateSecretKey(32);
        $pending = $user->mfaFactors()->create(['type' => FactorType::Totp, 'secret' => $secret]);
        PendingEnrollments::add(session()->driver(), $pending->id);

        $response = $this->postJson("/mfa/factors/{$pending->id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))]);

        $response->assertExactJson(['status' => 'factor-enabled']);
    });

    it('marks the session verified with the enrolled factor on first enrollment', function () {
        Event::fake([Events\VerificationSucceeded::class]);
        $user = $this->makeUser();
        $this->loginWithSession($user);
        $secret = $this->postJson('/mfa/factors', ['type' => 'totp'])->json('setup.secret');
        $id = MfaFactor::sole()->id;

        $this->postJson("/mfa/factors/{$id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))])->assertOk();

        Event::assertDispatched(Events\VerificationSucceeded::class, fn ($e) => $e->factorType === FactorType::Totp && $e->context === ['stage' => 'enrollment']);
    });

    it('returns retry_after when resending during enrollment', function () {
        Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user);
        addSms('+15555550101')->assertOk();
        $this->travel(3)->minutes();

        $this->postJson('/mfa/factors/'.MfaFactor::sole()->id.'/resend')->assertExactJson(['status' => 'code-sent', 'retry_after' => 240]);
    });
});

describe('final triage round', function () {
    it('challenges a remember-me login even on a page without auth middleware that reads the user', function () {
        [$user] = $this->userWithFactor();
        $user->forceFill(['remember_token' => 'remember-me-token'])->save();

        $this->withCookie(auth()->guard('web')->getRecallerName(), $user->id.'|remember-me-token|'.$user->getAuthPassword())
            ->get('/public-user')
            ->assertRedirect(route('mfa.challenge'));
    });

    it('lets the distinct-destination caps expire after their window', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 1, 'mfa.rate_limit.new_destinations_per_account_per_day' => 1]);
        Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user);

        addSms('+15555550101', '198.51.100.1')->assertOk();
        addSms('+15555550102', '198.51.100.2')->assertStatus(429);   // account: 1 distinct per day

        $this->travel(25)->hours();
        addSms('+15555550103', '198.51.100.1')->assertOk();          // both windows have passed
    });

    it('keeps the per-account distinct cap for the whole day (not the hourly window)', function () {
        config(['mfa.rate_limit.new_destinations_per_account_per_day' => 1]);
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());

        addSms('+15555550101', '198.51.100.1')->assertOk();
        $this->travel(2)->hours();
        addSms('+15555550102', '198.51.100.2')->assertStatus(429);
    });

    it('accepts explicit nulls for the optional enrollment fields', function () {
        Notification::fake();
        $user = $this->makeUser();

        $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'email', 'destination' => null, 'label' => null])->assertOk();
        expect($user->mfaFactors()->sole()->destination)->toBe($user->email);
    });

    it('rolls back the account distinct counter when the per-IP cap refuses', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 1, 'mfa.rate_limit.new_destinations_per_account_per_day' => 3]);
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());

        addSms('+15555550101', '198.51.100.1')->assertOk();          // account 1, ip1 1
        addSms('+15555550102', '198.51.100.1')->assertStatus(429);   // ip1 full → account must not count it
        addSms('+15555550102', '198.51.100.2')->assertOk();          // account 2
        addSms('+15555550103', '198.51.100.3')->assertOk();          // account 3 (would be 4 without the rollback)
        addSms('+15555550104', '198.51.100.4')->assertStatus(429);   // account full
    });

    it('still applies the per-IP cap to a destination the account already counted', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 1]);
        Mfa::fakeSms();
        $this->loginWithSession($this->makeUser());
        addSms('+15555550101', '198.51.100.1')->assertOk();

        $this->freshGuards()->loginWithSession($this->makeUser());
        addSms('+15555550102', '198.51.100.2')->assertOk();          // fills ip .2

        $this->freshGuards()->loginWithSession(User::first());
        $this->travel(3)->minutes();
        addSms('+15555550101', '198.51.100.2')->assertStatus(429);   // account has seen it, ip .2 has not
    });

    it('draws recovery code characters uniformly', function () {
        $chars = str_replace('-', '', implode('', array_map(fn () => (new RandomCodeGenerator)->recoveryCode(), range(1, 3000))));
        $counts = count_chars($chars, 1);

        expect(max($counts) / (strlen($chars) / count($counts)))->toBeLessThan(1.3);
    });

    it('keeps a custom label for authenticator apps', function () {
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'totp', 'label' => 'Phone'])->assertOk();

        expect($user->mfaFactors()->sole()->label)->toBe('Phone');
    });

    it('reports hasRecoveryCodes with exactly one code left', function () {
        [$user, $factor] = $this->userWithFactor();
        $codes = app(RecoveryCodes::class)->generate($user);
        foreach (array_slice($codes, 1) as $code) {
            app(RecoveryCodes::class)->consume($user, $code);
        }

        $this->loginWithSession($user)->getJson('/mfa/challenge')->assertJsonPath('hasRecoveryCodes', true);
    });

    it('explains a refused confirm from another session', function () {
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'totp']);
        $id = $user->mfaFactors()->sole()->id;
        app('session')->driver()->flush();

        $this->freshGuards()->loginWithSession($user)
            ->postJson("/mfa/factors/{$id}/confirm", ['code' => '123456'])
            ->assertJsonPath('errors.code.0', 'This verification method is not available.');
    });

    it('binds a fresh flow id for EnrollmentRequired', function () {
        Event::fake([Events\EnrollmentRequired::class]);
        config(['mfa.enforcement.policy' => EnforceForAdmins::class]);
        Context::flush();

        $this->loginWithSession($this->makeUser(['is_admin' => true]))->get('/dashboard');

        Event::assertDispatched(Events\EnrollmentRequired::class, fn ($e) => is_string($e->flowId));
    });

    it('grants under the guard the target is logged in with, even when it is not the first', function () {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'], 'mfa.guards' => ['web', 'admin']]);
        $other = $this->makeUser();                          // no MFA, on web
        $target = $this->makeUser();                         // no MFA, on admin
        $this->loginWithSession($other, 'web')->loginWithSession($target, 'admin');
        $request = Request::create('/');
        $request->setLaravelSession($session = app('session')->driver());

        Mfa::grantForImpersonation($this->makeUser(), $target, $request);

        expect($session->has(Mfa::sessionKey('admin', $target->id)))->toBeTrue()
            ->and($session->has(Mfa::sessionKey('web', $target->id)))->toBeFalse();
    });

    it('reports retry_after for the window that actually refused', function () {
        config(['mfa.rate_limit.verify_per_minute' => 1, 'mfa.rate_limit.verify_per_day' => 2]);
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000']);
        $minute = $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])->json('retry_after');
        expect($minute)->toBeLessThanOrEqual(60);               // day count is AT the limit, not over it

        $this->travel(61)->seconds();
        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000']);
        $day = $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])->json('retry_after');
        expect($day)->toBeGreaterThan(80000);
    });

    it('includes attempts_remaining in OTP verification failures', function () {
        Event::fake([Events\VerificationFailed::class]);
        Mfa::fakeCodes('111111');
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '222222']);

        Event::assertDispatched(Events\VerificationFailed::class, fn ($e) => $e->context === ['stage' => 'challenge', 'factor_id' => $factor->id, 'attempts_remaining' => 4]);
    });

    it('masks only the first @ split', function () {
        expect(Mask::email('a@b@c.io'))->toBe('a***@b@c.io');
    });

    it('enforces length limits with the validation message, not a later code failure', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => str_repeat('1', 17)])
            ->assertJsonValidationErrors(['code' => 'must not be greater than 16']);
        $this->postJson('/mfa/challenge/recover', ['code' => str_repeat('a', 33)])
            ->assertJsonValidationErrors(['code' => 'must not be greater than 32']);

        $this->freshGuards()->post('/logout');
        $this->loginWithSession($this->makeUser());
        $this->postJson('/mfa/factors', ['type' => 'email', 'destination' => str_repeat('a', 251).'@x.io'])
            ->assertJsonValidationErrors(['destination' => 'must not be greater than 255']);
        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => ['x']])
            ->assertJsonValidationErrors(['destination' => 'must be a string']);
        $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk();
        $this->postJson('/mfa/factors/'.MfaFactor::whereNull('confirmed_at')->sole()->id.'/confirm', ['code' => str_repeat('1', 17)])
            ->assertJsonValidationErrors(['code' => 'must not be greater than 16']);
    });
});
