<?php

use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Support\OtpStore;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;

beforeEach(function () {
    // Exact waits: a send at x.999s must not straddle a second boundary.
    $this->freezeSecond();
    [$this->user, $this->factor] = $this->userWithFactor(FactorType::Sms);
    $this->store = app(OtpStore::class);
    $this->opts = ['length' => 6, 'ttl' => 600, 'max_attempts' => 3,
        'resend_cooldown' => ['base' => 120, 'multiplier' => 2, 'max' => 900]];
    $this->send = fn () => $this->store->issue($this->factor, $this->opts)['result'];
});

it('waits 2, 4, 8, then 15 minutes between sends', function () {
    expect(($this->send)()->context['retry_after'])->toBe(120);

    foreach ([120, 240, 480] as $wait) {
        $this->travel($wait - 1)->seconds();
        expect(($this->send)()->reason)->toBe(FailureReason::Cooldown);
        $this->travel(1)->seconds();
        expect(($this->send)()->successful)->toBeTrue();
    }
});

it('reports the wait as retry_after, never longer than the code lifetime', function () {
    $this->opts['ttl'] = 300;

    expect(($this->send)()->context['retry_after'])->toBe(120);
    $this->travel(120)->seconds();
    expect(($this->send)()->context['retry_after'])->toBe(240);
    $this->travel(240)->seconds();
    expect(($this->send)()->context['retry_after'])->toBe(300);   // capped at ttl, not 480
});

it('allows a resend as soon as the current code has expired', function () {
    $this->opts['ttl'] = 60;
    ($this->send)();
    $this->travel(61)->seconds();   // code expired, cooldown (120s) not yet over

    expect(($this->send)()->successful)->toBeTrue();
});

it('allows a resend as soon as the current code has been burned by wrong guesses', function () {
    $code = $this->store->issue($this->factor, $this->opts)['code'];
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, 3) as $_) {
        $this->store->verify($this->factor, $wrong, $this->opts);
    }

    expect(($this->send)()->successful)->toBeTrue();
});

it('keeps the curve across a successful verification, one step lower, from that code\'s send', function () {
    $this->freezeSecond(); // exact waits
    $verify = fn (string $code) => expect($this->store->verify($this->factor, $code, $this->opts)->successful)->toBeTrue();

    $verify($this->store->issue($this->factor, $this->opts)['code']);   // 1st send, verified
    $verify($this->store->issue($this->factor, $this->opts)['code']);   // 2nd: at once (after(0))
    $this->travel(30)->seconds();

    $refused = ($this->send)();                                         // 3rd: one step lower than a resend
    expect($refused->reason)->toBe(FailureReason::Cooldown)
        ->and($refused->context['retry_after'])->toBe(90);              // 120 from the 2nd send

    $this->travel(90)->seconds();
    expect(($this->send)()->context['retry_after'])->toBe(480);         // the 3rd send: the curve goes on
});

it('does not reset the curve when last_used_at alone moves (e.g. a TOTP factor)', function () {
    $this->freezeSecond();
    ($this->send)();
    $this->travel(120)->seconds();
    ($this->send)();
    $this->factor->forceFill(['last_used_at' => now()])->save();
    $this->travel(1)->seconds();

    expect(($this->send)()->context['retry_after'])->toBe(239);       // refused: still the 2nd send's wait
});

it('resets the curve after an hour without sends', function () {
    ($this->send)();
    $this->travel(120)->seconds();
    ($this->send)();
    $this->travel(61)->minutes();

    expect(($this->send)()->context['retry_after'])->toBe(120);
});

it('does not run the send gate while in cooldown, so rejected requests cost nothing', function () {
    $gateCalls = 0;
    $gate = function () use (&$gateCalls) {
        $gateCalls++;

        return null;
    };

    $this->store->issue($this->factor, $this->opts, $gate);
    $this->store->issue($this->factor, $this->opts, $gate);   // cooldown
    $this->store->issue($this->factor, $this->opts, $gate);   // cooldown

    expect($gateCalls)->toBe(1);
});

it('issues nothing when the gate refuses', function () {
    $result = $this->store->issue($this->factor, $this->opts, fn () => VerificationResult::failure(FailureReason::RateLimited));

    expect($result['code'])->toBeNull()
        ->and($result['result']->reason)->toBe(FailureReason::RateLimited)
        ->and($this->factor->otpCodes()->count())->toBe(0);
});

it('defaults to a 10 minute SMS code lifetime and the 2 minute curve', function () {
    expect(config('mfa.factors.sms.ttl'))->toBe(600)
        ->and(config('mfa.factors.sms.resend_cooldown'))->toBe(['base' => 120, 'multiplier' => 2, 'max' => 900])
        ->and(config('mfa.factors.email.resend_cooldown'))->toBe(['base' => 120, 'multiplier' => 2, 'max' => 900]);
});
