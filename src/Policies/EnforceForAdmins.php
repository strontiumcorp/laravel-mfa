<?php

namespace StrontiumCorp\LaravelMfa\Policies;

use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

/**
 * Example policy: users whose model reports isAdmin() must enroll.
 * Set 'enforcement.policy' => EnforceForAdmins::class, or write your own.
 */
class EnforceForAdmins implements EnforcementPolicy
{
    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        return method_exists($user, 'isAdmin') && (bool) $user->isAdmin();
    }
}
