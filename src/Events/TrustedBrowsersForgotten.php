<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A user's trusted browsers stopped being trusted: they asked for it, their
 * sign-in methods changed, or a recovery code was used. Context: count, cause
 * ("settings" | "factor_enabled" | "factor_disabled" | "recovery_code_used"
 * | "password_changed" | "verifications_revoked"), and trusted_browser_id when one browser was forgotten.
 */
final class TrustedBrowsersForgotten extends MfaEvent
{
    public function level(): string
    {
        return 'notice';
    }
}
