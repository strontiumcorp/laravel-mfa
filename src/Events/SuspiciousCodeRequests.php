<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * Login codes keep being requested without a successful verification, or the
 * user hit the hourly send cap. Since the challenge only appears after the
 * password step, this usually means someone else has the password: listen to
 * this event and warn the owner on another channel.
 *
 * context.reason: "repeated_unverified_sends" | "send_cap_reached"
 */
final class SuspiciousCodeRequests extends MfaEvent
{
    public function level(): string
    {
        return 'warning';
    }
}
