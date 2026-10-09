<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Contracts\PasswordConfirmationPolicy;

/** clone-voice style: Google users have a password they never chose. */
class ExemptSocialLogins implements PasswordConfirmationPolicy
{
    public function mustConfirmPassword(MultiFactorAuthenticatable $user): bool
    {
        return $user->getAttribute('name') !== 'Google user';
    }
}
