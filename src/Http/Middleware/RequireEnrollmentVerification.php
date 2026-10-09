<?php

namespace StrontiumCorp\LaravelMfa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Events\EnrollmentVerificationRequired;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use StrontiumCorp\LaravelMfa\Support\EnrollmentVerification;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the routes that add a factor: until an account has its first factor,
 * the session must prove it owns the account beyond the password (an email
 * code or an administrator's link, see Support\EnrollmentVerification), so a
 * stolen password alone can't enroll the attacker's authenticator.
 */
class RequireEnrollmentVerification
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly EnrollmentVerification $verification,
        private readonly UiResponse $ui,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->mfa->sessionIdentity($request)?->user();

        if ($user instanceof MultiFactorAuthenticatable && $this->verification->required($request->session(), $user)) {
            event(new EnrollmentVerificationRequired($user, null, null, ['path' => $request->getPathInfo()]));

            $this->ui->enrollmentVerificationRequired($this->verification->describe($user));
        }

        return $next($request);
    }
}
