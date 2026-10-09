<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Arr;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use StrontiumCorp\LaravelMfa\Events\MfaEvent;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Models\MfaAuditLog;
use StrontiumCorp\LaravelMfa\Support\CacheKey;
use Throwable;

final class WriteMfaAuditLog
{
    public function __construct(private readonly CacheFactory $cache) {}

    public function handle(MfaActivity $event): void
    {
        if (! $event instanceof MfaEvent
            || ! config('mfa.observability.audit.enabled')
            || in_array($event::class, (array) config('mfa.observability.audit.ignore'), true)) {
            return;
        }

        try {
            if ($this->repeatsRefusal($event)) {
                return;
            }

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

    /**
     * Whether this is a refusal by a limit that this user already has a row
     * for in the current window: same event, reason, stage and scope, until
     * the refusal's retry_after (when the window frees up; 60s when
     * unknown). The marker is an HMAC key set with add(), so concurrent
     * refusals write one row. The log and metrics still see every refusal.
     */
    private function repeatsRefusal(MfaEvent $event): bool
    {
        if ($event->user === null || $event->reason?->isLimit() !== true) {
            return false;
        }

        $kind = implode('|', [
            CacheKey::user($event->user), $event->name(), $event->reason->value,
            $event->context['stage'] ?? '', $event->context['scope'] ?? '',
        ]);
        $window = (int) ($event->context['retry_after'] ?? 60);

        return ! $this->cache->store(config('mfa.cache.store'))
            ->add(CacheKey::for('audit-refusal', $kind), true, max(1, $window));
    }
}
