<?php

use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\PendingEnrollments;

/*
 * mfa.enrollment.ttl: how long a setup may take, from starting it to confirming its code, and
 * then to downloading its recovery codes and pressing Finish (a new set of codes for an account
 * that has some waits as long). 60 minutes unless MFA_ENROLLMENT_TTL says otherwise.
 */

beforeEach(function () {
    $this->user = $this->makeUser();
    $this->loginWithSession($this->user);
});

/** Start an authenticator setup; returns it. */
function startAppSetup($test): MfaFactor
{
    $test->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();

    return MfaFactor::query()->whereNull('confirmed_at')->latest('id')->firstOrFail();
}

it('gives a setup 60 minutes by default', function () {
    expect(config('mfa.enrollment.ttl'))->toBe(60)
        ->and(PendingEnrollments::ttlMinutes())->toBe(60);

    $factor = startAppSetup($this);
    $this->travel(59)->minutes();
    $this->getJson(route('mfa.settings'))->assertJsonCount(1, 'pending');
    $this->postJson(route('mfa.factors.confirm', $factor->id), ['code' => $this->currentTotpCode($factor)])
        ->assertOk()
        ->assertJsonPath('status', 'recovery-codes-pending');

    // The codes then wait 60 minutes of their own.
    $this->travel(59)->minutes();
    $this->get(route('mfa.recovery-codes.download'))->assertOk();
    $this->travel(2)->minutes();
    $this->get(route('mfa.recovery-codes.download'))->assertNotFound();
    $this->postJson(route('mfa.recovery-codes.finish'))->assertUnprocessable();
    expect($factor->fresh()->confirmed_at)->toBeNull();
});

it('ends a setup after the configured window', function () {
    config(['mfa.enrollment.ttl' => 10]);

    $late = startAppSetup($this);
    $this->travel(11)->minutes();
    $this->getJson(route('mfa.settings'))->assertJsonCount(0, 'pending');
    $this->postJson(route('mfa.factors.confirm', $late->id), ['code' => $this->currentTotpCode($late)])->assertUnprocessable();

    $factor = startAppSetup($this);
    $this->postJson(route('mfa.factors.confirm', $factor->id), ['code' => $this->currentTotpCode($factor)])->assertOk();
    $this->travel(9)->minutes();
    $this->getJson(route('mfa.settings'))->assertJsonPath('pendingRecoveryCodes.factor.id', $factor->id);
    $this->travel(2)->minutes();
    $this->getJson(route('mfa.settings'))->assertJsonPath('pendingRecoveryCodes', null);
    $this->get(route('mfa.recovery-codes.download'))->assertNotFound();
});

it('keeps a new set of codes for an account that has some waiting as long', function () {
    config(['mfa.enrollment.ttl' => 10]);
    $this->createMfaFactor($this->user);
    $this->actingAsMfaVerified($this->user);

    $this->postJson(route('mfa.recovery-codes.store'))->assertOk();
    $this->travel(9)->minutes();
    $this->get(route('mfa.recovery-codes.download'))->assertOk();
    $this->travel(2)->minutes();
    $this->postJson(route('mfa.recovery-codes.finish'))->assertUnprocessable();
});

it('reads the window from MFA_ENROLLMENT_TTL', function () {
    $_ENV['MFA_ENROLLMENT_TTL'] = $_SERVER['MFA_ENROLLMENT_TTL'] = '90';
    putenv('MFA_ENROLLMENT_TTL=90');

    try {
        expect((require __DIR__.'/../../config/mfa.php')['enrollment']['ttl'])->toBe(90);
    } finally {
        unset($_ENV['MFA_ENROLLMENT_TTL'], $_SERVER['MFA_ENROLLMENT_TTL']);
        putenv('MFA_ENROLLMENT_TTL');
    }
});

it('never prunes a setup that could still be finished, however long the window', function () {
    // Up to one window to confirm the code, then a fresh one for its codes: two windows in all.
    config(['mfa.enrollment.ttl' => 2 * 1440]);
    $factor = startAppSetup($this);
    $prune = fn () => $this->artisan('model:prune', ['--model' => [MfaFactor::class]]);

    $this->travel(4 * 24 - 1)->hours();
    $prune();
    expect($factor->fresh())->not->toBeNull();

    $this->travel(2)->hours();
    $prune();
    expect($factor->fresh())->toBeNull();
});
