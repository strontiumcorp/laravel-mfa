<?php

use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaOtpCode;
use StrontiumCorp\LaravelMfa\Support\OtpStore;

beforeEach(function () {
    [$this->user, $this->factor] = $this->userWithFactor(FactorType::Email);
    $this->store = app(OtpStore::class);
    $this->opts = ['length' => 6, 'ttl' => 300, 'max_attempts' => 3, 'resend_cooldown' => 60];
});

it('stores only a hash of the code', function () {
    $issued = $this->store->issue($this->factor, $this->opts);

    expect($issued['code'])->toMatch('/^\d{6}$/')
        ->and(MfaOtpCode::sole()->code_hash)->not->toContain($issued['code'])->toHaveLength(64);
});

it('accepts the right code exactly once', function () {
    $code = $this->store->issue($this->factor, $this->opts)['code'];

    expect($this->store->verify($this->factor, $code, $this->opts)->successful)->toBeTrue()
        ->and($this->store->verify($this->factor, $code, $this->opts)->reason)->toBe(FailureReason::NoActiveCode);
});

it('burns the code after max attempts, even if the right code comes next', function () {
    $code = $this->store->issue($this->factor, $this->opts)['code'];
    $wrong = $code === '000000' ? '111111' : '000000';

    expect($this->store->verify($this->factor, $wrong, $this->opts)->reason)->toBe(FailureReason::InvalidCode)
        ->and($this->store->verify($this->factor, $wrong, $this->opts)->context['attempts_remaining'])->toBe(1)
        ->and($this->store->verify($this->factor, $wrong, $this->opts)->reason)->toBe(FailureReason::TooManyAttempts)
        ->and($this->store->verify($this->factor, $code, $this->opts)->reason)->toBe(FailureReason::NoActiveCode);
});

it('rejects expired codes', function () {
    $code = $this->store->issue($this->factor, $this->opts)['code'];

    $this->travel(301)->seconds();

    expect($this->store->verify($this->factor, $code, $this->opts)->reason)->toBe(FailureReason::Expired);
});

it('enforces the resend cooldown', function () {
    $this->store->issue($this->factor, $this->opts);
    $second = $this->store->issue($this->factor, $this->opts);

    expect($second['code'])->toBeNull()
        ->and($second['result']->reason)->toBe(FailureReason::Cooldown)
        ->and($second['result']->context['retry_after'])->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('invalidates the previous code when a new one is issued', function () {
    $first = $this->store->issue($this->factor, $this->opts)['code'];
    $this->travel(61)->seconds();
    $second = $this->store->issue($this->factor, $this->opts)['code'];

    if ($first !== $second) {
        expect($this->store->verify($this->factor, $first, $this->opts)->successful)->toBeFalse();
    }

    expect($this->store->verify($this->factor, $second, $this->opts)->successful)->toBeTrue();
});

it('scopes codes per factor so equal digits on two factors stay independent', function () {
    [, $other] = $this->userWithFactor(FactorType::Email);
    app(Mfa::class)->fakeCodes('424242');
    $store = app(OtpStore::class);

    $store->issue($other, $this->opts);
    $store->issue($this->factor, $this->opts);
    $store->verify($other, '424242', $this->opts);

    // Same digits, different factor scope → separate hash, still valid here.
    expect($store->verify($this->factor, '424242', $this->opts)->successful)->toBeTrue();
});
