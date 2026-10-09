<?php

namespace StrontiumCorp\LaravelMfa\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use StrontiumCorp\LaravelMfa\Contracts\MultiFactorAuthenticatable;
use StrontiumCorp\LaravelMfa\Events\PasswordConfirmationRequired;
use StrontiumCorp\LaravelMfa\Http\UiResponse;
use StrontiumCorp\LaravelMfa\Mfa;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the factor-changing settings routes (routes.password_confirmation):
 * answers "password confirmation required" instead of redirecting to the
 * app's confirm-password page, so the settings page can ask inline (POST
 * mfa.password.confirm) and retry. Checks the session user, like the
 * settings controller acts on.
 */
class RequirePasswordConfirmation
{
    public function __construct(private readonly Mfa $mfa, private readonly UiResponse $ui) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->mfa->sessionIdentity($request)?->user();

        if ($user instanceof MultiFactorAuthenticatable
            && $this->mfa->requiresPasswordConfirmation($user)
            && ! $this->mfa->passwordRecentlyConfirmed($request->session())) {
            event(new PasswordConfirmationRequired($user, null, null, ['path' => $request->getPathInfo()]));

            $this->ui->passwordConfirmationRequired(route('mfa.password.confirm'));
        }

        return $next($request);
    }
}
