<?php

namespace StrontiumCorp\LaravelMfa\Enums;

/**
 * Why a user's trusted browsers stopped being trusted (TrustedBrowsersForgotten
 * context "cause"). Expiry isn't one: expired rows are simply pruned.
 */
enum TrustedBrowserRevocation: string
{
    /** The user forgot one or all of them on the settings page. */
    case Settings = 'settings';
    case FactorEnabled = 'factor_enabled';
    /** A method was removed, mfa:reset included. */
    case FactorDisabled = 'factor_disabled';
    /** Often a lost device. */
    case RecoveryCodeUsed = 'recovery_code_used';
    case PasswordChanged = 'password_changed';
    /** Mfa::reset() or Mfa::revokeVerifications(). */
    case VerificationsRevoked = 'verifications_revoked';
}
