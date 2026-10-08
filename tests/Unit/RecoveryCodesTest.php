<?php

use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

beforeEach(function () {
    $this->user = $this->makeUser();
    $this->codes = app(RecoveryCodes::class);
});

it('generates the configured number of unique codes, stored hashed', function () {
    $plain = $this->codes->generate($this->user);

    expect($plain)->toHaveCount(10)
        ->and(array_unique($plain))->toHaveCount(10)
        ->and($this->user->mfaRecoveryCodes()->pluck('code_hash')->intersect($plain))->toBeEmpty();
});

it('consumes each code once and accepts human formatting', function () {
    $code = $this->codes->generate($this->user)[0];

    expect($this->codes->consume($this->user, strtoupper(str_replace('-', ' ', $code))))->toBeTrue()
        ->and($this->codes->consume($this->user, $code))->toBeFalse()
        ->and($this->codes->remaining($this->user))->toBe(9);
});

it('invalidates old codes on regeneration', function () {
    $old = $this->codes->generate($this->user)[0];
    $this->codes->generate($this->user);

    expect($this->codes->consume($this->user, $old))->toBeFalse();
});

it('does not accept another user\'s code', function () {
    $code = $this->codes->generate($this->makeUser())[0];

    expect($this->codes->consume($this->user, $code))->toBeFalse();
});
