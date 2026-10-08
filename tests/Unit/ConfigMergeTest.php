<?php

use StrontiumCorp\LaravelMfa\MfaServiceProvider;
use StrontiumCorp\LaravelMfa\Support\ConfigMerge;

it('deep-merges nested settings but replaces lists wholesale', function () {
    $defaults = ['a' => ['x' => 1, 'y' => 2], 'list' => ['one', 'two', 'three'], 'scalar' => true];
    $app = ['a' => ['x' => 9], 'list' => ['mine'], 'extra' => 'kept'];

    expect(ConfigMerge::merge($defaults, $app))->toBe([
        'a' => ['x' => 9, 'y' => 2],
        'list' => ['mine'],          // not ['mine', 'two', 'three']
        'scalar' => true,
        'extra' => 'kept',
    ]);
});

it('lets an app explicitly empty a list or null a value', function () {
    expect(ConfigMerge::merge(['list' => ['a'], 'v' => 'x'], ['list' => [], 'v' => null]))
        ->toBe(['list' => [], 'v' => null]);
});

it('gives apps with an older published config the newer nested keys', function () {
    // An app published config/mfa.php before verify_per_day / blocked_prefixes existed.
    config(['mfa' => [
        'rate_limit' => ['verify_per_minute' => 3],
        'factors' => ['sms' => ['enabled' => true, 'allowed_calling_codes' => ['44']]],
        'middleware' => ['except' => ['logout']],
    ]]);

    (new MfaServiceProvider(app()))->register();

    expect(config('mfa.rate_limit.verify_per_minute'))->toBe(3)          // app value kept
        ->and(config('mfa.rate_limit.verify_per_day'))->toBe(50)          // new key filled in
        ->and(config('mfa.factors.sms.allowed_calling_codes'))->toBe(['44'])
        ->and(config('mfa.factors.sms.blocked_prefixes'))->toContain('1876')
        ->and(config('mfa.middleware.except'))->toBe(['logout'])          // list replaced, not merged
        ->and(config('mfa.middleware.allow_while_enrolling'))->toContain('password.confirm');
});
