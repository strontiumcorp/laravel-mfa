<?php

namespace StrontiumCorp\LaravelMfa\Metrics;

use Illuminate\Log\LogManager;
use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;

/**
 * Emits metrics as structured log lines (e.g. for log-based metrics in
 * CloudWatch, Datadog, Loki).
 */
final class LogMetricsRecorder implements MetricsRecorder
{
    public function __construct(
        private readonly LogManager $log,
        private readonly ?string $channel = null,
    ) {}

    public function increment(string $metric, array $tags = []): void
    {
        $this->log->channel($this->channel)->debug('[mfa.metric]', ['metric' => $metric, 'type' => 'counter', 'value' => 1, 'tags' => $tags]);
    }

    public function timing(string $metric, float $milliseconds, array $tags = []): void
    {
        $this->log->channel($this->channel)->debug('[mfa.metric]', ['metric' => $metric, 'type' => 'timing', 'value' => round($milliseconds, 2), 'tags' => $tags]);
    }
}
