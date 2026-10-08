<?php

// Behaviour the coverage report showed as untested (2026-10-09 review).

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use StrontiumCorp\LaravelMfa\Contracts\Factor;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Enums\FailureReason;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Jobs\DeliverOtp;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Models\MfaFactor;
use StrontiumCorp\LaravelMfa\Models\MfaOtpCode;
use StrontiumCorp\LaravelMfa\Models\MfaRecoveryCode;
use StrontiumCorp\LaravelMfa\Policies\EnforceForAdmins;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Support\VerificationResult;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\PlainUser;

/** MFA log lines written while the test runs. */
function mfaLogLines(): Collection
{
    $lines = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => str_starts_with($e->message, 'mfa.') ? $lines->push($e) : null);

    return $lines;
}

describe('settings', function () {
    it('refuses to resend a pending factor started by another session', function () {
        $sms = Mfa::fakeSms();
        $user = $this->makeUser();
        $this->loginWithSession($user)->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550100'])->assertOk();
        $factor = MfaFactor::sole();

        $this->flushSession();
        $this->freshGuards()->loginWithSession($user)
            ->postJson(route('mfa.factors.resend', ['factor' => $factor->id]))
            ->assertUnprocessable();

        $sms->assertSentTo('+15555550100', 1); // only the enrollment send
    });

    it('refuses to generate recovery codes before any factor exists', function () {
        $this->loginWithSession($this->makeUser())
            ->postJson(route('mfa.recovery-codes.store'))
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Enable a verification method first.']);

        expect(MfaRecoveryCode::count())->toBe(0);
    });

    it('shows recovery codes to Inertia exactly once after the first factor is confirmed', function () {
        config(['mfa.ui.driver' => 'inertia']);
        $user = $this->makeUser();
        $this->loginWithSession($user)->post(route('mfa.factors.store'), ['type' => 'totp'])->assertRedirect(route('mfa.settings'));
        $factor = MfaFactor::sole();

        $this->post(route('mfa.factors.confirm', ['factor' => $factor->id]), ['code' => $this->currentTotpCode($factor)])
            ->assertRedirect(route('mfa.settings'));

        $codes = $this->get(route('mfa.settings'), ['X-Inertia' => 'true'])->json('props.recoveryCodes');
        expect($codes)->toHaveCount(10)
            ->and(app(RecoveryCodes::class)->consume($user, $codes[0]))->toBeTrue()
            ->and($this->get(route('mfa.settings'), ['X-Inertia' => 'true'])->json('props.recoveryCodes'))->toBeNull();
    });
});

describe('challenge', function () {
    it('rejects sending to a factor the user does not own', function () {
        [, $theirs] = $this->userWithFactor(FactorType::Email);
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $theirs->id])->assertUnprocessable();
    });

    it('rate limits recovery codes with the same budget as codes', function () {
        config(['mfa.rate_limit.verify_per_minute' => 2]);
        [$user] = $this->userWithFactor();
        $codes = app(RecoveryCodes::class)->generate($user);
        $this->loginWithSession($user);

        $this->postJson(route('mfa.challenge.recover'), ['code' => 'wrong-guess'])->assertUnprocessable();
        $this->postJson(route('mfa.challenge.recover'), ['code' => 'wrong-guess'])->assertUnprocessable();

        // Even a valid code is refused once the budget is spent, and isn't burned.
        $this->postJson(route('mfa.challenge.recover'), ['code' => $codes[0]])->assertStatus(429)->assertJsonStructure(['retry_after']);
        expect(app(RecoveryCodes::class)->remaining($user))->toBe(10);
    });

    it('rejects a malformed code without spending an attempt on the real one', function () {
        Mfa::fakeSms();
        Mfa::fakeCodes('482913');
        [$user, $factor] = $this->userWithFactor(FactorType::Sms);
        $this->loginWithSession($user)->postJson(route('mfa.challenge.send'), ['factor_id' => $factor->id]);

        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '48z913'])->assertUnprocessable();

        expect(MfaOtpCode::sole()->attempts)->toBe(0);
        $this->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '482 913'])->assertOk();
    });

    it('drops a queued delivery whose factor was removed in the meantime', function () {
        $sms = Mfa::fakeSms();

        (new DeliverOtp(999_999, '123456', 600))->handle(app(MetricsRecorder::class));

        $sms->assertNothingSent();
    });
});

