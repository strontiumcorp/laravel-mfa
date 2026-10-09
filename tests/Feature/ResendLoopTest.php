<?php

/*
 * The resend cooldown spans logins (reported 2026-10-09): someone with the
 * password and the inbox/phone could log in, verify, log out and log in again,
 * and every round sent a code at once (only send_per_hour bounded it). A
 * successful verification no longer resets the curve: the first re-login is
 * still free, then each round waits one step lower on the curve, measured from
 * the last send (2, 4, 8, 15 minutes with the defaults).
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\SuspiciousCodeRequests;
use StrontiumCorp\LaravelMfa\Facades\Mfa;

beforeEach(function () {
    $this->freezeSecond(); // exact waits
    Mfa::fakeCodes('123456');
    Mfa::fakeSms();
    Notification::fake();
    // The daily caps have their own tests (SendLimitsTest); keep them out of the curve.
    config(['mfa.factors.email.send_per_day' => null, 'mfa.factors.sms.send_per_day' => null]);
});

/** Log in like a login form, open the challenge, and ask for a code. */
function loginAndSend($user, $factor)
{
    test()->freshGuards()->loginWithSession($user)->getJson(route('mfa.challenge'))->assertOk();

    return test()->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id]);
}

/** Verify with the (fixed) code, then log out. */
function verifyAndLogOut($factor): void
{
    test()->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '123456'])->assertOk();
    test()->postJson('/logout')->assertOk();
}

it('makes each login round wait longer: free, free, 2, 4, 8, then 15 minutes', function (FactorType $type) {
    [$user, $factor] = $this->userWithFactor($type);

    // 1st and 2nd rounds: sent at once.
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);

    foreach ([120, 240, 480, 900] as $wait) {
        loginAndSend($user, $factor)->assertStatus(429)
            ->assertJsonPath('retry_after', $wait)
            ->assertJsonPath('errors.code.0', 'Please wait before requesting another code.');

        $this->travel($wait - 1)->seconds();
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429)->assertJsonPath('retry_after', 1);
        $this->travel(1)->seconds();
        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
        verifyAndLogOut($factor);
    }

    // Capped at the curve's maximum.
    loginAndSend($user, $factor)->assertStatus(429)->assertJsonPath('retry_after', 900);
})->with([FactorType::Email, FactorType::Sms]);

it('forgets sends older than an hour', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);

    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    loginAndSend($user, $factor)->assertStatus(429)->assertJsonPath('retry_after', 120);

    $this->travel(61)->minutes();

    // A fresh streak: sent at once, and the next resend waits the first step.
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertExactJson(['status' => 'code-sent', 'retry_after' => 120]);
});

it('still sends at once when the last code expired, even mid-streak', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    loginAndSend($user, $factor)->assertOk();

    $this->travel(601)->seconds(); // never used: it expires

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
});

it('still sends at once when the last code expired while being entered', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    loginAndSend($user, $factor)->assertOk();

    $this->travel(601)->seconds();
    // The right code, too late: the code is marked used, but it was not a verification.
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '123456'])->assertUnprocessable();

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
});

it('still sends at once when the last code was burned by wrong guesses, even mid-streak', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    loginAndSend($user, $factor)->assertOk();

    foreach (range(1, 5) as $_) {
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])->assertUnprocessable();
    }

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
});

it('keeps the normal curve while the code out is still usable', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);

    // The 2nd send of the streak: a resend of this code waits the 2nd step.
    loginAndSend($user, $factor)->assertExactJson(['status' => 'code-sent', 'retry_after' => 240]);
    $this->travel(100)->seconds();
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429)->assertJsonPath('retry_after', 140);
});

it('gives the challenge page no code out and the wait, after a verified code', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $state = fn () => collect($this->getJson(route('mfa.challenge'))->assertOk()->json('factors.0'))
        ->only(['code_sent', 'retry_after', 'expires_in'])->all();

    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    $this->freshGuards()->loginWithSession($user);
    expect($state())->toBe(['code_sent' => false, 'retry_after' => null, 'expires_in' => null]); // first re-login: free

    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
    verifyAndLogOut($factor);
    $this->freshGuards()->loginWithSession($user);
    $this->travel(30)->seconds();

    expect($state())->toBe(['code_sent' => false, 'retry_after' => 90, 'expires_in' => null]);
    // And the server agrees with what the page shows.
    $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429)->assertJsonPath('retry_after', 90);

    $this->travel(90)->seconds();
    expect($state())->toBe(['code_sent' => false, 'retry_after' => null, 'expires_in' => null]);
});

it('gives Inertia pages the same state', function () {
    config(['mfa.ui.driver' => 'inertia']);
    [$user, $factor] = $this->userWithFactor(FactorType::Email);
    // Inertia requests only: a controller is reused across one test's requests.
    foreach (range(1, 2) as $_) {
        $this->freshGuards()->loginWithSession($user)->post(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertRedirect();
        $this->post(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '123456'])->assertRedirect();
        $this->post('/logout')->assertOk();
    }
    $this->freshGuards()->loginWithSession($user);

    $this->get(route('mfa.challenge'), ['X-Inertia' => 'true'])->assertOk()
        ->assertJsonPath('props.factors.0.code_sent', false)
        ->assertJsonPath('props.factors.0.retry_after', 120)
        ->assertJsonPath('props.factors.0.expires_in', null);
});

it('still warns only about sends that were never verified', function () {
    Event::fake([SuspiciousCodeRequests::class]);
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);

    // 5 verified rounds in the hour: an owner logging in often, not an attacker.
    loginAndSend($user, $factor)->assertOk();
    verifyAndLogOut($factor);
    foreach ([0, 120, 240, 480] as $wait) {
        $this->travel($wait)->seconds();
        loginAndSend($user, $factor)->assertOk();
        verifyAndLogOut($factor);
    }

    Event::assertNotDispatched(SuspiciousCodeRequests::class);
});
