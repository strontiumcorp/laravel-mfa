<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * Delivering a one-time code failed (provider error, bad config).
 */
final class ChallengeDeliveryFailed extends MfaEvent
{
    public function level(): string
    {
        return 'error';
    }
}
