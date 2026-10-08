<?php

namespace StrontiumCorp\LaravelMfa\Http\Controllers;

use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Mfa;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /**
     * The session user (see SessionIdentity) — not Auth::user(), which may be
     * an impersonated user swapped in for this request.
     */
    protected function sessionUser(Request $request, Mfa $mfa): MultiFactorAuthenticatable
    {
        $user = $mfa->sessionIdentity($request)?->user();

        if (! $user instanceof MultiFactorAuthenticatable) {
            throw new HttpException(403, 'This account does not support multi-factor authentication.');
        }

        return $user;
    }
}
