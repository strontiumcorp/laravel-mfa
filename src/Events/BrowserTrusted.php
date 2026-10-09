<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * After a challenge, the user chose to skip it on this browser for a while
 * (trusted_browsers). Context: trusted_browser_id, label, expires_at.
 * Signing in on it later is a VerificationSucceeded with via "trusted_browser".
 */
final class BrowserTrusted extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
