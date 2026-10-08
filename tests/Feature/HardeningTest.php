<?php

/*
 * Tests added to kill surviving mutants from `composer test:mutate`.
 * Each one pins a behaviour that a mutation could silently break.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Events\VerificationFailed;
use StrontiumCorp\LaravelMfa\Events\VerificationSucceeded;
use StrontiumCorp\LaravelMfa\Exceptions\DeliveryFailed;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\MfaServiceProvider;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;
use StrontiumCorp\LaravelMfa\Sms\SmsManager;

describe('data at rest', function () {
    it('encrypts phone numbers and email destinations', function () {
        $user = $this->makeUser();
        $sms = $this->createMfaFactor($user, FactorType::Sms, '+15555550177');
        $email = $this->createMfaFactor($user, FactorType::Email, 'private@example.com');

        $raw = DB::table('mfa_factors')->whereIn('id', [$sms->id, $email->id])->pluck('destination')->implode('|');

        expect($raw)->not->toContain('5555550177')->not->toContain('private@example.com')
            ->and($sms->fresh()->destination)->toBe('+15555550177');
    });

    it('casts timestamps so the UI gets ISO-8601 dates', function () {
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user)->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)]);

        expect($factor->fresh()->toPublicArray()['last_used_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    });
});

describe('cache invalidation', function () {
    it('busts the "has MFA" cache when a factor is deleted through the model', function () {
        [$user, $factor] = $this->userWithFactor();
        expect(Mfa::hasConfirmedFactors($user))->toBeTrue(); // warm the cache

        $factor->delete();

        expect(Mfa::hasConfirmedFactors($user))->toBeFalse();
    });
});

describe('event context', function () {
    it('builds a complete, null-free context', function () {
        [$user] = $this->userWithFactor();
        Context::add('mfa_flow_id', 'flow-123');
        Context::addHidden('mfa_ip', '10.0.0.1');

        $event = new VerificationFailed($user, FactorType::Totp, FailureReason::Replayed, ['stage' => 'challenge']);

        expect($event->toContext())->toBe([
            'event' => 'verification_failed',
            'user_type' => $user::class,
            'user_id' => $user->id,
            'factor' => 'totp',
            'reason' => 'replayed',
            'flow_id' => 'flow-123',
            'ip' => '10.0.0.1',
            'stage' => 'challenge',
        ])->and($event->level())->toBe('warning');

        Context::flush();

        expect((new VerificationSucceeded(null))->toContext())->toBe(['event' => 'verification_succeeded'])
            ->and((new VerificationSucceeded(null))->level())->toBe('info');
    });
});

describe('sms transports', function () {
    it('uses the Twilio messaging service instead of a from-number when configured', function () {
        Http::fake(['api.twilio.com/*' => Http::response([], 201)]);
        config(['mfa.sms.drivers.twilio' => ['sid' => 'AC1', 'token' => 't', 'from' => '+1555', 'messaging_service_sid' => 'MG9']]);

        app(SmsManager::class)->driver('twilio')->send('+15555550100', 'hi');

        Http::assertSent(fn (Request $r) => $r['MessagingServiceSid'] === 'MG9' && ! isset($r['From']));
    });

    it('turns connection failures into DeliveryFailed', function (string $driver) {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        config(["mfa.sms.drivers.{$driver}" => ['sid' => 'AC1', 'token' => 't', 'from' => '+1555', 'key' => 'k', 'secret' => 's']]);

        expect(fn () => app(SmsManager::class)->driver($driver)->send('+15555550100', 'hi'))
            ->toThrow(DeliveryFailed::class, "[{$driver}] connection: timed out");
    })->with(['twilio', 'vonage']);
});

describe('wiring', function () {
    it('ships a log metrics driver', function () {
        config(['mfa.observability.metrics.driver' => 'log']);
        app()->forgetInstance(MetricsRecorder::class);
        $logged = collect();
        Event::listen(MessageLogged::class, fn ($e) => $logged->push($e));

        app(MetricsRecorder::class)->increment('mfa.test', ['factor' => 'totp']);
        app(MetricsRecorder::class)->timing('mfa.delivery_duration_ms', 12.345);

        expect($logged->where('message', '[mfa.metric]')->pluck('context')->all())->toBe([
            ['metric' => 'mfa.test', 'type' => 'counter', 'value' => 1, 'tags' => ['factor' => 'totp']],
            ['metric' => 'mfa.delivery_duration_ms', 'type' => 'timing', 'value' => 12.35, 'tags' => []],
        ]);
    });

    // Asserts the publish mapping instead of copying files: tests must never
    // write into the shared Testbench skeleton (concurrent runs collide).
    it('registers the config and migrations for publishing', function () {
        $config = ServiceProvider::pathsToPublish(MfaServiceProvider::class, 'mfa-config');
        $migrations = ServiceProvider::pathsToPublish(MfaServiceProvider::class, 'mfa-migrations');

        expect(array_map('realpath', array_keys($config)))->toBe([realpath(__DIR__.'/../../config/mfa.php')])
            ->and(array_values($config))->toBe([config_path('mfa.php')])
            ->and(array_map('realpath', array_keys($migrations)))->toBe([realpath(__DIR__.'/../../database/migrations')])
            ->and(array_values($migrations))->toBe([database_path('migrations')]);
    });
});

it('rounds the email expiry to whole minutes, never below one, and bolds the code', function () {
    $lines = fn (int $ttl) => implode(' ', (new OtpCodeNotification('739104', $ttl))->toMail(null)->introLines);

    expect($lines(30))->toContain('1 minutes')
        ->and($lines(150))->toContain('2 minutes')
        ->and($lines(600))->toContain('**739104**');
});
