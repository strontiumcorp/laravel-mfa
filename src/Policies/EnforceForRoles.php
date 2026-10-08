<?php

namespace StrontiumCorp\LaravelMfa\Policies;

use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

/**
 * Users holding any of the given roles must enroll. Used when config
 * 'enforce' is a list of roles, e.g. ['admin', 'super_admin', 'support'].
 *
 * Roles come from getMfaRoles() (HasMultiFactorAuthentication reads the
 * "role" attribute, string or enum); override it for other role systems.
 */
final class EnforceForRoles implements EnforcementPolicy
{
    /** @param list<string> $roles */
    public function __construct(private readonly array $roles) {}

    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        $held = method_exists($user, 'getMfaRoles') ? (array) $user->getMfaRoles() : [];

        return array_intersect(array_map('strval', $held), array_map('strval', $this->roles)) !== [];
    }
}
