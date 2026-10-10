<?php

namespace StrontiumCorp\LaravelMfa\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The MFA cache store failed while reading or writing a revocation stamp
 * (Support\Revocations). Reported, not thrown, on reads (the table answers
 * instead): during an outage that is one report per request, so throttle or
 * ignore it in the app's exception handler, e.g.
 * Exceptions::throttle(fn ($e) => $e instanceof RevocationCacheUnavailable ? Limit::perMinute(1) : null).
 */
class RevocationCacheUnavailable extends RuntimeException
{
    public static function wrap(Throwable $previous): self
    {
        return new self('The MFA cache store is unavailable for revocations: '.$previous::class, 0, $previous);
    }
}