describe('observability', function () {
    it('logs a tripped send circuit as critical, so alerts can fire on it', function () {
        $lines = mfaLogLines();
        config(['mfa.rate_limit.unconfirmed_global_per_hour' => 0]);
        Mfa::fakeSms();

        $this->loginWithSession($this->makeUser())
            ->postJson(route('mfa.factors.store'), ['type' => 'sms', 'destination' => '+15555550100'])
            ->assertStatus(503);

        expect($lines->firstWhere('message', 'mfa.sending_circuit_tripped')?->level)->toBe('critical');
    });

    it('keeps enrollment redirects (debug) out of the log at the default level', function () {
        $lines = mfaLogLines();
        config(['mfa.enforce' => EnforceForAdmins::class]);

        $this->loginWithSession($this->makeUser(['is_admin' => true]))->get('/dashboard')->assertRedirect(route('mfa.settings'));
        expect($lines->pluck('message'))->not->toContain('mfa.enrollment_required');

        config(['mfa.observability.log.level' => 'debug']);
        $this->get('/dashboard');
        expect($lines->pluck('message'))->toContain('mfa.enrollment_required');
    });

    it('writes no MFA log lines when logging is off', function () {
        $lines = mfaLogLines();
        config(['mfa.observability.log.enabled' => false]);
        [$user, $factor] = $this->userWithFactor();

        $this->loginWithSession($user)->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000']);

        expect($lines)->toBeEmpty()->and(MfaAuditLog::count())->toBeGreaterThan(0);
    });

    it('never blocks a login when the log channel or the metrics recorder throws', function () {
        Exceptions::fake();
        config([
            // Throws on write: a channel that fails to build is already caught by
            // Laravel (emergency logger), a failing handler is not.
            'logging.channels.broken' => ['driver' => 'custom', 'via' => fn () => new Logger('broken', [new class extends AbstractProcessingHandler
            {
                protected function write(LogRecord $record): void
                {
                    throw new RuntimeException('log down');
                }
            }])],
            'mfa.observability.log.channel' => 'broken',
        ]);
        app()->instance(MetricsRecorder::class, new class implements MetricsRecorder
        {
            public function increment(string $name, array $tags = []): void
            {
                throw new RuntimeException('metrics down');
            }

            public function timing(string $name, float $milliseconds, array $tags = []): void {}
        });
        [$user, $factor] = $this->userWithFactor();

        $this->loginWithSession($user)
            ->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => $this->currentTotpCode($factor)])
            ->assertOk();

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'log down');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'metrics down');
    });
});

describe('support commands', function () {
    it('changes nothing when mfa:reset is not confirmed', function () {
        [$user] = $this->userWithFactor();

        $this->artisan('mfa:reset', ['user' => $user->email])
            ->expectsConfirmation("Remove 1 factor(s) and all recovery codes for user #{$user->id}?", 'no')
            ->assertFailed();

        expect(Mfa::hasConfirmedFactors($user))->toBeTrue();
    });

    it('reports unknown users and users without MFA support', function () {
        config([
            'auth.providers.plain' => ['driver' => 'eloquent', 'model' => PlainUser::class],
            'auth.guards.plain' => ['driver' => 'session', 'provider' => 'plain'],
        ]);
        $user = $this->makeUser();

        $this->artisan('mfa:status', ['user' => 'nobody@example.com'])->assertFailed()->expectsOutputToContain('No MFA-capable user found');
        $this->artisan('mfa:status', ['user' => $user->email, '--guard' => 'plain'])->assertFailed()->expectsOutputToContain('on guard [plain]');
    });
});

