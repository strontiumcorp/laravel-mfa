<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * One provider in a failover chain failed to send. context.provider is the
 * provider that failed, context.next the one tried next (null = none left),
 * context.error the PII-free reason.
 */
final class SmsProviderFailed extends MfaEvent
{
    public function level(): string
    {
        return 'warning';
    }
}
