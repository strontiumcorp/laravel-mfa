<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use PragmaRX\Google2FA\Google2FA;
use StrontiumCorp\LaravelMfa\Enums\FactorType;
use StrontiumCorp\LaravelMfa\Events\SuspiciousCodeRequests;
use StrontiumCorp\LaravelMfa\Facades\Mfa;
use StrontiumCorp\LaravelMfa\Notifications\SecurityAlertNotification;
use StrontiumCorp\LaravelMfa\Support\RecoveryCodes;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\CustomSecurityAlert;
use StrontiumCorp\LaravelMfa\Tests\Fixtures\OwnQueueSecurityAlert;

beforeEach(function () {
    Notification::fake();
});

/** @return list<SecurityAlertNotification> the alerts sent to $email, in order */
function alertsTo(string $email): array
{
    $alerts = [];
    foreach (Notification::sentNotifications() as $byKey) {
        foreach ($byKey as $byClass) {
            foreach ($byClass as $sent) {
                foreach ($sent as $entry) {
                    if ($entry['notification'] instanceof SecurityAlertNotification && ($entry['notifiable']->routes['mail'] ?? null) === $email) {
                        $alerts[] = $entry['notification'];
                    }
                }
            }
        }
    }

    return $alerts;
}

/** The alert names sent to $email, in order. */
function alertNames(string $email): array
{
    return array_map(fn (SecurityAlertNotification $n) => $n->alert, alertsTo($email));
}

function enableTotp($test, $user): int
{
    $secret = $test->postJson('/mfa/factors', ['type' => 'totp'])->assertOk()->json('setup.secret');
    $id = $user->mfaFactors()->whereNull('confirmed_at')->sole()->id;
    $test->postJson("/mfa/factors/{$id}/confirm", ['code' => (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30))])->assertOk();

    return $id;
}

it('tells the owner when a method is added, without a second email for the first recovery codes', function () {
    $user = $this->makeUser(['email' => 'owner@example.com']);
    $this->loginWithSession($user);

    enableTotp($this, $user);

    expect(alertNames('owner@example.com'))->toBe(['factor_enabled']);
    $alert = alertsTo('owner@example.com')[0];
    expect($alert->details)->toMatchArray(['factor' => 'Authenticator app', 'ip' => '127.0.0.1', 'by_administrator' => false, 'remaining' => null])
        ->and($alert->details['occurred_at'])->toBe(now()->toIso8601String());
});

it('tells the owner when a method is removed, and when an administrator removed it', function () {
    [$user, $factor] = $this->userWithFactor(FactorType::Email, ['email' => 'owner@example.com']);
    $this->actingAsMfaVerified($user)->deleteJson("/mfa/factors/{$factor->id}")->assertOk();

    $second = $this->createMfaFactor($user);
    $this->artisan('mfa:reset', ['user' => $user->id, '--force' => true])->assertSuccessful();

    $alerts = alertsTo('owner@example.com');
    expect(array_map(fn ($n) => [$n->alert, $n->details['factor'], $n->details['by_administrator']], $alerts))->toBe([
        ['factor_disabled', 'Email', false],
        ['factor_disabled', 'Authenticator app', true],
    ]);
    expect($second->exists)->toBeTrue();
});

it('tells the owner about new recovery codes and about each one used', function () {
    [$user] = $this->userWithFactor(FactorType::Totp, ['email' => 'owner@example.com']);
    $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();

    $code = app(RecoveryCodes::class)->generate($user)[0];
    $this->post('/logout');
    $this->freshGuards()->loginWithSession($user)->postJson('/mfa/challenge/recover', ['code' => $code])->assertOk();

    $alerts = alertsTo('owner@example.com');
    expect(array_map(fn ($n) => $n->alert, $alerts))->toBe(['recovery_codes_generated', 'recovery_code_used'])
        ->and($alerts[1]->details['remaining'])->toBe(9);
});

it('warns the owner when login codes keep being requested without being used', function () {
    Mfa::fakeSms();
    [$user, $factor] = $this->userWithFactor(FactorType::Sms, ['email' => 'owner@example.com']);
    $this->loginWithSession($user);

    foreach ([0, 120, 240, 480, 900] as $wait) {
        $this->travel($wait)->seconds();
        $this->postJson('/mfa/challenge/send', ['factor_id' => $factor->id])->assertOk();
    }

    expect(alertNames('owner@example.com'))->toBe(['suspicious_code_requests'])
        ->and(alertsTo('owner@example.com')[0]->details['factor'])->toBe('SMS');
});

