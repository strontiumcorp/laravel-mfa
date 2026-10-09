<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * The user confirmed their password on the MFA settings page, so factor
 * changes are allowed until auth.password_timeout.
 */
final class PasswordConfirmed extends MfaEvent
{
    //
}
