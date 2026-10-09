<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A code was sent to the account's email address to prove inbox access
 * before its first factor is added (enrollment_verification). Context: queued.
 */
final class EnrollmentVerificationSent extends MfaEvent
{
    //
}
