<?php

use StrontiumCorp\LaravelMfa\Support\RandomCodeGenerator;
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

it('shows an example code on the recovery form that no account can ever have', function () {
    $form = (string) file_get_contents(__DIR__.'/../../stubs/inertia-react/components/recovery-code-form.tsx');
    expect(preg_match('/like ([a-z0-9]{5}-[a-z0-9]{5})/', $form, $match))->toBe(1);

    $alphabet = (string) (new ReflectionClassConstant(RandomCodeGenerator::class, 'RECOVERY_ALPHABET'))->getValue();
    $impossible = array_diff(str_split(str_replace('-', '', $match[1])), str_split($alphabet));

    // A character the generator never uses (0, 1, i, l, o) makes it impossible as a real code.
    expect($impossible)->not->toBeEmpty();
});
