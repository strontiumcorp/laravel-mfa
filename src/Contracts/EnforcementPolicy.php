<?php

namespace StrontiumCorp\LaravelMfa\Contracts;

interface EnforcementPolicy
{
    /**
     * Whether this user must have at least one factor. Users who must, but
     * have none, are redirected to the MFA settings page until they enroll.
     */
    public function mustEnroll(MultiFactorAuthenticatable $user): bool;
}
