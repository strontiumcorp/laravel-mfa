<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * An MFA-verified user was allowed to impersonate another user.
 */
final class ImpersonationGranted extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
