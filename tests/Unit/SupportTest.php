<?php

use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Support\Mask;
use StrontiumCorp\LaravelMfa\Support\PhoneNumber;
use StrontiumCorp\LaravelMfa\Support\RandomCodeGenerator;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;

it('normalises E.164 phone numbers', function (string $input, ?string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    ['+1 (555) 555-0100', '+15555550100'],
    ['0044 20 7946 0958', '+442079460958'],
    ['5555550100', null],          // no country code
    ['+0123456789', null],         // invalid leading zero
    ['+1234', null],               // too short
    ['+1555abc0100', null],
]);

it('enforces the calling-code allowlist', function () {
    expect(PhoneNumber::isAllowed('+15555550100', ['1', '44']))->toBeTrue()
        ->and(PhoneNumber::isAllowed('+8801711000000', ['1', '44']))->toBeFalse()
        ->and(PhoneNumber::isAllowed('+8801711000000', []))->toBeTrue();
});

it('masks destinations', function () {
    expect(Mask::destination(FactorType::Email, 'jane@example.com'))->toBe('j***@example.com')
        ->and(Mask::destination(FactorType::Sms, '+15555550100'))->toBe('+*******0100')
        ->and(Mask::destination(FactorType::Totp, 'anything'))->toBeNull()
        ->and(Mask::destination(FactorType::Email, null))->toBeNull();
});

it('generates numeric OTPs of the requested length, including leading zeros', function () {
    $generator = new RandomCodeGenerator;
    $codes = collect(range(1, 500))->map(fn () => $generator->otp(6));

    expect($codes->every(fn ($c) => preg_match('/^\d{6}$/', $c) === 1))->toBeTrue()
        ->and($codes->unique()->count())->toBeGreaterThan(450);
});

it('generates readable, unique recovery codes', function () {
    $generator = new RandomCodeGenerator;
    $codes = collect(range(1, 200))->map(fn () => $generator->recoveryCode());

    expect($codes->every(fn ($c) => preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $c) === 1))->toBeTrue()
        ->and($codes->contains(fn ($c) => preg_match('/[01ilo]/', $c) === 1))->toBeFalse()
        ->and($codes->unique())->toHaveCount(200);
});

it('normalises recovery codes typed by humans', function () {
    expect(RecoveryCodes::normalize(' AB3DE - fg7HK '))->toBe('ab3defg7hk');
});
