<?php

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Notifications\OtpCodeNotification;
use StrontiumCorp\LaravelMfa\Support\CodeHasher;
use StrontiumCorp\LaravelMfa\Support\DestinationFingerprint;

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

describe('refusals by a limit', function () {
    // An attacker (or a stuck client) hammering a limit must not flood the
    // audit table: one row per user, per kind of refusal, per limit window.
    // The log and metrics still see every refusal.
    $rows = fn (string $reason = 'rate_limited') => MfaAuditLog::where('reason', $reason)->get(['event', 'context'])
        ->map(fn ($row) => $row->event.':'.($row->context['stage'] ?? '-'))->all();

    it('writes one audit row per user and stage per verification window', function () use ($rows) {
        $this->freezeSecond();
        config(['mfa.rate_limit.verify_per_minute' => 1]);
        $logs = captureMfaLogs();
        [$user, $factor] = $this->userWithFactor();
        $this->loginWithSession($user);
        $wrong = fn () => $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000']);

        $wrong()->assertUnprocessable();
        foreach (range(1, 4) as $_) {
            $wrong()->assertStatus(429);
        }
        $this->postJson(route('mfa.challenge.recover'), ['code' => 'AAAAA-BBBBB'])->assertStatus(429);

        expect($rows())->toBe(['verification_failed:challenge', 'verification_failed:recovery'])
            ->and($logs->filter(fn ($l) => ($l->context['reason'] ?? null) === 'rate_limited'))->toHaveCount(5);

        // The window frees up: the next refusal is recorded again.
        $this->travel(61)->seconds();
        $wrong()->assertUnprocessable();
        $wrong()->assertStatus(429);
        $wrong()->assertStatus(429);

        expect($rows())->toBe(['verification_failed:challenge', 'verification_failed:recovery', 'verification_failed:challenge']);
    });

    it('keeps users apart', function () use ($rows) {
        config(['mfa.rate_limit.verify_per_minute' => 0]);
        foreach (range(1, 2) as $_) {
            [$user, $factor] = $this->userWithFactor();
            $this->freshGuards()->loginWithSession($user);
            $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])->assertStatus(429);
            $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])->assertStatus(429);
        }

        expect(MfaAuditLog::where('reason', 'rate_limited')->distinct()->count('user_id'))->toBe(2)
            ->and($rows())->toHaveCount(2);
    });

    it('writes one row per password lockout window', function () use ($rows) {
        config(['mfa.routes.password_confirmation' => true, 'mfa.rate_limit.password_per_minute' => 0]);
        $this->actingAsMfaVerified($this->makeUser());

        foreach (range(1, 3) as $_) {
            $this->postJson(route('mfa.password.confirm'), ['password' => 'nope'])->assertStatus(429);
        }

        expect($rows())->toBe(['password_confirmation_failed:-']);
    });

    it('writes one row per send-limit window, and still counts every refusal in metrics', function () use ($rows) {
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
        config(['mfa.rate_limit.send_per_hour' => 1, 'mfa.factors.sms.resend_cooldown' => 0]);
        Mfa::fakeSms();
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertOk();
        foreach (range(1, 3) as $_) {
            $this->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id])->assertStatus(429);
        }

        expect($rows())->toBe(['verification_failed:send'])
            ->and(array_filter($recorder->counters, fn ($c) => ($c[1]['reason'] ?? null) === 'rate_limited'))->toHaveCount(3);
    });
});

/** Audit context of the destination events, keyed by event name. */
function destinationAudit(int $userId): array
{
    return MfaAuditLog::query()->where('user_id', $userId)
        ->whereIn('event', ['factor_enrollment_started', 'challenge_sent', 'factor_enabled'])
        ->orderBy('id')->get()
        ->mapWithKeys(fn (MfaAuditLog $row) => [$row->event => $row->context])
        ->all();
}

