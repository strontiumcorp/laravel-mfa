<?php

use StrontiumCorp\LaravelMfa\Support\Cooldown;

$curve = ['base' => 120, 'multiplier' => 2, 'max' => 900];

it('grows exponentially per send in the streak, up to the cap', function () use ($curve) {
    expect(array_map(fn ($n) => Cooldown::after($n, $curve), [1, 2, 3, 4, 5, 9]))
        ->toBe([120, 240, 480, 900, 900, 900]);
});

it('has no cooldown before the first send', function () use ($curve) {
    expect(Cooldown::after(0, $curve))->toBe(0);
});

it('accepts a plain number of seconds as a flat cooldown (older published configs)', function () {
    expect(Cooldown::after(1, 60))->toBe(60)
        ->and(Cooldown::after(5, 60))->toBe(60)
        ->and(Cooldown::after(3, 0))->toBe(0);
});

it('fills missing curve keys with safe defaults', function () {
    expect(Cooldown::after(2, ['base' => 100]))->toBe(200)          // multiplier 2
        ->and(Cooldown::after(9, ['base' => 100, 'multiplier' => 3]))->toBe(900); // max 900
});
