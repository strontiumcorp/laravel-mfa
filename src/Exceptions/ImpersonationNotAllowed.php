<?php

namespace StrontiumCorp\LaravelMfa\Exceptions;

use RuntimeException;

class ImpersonationNotAllowed extends RuntimeException
{
    public static function impersonatorNotVerified(): self
    {
        return new self('The impersonator must pass MFA before impersonating another user.');
    }
}
