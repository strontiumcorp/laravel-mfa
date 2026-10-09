<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;

class EnforceForEveryone implements EnforcementPolicy
{
    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        return true;
    }
}
