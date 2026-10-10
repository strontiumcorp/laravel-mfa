<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use StrontiumCorp\LaravelMfa\Contracts\LifetimePolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

/** Admins get the "support" profile; everyone else the default one. */
class SupportStaffLifetime implements LifetimePolicy
{
    public function profile(MultiFactorAuthenticatable $user): string
    {
        return $user instanceof User && $user->isAdmin() ? 'support' : 'default';
    }
}
