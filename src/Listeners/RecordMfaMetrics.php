<?php

namespace StrontiumCorp\LaravelMfa\Listeners;

use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;
use StrontiumCorp\LaravelMfa\Contracts\MfaActivity;
use Throwable;

final class RecordMfaMetrics
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function handle(MfaActivity $event): void
    {
        try {
            $context = $event->toContext();

            $this->metrics->increment('mfa.'.$event->name(), array_filter([
                'factor' => $context['factor'] ?? null,
                'reason' => $context['reason'] ?? null,
                'provider' => $context['provider'] ?? null,   // SMS failover: which provider failed
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
