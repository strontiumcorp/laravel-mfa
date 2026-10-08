<?php

use StrontiumCorp\LaravelMfa\Support\CodeHasher;

it('is deterministic and keyed', function () {
    $a = new CodeHasher(['key-one']);
    $b = new CodeHasher(['key-two']);

    expect($a->hash('123456'))->toBe($a->hash('123456'))
        ->and($a->hash('123456'))->not->toBe($b->hash('123456'))
        ->and($a->hash('123456'))->toHaveLength(64);
});

it('separates scopes so a hash cannot be replayed across factors', function () {
    $hasher = new CodeHasher(['key']);

    expect($hasher->hash('123456', 'otp:1'))->not->toBe($hasher->hash('123456', 'otp:2'));
});

it('still verifies codes hashed under a previous APP_KEY', function () {
    $old = new CodeHasher(['old-key']);
    $rotated = new CodeHasher(['new-key', 'old-key']);

    $hash = $old->hash('abc', 'recovery');

    expect($rotated->matches('abc', $hash, 'recovery'))->toBeTrue()
        ->and($rotated->matches('abd', $hash, 'recovery'))->toBeFalse()
        ->and($rotated->candidates('abc', 'recovery'))->toHaveCount(2);
});

it('decodes base64 app keys', function () {
    $raw = str_repeat('x', 32);

    expect((new CodeHasher(['base64:'.base64_encode($raw)]))->hash('1'))
        ->toBe((new CodeHasher([$raw]))->hash('1'));
});

it('refuses to run without a key', function () {
    new CodeHasher(['', null]);
})->throws(RuntimeException::class);
