<?php

use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;

beforeEach(function () {
    [$this->user, $this->factor] = $this->userWithFactor(FactorType::Totp);
    $this->totp = Mfa::factor(FactorType::Totp);
});

it('accepts the current code', function () {
    expect($this->totp->verify($this->factor, $this->currentTotpCode($this->factor))->successful)->toBeTrue()
        ->and($this->factor->fresh()->last_totp_timestep)->toBe($this->totp->currentStep());
});

it('rejects a replayed code', function () {
    $code = $this->currentTotpCode($this->factor);

    $this->totp->verify($this->factor, $code);

    expect($this->totp->verify($this->factor->fresh(), $code)->reason)->toBe(FailureReason::Replayed);
});

it('tolerates one step of clock drift but not more', function () {
    $g = new Google2FA;
    $step = $this->totp->currentStep();

    expect($this->totp->verify($this->factor, $g->oathTotp($this->factor->secret, $step - 1))->successful)->toBeTrue();
    expect($this->totp->verify($this->factor->fresh(), $g->oathTotp($this->factor->secret, $step - 3))->reason)->toBe(FailureReason::InvalidCode);
});

it('loses the race when another request already used a newer step', function () {
    $code = $this->currentTotpCode($this->factor);

    // Another server verified the same code between our read and write.
    MfaFactor::whereKey($this->factor->id)->update(['last_totp_timestep' => $this->totp->currentStep() + 5]);

    expect($this->totp->verify($this->factor, $code)->reason)->toBe(FailureReason::Replayed);
});

it('rejects malformed input without touching the secret', function (string $code) {
    expect($this->totp->verify($this->factor, $code)->reason)->toBe(FailureReason::InvalidCode);
})->with(['', 'abcdef', '12345', '1234567', '12 34 5']);

it('accepts codes with spaces', function () {
    $code = $this->currentTotpCode($this->factor);

    expect($this->totp->verify($this->factor, substr($code, 0, 3).' '.substr($code, 3))->successful)->toBeTrue();
});

it('produces a scannable otpauth URL and inline SVG', function () {
    $setup = $this->totp->setupData($this->factor, $this->user);

    expect($setup['otpauth_url'])->toStartWith('otpauth://totp/')->toContain('secret='.$this->factor->secret)
        ->and($setup['qr_svg'])->toStartWith('<svg');
});

it('stores the secret encrypted at rest', function () {
    $raw = DB::table('mfa_factors')->where('id', $this->factor->id)->value('secret');

    expect($raw)->not->toBe($this->factor->secret)
        ->and(decrypt($raw, false))->toBe($this->factor->secret);
});
