<?php

/*
 * Pins behaviour that surviving mutants showed was untested (mutation triage
 * 2026-10-08). Each test names the gap it closes.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Support\CodeHasher;
use StrontiumCorp\LaravelMfa\Support\ConfigMerge;
use StrontiumCorp\LaravelMfa\Support\Cooldown;
use StrontiumCorp\LaravelMfa\Support\Mask;
use StrontiumCorp\LaravelMfa\Support\RandomCodeGenerator;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\RequestContext;
use StrontiumCorp\LaravelMfa\Support\SendGuard;

describe('code generation uses the full alphabet', function () {
    it('produces every digit in OTPs', function () {
        $digits = implode('', array_map(fn () => (new RandomCodeGenerator)->otp(6), range(1, 300)));

        expect(count_chars($digits, 3))->toBe('0123456789');
    });

    it('produces every character of the recovery alphabet, in both halves independently', function () {
        $codes = array_map(fn () => (new RandomCodeGenerator)->recoveryCode(), range(1, 400));

        expect(count_chars(str_replace('-', '', implode('', $codes)), 3))->toBe('23456789abcdefghjkmnpqrstuvwxyz')
            // halves must not overlap (would silently drop entropy)
            ->and(array_filter($codes, fn ($c) => $c[4] !== $c[6]))->not->toBeEmpty();
    });
});

describe('cooldown config parsing', function () {
    it('uses defaults for missing keys', function () {
        expect(array_map(fn ($n) => Cooldown::after($n, []), [1, 2, 3, 4]))->toBe([120, 240, 480, 900]);
    });

    it('accepts numeric strings (values from env)', function () {
        expect(Cooldown::after(2, ['base' => '60', 'multiplier' => '3', 'max' => '1000']))->toBe(180);
    });

    it('clamps nonsense: negative base/flat, multiplier below 1, max below base', function () {
        expect(Cooldown::after(1, -5))->toBe(0)
            ->and(Cooldown::after(3, ['base' => -10]))->toBe(0)
            ->and(Cooldown::after(3, ['base' => 100, 'multiplier' => 0.5]))->toBe(100)
            ->and(Cooldown::after(5, ['base' => 300, 'max' => 10]))->toBe(300);
    });
});

describe('CodeHasher', function () {
    it('uses the next key when the current one is empty, and reads previous_keys from config', function () {
        expect((new CodeHasher(['', 'k']))->hash('1'))->toBe((new CodeHasher(['k']))->hash('1'));

        config(['app.key' => 'new', 'app.previous_keys' => ['old']]);
        $hash = (new CodeHasher(['old']))->hash('x', 's');

        expect(CodeHasher::fromConfig(config())->matches('x', $hash, 's'))->toBeTrue();

        config(['app.previous_keys' => 'not-an-array']);
        expect(CodeHasher::fromConfig(config())->candidates('x'))->toHaveCount(1);
    });

    it('separates scope and code so they cannot be shifted into each other', function () {
        $h = new CodeHasher(['k']);

        expect($h->hash('3', 'otp:12'))->not->toBe($h->hash('23', 'otp:1'));
    });
});

describe('Mask', function () {
    it('handles short, odd and empty values', function () {
        expect(Mask::email('ab@x.io'))->toBe('a***@x.io')
            ->and(Mask::email('abcdefg@x.io'))->toBe('a******@x.io')
            ->and(Mask::email('nodomain'))->toBe('n*******@')
            ->and(Mask::phone('+1234'))->toBe('*****')
            ->and(Mask::phone('+12345'))->toBe('+*2345')
            ->and(Mask::phone('+123456'))->toBe('+**3456')
            ->and(Mask::destination(FactorType::Email, ''))->toBeNull();
    });
});

it('ConfigMerge lets an app replace a scalar default with an array and vice versa', function () {
    expect(ConfigMerge::merge(['a' => 'x', 'b' => ['k' => 1]], ['a' => ['k' => 2], 'b' => 'y']))
        ->toBe(['a' => ['k' => 2], 'b' => 'y']);
});

it('RecoveryCodes stores a creation timestamp', function () {
    $user = $this->makeUser();
    app(RecoveryCodes::class)->generate($user);

    expect($user->mfaRecoveryCodes()->whereNull('created_at')->count())->toBe(0);
});

describe('RequestContext', function () {
    it('creates a string flow id even without a session', function () {
        $flow = RequestContext::bind(Request::create('/'));

        expect($flow)->toBeString()->toMatch('/^[0-9a-f-]{36}$/')
            ->and(Context::get('mfa_flow_id'))->toBe($flow);
    });

    it('truncates the user agent to 250 characters', function () {
        $request = Request::create('/', server: ['HTTP_USER_AGENT' => str_repeat('x', 400)]);
        RequestContext::bind($request);

        expect(Context::getHidden('mfa_user_agent'))->toBe(str_repeat('x', 250));
    });
});

it('SendGuard groups IPs: empty is none, invalid IPv6 falls back to the literal', function () {
    expect(SendGuard::ipBucket(null))->toBeNull()
        ->and(SendGuard::ipBucket(''))->toBeNull()
        ->and(SendGuard::ipBucket('not:an:ip'))->toBe('v4:not:an:ip')
        ->and(SendGuard::ipBucket('2001:db8::1'))->toBe(SendGuard::ipBucket('2001:db8::ffff'))
        ->and(SendGuard::ipBucket('2001:db8::1'))->not->toBe(SendGuard::ipBucket('2001:db9::1'));
});