it('has a switch per event and one for all of them', function () {
    config(['mfa.notifications.events.factor_enabled' => false]);
    $user = $this->makeUser(['email' => 'owner@example.com']);
    $this->loginWithSession($user);
    $id = enableTotp($this, $user);

    expect(alertNames('owner@example.com'))->toBe([]);

    config(['mfa.notifications.enabled' => false]);
    $this->deleteJson("/mfa/factors/{$id}")->assertOk();
    event(new SuspiciousCodeRequests($user, FactorType::Sms));

    Notification::assertNothingSent();
});

it('sets the delivery queue on the email when one is set, and leaves it unset otherwise (then it is sent inline)', function () {
    [$user] = $this->userWithFactor(FactorType::Totp, ['email' => 'owner@example.com']);
    $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();

    config(['mfa.delivery.queue_connection' => 'redis', 'mfa.delivery.queue' => 'mfa']);
    $this->postJson('/mfa/recovery-codes')->assertOk();

    $alerts = alertsTo('owner@example.com');
    expect($alerts[0])->toBeInstanceOf(ShouldQueue::class)
        ->and([$alerts[0]->connection, $alerts[0]->queue])->toBe([null, null])
        ->and([$alerts[1]->connection, $alerts[1]->queue])->toBe(['redis', 'mfa']);
});

it('uses the configured notification class', function () {
    config(['mfa.notifications.notification' => CustomSecurityAlert::class]);
    [$user] = $this->userWithFactor(FactorType::Totp, ['email' => 'owner@example.com']);

    $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();

    Notification::assertSentOnDemand(CustomSecurityAlert::class, fn ($n, $channels, $to) => $n->alert === 'recovery_codes_generated' && $to->routes === ['mail' => 'owner@example.com']);
});

it('skips users without an email address', function () {
    [$user] = $this->userWithFactor(FactorType::Totp, ['email' => '']);

    $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();

    Notification::assertNothingSent();
});

it('never blocks the request when sending fails', function () {
    Exceptions::fake();
    Notification::swap(new class extends NotificationFake
    {
        public function send($notifiables, $notification)
        {
            throw new RuntimeException('mail is down');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            throw new RuntimeException('mail is down');
        }
    });
    [$user] = $this->userWithFactor(FactorType::Totp, ['email' => 'owner@example.com']);

    $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'mail is down');
});

it('says what happened, when and from where, and never includes a code or secret', function (string $alert, string $subject, string $line) {
    config(['app.name' => 'Acme', 'app.timezone' => 'UTC']);
    $mail = (new SecurityAlertNotification($alert, [
        'factor' => 'Authenticator app', 'ip' => '203.0.113.9', 'occurred_at' => '2026-10-10T08:30:00+00:00', 'by_administrator' => false, 'remaining' => 7,
    ]))->toMail(new AnonymousNotifiable);

    $text = implode("\n", $mail->introLines);
    expect($mail->subject)->toBe($subject)
        ->and($text)->toContain($line)
        ->and($text)->toContain('When: 10 Oct 2026, 08:30 UTC')
        ->and($text)->toContain('IP address: 203.0.113.9');
})->with([
    ['factor_enabled', 'A sign-in method was added to your Acme account', 'Authenticator app was added to your account'],
    ['factor_disabled', 'A sign-in method was removed from your Acme account', 'Authenticator app was removed from your account'],
    ['recovery_codes_generated', 'New recovery codes for your Acme account', 'Your previous recovery codes no longer work'],
    ['recovery_code_used', 'A recovery code was used on your Acme account', 'Recovery codes left: 7.'],
    ['suspicious_code_requests', 'Sign-in codes keep being requested for your Acme account', 'keeps asking for sign-in codes'],
]);

it('says an administrator removed a method', function () {
    $mail = (new SecurityAlertNotification('factor_disabled', ['factor' => 'SMS', 'by_administrator' => true]))->toMail(new AnonymousNotifiable);

    expect(implode("\n", $mail->introLines))->toContain('An administrator removed SMS from your account.')->not->toContain('IP address');
});

