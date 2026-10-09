<?php

/*
 * Send limits, as agreed on 2026-10-08:
 *  - unconfirmed destinations (enrollment): 2 messages per destination per
 *    24h across ALL accounts; 3 new destinations per account per day;
 *    10 distinct new destinations per IP per hour (IPv6 per /64);
 *    app-wide breaker on unconfirmed sends.
 *  - confirmed destinations (login): cooldown curve + per-account hourly cap
 *    only. Strangers can never consume this budget.
 * And on 2026-10-09: per-account daily caps per method, across both budgets
 * (factors.email.send_per_day 15, factors.sms.send_per_day 5), counted per
 * network (the client IP; IPv6 per /64).
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\SendingCircuitTripped;
use StrontiumCorp\LaravelMfa\Events\SuspiciousCodeRequests;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\SendGuard;

const VICTIM = '+15555550199';

function enrollSms(string $phone, string $ip = '203.0.113.7')
{
    $user = test()->makeUser();
    test()->freshGuards()->loginWithSession($user);

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/mfa/factors', ['type' => 'sms', 'destination' => $phone]);
}

/** A new user with a confirmed SMS factor asks for a login code. */
function loginSendSms(string $ip = '203.0.113.60'): array
{
    [$user, $factor] = test()->userWithFactor(FactorType::Sms);

    return [$user, $factor, test()->freshGuards()->loginWithSession($user)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])];
}

describe('unconfirmed destinations (enrollment)', function () {
    it('sends at most 2 messages per destination per day, across all accounts', function () {
        $sms = Mfa::fakeSms();

        enrollSms(VICTIM)->assertOk();
        enrollSms(VICTIM)->assertOk();
        enrollSms(VICTIM)->assertStatus(429)
            ->assertJsonPath('errors.destination.0', 'Too many codes were sent to this destination today. Try again tomorrow, or use an authenticator app.');

        $sms->assertSentTo(VICTIM, 2);

        $this->travel(25)->hours();
        enrollSms(VICTIM)->assertOk();
    });

    it('counts resends during enrollment toward the same 2', function () {
        $sms = Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson('/mfa/factors', ['type' => 'sms', 'destination' => VICTIM])->assertOk();
        $id = $user->mfaFactors()->sole()->id;

        $this->travel(3)->minutes();
        $this->postJson("/mfa/factors/{$id}/resend")->assertOk();
        $this->travel(5)->minutes();
        $this->postJson("/mfa/factors/{$id}/resend")->assertStatus(429);

        $sms->assertSentTo(VICTIM, 2);
    });

    it('cannot lock the real owner out of their login codes', function () {
        $sms = Mfa::fakeSms();
        [$owner, $factor] = $this->userWithFactor(FactorType::Sms);
        $factor->update(['destination' => VICTIM]);

        // Attackers burn the unconfirmed budget for the victim's number...
        enrollSms(VICTIM)->assertOk();
        enrollSms(VICTIM)->assertOk();
        enrollSms(VICTIM)->assertStatus(429);

        // ...but the owner's login code still goes out.
        $this->freshGuards()->loginWithSession($owner)->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        $sms->assertSentTo(VICTIM, 3);
    });

    it('allows 3 new destinations per account per day', function () {
        Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user);

        foreach (['+15555550101', '+15555550102', '+15555550103'] as $phone) {
            $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => $phone])->assertOk();
        }

        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => '+15555550104'])->assertStatus(429);
    });

    it('allows 10 distinct new destinations per IP per hour, and retries of the same one are free', function () {
        $sms = Mfa::fakeSms();

        foreach (range(10, 19) as $n) {
            enrollSms("+155555501{$n}")->assertOk();
        }
        enrollSms('+15555550110')->assertOk();                 // same number again: not a new destination
        enrollSms('+15555550120')->assertStatus(429);          // 11th distinct
        enrollSms('+15555550121', '198.51.100.9')->assertOk(); // other IP unaffected

        expect($sms->sent)->toHaveCount(12);
    });

    it('groups IPv6 clients by /64', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 1]);
        Mfa::fakeSms();

        enrollSms('+15555550101', '2001:db8:aa:bb::1')->assertOk();
        enrollSms('+15555550102', '2001:db8:aa:bb:ffff::2')->assertStatus(429); // same /64
        enrollSms('+15555550103', '2001:db8:aa:cc::1')->assertOk();             // different /64
    });

    it('trips an app-wide breaker on unconfirmed sends and raises a critical event once', function () {
        Event::fake([SendingCircuitTripped::class]);
        config(['mfa.rate_limit.unconfirmed_global_per_hour' => 2]);
        Mfa::fakeSms();

        enrollSms('+15555550101', '203.0.113.1')->assertOk();
        enrollSms('+15555550102', '203.0.113.2')->assertOk();
        enrollSms('+15555550103', '203.0.113.3')->assertStatus(503)
            ->assertJsonPath('errors.destination.0', 'Too many codes are being sent right now. Try again in 60 minutes, or use an authenticator app.');
        enrollSms('+15555550104', '203.0.113.4')->assertStatus(503);

        Event::assertDispatchedTimes(SendingCircuitTripped::class, 1);
    });

    it('raises the breaker event once per window, so a new window that trips alerts again', function () {
        Event::fake([SendingCircuitTripped::class]);
        // 2, not 1: Laravel 11.22's RateLimiter re-puts a counter rolled back
        // to 1 with a fresh decay, which would stretch the first window.
        config(['mfa.rate_limit.unconfirmed_global_per_hour' => 2]);
        Mfa::fakeSms();

        enrollSms('+15555550101', '203.0.113.1')->assertOk();
        enrollSms('+15555550102', '203.0.113.2')->assertOk();
        $this->travel(50)->minutes();
        enrollSms('+15555550103', '203.0.113.3')->assertStatus(503);   // trips, 10 minutes left
        $this->travel(11)->minutes();                                   // a new window
        enrollSms('+15555550104', '203.0.113.4')->assertOk();
        enrollSms('+15555550105', '203.0.113.5')->assertOk();
        enrollSms('+15555550106', '203.0.113.6')->assertStatus(503);   // trips again

        Event::assertDispatchedTimes(SendingCircuitTripped::class, 2);
    });

    it('does not create a pending factor when the send is refused', function () {
        config(['mfa.rate_limit.new_destinations_per_ip_per_hour' => 0]);
        Mfa::fakeSms();

        enrollSms('+15555550101')->assertStatus(429);

        expect(MfaFactor::count())->toBe(0);
    });
});

