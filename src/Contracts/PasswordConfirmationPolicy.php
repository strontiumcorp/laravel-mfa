<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

interface PasswordConfirmationPolicy
{
    /**
     * Whether this user is asked for their password before adding or
     * removing a factor or regenerating recovery codes. Return false for
     * users who don't know a password, e.g. social-login accounts. Users
     * with an empty stored password are never asked, whatever this says.
     */
    public function mustConfirmPassword(MultiFactorAuthenticatable $user): bool;
}
