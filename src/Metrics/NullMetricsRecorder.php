<?php

namespace StrontiumCorp\LaravelMfa\Metrics;

use StrontiumCorp\LaravelMfa\Contracts\MetricsRecorder;

final class NullMetricsRecorder implements MetricsRecorder
{
    public function increment(string $metric, array $tags = []): void {}

    public function timing(string $metric, float $milliseconds, array $tags = []): void {}
}
