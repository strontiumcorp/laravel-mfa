<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A password confirmation on the MFA settings page was refused: a wrong
 * password (invalid_password) or too many attempts (rate_limited).
 */
final class PasswordConfirmationFailed extends MfaEvent
{
    //
}
