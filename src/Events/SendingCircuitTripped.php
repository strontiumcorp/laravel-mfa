<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * The app-wide cap on sends to unconfirmed destinations was hit — likely a
 * distributed SMS pumping or email bombing attempt. New enrollments by SMS /
 * email are paused until the hour rolls over; logins are unaffected.
 * Dispatched once per hour.
 */
final class SendingCircuitTripped extends MfaEvent
{
    public function level(): string
    {
        return 'critical';
    }
}
