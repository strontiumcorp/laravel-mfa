<?php

/*
 * Send limits, as agreed on 2026-10-08:
 *  - unconfirmed destinations (enrollment): 2 messages per destination per
 *    24h across ALL accounts; 3 new destinations per account per day;
 *    10 distinct new destinations per IP per hour (IPv6 per /64);
 *    app-wide breaker on unconfirmed sends.
 *  - confirmed destinations (login): cooldown curve + per-account hourly cap
 *    only. Strangers can never consume this budget.
 */

use Illuminate\Support\Facades\Event;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\SendingCircuitTripped;
use StrontiumCorp\LaravelMfa\Events\SuspiciousCodeRequests;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

const VICTIM = '+15555550199';

function enrollSms(string $phone, string $ip = '203.0.113.7')
{
    $user = test()->makeUser();
    test()->freshGuards()->loginWithSession($user);

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/mfa/factors', ['type' => 'sms', 'destination' => $phone]);
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
            ->assertJsonPath('errors.destination.0', "We can't send codes right now. Please use an authenticator app or try again later.");
        enrollSms('+15555550104', '203.0.113.4')->assertStatus(503);

        Event::assertDispatchedTimes(SendingCircuitTripped::class, 1);
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

describe('retry_after', function () {
    it('tells the client when the next resend unlocks', function () {
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
