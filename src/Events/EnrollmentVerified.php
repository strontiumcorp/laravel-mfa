<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * This session proved it owns the account beyond its password, so it may add
 * the account's first factor (enrollment_verification).
 * Context: method ("email" | "link").
 */
final class EnrollmentVerified extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
