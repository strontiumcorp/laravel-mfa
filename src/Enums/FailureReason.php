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
    case SendingPaused = 'sending_paused';
    case FactorNotFound = 'factor_not_found';
    case FactorDisabled = 'factor_disabled';
    case DestinationNotAllowed = 'destination_not_allowed';
    case DeliveryFailed = 'delivery_failed';
    case InvalidRecoveryCode = 'invalid_recovery_code';
    case InvalidPassword = 'invalid_password';

    public function message(): string
    {
        return match ($this) {
            self::InvalidCode, self::InvalidRecoveryCode => 'The provided code is invalid.',
            self::Expired, self::NoActiveCode => 'This code has expired. Please request a new one.',
            self::TooManyAttempts => 'Too many incorrect attempts. Please request a new code.',
            self::Replayed => 'This code has already been used. Wait for the next one.',
            self::RateLimited => 'Too many attempts. Please try again later.',
            self::Cooldown => 'Please wait before requesting another code.',
            self::DestinationLimit => 'Too many codes were sent to this destination today. Try again tomorrow, or use an authenticator app.',
            self::SendingPaused => "We can't send codes right now. Please use an authenticator app or try again later.",
            self::FactorNotFound => 'This verification method is not available.',
            self::FactorDisabled => 'This verification method is currently disabled.',
            self::DestinationNotAllowed => "We can't send verification codes to this destination.",
            self::DeliveryFailed => 'We could not send your code. Please try again.',
            self::InvalidPassword => 'The provided password is incorrect.',
        };
    }
}
