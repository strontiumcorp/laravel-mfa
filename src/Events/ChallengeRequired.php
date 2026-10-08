<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A logged-in user hit a protected route without passing MFA.
 */
final class ChallengeRequired extends MfaEvent
{
    public function level(): string
    {
        return 'debug';
    }
}
