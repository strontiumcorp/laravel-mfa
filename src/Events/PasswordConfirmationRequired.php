<?php

namespace StrontiumCorp\LaravelMfa\Events;

/**
 * A factor change was refused until the user confirms their password
 * (routes.password_confirmation). Context: path.
 */
final class PasswordConfirmationRequired extends MfaEvent
{
    //
}
