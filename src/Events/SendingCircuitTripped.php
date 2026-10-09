<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * An app-wide send cap was hit — likely a distributed SMS pumping or email
 * bombing attempt. Dispatched once per window (at most once an hour) for
 * each cap.
 *
 * context.scope:
 *  - "unconfirmed" (rate_limit.unconfirmed_global_per_hour): new enrollments
 *    by SMS / email are paused until the window frees up; logins go on.
 *  - "confirmed" (rate_limit.confirmed_global_per_hour): login codes by SMS /
 *    email are paused for everyone until the window frees up; authenticator
 *    apps and recovery codes still work.
 *
 * context.limit: the cap that was hit.
 */
final class SendingCircuitTripped extends MfaEvent
{
    public function level(): string
    {
        return 'critical';
    }
}
