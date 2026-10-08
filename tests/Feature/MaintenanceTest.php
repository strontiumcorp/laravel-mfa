<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use StrontiumCorp\LaravelMfa\Contracts\SmsSender;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaOtpCode;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;

it('prunes expired codes after a day and audit rows after the retention period', function () {
    config(['mfa.observability.audit.retention_days' => 30]);
    [, $factor] = $this->userWithFactor(FactorType::Email);

    $stale = $factor->otpCodes()->create(['code_hash' => str_repeat('a', 64), 'expires_at' => now()->subDays(2)]);
    $fresh = $factor->otpCodes()->create(['code_hash' => str_repeat('b', 64), 'expires_at' => now()->subHour()]);
    $old = MfaAuditLog::create(['event' => 'verification_failed', 'created_at' => now()->subDays(31)]);
    $recent = MfaAuditLog::create(['event' => 'verification_failed', 'created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [MfaOtpCode::class, MfaAuditLog::class]])->assertSuccessful();

    expect(MfaOtpCode::pluck('id')->all())->toBe([$fresh->id])
        ->and(MfaAuditLog::pluck('id')->all())->toBe([$recent->id]);
});

it('schedules pruning on one server when enabled', function () {
    config(['mfa.prune.schedule' => true]);

    $events = collect(app(Schedule::class)->events());

    expect($events->first(fn ($e) => $e->description === 'mfa:prune'))->not->toBeNull()
        ->onOneServer->toBeTrue();
});

it('validates email destinations on enrollment', function () {
    $this->loginWithSession($this->makeUser());

    $this->postJson(route('mfa.factors.store'), ['type' => 'email', 'destination' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destination');
});

it('defaults the email factor to the account email', function () {
    Notification::fake();
    $user = $this->makeUser();
    $this->loginWithSession($user);

    $this->postJson(route('mfa.factors.store'), ['type' => 'email'])->assertOk();

    expect($user->mfaFactors()->sole()->destination)->toBe($user->email);
});

it('renders the code email', function () {
    config(['app.name' => 'Podcast Flow']);

    $mail = (new OtpCodeNotification('739104', 600))->toMail(null);

    expect($mail->subject)->toBe('Your Podcast Flow verification code')
        ->and(implode(' ', $mail->introLines))->toContain('739104')->toContain('10 minutes');
});

it('exposes the log SMS driver for local development', function () {
    $logged = collect();
    Event::listen(MessageLogged::class, fn ($e) => $logged->push($e));

    Mfa::factor(FactorType::Sms);
    app(SmsSender::class)->send('+15555550100', 'code 1');

    expect($logged->firstWhere('message', '[mfa] SMS (log driver)')->context)->toBe(['to' => '+15555550100', 'message' => 'code 1']);
});
