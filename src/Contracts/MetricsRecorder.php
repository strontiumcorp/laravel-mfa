<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

/**
 * Bind your own implementation to ship MFA metrics to Prometheus, StatsD,
 * Datadog, Pulse, etc. Tags are low-cardinality (factor type, reason) — never
 * user ids.
 */
interface MetricsRecorder
{
    /** @param array<string, string> $tags */
    public function increment(string $metric, array $tags = []): void;

    /** @param array<string, string> $tags */
    public function timing(string $metric, float $milliseconds, array $tags = []): void;
}
