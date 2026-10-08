<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * An enforced user without factors was sent to enroll.
 */
final class EnrollmentRequired extends MfaEvent
{
    public function level(): string
    {
        return 'debug';
    }
}
