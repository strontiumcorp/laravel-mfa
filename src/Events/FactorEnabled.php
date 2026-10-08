<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A factor was confirmed and is now active.
 */
final class FactorEnabled extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