describe('mfa:doctor', function () {
    beforeEach(fn () => config(['session.driver' => 'database']));

    it('validates the enforcement setting (D5)', function () {
        config(['mfa.enforce' => ['admin', 'support']]);
        $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('Enforced for roles [admin, support]');

        config(['mfa.enforce' => ['admin', '']]);
        $this->artisan('mfa:doctor')->assertFailed();

        config(['mfa.enforce' => PlainUser::class]);
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('implements EnforcementPolicy');

        config(['mfa.enforce' => null]);
        Mfa::enforceUsing(fn () => true);
        try {
            $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('Enforcement decided by Mfa::enforceUsing()');
        } finally {
            Mfa::enforceUsing(null);
        }
    });

    it('checks the delivery queue (D8)', function () {
        config(['mfa.delivery.queue' => 'mfa', 'queue.default' => 'database']);
        $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('Delivery queue connection [database] exists');

        config(['mfa.delivery.queue_connection' => 'nope']);
        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('Delivery queue connection [nope] exists');
    });

    it('warns about settings that silently break delivery in production', function () {
        app()->detectEnvironment(fn () => 'production');
        config([
            'mfa.sms.driver' => 'log',
            'mfa.factors.sms.allowed_calling_codes' => [],
            'mail.default' => 'log',
            'session.driver' => 'file',
            'mfa.delivery.queue' => 'mfa',
            'queue.default' => 'sync',
        ]);

        $this->artisan('mfa:doctor')
            ->expectsOutputToContain('SMS driver is "log" in production')
            ->expectsOutputToContain('allowed_calling_codes is empty')
            ->expectsOutputToContain('Mailer is "log" in production')
            ->expectsOutputToContain('Session driver is "file"')
            ->expectsOutputToContain('is "sync"');
    });

    it('fails on a session driver that cannot hold MFA state', function () {
        config(['session.driver' => 'array']);

        $this->artisan('mfa:doctor')->assertFailed()->expectsOutputToContain('Session driver persists between requests');
    });

    it('warns when the middleware is not appended to the web group', function () {
        config(['mfa.middleware.append_to_web_group' => false]);

        $this->artisan('mfa:doctor')->expectsOutputToContain('Middleware is NOT appended to the web group');
    });

    it('reads trusted proxies from a Kernel-style app\'s TrustProxies (artistly)', function () {
        require_once __DIR__.'/../Fixtures/KernelApp/TrustProxies.php';

        $this->artisan('mfa:doctor')->assertSuccessful()->expectsOutputToContain('Trusted proxies configured');
    });
});

describe('public API', function () {
    it('exposes the relations and helpers apps use', function () {
        [$user, $factor] = $this->userWithFactor(FactorType::Email);
        $this->createMfaFactor($user)->forceFill(['confirmed_at' => null])->save();
        app(RecoveryCodes::class)->generate($user);
        Mfa::fakeCodes();
        Mfa::factor(FactorType::Email)->challenge($factor);

        expect($user->hasMfaEnabled())->toBeTrue()
            ->and($user->mfaAuditLogs()->count())->toBeGreaterThan(0)
            ->and(MfaAuditLog::where('user_id', $user->id)->first()->user->is($user))->toBeTrue()
            ->and(MfaRecoveryCode::first()->user->is($user))->toBeTrue()
            ->and(MfaOtpCode::sole()->factor->is($factor))->toBeTrue()
            ->and(MfaFactor::confirmed()->pluck('id')->all())->toBe([$factor->id])
            ->and($this->makeUser()->hasMfaEnabled())->toBeFalse();
    });

    it('lets Mfa::extend() replace a built-in factor implementation', function () {
        Mfa::factor(FactorType::Email); // already built: extend() must still take effect
        Mfa::extend('email', fn () => new class implements Factor
        {
            public function type(): FactorType
            {
                return FactorType::Email;
            }

            public function enroll(MultiFactorAuthenticatable $user, array $input): array
            {
                throw new LogicException('not used');
            }

            public function challenge(MfaFactor $factor): VerificationResult
            {
                return VerificationResult::success();
            }

            public function verify(MfaFactor $factor, string $code): VerificationResult
            {
                return $code === '000000' ? VerificationResult::success() : VerificationResult::failure(FailureReason::InvalidCode);
            }
        });
        [$user, $factor] = $this->userWithFactor(FactorType::Email);

        $this->loginWithSession($user)->postJson(route('mfa.challenge.verify'), ['factor_id' => $factor->id, 'code' => '000000'])->assertOk();
    });
});

describe('middleware.append_to_web_group = false', function () {
    it('protects only routes that use the "mfa" alias', function () {
        $this->rebootWith(['mfa.middleware.append_to_web_group' => false]);
        Route::middleware(['web', 'auth', 'mfa'])->get('/guarded', fn () => 'guarded');
        [$user] = $this->userWithFactor();

        $this->loginWithSession($user)->get('/dashboard')->assertOk();
        $this->get('/guarded')->assertRedirect(route('mfa.challenge'));
    });
});
