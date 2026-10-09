<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * An administrator issued a link that lets this user add their first factor
 * without an email code (Mfa::enrollmentLink(), mfa:enrollment-link).
 * Context: expires_at, via.
 */
final class EnrollmentLinkIssued extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