describe('confirmed destinations (login)', function () {
    it('has no per-IP cap, so many users behind one NAT can log in', function () {
        $sms = Mfa::fakeSms();

        foreach (range(1, 25) as $i) {
            [$user, $factor] = $this->userWithFactor(FactorType::Sms);
            $this->freshGuards()->loginWithSession($user)
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
                ->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        }

        expect($sms->sent)->toHaveCount(25);
    });

    it('does not charge the hourly cap for requests rejected by the cooldown', function () {
        config(['mfa.rate_limit.send_per_hour' => 2]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        foreach (range(1, 5) as $_) {
            $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertStatus(429); // cooldown
        }
        $this->travel(2)->minutes();

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk(); // 2nd real send still allowed
    });

    it('warns the app once when login codes keep being requested without a verification', function () {
        Event::fake([SuspiciousCodeRequests::class]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        foreach ([0, 120, 240, 480, 900] as $wait) {      // 5 sends, following the curve
            $this->travel($wait)->seconds();
            $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        }

        Event::assertDispatchedTimes(SuspiciousCodeRequests::class, 1);
        Event::assertDispatched(SuspiciousCodeRequests::class, fn ($e) => $e->context['reason'] === 'repeated_unverified_sends' && $e->user->is($user));
    });

    it('warns the app when a user hits the hourly send cap', function () {
        Event::fake([SuspiciousCodeRequests::class]);
        config(['mfa.rate_limit.send_per_hour' => 1, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertStatus(429);

        Event::assertDispatched(SuspiciousCodeRequests::class, fn ($e) => $e->context['reason'] === 'send_cap_reached');
    });
});

describe('app-wide cap on confirmed sends (SMS pumping)', function () {
    it('pauses login codes app-wide after N sends in an hour, with the wait until the window frees up', function () {
        $this->freezeSecond(); // exact waits
        config(['mfa.rate_limit.confirmed_global_per_hour' => 2]);
        $sms = Mfa::fakeSms();

        loginSendSms()[2]->assertOk();
        $this->travel(10)->minutes();
        loginSendSms()[2]->assertOk();
        $this->travel(5)->minutes();

        // The window opened with the first send: 45 minutes are left.
        loginSendSms()[2]->assertStatus(503)
            ->assertJsonPath('retry_after', 2700);
        expect($sms->sent)->toHaveCount(2);

        $this->travel(2701)->seconds();
        loginSendSms()[2]->assertOk();
    });

    it('tells the user when to try again, in minutes', function () {
        $this->freezeSecond();
        config(['mfa.rate_limit.confirmed_global_per_hour' => 1]);
        Mfa::fakeSms();
        loginSendSms()[2]->assertOk();
        $this->travel(2570)->seconds();

        loginSendSms()[2]->assertStatus(503) // 1030 seconds left: rounded up
            ->assertJsonPath('message', 'Too many codes are being sent right now. Try again in 18 minutes, or use an authenticator app.')
            ->assertJsonPath('retry_after', 1030);

        $this->travel(1000)->seconds();
        loginSendSms()[2]->assertStatus(503)->assertJsonPath('errors.code.0', 'Too many codes are being sent right now. Try again in a minute, or use an authenticator app.');
    });

    it('gives the challenge page the message and the countdown', function () {
        $this->freezeSecond();
        config(['mfa.rate_limit.confirmed_global_per_hour' => 1, 'mfa.ui.driver' => 'inertia']);
        Mfa::fakeSms();
        [$first, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($first)->post('/mfa/challenge/send', ['factor_id' => $factor->id])->assertRedirect();
        $this->travel(3570)->seconds();

        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->freshGuards()->loginWithSession($user)
            ->from('/mfa/challenge')->post('/mfa/challenge/send', ['factor_id' => $factor->id])
            ->assertRedirect('/mfa/challenge')
            ->assertSessionHasErrors(['code' => 'Too many codes are being sent right now. Try again in a minute, or use an authenticator app.']);
        // The resend countdown runs to the same moment.
        $this->get('/mfa/challenge', ['X-Inertia' => 'true'])->assertJsonPath('props.retryAfter', 30);
    });

    it('rolls back the account counter when the cap refuses', function () {
        config(['mfa.rate_limit.confirmed_global_per_hour' => 1, 'mfa.rate_limit.send_per_hour' => 1]);
        Mfa::fakeSms();

        loginSendSms()[2]->assertOk();                  // 1 of 1 app-wide
        [$user, $factor, $refused] = loginSendSms();
        $refused->assertStatus(503);                       // this user's 1 per hour was not used up

        config(['mfa.rate_limit.confirmed_global_per_hour' => 10]);
        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
    });

    it('is not used by enrollment sends, which have their own breaker', function () {
        config(['mfa.rate_limit.confirmed_global_per_hour' => 1]);
        Mfa::fakeSms();

        enrollSms('+15555550101')->assertOk();
        enrollSms('+15555550102')->assertOk();
        loginSendSms()[2]->assertOk();
    });

    it('raises SendingCircuitTripped once when the cap trips', function () {
        Event::fake([SendingCircuitTripped::class]);
        config(['mfa.rate_limit.confirmed_global_per_hour' => 1]);
        Mfa::fakeSms();

        loginSendSms()[2]->assertOk();
        loginSendSms()[2]->assertStatus(503);
        loginSendSms()[2]->assertStatus(503);

        Event::assertDispatchedTimes(SendingCircuitTripped::class, 1);
        Event::assertDispatched(SendingCircuitTripped::class, fn ($e) => $e->context === ['limit' => 1, 'scope' => 'confirmed']);
    });

    it('can be turned off with null or 0', function ($limit) {
        config(['mfa.rate_limit.confirmed_global_per_hour' => $limit]);
        Mfa::fakeSms();

        foreach (range(1, 3) as $_) {
            loginSendSms()[2]->assertOk();
        }
    })->with([null, 0]);

    it('defaults to 1000 an hour', function () {
        expect(config('mfa.rate_limit.confirmed_global_per_hour'))->toBe(1000);
    });
});

describe('daily caps per method (factors.{type}.send_per_day)', function () {
    beforeEach(function () {
        $this->freezeSecond(); // exact waits
        // Only the daily caps: no cooldown, and the hourly cap out of the way.
        config(['mfa.factors.email.resend_cooldown' => 0, 'mfa.factors.sms.resend_cooldown' => 0, 'mfa.rate_limit.send_per_hour' => 100]);
        Notification::fake();
        Mfa::fakeSms();
    });

    $send = fn ($factor) => test()->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

    it('defaults to 15 email and 5 SMS codes a day', function () {
        expect(config('mfa.factors.email.send_per_day'))->toBe(15)
            ->and(config('mfa.factors.sms.send_per_day'))->toBe(5);
    });

    it('refuses the 16th email code in a day, saying when to try again in hours', function () use ($send) {
        [$user, $factor] = $this->userWithFactor(FactorType::Email);
        $this->loginWithSession($user);

        $send($factor)->assertOk();          // the window opens
        $this->travel(19)->hours();
        foreach (range(2, 15) as $_) {
            $send($factor)->assertOk();
        }

        $send($factor)->assertStatus(429)
            ->assertJsonPath('retry_after', 5 * 3600)
            ->assertJsonPath('message', "You've had too many codes today. Try again in 5 hours, or use an authenticator app.");

        $this->travel(4 * 3600 + 1)->seconds(); // 59:59 left
        $send($factor)->assertStatus(429)
            ->assertJsonPath('retry_after', 3599)
            ->assertJsonPath('errors.code.0', "You've had too many codes today. Try again in an hour, or use an authenticator app.");

        $this->travel(3599 - 18 * 60)->seconds(); // 18 minutes left: minutes below an hour
        $send($factor)->assertStatus(429)
            ->assertJsonPath('errors.code.0', "You've had too many codes today. Try again in 18 minutes, or use an authenticator app.");

        $this->travel(18 * 60)->seconds();
        $send($factor)->assertOk();
    });

    it('rounds a wait over an hour up to whole hours', function () use ($send) {
        config(['mfa.factors.sms.send_per_day' => 1]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);
        $send($factor)->assertOk();

        $this->travel(86400 - 3601)->seconds(); // an hour and a second left
        $send($factor)->assertStatus(429)->assertJsonPath('message', "You've had too many codes today. Try again in 2 hours, or use an authenticator app.");

        $this->travel(1)->seconds();            // exactly an hour
        $send($factor)->assertStatus(429)->assertJsonPath('message', "You've had too many codes today. Try again in an hour, or use an authenticator app.");

        $this->travel(3599)->seconds();         // a second
        $send($factor)->assertStatus(429)->assertJsonPath('message', "You've had too many codes today. Try again in a minute, or use an authenticator app.");
    });

    it('refuses the 6th SMS code in a day', function () use ($send) {
        $sms = Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        foreach (range(1, 5) as $_) {
            $send($factor)->assertOk();
        }
        $send($factor)->assertStatus(429)->assertJsonPath('retry_after', 86400);

        $sms->assertSentTo('+15555550100', 5);
    });

    it('counts email and SMS apart', function () use ($send) {
        $user = $this->makeUser();
        $sms = $this->createMfaFactor($user, FactorType::Sms);
        $email = $this->createMfaFactor($user, FactorType::Email);
        $this->loginWithSession($user);

        foreach (range(1, 5) as $_) {
            $send($sms)->assertOk();
        }
        $send($sms)->assertStatus(429);

        foreach (range(1, 15) as $_) {
            $send($email)->assertOk();
        }
        $send($email)->assertStatus(429);
    });

    it('counts per account', function () use ($send) {
        config(['mfa.factors.sms.send_per_day' => 1]);
        [$first, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($first);
        $send($factor)->assertOk();
        $send($factor)->assertStatus(429);

        [$second, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->freshGuards()->loginWithSession($second);
        $send($factor)->assertOk();
    });

    it('counts enrollment sends too, and leaves no pending factor when it refuses', function () {
        config(['mfa.factors.sms.send_per_day' => 1]);
        $this->actingAsMfaVerified($user = $this->makeUser());

        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => '+15555550101'])->assertOk();
        $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => '+15555550102'])->assertStatus(429)
            ->assertJsonPath('errors.destination.0', "You've had too many codes today. Try again in 24 hours, or use an authenticator app.");

        // The first pending SMS was replaced by the second, which was refused and removed.
        expect($user->mfaFactors()->count())->toBe(0);
    });

    it('rolls back the other counters when it refuses', function () use ($send) {
        config(['mfa.rate_limit.send_per_hour' => 2, 'mfa.factors.sms.send_per_day' => 1]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $send($factor)->assertOk();                            // 1 of 2 this hour
        $send($factor)->assertStatus(429)->assertJsonPath('retry_after', 86400);

        config(['mfa.factors.sms.send_per_day' => 10]);
        $send($factor)->assertOk();                            // the refused one did not use the 2nd
        $send($factor)->assertStatus(429)->assertJsonPath('retry_after', 3600);
    });

    it('is not charged for a send another limit refuses', function () use ($send) {
        config(['mfa.rate_limit.send_per_hour' => 1, 'mfa.factors.sms.send_per_day' => 2]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $send($factor)->assertOk();
        $send($factor)->assertStatus(429)->assertJsonPath('retry_after', 3600); // the hourly cap
        $this->travel(1)->hours();

        $send($factor)->assertOk(); // the 2nd of the day is still there
    });

    it('can be turned off with null or 0, and then counts nothing', function ($limit) use ($send) {
        config(['mfa.factors.sms.send_per_day' => $limit]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        foreach (range(1, 7) as $_) {
            $send($factor)->assertOk();
        }

        config(['mfa.factors.sms.send_per_day' => 1]);
        $send($factor)->assertOk(); // none of the 7 was counted
        $send($factor)->assertStatus(429);
    })->with([null, 0]);

    it('leaves the authenticator app and recovery codes working', function () use ($send) {
        config(['mfa.factors.email.send_per_day' => 1]);
        $user = $this->makeUser();
        $email = $this->createMfaFactor($user, FactorType::Email);
        $totp = $this->createMfaFactor($user, FactorType::Totp);
        $this->loginWithSession($user);

        $send($email)->assertOk();
        $send($email)->assertStatus(429);

        expect(collect($this->getJson('/mfa/challenge')->assertOk()->json('factors'))->pluck('type')->sort()->values()->all())->toBe(['email', 'totp']);
        $this->postJson('/mfa/challenge', ['factor_id' => $totp->id, 'code' => $this->currentTotpCode($totp)])->assertOk();
    });

    it('warns the app once', function () use ($send) {
        Event::fake([SuspiciousCodeRequests::class]);
        config(['mfa.factors.sms.send_per_day' => 1]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $send($factor)->assertOk();
        foreach (range(1, 3) as $_) {
            $send($factor)->assertStatus(429);
        }

        Event::assertDispatchedTimes(SuspiciousCodeRequests::class, 1);
        Event::assertDispatched(SuspiciousCodeRequests::class, fn ($e) => $e->context['reason'] === 'send_cap_reached');
    });

    it('writes one audit row per window', function () use ($send) {
        config(['mfa.factors.sms.send_per_day' => 1]);
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $send($factor)->assertOk();
        foreach (range(1, 3) as $_) {
            $send($factor)->assertStatus(429);
        }

        expect(MfaAuditLog::where('reason', 'daily_limit')->count())->toBe(1);
    });
});

/*
 * Reported 2026-10-09: with the password alone, an attacker could log in, burn
 * each code with wrong guesses (a burned code allows a send at once) and use up
 * the owner's daily SMS cap in about five minutes. The daily caps count per
 * user, per method and per network (the client's IP; IPv6 per /64), so the
 * attacker only spends their own network's budget.
 */
describe('daily caps per network', function () {
    beforeEach(function () {
        $this->freezeSecond();
        Mfa::fakeCodes('123456');
        Notification::fake();
        // Only the daily caps: the hourly and the verification caps out of the way.
        config(['mfa.rate_limit.send_per_hour' => 100, 'mfa.rate_limit.verify_per_day' => 1000]);
    });

    /** Log in from $ip and ask for a code. */
    $loginAndSend = fn ($user, $factor, string $ip) => test()->freshGuards()->loginWithSession($user)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/mfa/challenge/send', ['factor_id' => $factor->id]);

    /** Burn the code out with wrong guesses, then wait out the per-minute verify limit. */
    $burn = function ($factor): void {
        foreach (range(1, 5) as $_) {
            test()->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])->assertUnprocessable();
        }
        test()->travel(61)->seconds();
    };

    it('cannot be used up by someone with only the password, from another network', function (FactorType $type, int $cap) use ($loginAndSend, $burn) {
        $sms = Mfa::fakeSms();
        [$owner, $factor] = $this->userWithFactor($type);

        foreach (range(1, $cap) as $_) {
            $loginAndSend($owner, $factor, '198.51.100.66')->assertOk();   // the attacker
            $burn($factor);
        }
        $loginAndSend($owner, $factor, '198.51.100.66')->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_starts_with($m, "You've had too many codes today."));

        // The owner, at home, still gets a login code.
        $loginAndSend($owner, $factor, '203.0.113.10')->assertOk();
        if ($type === FactorType::Sms) {
            $sms->assertSentTo('+15555550100', $cap + 1);
        }
    })->with([[FactorType::Sms, 5], [FactorType::Email, 15]]);

    it('still caps a loop from one network', function (FactorType $type, int $cap) use ($loginAndSend, $burn) {
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor($type);

        foreach (range(1, $cap) as $_) {
            $loginAndSend($user, $factor, '203.0.113.10')->assertOk();
            $burn($factor);
        }

        $loginAndSend($user, $factor, '203.0.113.10')->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_starts_with($m, "You've had too many codes today."));
    })->with([[FactorType::Sms, 5], [FactorType::Email, 15]]);

    it('groups IPv6 clients by /64', function () use ($loginAndSend) {
        config(['mfa.factors.sms.send_per_day' => 1, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $loginAndSend($user, $factor, '2001:db8:aa:bb::1')->assertOk();
        $loginAndSend($user, $factor, '2001:db8:aa:bb:ffff::2')->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_starts_with($m, "You've had too many codes today.")); // same /64
        $loginAndSend($user, $factor, '2001:db8:aa:cc::1')->assertOk();                                                    // another /64
    });

    it('counts sends with no client IP in one shared bucket, still capped', function () use ($loginAndSend) {
        config(['mfa.factors.sms.send_per_day' => 2, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $send = fn () => app(SendGuard::class)->attempt($factor, null);

        expect($send())->toBeNull()
            ->and($send())->toBeNull()
            ->and($send()?->reason)->toBe(FailureReason::DailyLimit)
            ->and(app(SendGuard::class)->attempt($factor, '')?->reason)->toBe(FailureReason::DailyLimit);

        // A client with an IP has its own budget.
        $loginAndSend($user, $factor, '203.0.113.10')->assertOk();
    });

    it('rolls back the daily count of the right network when another limit refuses', function () use ($loginAndSend) {
        config(['mfa.factors.sms.send_per_day' => 1, 'mfa.factors.sms.resend_cooldown' => 0, 'mfa.rate_limit.confirmed_global_per_hour' => 1]);
        Mfa::fakeSms();
        loginSendSms()[2]->assertOk();                                 // someone else uses the app-wide 1
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);

        $loginAndSend($user, $factor, '203.0.113.10')->assertStatus(503); // counted, then rolled back

        config(['mfa.rate_limit.confirmed_global_per_hour' => 100]);
        $loginAndSend($user, $factor, '203.0.113.10')->assertOk();        // its 1 was still there
        $loginAndSend($user, $factor, '203.0.113.10')->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_starts_with($m, "You've had too many codes today."));
        $loginAndSend($user, $factor, '198.51.100.66')->assertOk();       // another network's budget
    });
});

describe('retry_after', function () {
    it('tells the client when the next resend unlocks', function () {
        $this->freezeSecond(); // exact waits: a second boundary mid-test would give 89
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertExactJson(['status' => 'code-sent', 'retry_after' => 120]);

        $this->travel(30)->seconds();
        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])
            ->assertStatus(429)
            ->assertJsonPath('retry_after', 90)
            ->assertJsonPath('errors.code.0', 'Please wait before requesting another code.');
    });

    it('gives Inertia pages the countdown via the retryAfter prop', function () {
        config(['mfa.ui.driver' => 'inertia']);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->post('/mfa/challenge/send', ['factor_id' => $factor->id])->assertRedirect();
        $this->get('/mfa/challenge', ['X-Inertia' => 'true'])->assertJsonPath('props.retryAfter', 120);

        $this->post('/mfa/challenge/send', ['factor_id' => $factor->id])->assertRedirect();
        expect($this->get('/mfa/challenge', ['X-Inertia' => 'true'])->json('props.retryAfter'))->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
    });
});
