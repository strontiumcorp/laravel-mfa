<?php

namespace StrontiumCorp\LaravelMfa\Tests\Fixtures;

use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Contracts\EnforcementPolicy;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Contracts\PasswordConfirmationPolicy;

/** A rule that needs the current request (e.g. a tenant or an admin host). */
class RequestAwarePolicy implements EnforcementPolicy, PasswordConfirmationPolicy
{
    public function __construct(private readonly Request $request) {}

    public function mustEnroll(MultiFactorAuthenticatable $user): bool
    {
        return $this->request->headers->has('X-Strict');
    }

    public function mustConfirmPassword(MultiFactorAuthenticatable $user): bool
    {
        return $this->request->headers->has('X-Strict');
    }
}
