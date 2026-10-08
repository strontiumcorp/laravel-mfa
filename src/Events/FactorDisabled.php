<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A factor was removed.
 */
final class FactorDisabled extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