function lastEmailedCode(): string
{
    $code = null;
    Notification::assertSentOnDemand(OtpCodeNotification::class, function (OtpCodeNotification $n) use (&$code) {
        $code = $n->code;

        return true;
    });

    return $code;
}

it('fingerprints an email destination and flags that it is the account email, without logging the address', function () {
    Notification::fake();
    $logs = captureMfaLogs();
    $user = $this->makeUser(['email' => 'owner@example.com']);
    $this->loginWithSession($user);

    // Case and spacing don't matter: it is still the account's own address.
    $id = $this->postJson(route('mfa.factors.store'), ['type' => 'email', 'destination' => '  Owner@EXAMPLE.com '])->assertOk()->json('factor.id');
    $this->postJson(route('mfa.factors.confirm', $id), ['code' => lastEmailedCode()])->assertOk();

    $audit = destinationAudit($user->id);
    expect(array_keys($audit))->toEqualCanonicalizing(['factor_enrollment_started', 'challenge_sent', 'factor_enabled']);
    foreach ($audit as $context) {
        expect($context['destination_is_account_email'])->toBeTrue()
            ->and($context['destination_fingerprint'])->toMatch('/^fp_[0-9a-f]{16}$/');
    }
    expect(array_unique(array_column($audit, 'destination_fingerprint')))->toHaveCount(1);

    $everything = json_encode(MfaAuditLog::all()->toArray()).$logs->map(fn ($e) => json_encode($e->context).$e->message)->implode('');
    expect(strtolower($everything))->not->toContain('owner@')->not->toContain('example.com');
});

it('flags an email destination that is not the account email, with the same fingerprint for the same address on any account', function () {
    Notification::fake();
    $prints = [];

    foreach (['first@example.com', 'second@example.com'] as $email) {
        $user = $this->makeUser(['email' => $email]);
        $this->loginWithSession($user);
        $this->postJson(route('mfa.factors.store'), ['type' => 'email', 'destination' => 'Someone.Else@Elsewhere.test'])->assertOk();

        $context = destinationAudit($user->id)['factor_enrollment_started'];
        expect($context['destination_is_account_email'])->toBeFalse();
        $prints[] = $context['destination_fingerprint'];
    }

    // One address used on two accounts links them, without storing it.
    expect($prints[0])->toBe($prints[1])
        ->and(json_encode(MfaAuditLog::all()->toArray()))->not->toContain('elsewhere');
});

it('fingerprints an SMS destination but makes no account-email claim', function () {
    Mfa::fakeSms();
    $user = $this->makeUser();
    $this->loginWithSession($user);

    $this->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+1 555 555 0199'])->assertOk();

    $audit = destinationAudit($user->id);
    foreach (['factor_enrollment_started', 'challenge_sent'] as $event) {
        expect($audit[$event]['destination_fingerprint'])->toMatch('/^fp_[0-9a-f]{16}$/')
            ->and($audit[$event])->not->toHaveKey('destination_is_account_email');
    }
    expect(json_encode(MfaAuditLog::all()->toArray()))->not->toContain('5550199');
});

it('adds no destination signal for an authenticator app', function () {
    $user = $this->makeUser();
    $this->loginWithSession($user);

    $this->postJson(route('mfa.factors.store'), ['type' => 'totp'])->assertOk();

    expect(destinationAudit($user->id)['factor_enrollment_started'])
        ->not->toHaveKey('destination_fingerprint')
        ->not->toHaveKey('destination_is_account_email');
});

it('keys the fingerprint from APP_KEY, so it changes when the key does', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Sms);
    $print = fn (string $key) => (new DestinationFingerprint(new CodeHasher([$key])))->context($factor, $user)['destination_fingerprint'];
    $a = 'base64:'.base64_encode(str_repeat('a', 32));
    $b = 'base64:'.base64_encode(str_repeat('b', 32));

    expect($print($a))->toBe($print($a))->not->toBe($print($b));
});
