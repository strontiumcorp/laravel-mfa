<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Events\EnrollmentLinkIssued;
use StrontiumCorp\LaravelMfa\Events\FactorDisabled;
use StrontiumCorp\LaravelMfa\Events\FactorEnabled;
use StrontiumCorp\LaravelMfa\Events\MfaEvent;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodesGenerated;
use StrontiumCorp\LaravelMfa\Events\RecoveryCodeUsed;
use StrontiumCorp\LaravelMfa\Events\SuspiciousCodeRequests;
use StrontiumCorp\LaravelMfa\Notifications\SecurityAlertNotification;
use StrontiumCorp\LaravelMfa\Support\CacheKey;
use StrontiumCorp\LaravelMfa\Support\DeliveryQueue;
use Throwable;

/**
 * Emails the account owner about changes to their two-factor setup, and
 * about code requests that look like someone else has the password
 * (config mfa.notifications). Like the other sinks, a failure is reported
 * and never blocks the request.
 */
final class NotifyAccountOwner
{
    /** The events it listens to, by their notifications.events switch. */
    public const EVENTS = [
        FactorEnabled::class => 'factor_enabled',
        FactorDisabled::class => 'factor_disabled',
        RecoveryCodesGenerated::class => 'recovery_codes_generated',
        RecoveryCodeUsed::class => 'recovery_code_used',
        SuspiciousCodeRequests::class => 'suspicious_code_requests',
        EnrollmentLinkIssued::class => 'enrollment_link_issued',
    ];

    /** At most one "suspicious requests" email per account in this many seconds. */
    private const SUSPICIOUS_EVERY = 3600;

    public function __construct(private readonly CacheFactory $cache) {}

    public function handle(MfaEvent $event): void
    {
        $alert = self::EVENTS[$event::class] ?? null;

        if ($alert === null || ! config('mfa.notifications.enabled') || ! config("mfa.notifications.events.{$alert}")) {
            return;
        }

        // The first set comes with the first factor: factor_enabled says it.
        if ($alert === 'recovery_codes_generated' && ($event->context['initial'] ?? false)) {
            return;
        }

        $email = $event->user instanceof MultiFactorAuthenticatable ? $event->user->getMfaEmail() : null;

        if (! is_string($email) || $email === '') {
            return;
        }

        $throttle = $alert === 'suspicious_code_requests' ? CacheKey::for('suspicious-email', CacheKey::user($event->user)) : null;

        try {
            // Each method and each cap can raise it; one email an hour says it.
            if ($throttle !== null && ! $this->cache()->add($throttle, true, self::SUSPICIOUS_EVERY)) {
                return;
            }

            /** @var class-string<SecurityAlertNotification> $class */
            $class = config('mfa.notifications.notification');
            $notification = new $class($alert, [
                'factor' => $event->factorType?->label(),
                'ip' => $event->ipAddress,
                'occurred_at' => $event->occurredAt->toIso8601String(),
                'by_administrator' => ($event->context['by_administrator'] ?? false) === true,
                'remaining' => $event->context['remaining'] ?? null,
            ]);

            // Like codes: on the delivery queue when one is set, else inline.
            DeliveryQueue::send($email, $notification);
        } catch (Throwable $e) {
            report($e); // Observability must never break authentication.

            // Not sent: the next warning this hour may go out instead.
            if ($throttle !== null) {
                rescue(fn () => $this->cache()->forget($throttle));
            }
        }
    }

    private function cache(): Repository
    {
        return $this->cache->store(config('mfa.cache.store'));
    }
}
