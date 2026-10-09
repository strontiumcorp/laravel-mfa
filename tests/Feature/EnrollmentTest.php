<?php

use Illuminate\Support\Facades\Event;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\FactorEnabled;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

beforeEach(function () {
    $this->user = $this->makeUser();
    $this->loginWithSession($this->user);
});

it('enrolls an authenticator app end to end', function () {
    Event::fake([FactorEnabled::class]);

    $setup = $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk()->json('setup');

    expect($setup['qr_svg'])->toStartWith('<svg')->and($setup['secret'])->toHaveLength(32);

    // The settings page can rebuild the QR from the pending factor (no secret in session).
    $factorId = $this->getJson(route('mfa.settings'))->assertOk()->assertJsonCount(1, 'pending')->json('pending.0.id');

    $codes = $this->postJson(route('mfa.factors.confirm', $factorId), [
        'code' => (new Google2FA)->oathTotp($setup['secret'], intdiv(now()->getTimestamp(), 30)),
    ])->assertOk()->json('recovery_codes');

    expect($codes)->toHaveCount(10)
        ->and(Mfa::hasConfirmedFactors($this->user))->toBeTrue()
        ->and(Mfa::isVerified(session()->driver(), $this->user))->toBeTrue();

    Event::assertDispatched(FactorEnabled::class);
    $this->get('/dashboard')->assertOk();
});

it('does not confirm with a wrong code', function () {
    $this->postJson(route('mfa.factors.store'), ['type' => 'totp']);
    $factor = MfaFactor::sole();

    $this->postJson(route('mfa.factors.confirm', $factor->id), ['code' => '000000'])->assertUnprocessable();

    expect($factor->fresh()->confirmed_at)->toBeNull()
        ->and(Mfa::hasConfirmedFactors($this->user))->toBeFalse();
});

it('enrolls a phone number and sends a code', function () {
    $sms = Mfa::fakeSms();

    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+1 555 555 0199'])
        ->assertOk()
        ->assertJsonPath('setup.destination', '+*******0199');

    $factor = MfaFactor::sole();
    $this->postJson(route('mfa.factors.confirm', $factor->id), ['code' => $sms->lastCodeFor('+15555550199')])->assertOk();

    expect($factor->fresh()->isConfirmed())->toBeTrue();
});

it('refuses phone numbers outside the allowed calling codes', function () {
    $sms = Mfa::fakeSms();

    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+8801711000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destination');

    $sms->assertNothingSent();
});

it('counts enrollment sends toward the hourly send limit (SMS pumping)', function () {
    config(['mfa.rate_limit.send_per_hour' => 2]);
    $sms = Mfa::fakeSms();

    foreach (['+15555550101', '+15555550102'] as $phone) {
        $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => $phone])->assertOk();
    }

    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550103'])->assertStatus(429);
    expect($sms->sent)->toHaveCount(2);
});

it('refuses factor types that are disabled', function () {
    config(['mfa.factors.sms.enabled' => false]);

    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550100'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('removes a factor, and recovery codes with the last one', function () {
    Event::fake([FactorDisabled::class]);
    $factor = $this->createMfaFactor($this->user);
    app(RecoveryCodes::class)->generate($this->user);
    $this->actingAsMfaVerified($this->user);

    $this->deleteJson(route('mfa.factors.destroy', $factor->id))->assertOk();

    expect($this->user->mfaFactors()->count())->toBe(0)
        ->and($this->user->mfaRecoveryCodes()->count())->toBe(0)
        ->and(Mfa::hasConfirmedFactors($this->user))->toBeFalse();
    Event::assertDispatched(FactorDisabled::class);
});

it('cannot remove another user\'s factor', function () {
    [, $other] = $this->userWithFactor();

    $this->deleteJson(route('mfa.factors.destroy', $other->id))->assertOk();

    expect($other->fresh())->not->toBeNull();
});

it('regenerates recovery codes', function () {
    $this->createMfaFactor($this->user);
    $this->actingAsMfaVerified($this->user);

    $this->postJson(route('mfa.recovery-codes.store'))->assertOk()->assertJsonCount(10, 'recovery_codes');
});

it('requires password confirmation for sensitive changes when configured', function () {
    config(['mfa.routes.confirm_middleware' => ['password.confirm']]);
    require __DIR__.'/../../routes/mfa.php';
    app('router')->getRoutes()->refreshNameLookups();

    $this->post(route('mfa.factors.store'), ['type' => 'totp'])->assertRedirect(route('password.confirm'));
});

describe('the recovery codes file', function () {
    it('names the app and the account, like the authenticator app does', function () {
        config(['mfa.factors.totp.issuer' => 'Artistly', 'app.env' => 'production']);

        $this->getJson(route('mfa.settings'))->assertOk()->assertJsonPath('recoveryCodesFile', [
            'app' => 'Artistly', 'slug' => 'artistly', 'account' => $this->user->email,
        ]);
    });

    it('names the environment outside production', function () {
        config(['mfa.factors.totp.issuer' => 'Podcast Flow', 'app.env' => 'staging']);

        $this->getJson(route('mfa.settings'))->assertOk()->assertJsonPath('recoveryCodesFile', [
            'app' => 'Podcast Flow (staging)', 'slug' => 'podcast-flow-staging', 'account' => $this->user->email,
        ]);
    });

    it('leaves the environment out when issuer_environment is off', function () {
        config(['mfa.factors.totp.issuer' => 'Artistly', 'mfa.factors.totp.issuer_environment' => false, 'app.env' => 'staging']);

        $this->getJson(route('mfa.settings'))->assertOk()
            ->assertJsonPath('recoveryCodesFile.app', 'Artistly')
            ->assertJsonPath('recoveryCodesFile.slug', 'artistly');
    });
});
