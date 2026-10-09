<?php

namespace StrontiumCorp\LaravelMfa\Exceptions;

use RuntimeException;
use StrontiumCorp\LaravelMfa\Support\Redact;
use Throwable;

/**
 * Thrown by delivery channels. Messages never contain the code or the full
 * destination, so they are safe to log.
 *
 * $maybeDelivered: the request may have reached the provider (e.g. a read
 * timeout after the message was uploaded), so the message may still arrive.
 * Nothing sends it again (no failover, no queue retry) and the code stays
 * valid; the user can resend after the cooldown if it never comes.
 */
class DeliveryFailed extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly bool $maybeDelivered = false)
    {
        parent::__construct($message, $code, $previous);
    }

    /** The provider refused the message, or was certainly never reached. */
    public static function provider(string $provider, string $detail): self
    {
        return new self("[{$provider}] ".Redact::text($detail));
    }

    /** The outcome is unknown: the provider may have accepted it. */
    public static function uncertain(string $provider, string $detail): self
    {
        return new self("[{$provider}] ".Redact::text($detail), maybeDelivered: true);
    }
}
