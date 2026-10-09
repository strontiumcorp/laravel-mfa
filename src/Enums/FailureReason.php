<?php

namespace StrontiumCorp\LaravelMfa\Enums;

/**
 * Machine-readable reasons attached to every failure event, log line and
 * audit row. Grep / group / alert on these values.
 */
enum FailureReason: string
{
    case InvalidCode = 'invalid_code';
    case Expired = 'expired';
    case NoActiveCode = 'no_active_code';
    case TooManyAttempts = 'too_many_attempts';
    case Replayed = 'replayed';
    case RateLimited = 'rate_limited';
    case Cooldown = 'cooldown';
    case DestinationLimit = 'destination_limit';
    case DailyLimit = 'daily_limit';
    case SendingPaused = 'sending_paused';
    case FactorNotFound = 'factor_not_found';
    case FactorDisabled = 'factor_disabled';
    case DestinationNotAllowed = 'destination_not_allowed';
    case DeliveryFailed = 'delivery_failed';
    case InvalidRecoveryCode = 'invalid_recovery_code';
    case InvalidPassword = 'invalid_password';

    /**
     * The end-user message. $retryAfter (seconds), when known, says when to
     * try again where that helps (an app-wide send cap, a daily cap).
     */
    public function message(?int $retryAfter = null): string
    {
        return match ($this) {
            self::InvalidCode, self::InvalidRecoveryCode => 'The provided code is invalid.',
            self::Expired, self::NoActiveCode => 'This code has expired. Please request a new one.',
            self::TooManyAttempts => 'Too many incorrect attempts. Please request a new code.',
            self::Replayed => 'This code has already been used. Wait for the next one.',
            self::RateLimited => 'Too many attempts. Please try again later.',
            self::Cooldown => 'Please wait before requesting another code.',
            self::DestinationLimit => 'Too many codes were sent to this destination today. Try again tomorrow, or use an authenticator app.',
            self::SendingPaused => 'Too many codes are being sent right now. Try again '.self::inMinutes($retryAfter).', or use an authenticator app.',
            self::DailyLimit => "You've had too many codes today. Try again ".self::inHours($retryAfter).', or use an authenticator app.',
            self::FactorNotFound => 'This verification method is not available.',
            self::FactorDisabled => 'This verification method is currently disabled.',
            self::DestinationNotAllowed => "We can't send verification codes to this destination.",
            self::DeliveryFailed => 'We could not send your code. Please try again.',
            self::InvalidPassword => 'The provided password is incorrect.',
        };
    }

    /** Refused by a limit on sending codes or on attempts (written to the audit table once per window). */
    public function isLimit(): bool
    {
        return in_array($this, [self::RateLimited, self::DestinationLimit, self::DailyLimit, self::SendingPaused], true);
    }

    /** "in 5 hours" over an hour (rounded up), "in an hour", or as inMinutes() below that. */
    private static function inHours(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 3540) {
            return self::inMinutes($seconds);
        }

        return $seconds <= 3600 ? 'in an hour' : 'in '.intdiv($seconds + 3599, 3600).' hours';
    }

    /** "in a minute", "in 18 minutes" (rounded up), or "later" when unknown. */
    private static function inMinutes(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return 'later';
        }

        $minutes = intdiv($seconds + 59, 60);

        return $minutes === 1 ? 'in a minute' : "in {$minutes} minutes";
    }
}
