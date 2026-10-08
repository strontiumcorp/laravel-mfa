<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Support\Arr;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use StrontiumCorp\LaravelMfa\Events\MfaEvent;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use Throwable;

final class WriteMfaAuditLog
{
    public function handle(MfaActivity $event): void
    {
        if (! $event instanceof MfaEvent
            || ! config('mfa.observability.audit.enabled')
            || in_array($event::class, (array) config('mfa.observability.audit.ignore'), true)) {
            return;
        }

        try {
            // user_id is a foreign key to the MFA user model only.
            $user = is_a($event->user, Mfa::userModel()) ? $event->user : null;

            MfaAuditLog::query()->create([
                'user_id' => $user?->getKey(),
                'event' => $event->name(),
                'factor_type' => $event->factorType?->value,
                'reason' => $event->reason?->value,
                'flow_id' => $event->flowId,
                'ip_address' => $event->ipAddress,
                'user_agent' => $event->userAgent,
                'context' => Arr::except($event->toContext(), ['event', 'user_type', 'user_id', 'factor', 'reason', 'flow_id', 'ip']) ?: null,
                'created_at' => $event->occurredAt,
            ]);
        } catch (Throwable $e) {
            report($e); // Observability must never break authentication.
        }
    }
}
