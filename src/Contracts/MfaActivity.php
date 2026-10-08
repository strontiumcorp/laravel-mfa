<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

/**
 * Marker for every MFA domain event. Listen to this interface to receive all
 * of them: Event::listen(MfaActivity::class, ...).
 */
interface MfaActivity
{
    /** Stable snake_case name, e.g. "verification_failed". */
    public function name(): string;

    /** PSR-3 level used for the structured log line. */
    public function level(): string;

    /** @return array<string, mixed> PII-free context for logs/audit/metrics. */
    public function toContext(): array;
}
