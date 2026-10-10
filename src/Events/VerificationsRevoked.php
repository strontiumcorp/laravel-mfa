<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * Every verified session of this user must pass MFA again (Mfa::reset(),
 * Mfa::revokeVerifications()). Context: by (who asked, when given),
 * by_administrator.
 */
final class VerificationsRevoked extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