describe('review fixes', function () {
    it('sends inline when no delivery queue is set, like codes, and queues on the delivery queue when one is', function () {
        Notification::swap(new ChannelManager(app())); // real notifications (app() would return the fake)
        Queue::fake();
        config(['mail.default' => 'array']);
        [$user] = $this->userWithFactor(FactorType::Totp, ['email' => 'owner@example.com']);

        $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();
        Queue::assertNothingPushed();
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);

        config(['mfa.delivery.queue' => 'mfa', 'queue.default' => 'database']);
        $this->postJson('/mfa/recovery-codes')->assertOk();
        Queue::assertPushedOn('mfa', SendQueuedNotifications::class);
    });

    it("keeps a replacement class's own queue", function () {
        Queue::fake();
        Notification::swap(new ChannelManager(app())); // real notifications (app() would return the fake)
        config(['mfa.delivery.queue' => 'mfa', 'queue.default' => 'database', 'mfa.notifications.notification' => OwnQueueSecurityAlert::class]);
        [$user] = $this->userWithFactor(FactorType::Totp, ['email' => 'owner@example.com']);

        $this->actingAsMfaVerified($user)->postJson('/mfa/recovery-codes')->assertOk();

        Queue::assertPushedOn('alerts', SendQueuedNotifications::class);
    });

    it('emails about new recovery codes when they are regenerated by adding a method after all were used', function () {
        [$user] = $this->userWithFactor(FactorType::Email, ['email' => 'owner@example.com']);
        foreach (app(RecoveryCodes::class)->generate($user) as $code) {
            app(RecoveryCodes::class)->consume($user, $code);
        }
        $this->actingAsMfaVerified($user);

        enableTotp($this, $user);

        expect(alertNames('owner@example.com'))->toBe(['factor_enabled', 'recovery_codes_generated']);
    });

    it('sends at most one suspicious-requests email per account per hour', function () {
        [$user] = $this->userWithFactor(FactorType::Sms, ['email' => 'owner@example.com']);

        event(new SuspiciousCodeRequests($user, FactorType::Sms, null, ['reason' => 'repeated_unverified_sends']));
        event(new SuspiciousCodeRequests($user, FactorType::Email, null, ['reason' => 'send_cap_reached']));
        expect(alertNames('owner@example.com'))->toBe(['suspicious_code_requests']);

        $this->travel(61)->minutes();
        event(new SuspiciousCodeRequests($user, FactorType::Sms, null, ['reason' => 'send_cap_reached']));
        expect(alertNames('owner@example.com'))->toBe(['suspicious_code_requests', 'suspicious_code_requests']);
    });

    it('tells the owner when an administrator issues a setup link for their account', function () {
        $user = $this->makeUser(['email' => 'owner@example.com']);

        $this->artisan('mfa:enrollment-link', ['user' => $user->id])->assertSuccessful();

        $alerts = alertsTo('owner@example.com');
        expect(array_map(fn ($n) => $n->alert, $alerts))->toBe(['enrollment_link_issued'])
            ->and($alerts[0]->details['by_administrator'])->toBeTrue();
        $mail = $alerts[0]->toMail(new AnonymousNotifiable);
        expect($mail->subject)->toContain('setup link')->and(implode(' ', $mail->introLines))->not->toContain('http');
    });
});

describe('verification review fixes', function () {
    it('never blocks the request when the cache for the hourly limit fails', function () {
        Exceptions::fake();
        [$user] = $this->userWithFactor(FactorType::Sms, ['email' => 'owner@example.com']);
        config(['mfa.cache.store' => 'missing']);

        event(new SuspiciousCodeRequests($user, FactorType::Sms, null, ['reason' => 'send_cap_reached']));

        Exceptions::assertReported(InvalidArgumentException::class);
    });

    it('frees the hour when the suspicious-requests email fails, so the next one is sent', function () {
        Exceptions::fake();
        [$user] = $this->userWithFactor(FactorType::Sms, ['email' => 'owner@example.com']);
        Notification::swap(new class extends NotificationFake
        {
            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('mail is down');
            }
        });
        event(new SuspiciousCodeRequests($user, FactorType::Sms, null, ['reason' => 'send_cap_reached']));

        Notification::fake();
        event(new SuspiciousCodeRequests($user, FactorType::Sms, null, ['reason' => 'send_cap_reached']));

        expect(alertNames('owner@example.com'))->toBe(['suspicious_code_requests']);
    });
});
