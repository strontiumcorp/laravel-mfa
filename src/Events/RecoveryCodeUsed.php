<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A recovery code was consumed. Consider notifying the user.
 */
final class RecoveryCodeUsed extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
