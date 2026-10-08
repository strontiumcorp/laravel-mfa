<?php

namespace StrontiumCorp\LaravelMfa\Enums;

enum FactorType: string
{
    case Totp = 'totp';
    case Email = 'email';
    case Sms = 'sms';

    /**
     * Whether a code must be generated and delivered for each challenge.
     */
    public function isDelivered(): bool
    {
        return $this !== self::Totp;
    }

    public function label(): string
    {
        return match ($this) {
            self::Totp => 'Authenticator app',
            self::Email => 'Email',
            self::Sms => 'SMS',
        };
    }
}
