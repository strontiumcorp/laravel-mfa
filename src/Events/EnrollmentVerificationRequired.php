<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * Adding a first factor was refused until the user proves they own the
 * account beyond its password: an email code or an admin-issued link
 * (enrollment_verification). Context: path.
 */
final class EnrollmentVerificationRequired extends MfaEvent
{
    //
}
