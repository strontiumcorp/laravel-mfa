<?php

use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

/*
 * Pins the JSON contract documented in docs/json-mode.md. If one of these
 * fails, update the docs in the same change.
 */

it('GET /mfa/challenge', function () {
    [$user] = $this->userWithFactor(FactorType::Sms);
    app(RecoveryCodes::class)->generate($user);

    $this->loginWithSession($user)->getJson('/mfa/challenge')->assertOk()->assertExactJsonStructure([
        'factors' => ['*' => ['id', 'type', 'type_label', 'label', 'destination', 'confirmed', 'confirmed_at', 'last_used_at']],
        'defaultFactorId', 'hasRecoveryCodes',
        'urls' => ['send', 'verify', 'recover', 'logout'],
        'status', 'recoveryCodes', 'retryAfter',
    ]);
});

it('POST /mfa/challenge/send, /mfa/challenge, /mfa/challenge/recover', function () {
    $sms = Mfa::fakeSms();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $code = app(RecoveryCodes::class)->generate($user)[0];
    $this->loginWithSession($user);

    $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertExactJson(['status' => 'code-sent', 'retry_after' => 120]);

    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => $sms->lastCodeFor('+15555550100')])
        ->assertExactJsonStructure(['status', 'redirect'])->assertJsonPath('status', 'verified');

    $this->freshGuards()->post('/logout');
    $this->loginWithSession($user)->postJson('/mfa/challenge/recover', ['code' => $code])
        ->assertExactJsonStructure(['status', 'redirect', 'remaining'])
        ->assertJsonPath('status', 'verified-with-recovery-code');
});

it('error shapes: 422 invalid, 429 throttled, 403 from the middleware', function () {
    [$user, $factor] = $this->userWithFactor();
    $this->loginWithSession($user);

    $this->getJson('/api/me')->assertStatus(403)->assertExactJson([
        'message' => 'Multi-factor authentication required.', 'error' => 'mfa_required', 'redirect' => url('/mfa/challenge'),
    ]);

    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])
        ->assertStatus(422)->assertExactJsonStructure(['message', 'errors' => ['code']]);

    config(['mfa.rate_limit.verify_per_minute' => 1]);
    $this->postJson('/mfa/challenge', ['factor_id' => $factor->id, 'code' => '000000'])
        ->assertStatus(429)->assertExactJsonStructure(['message', 'errors' => ['code'], 'retry_after']);
});

it('GET /mfa/settings says how many recovery codes a fresh set has', function () {
    config(['mfa.recovery_codes.count' => 8]);
    $this->loginWithSession($this->makeUser());

    $this->getJson('/mfa/settings')->assertJson(['recoveryCodesRemaining' => 0, 'recoveryCodesTotal' => 8]);
});

it('GET /mfa/settings and the enrollment endpoints', function () {
    $user = $this->makeUser();
    $this->loginWithSession($user);

    $this->getJson('/mfa/settings')->assertOk()->assertExactJsonStructure([
        'factors', 'pending', 'availableTypes' => ['*' => ['type', 'label', 'recommended']], 'recoveryCodesRemaining', 'recoveryCodesTotal', 'mustEnroll', 'requiredTypes',
        'urls' => ['store', 'confirm', 'resend', 'destroy', 'recoveryCodes', 'confirmPassword'], 'passwordConfirmationRequired', 'passwordRetryAfter', 'status', 'recoveryCodes', 'retryAfter',
    ]);

    $created = $this->postJson('/mfa/factors', ['type' => 'totp'])->assertOk()->assertExactJsonStructure([
        'status', 'factor' => ['id', 'type', 'type_label', 'label', 'destination', 'confirmed', 'confirmed_at', 'last_used_at'],
        'setup' => ['secret', 'otpauth_url', 'qr_svg'],
    ]);

    $id = $created->json('factor.id');
    $code = (new Google2FA)->oathTotp($created->json('setup.secret'), intdiv(now()->getTimestamp(), 30));

    $this->postJson("/mfa/factors/{$id}/confirm", ['code' => $code])
        ->assertExactJsonStructure(['status', 'recovery_codes'])->assertJsonPath('status', 'factor-enabled');

    $this->postJson('/mfa/recovery-codes')->assertExactJsonStructure(['status', 'recovery_codes'])
        ->assertJsonPath('status', 'recovery-codes-generated');

    $this->deleteJson("/mfa/factors/{$id}")->assertExactJson(['status' => 'factor-disabled']);
});

it('POST /mfa/factors with a delivered type returns a masked destination', function () {
    Mfa::fakeSms();
    $this->loginWithSession($this->makeUser());

    $this->postJson('/mfa/factors', ['type' => 'sms', 'destination' => '+15555550142'])
        ->assertExactJsonStructure(['status', 'factor', 'setup' => ['destination', 'sent', 'reason', 'retry_after'], 'retry_after'])
        ->assertJsonPath('setup.destination', '+*******0142');
});

it('POST /mfa/factors (and the other factor changes) answer 423 until the password is confirmed', function () {
    config(['mfa.routes.password_confirmation' => true]);
    $this->loginWithSession($this->makeUser());

    $this->postJson('/mfa/factors', ['type' => 'totp'])->assertStatus(423)->assertExactJson([
        'message' => 'Please confirm your password to continue.',
        'error' => 'password_confirmation_required',
        'confirm_url' => route('mfa.password.confirm'),
    ]);
});

it('POST /mfa/confirm-password', function () {
    $this->loginWithSession($this->makeUser());

    $this->postJson('/mfa/confirm-password', ['password' => 'nope'])->assertStatus(422)->assertExactJson([
        'message' => 'The provided password is incorrect.',
        'errors' => ['password' => ['The provided password is incorrect.']],
    ]);
    $this->postJson('/mfa/confirm-password', ['password' => 'password'])->assertExactJson(['status' => 'password-confirmed']);
});

it('POST /mfa/confirm-password over the attempt limit', function () {
    $this->freezeSecond();
    config(['mfa.rate_limit.password_per_minute' => 1]);
    $this->loginWithSession($this->makeUser());
    $this->postJson('/mfa/confirm-password', ['password' => 'nope'])->assertStatus(422);

    $this->postJson('/mfa/confirm-password', ['password' => 'password'])->assertStatus(429)->assertExactJson([
        'message' => 'Too many attempts. Please try again later.',
        'errors' => ['password' => ['Too many attempts. Please try again later.']],
        'retry_after' => 60,
    ]);
});

it('the app\'s password.confirm middleware answers 423 over JSON', function () {
    config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
    require __DIR__.'/../../routes/mfa.php';
    app('router')->getRoutes()->refreshNameLookups();

    $this->loginWithSession($this->makeUser())
        ->postJson('/mfa/factors', ['type' => 'totp'])
        ->assertStatus(423);
});
