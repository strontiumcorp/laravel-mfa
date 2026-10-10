<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A session's verification ended before the session did (config
 * mfa.lifetime). Context: cause ("absolute" | "idle" | "revoked"), profile,
 * verified_for (seconds), on_expiry ("challenge" | "logout").
 */
final class VerificationExpired extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
