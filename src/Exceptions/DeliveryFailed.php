<?php

namespace StrontiumCorp\LaravelMfa\Exceptions;

use RuntimeException;
use StrontiumCorp\LaravelMfa\Support\Redact;

/**
 * Thrown by delivery channels. Messages never contain the code or the full
 * destination, so they are safe to log.
 */
class DeliveryFailed extends RuntimeException
{
    public static function provider(string $provider, string $detail): self
    {
        return new self("[{$provider}] ".Redact::text($detail));
    }
}
