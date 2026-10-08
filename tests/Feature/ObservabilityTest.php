<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;

function captureMfaLogs(): Collection
{
    $logs = collect();
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($logs) {
        if (str_starts_with($e->message, 'mfa.')) {
            $logs->push($e);
        }
    });

    return $logs;
}

it('ties every event in one challenge together with a flow id', function () {
    $events = collect();
    Event::listen(MfaActivity::class, fn ($e) => $events->push($e));
    [$user, $factor] = $this->userWithFactor();

    $this->loginWithSession($user)->get('/dashboard');
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000']);
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)]);

    expect($events->map->name()->all())->toBe(['challenge_required', 'verification_failed', 'verification_succeeded'])
        ->and($events->pluck('flowId')->unique())->toHaveCount(1)
        ->and($events->first()->flowId)->toBeString();
});

it('writes a queryable audit trail with reason, flow, ip and user agent', function () {
    [$user, $factor] = $this->userWithFactor();

    $this->loginWithSession($user)
        ->withHeaders(['User-Agent' => 'PestBrowser/1.0'])
        ->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000']);

    $row = MfaAuditLog::sole();

    expect($row->event)->toBe('verification_failed')
        ->and($row->reason)->toBe('invalid_code')
        ->and($row->factor_type)->toBe('totp')
        ->and($row->user_id)->toBe($user->id)
        ->and($row->flow_id)->not->toBeNull()
        ->and($row->ip_address)->toBe('127.0.0.1')
        ->and($row->user_agent)->toBe('PestBrowser/1.0')
        ->and($row->context)->toMatchArray(['stage' => 'challenge', 'factor_id' => $factor->id]);
});

it('keeps noisy events out of the audit table but still logs them at debug', function () {
    config(['mfa.observability.log.level' => 'debug']);
    $logs = captureMfaLogs();
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->get('/dashboard');

    expect(MfaAuditLog::count())->toBe(0)
        ->and($logs->pluck('message')->all())->toBe(['mfa.challenge_required'])
        ->and($logs->first()->level)->toBe('debug');
});

it('logs one structured line per event at the right level', function () {
    $logs = captureMfaLogs();
    $sms = Mfa::fakeSms()->failWith('boom');
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);

    $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id]);

    $line = $logs->firstWhere('message', 'mfa.challenge_delivery_failed');

    expect($line->level)->toBe('error')
        ->and($line->context)->toMatchArray(['factor' => 'sms', 'reason' => 'delivery_failed', 'user_id' => $user->id])
        ->and($line->context['flow_id'])->toBeString();
});

it('never logs codes, secrets or full destinations', function () {
    $logs = captureMfaLogs();
    $sms = Mfa::fakeSms();
    Mfa::fakeCodes('918273');
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    [, $totp] = [$user, $this->createMfaFactor($user, FactorType::Totp)];

    $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id]);
    $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '918273']);

    $dump = json_encode([$logs->map(fn ($l) => [$l->message, $l->context])->all(), MfaAuditLog::all()->toArray()]);

    expect($dump)->not->toContain('918273')
        ->not->toContain('+15555550100')
        ->not->toContain($totp->secret);
});

it('never lets a broken audit table break authentication', function () {
    [$user, $factor] = $this->userWithFactor();
    Schema::drop('mfa_audit_logs');

    $this->loginWithSession($user)
        ->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
        ->assertOk();
});

it('emits metrics through the bound recorder', function () {
    $recorder = new class implements MetricsRecorder
    {
        public array $counters = [];

        public function increment(string $metric, array $tags = []): void
        {
            $this->counters[] = [$metric, $tags];
        }

        public function timing(string $metric, float $milliseconds, array $tags = []): void {}
    };
    app()->instance(MetricsRecorder::class, $recorder);
    [$user, $factor] = $this->userWithFactor();

    $this->loginWithSession($user)->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000']);

    expect($recorder->counters)->toContain(['mfa.verification_failed', ['factor' => 'totp', 'reason' => 'invalid_code']]);
});

it('exposes the flow id to the app\'s own log lines via Context', function () {
    [$user] = $this->userWithFactor();

    $this->loginWithSession($user)->get('/dashboard');

    expect(Context::get('mfa_flow_id'))->toBe(session('mfa.flow_id'));
});
